<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use PDO;
use ReflectionObject;
use Throwable;

/** Process-local guard, diagnostics and timing for the shared PHPUnit schema check. */
final class FixtureSchemaMonitor
{
    private static ?self $instance = null;

    private FixtureSchemaGuard $guard;

    private string $root;

    private float $seconds = 0;

    private int $tests = 0;

    /** @var array<string, true> */
    private array $reported = [];

    /** Whether the running test is between the start of setUp() and its schema check. */
    private bool $recording = false;

    /** @var list<array{table: string, rule: string, subject: string, authority: string, database: string, connection: string, scope: string, destroyedBy: string}> */
    private array $destroyed = [];

    /** @var array<string, true> Production tables the running test built, including destroyed ones. */
    private array $built = [];

    /** @var array<string, true> "connection\0table" for tables whose current shape a migration built. */
    private array $migrated = [];

    /** @var array<string, true> Tables the running test created, by name without prefix. */
    private array $created = [];

    /** @var list<string> */
    private array $failures = [];

    private int $ownerPid;

    private function __construct()
    {
        $started = hrtime(true);
        $this->ownerPid = getmypid();
        $this->root = dirname(__DIR__, 2);
        $this->guard = new FixtureSchemaGuard((new SchemaAuthority($this->root.'/database/schema/mariadb-schema.sql'))->tables());
        $this->watchConnections();
        register_shutdown_function($this->finish(...));
        $this->seconds += (hrtime(true) - $started) / 1e9;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /** Start watching for tables the test destroys before its check. */
    public function begin(): void
    {
        $this->reset();
        $this->recording = true;
    }

    /** Stop watching: the check ran, or the test ended without reaching it. */
    public function end(): void
    {
        $this->reset();
    }

    /**
     * @param  list<DatabaseManager>  $managers
     * @param  list<string>  $testOnlyTables
     * @param  array{migration: string, tables: list<string>}|null  $historical
     * @return list<string>
     */
    public function check(string $class, string $test, array $managers, array $testOnlyTables, ?array $historical = null): array
    {
        $started = hrtime(true);
        try {
            $this->tests++;
            $violations = $this->destroyed;
            $errors = $this->failures;
            $built = $this->built;
            $created = $this->created;
            $this->reset();
            $seen = [];
            foreach ($managers as $manager) {
                foreach ($manager->getConnections() as $name => $connection) {
                    $sqlite = $connection->getDriverName() === 'sqlite';
                    // An admin MariaDB handle without a schema owns no tables; a schema it
                    // dropped was inspected first.
                    if (! $sqlite && (string) $connection->getDatabaseName() === '') {
                        continue;
                    }
                    foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
                        // Never connect lazy/closed handles just to inspect a fixture.
                        if (! $pdo instanceof PDO || isset($seen[spl_object_id($pdo)])) {
                            continue;
                        }
                        $seen[spl_object_id($pdo)] = true;
                        try {
                            $result = $this->guard->examine($pdo, $connection->getTablePrefix());
                        } catch (Throwable $exception) {
                            $errors[] = $class.'::'.$test.' connection='.$name.' could not be inspected: '.$exception->getMessage();

                            continue;
                        }
                        $built += array_fill_keys($result['tables'], true);
                        foreach ($result['violations'] as $violation) {
                            // A shared MariaDB schema can hold other classes' leftovers; an
                            // unknown table there is this test's only if the test created it.
                            if ($sqlite || $violation['rule'] !== 'R4' || isset($created[$violation['table']])) {
                                $violations[] = $this->located($violation, $connection);
                            }
                        }
                    }
                }
            }
            foreach ($testOnlyTables as $table) {
                $this->reportOnce($class.' declared '.$table, 'SCHEMA_DECLARED '.$class.' table='.$table.' via fixtureOnlyTables()');
            }
            $historicalTables = [];
            if ($historical !== null) {
                $historicalTables = $historical['tables'];
                $errors = [...$errors, ...$this->historicalErrors($class, $test, $historical, $built)];
                foreach ($historicalTables as $table) {
                    $this->reportOnce($class.' historical '.$table, 'SCHEMA_HISTORICAL '.$class.' table='.$table.' before='.$historical['migration'].'; R1-R3 skipped via historicalSchema()');
                }
            }
            $messages = [];
            foreach ($violations as $violation) {
                if ($violation['rule'] === 'R4' ? in_array($violation['table'], $testOnlyTables, true) : in_array($violation['table'], $historicalTables, true)) {
                    continue;
                }
                // MariaDB sessions on one schema share its base tables; report each once.
                $key = $violation['scope'].' '.$violation['table'].' '.$violation['rule'].' '.$violation['subject'];
                $messages[$key] ??= $class.'::'.$test.' connection='.$violation['connection'].' table='.$violation['table'].' '.$violation['rule'].' '.$violation['subject']
                    .(isset($violation['destroyedBy']) ? ' (inspected before: '.$violation['destroyedBy'].')' : '').'; authority: '.$violation['authority'];
            }

            return [...$errors, ...array_values($messages)];
        } finally {
            $this->seconds += (hrtime(true) - $started) / 1e9;
        }
    }

