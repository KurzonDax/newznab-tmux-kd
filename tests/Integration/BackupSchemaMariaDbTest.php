<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\BackupKind;
use App\Services\Backup\BackupTableClassifier;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

final class BackupSchemaMariaDbTest extends TestCase
{
    /**
     * @param  list<string>  $expectedTables
     */
    #[DataProvider('backupModes')]
    public function test_only_current_schema_tables_and_rows_survive_dump_and_restore(BackupKind $kind, bool $includeWorking, bool $localArticles, array $expectedTables): void
    {
        if (getenv('BACKUP_INTEGRATION_MARIADB') !== '1') {
            $this->markTestSkipped('Set BACKUP_INTEGRATION_MARIADB=1 in the isolated Sail/CI runtime.');
        }

        $originalDefault = DB::getDefaultConnection();
        $originalConnections = config('database.connections');
        $originalSchema = Schema::getFacadeRoot();
        $prefix = 'backup_scope_'.bin2hex(random_bytes(8));
        $databases = ['local' => $prefix.'_local', 'research' => $prefix.'_research', 'restore' => $prefix.'_restore'];
        $createdDatabases = [];
        $admin = null;

        // These credentials belong only to .github/docker-compose.ci.yml.
        $connectionConfig = [
            'driver' => 'mariadb',
            'host' => 'mariadb',
            'port' => 3306,
            'database' => null,
            'username' => 'root',
            'password' => 'password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];

        try {
            config(['database.connections.backup_schema_admin' => $connectionConfig]);
            $admin = DB::connection('backup_schema_admin');

            foreach ($databases as $role => $database) {
                $admin->statement("CREATE DATABASE `{$database}`");
                $createdDatabases[] = $database;
                config(['database.connections.backup_schema_'.$role => [...$connectionConfig, 'database' => $database]]);
            }

            $local = DB::connection('backup_schema_local');
            $research = DB::connection('backup_schema_research');
            $restore = DB::connection('backup_schema_restore');
            $this->createMarkerTables($local, ['users', 'custom_Important', 'collections', 'multigroup_parts_12', 'cache', 'pulse_entries'], 'local');
            $this->createMarkerTables($research, ['users', 'articles', 'capture_runs'], 'research');
            if ($localArticles) {
                $this->createMarkerTables($local, ['articles'], 'local');
            }

            DB::setDefaultConnection('backup_schema_local');
            Schema::swap($local->getSchemaBuilder());
            $this->assertSame($databases['local'], Schema::getCurrentSchemaName());

            $visibleTables = Schema::getTableListing();
            $this->assertContains($databases['research'].'.articles', $visibleTables);
            $this->assertContains($databases['research'].'.capture_runs', $visibleTables);
            $this->assertContains($databases['research'].'.users', $visibleTables);
            $this->assertContains($databases['local'].'.users', $visibleTables);

            $selection = (new BackupTableClassifier)->tablesFor($kind, $includeWorking);
            $this->assertSame($expectedTables, $selection['tables']);
            $this->assertSame($kind === BackupKind::Full && $includeWorking ? ['important', 'working'] : ['important'], $selection['tiers']);
            $this->assertNotEmpty($selection['tables']);

            $dump = Process::env(['MYSQL_PWD' => 'password'])->timeout(30)->run([
                'mariadb-dump', '--no-defaults', '--skip-ssl', '--host=mariadb', '--port=3306', '--user=root',
                '--single-transaction', '--quick', '--routines', '--triggers', '--skip-lock-tables',
                $databases['local'], ...$selection['tables'],
            ]);
            $this->assertTrue($dump->successful(), $dump->errorOutput());
            $this->assertNotSame('', $dump->output());

            $import = Process::env(['MYSQL_PWD' => 'password'])->timeout(30)->input($dump->output())->run([
                'mariadb', '--no-defaults', '--skip-ssl', '--host=mariadb', '--port=3306', '--user=root', $databases['restore'],
            ]);
            $this->assertTrue($import->successful(), $import->errorOutput());
            $this->assertEqualsCanonicalizing($expectedTables, $restore->getSchemaBuilder()->getTableListing([$databases['restore']], false));
            foreach ($expectedTables as $table) {
                $this->assertSame(['local-'.$table], $restore->table($table)->pluck('marker')->all());
            }

            $this->assertEqualsCanonicalizing(['articles', 'capture_runs', 'users'], $research->getSchemaBuilder()->getTableListing([$databases['research']], false));
            foreach (['articles', 'capture_runs', 'users'] as $table) {
                $this->assertSame(['research-'.$table], $research->table($table)->pluck('marker')->all());
            }
            $this->assertSame(['local-users'], $local->table('users')->pluck('marker')->all());
        } finally {
            $cleanupError = null;
            foreach (array_reverse($createdDatabases) as $database) {
                try {
                    $admin->statement("DROP DATABASE `{$database}`");
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
            foreach (['local', 'research', 'restore', 'admin'] as $role) {
                DB::purge('backup_schema_'.$role);
            }
            config(['database.connections' => $originalConnections]);
            DB::setDefaultConnection($originalDefault);
            Schema::swap($originalSchema);

            if ($cleanupError !== null) {
                throw $cleanupError;
            }
        }
    }

    /**
     * @return array<string, array{BackupKind, bool, bool, list<string>}>
     */
    public static function backupModes(): array
    {
        return [
            'full with working' => [BackupKind::Full, true, false, ['collections', 'custom_Important', 'multigroup_parts_12', 'users']],
            'full without working' => [BackupKind::Full, false, false, ['custom_Important', 'users']],
            'daily with working' => [BackupKind::Daily, true, false, ['custom_Important', 'users']],
            'daily without working' => [BackupKind::Daily, false, false, ['custom_Important', 'users']],
            'legitimate local articles' => [BackupKind::Daily, false, true, ['articles', 'custom_Important', 'users']],
        ];
    }

    /**
     * @param  list<string>  $tables
     */
    private function createMarkerTables(Connection $connection, array $tables, string $origin): void
    {
        foreach ($tables as $table) {
            $connection->getSchemaBuilder()->create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('marker');
            });
            $connection->table($table)->insert(['marker' => $origin.'-'.$table]);
        }
    }
}
