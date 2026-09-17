<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;
use PDO;
use RuntimeException;

/** Process-local guard, diagnostics and timing for the shared PHPUnit postcondition. */
final class FixtureSchemaMonitor
{
    private static ?self $instance = null;

    private FixtureSchemaGuard $guard;

    private FixtureSchemaBaseline $baseline;

    private float $seconds = 0;

    private int $tests = 0;

    /** @var array<string, true> */
    private array $reported = [];

    /** @var array<string, array> */
    private array $collected = [];

    private string|false $collectionPath;

    private int $ownerPid;

    private function __construct()
    {
        $started = hrtime(true);
        $this->ownerPid = getmypid();
        $root = dirname(__DIR__, 2);
        $this->guard = new FixtureSchemaGuard((new SchemaAuthority($root.'/database/schema/mariadb-schema.sql'))->tables());
        $this->baseline = new FixtureSchemaBaseline(json_decode(file_get_contents($root.'/tests/schema-fixture-baseline.json'), true, flags: JSON_THROW_ON_ERROR));
        $this->collectionPath = getenv('NNTMUX_SCHEMA_COLLECT');
        if ($this->collectionPath !== false && ($this->collectionPath === '' || file_exists($this->collectionPath))) {
            throw new RuntimeException('Schema baseline collection requires a new output path. Never overwrite the committed baseline.');
        }
        register_shutdown_function($this->finish(...));
        $this->seconds += (hrtime(true) - $started) / 1e9;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @param  list<DatabaseManager>  $managers
     * @param  list<string>  $testOnlyTables
     * @return list<string>
     */
    public function check(string $class, string $test, array $managers, array $testOnlyTables): array
    {
        $started = hrtime(true);
        try {
            $this->tests++;
            $violations = [];
            $seen = [];
            foreach ($managers as $manager) {
                foreach ($manager->getConnections() as $name => $connection) {
                    foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
                        // Never connect lazy/closed handles just to inspect a fixture.
                        if (! $pdo instanceof PDO || isset($seen[spl_object_id($pdo)]) || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                            continue;
                        }
                        $seen[spl_object_id($pdo)] = true;
                        foreach ($this->guard->inspect($pdo, $testOnlyTables) as $violation) {
                            $violations[] = $violation + ['connection' => $name.($violation['database'] === 'main' ? '' : '/'.$violation['database'])];
                        }
                    }
                }
            }
            foreach ($testOnlyTables as $table) {
                $this->reportOnce($class.' declared '.$table, 'SCHEMA_DECLARED '.$class.' table='.$table.' via fixtureOnlyTables()');
            }
            if ($this->collectionPath !== false) {
                foreach ($violations as $violation) {
                    $key = $class.' '.FixtureSchemaBaseline::key($violation);
                    $this->collected[$key] ??= ['class' => $class, 'table' => $violation['table'], 'rule' => $violation['rule'], 'subject' => $violation['subject'], 'tests' => []];
                    $this->collected[$key]['tests'][$test] = true;
                }

                return [];
            }
            $result = $this->baseline->check($class, $test, $violations);
            foreach ($result['reports'] as $report) {
                // Full diagnostics once per class/table/rule; do not flood every data set.
                $this->reportOnce($class.' '.substr($report, strpos($report, ' table=')), $report);
            }

            return $result['errors'];
        } finally {
            $this->seconds += (hrtime(true) - $started) / 1e9;
        }
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
        if ($this->collectionPath !== false) {
            ksort($this->collected);
            $entries = [];
            foreach ($this->collected as $entry) {
                $entry['tests'] = array_keys($entry['tests']);
                sort($entry['tests']);
                $entries[] = $entry;
            }
            file_put_contents($this->collectionPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        fprintf(STDERR, "SCHEMA_GUARD tests=%d inspections=%d distinct_definitions=%d seconds=%.6f\n", $this->tests, $this->guard->inspections, $this->guard->validations, $this->seconds);
    }
}