    /**
     * Every connection Laravel creates inspects the tables a statement is about to drop,
     * rename or wipe, so a fixture cannot escape the check by disappearing before it.
     */
    private function watchConnections(): void
    {
        foreach (['sqlite' => SQLiteConnection::class, 'mysql' => MySqlConnection::class, 'mariadb' => MariaDbConnection::class] as $driver => $class) {
            $resolve = Connection::getResolver($driver);
            Connection::resolverFor($driver, function ($pdo, $database, $prefix, $config) use ($resolve, $class): Connection {
                $connection = $resolve === null ? new $class($pdo, $database, $prefix, $config) : $resolve($pdo, $database, $prefix, $config);
                $connection->beforeExecuting($this->beforeStatement(...));

                return $connection;
            });
        }
    }

    /**
     * Tables built by running migrations pass by construction, so a table a migration built is
     * not inspected when a migration drops or rebuilds it; one the test built or reshaped is.
     *
     * @param  array<mixed>  $bindings
     */
    private function beforeStatement(string $query, array $bindings, Connection $connection): void
    {
        if (! $this->recording || ! preg_match('/\b(?:create|alter|drop|rename|detach|sqlite_master|sqlite_schema)\b/i', $query)) {
            return;
        }
        $started = hrtime(true);
        try {
            $changes = FixtureSchemaGuard::schemaChanges($query);
            if ($changes === ['destroyed' => [], 'created' => [], 'reshaped' => []]) {
                return;
            }
            $migration = $this->issuedByMigration();
            if ($changes['destroyed'] !== []) {
                $this->inspectDestroyedTables($changes['destroyed'], $query, $connection, $migration);
            }
            $key = static fn (string $table): string => $connection->getName()."\0".FixtureSchemaGuard::logicalName($table, $connection->getTablePrefix());
            foreach ($changes['created'] as $table) {
                $this->created[FixtureSchemaGuard::logicalName($table, $connection->getTablePrefix())] = true;
                if ($migration) {
                    $this->migrated[$key($table)] = true;
                } else {
                    unset($this->migrated[$key($table)]);
                }
            }
            // A migration may reshape the tables it built; a test reshaping one makes it hand-built.
            foreach ($migration ? [] : $changes['reshaped'] as $table) {
                unset($this->migrated[$key($table)]);
            }
        } catch (Throwable $exception) {
            $this->failures[] = 'connection='.$connection->getName().' could not be inspected before "'.substr($query, 0, 120).'": '.$exception->getMessage();
        } finally {
            $this->seconds += (hrtime(true) - $started) / 1e9;
        }
    }

    /** @param list<array{database: ?string, table: ?string}> $targets */
    private function inspectDestroyedTables(array $targets, string $query, Connection $connection, bool $migration): void
    {
        // The statement is about to reconnect a closed handle anyway; its tables may outlive it.
        if ($connection->getRawPdo() === null) {
            $connection->reconnect();
        }
        $pdo = $connection->getPdo();
        if (! $pdo instanceof PDO) {
            return;
        }
        $prefix = $connection->getTablePrefix();
        // One inspection per database: a dump reload names every table it drops.
        $groups = [];
        foreach ($targets as ['database' => $database, 'table' => $table]) {
            $key = $database ?? '';
            if ($table === null) {
                $groups[$key] = ['database' => $database, 'tables' => null];
            } elseif (! isset($groups[$key]) || $groups[$key]['tables'] !== null) {
                $groups[$key] ??= ['database' => $database, 'tables' => []];
                $groups[$key]['tables'][] = FixtureSchemaGuard::logicalName($table, $prefix);
            }
        }
        $statement = trim(preg_replace('/\s+/', ' ', substr($query, 0, 120)));
        foreach ($groups as ['database' => $database, 'tables' => $tables]) {
            $result = $this->guard->examine($pdo, $prefix, $tables, $database);
            $this->built += array_fill_keys($result['tables'], true);
            foreach ($result['violations'] as $violation) {
                if ($violation['rule'] === 'R4' || ($migration && isset($this->migrated[$connection->getName()."\0".$violation['table']]))) {
                    continue;
                }
                $this->destroyed[] = $this->located($violation, $connection) + ['destroyedBy' => $statement];
            }
        }
    }

    /** Only a migration file's own class counts; a test cannot wrap its fixture in one. */
    private function issuedByMigration(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $object = $frame['object'] ?? null;
            if ($object instanceof Migration && str_starts_with((string) (new ReflectionObject($object))->getFileName(), $this->root.'/database/migrations/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{table: string, rule: string, subject: string, authority: string, database: string}  $violation
     * @return array{table: string, rule: string, subject: string, authority: string, database: string, connection: string, scope: string}
     */
    private function located(array $violation, Connection $connection): array
    {
        $label = $connection->getName().($violation['database'] === 'main' ? '' : '/'.$violation['database']);

        return $violation + ['connection' => $label, 'scope' => $connection->getDriverName() === 'sqlite' ? 'sqlite '.$label : 'schema '.$violation['database']];
    }

    private function reset(): void
    {
        $this->recording = false;
        $this->destroyed = [];
        $this->built = [];
        $this->migrated = [];
        $this->created = [];
        $this->failures = [];
    }

    /**
     * @param  array{migration: string, tables: list<string>}  $historical
     * @param  array<string, true>  $built
     * @return list<string>
     */
    private function historicalErrors(string $class, string $test, array $historical, array $built): array
    {
        $migration = $historical['migration'];
        $path = $this->root.'/database/migrations/'.$migration;
        if (basename($migration) !== $migration || ! str_ends_with($migration, '.php') || ! is_file($path)) {
            return [$class.'::'.$test.' historicalSchema() names a migration file that does not exist: database/migrations/'.$migration];
        }
        if ($historical['tables'] === []) {
            return [$class.'::'.$test.' historicalSchema() names no tables.'];
        }
        $source = (string) file_get_contents($path);
        $errors = [];
        foreach ($historical['tables'] as $table) {
            if (! isset($built[$table])) {
                $errors[] = $class.'::'.$test.' historicalSchema() names '.$table.', but the test never builds that production table.';
            }
            if (! preg_match('/(?<![\w$])'.preg_quote($table, '/').'(?!\w)/', $source)) {
                $errors[] = $class.'::'.$test.' historicalSchema() names '.$table.', but '.$migration.' never mentions it.';
            }
        }

        return $errors;
    }

    private function reportOnce(string $key, string $message): void
    {
        if (! isset($this->reported[$key])) {
            $this->reported[$key] = true;
            fwrite(STDERR, $message.PHP_EOL);
        }
    }

    private function finish(): void
    {
        // Forked NNTP/process fixtures inherit shutdown callbacks, not ownership.
        if (getmypid() !== $this->ownerPid) {
            return;
        }
        fprintf(STDERR, "SCHEMA_GUARD tests=%d inspections=%d distinct_definitions=%d seconds=%.6f\n", $this->tests, $this->guard->inspections, $this->guard->validations, $this->seconds);
    }
}
