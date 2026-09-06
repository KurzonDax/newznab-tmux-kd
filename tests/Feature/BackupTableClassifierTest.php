<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupKind;
use App\Services\Backup\BackupTableClassifier;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BackupTableClassifierTest extends TestCase
{
    private bool $researchAttached = false;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS research");
        $this->researchAttached = true;

        foreach (['users', 'collections', 'multigroup_parts_12', 'cache', 'pulse_entries', 'custom_Important'] as $table) {
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }

        foreach (['articles', 'capture_runs', 'users'] as $table) {
            Schema::create('research.'.$table, fn (Blueprint $blueprint) => $blueprint->id());
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->researchAttached) {
                DB::statement('DETACH DATABASE research');
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $tiers
     */
    #[DataProvider('backupModes')]
    public function test_backup_selects_only_local_tables(BackupKind $kind, bool $includeWorking, array $tables, array $tiers): void
    {
        $this->assertContains('research.articles', Schema::getTableListing());

        $this->assertSame([
            'tables' => $tables,
            'tiers' => $tiers,
        ], (new BackupTableClassifier)->tablesFor($kind, $includeWorking));

        $this->assertEqualsCanonicalizing(['articles', 'capture_runs', 'users'], Schema::getTableListing(['research'], false));
    }

    /**
     * @return array<string, array{BackupKind, bool, list<string>, list<string>}>
     */
    public static function backupModes(): array
    {
        return [
            'full with working' => [BackupKind::Full, true, ['collections', 'custom_Important', 'multigroup_parts_12', 'users'], ['important', 'working']],
            'full without working' => [BackupKind::Full, false, ['custom_Important', 'users'], ['important']],
            'daily with working' => [BackupKind::Daily, true, ['custom_Important', 'users'], ['important']],
            'daily without working' => [BackupKind::Daily, false, ['custom_Important', 'users'], ['important']],
        ];
    }

    public function test_local_articles_and_physical_names_are_preserved(): void
    {
        Schema::create('articles', fn (Blueprint $table) => $table->id());
        DB::statement('CREATE TABLE "Archive.v1" (id INTEGER)');

        $this->assertSame([
            'tables' => ['Archive.v1', 'articles', 'custom_Important', 'users'],
            'tiers' => ['important'],
        ], (new BackupTableClassifier)->tablesFor(BackupKind::Daily, false));
    }

    #[DataProvider('missingSchemas')]
    public function test_missing_schema_fails_before_inventory_is_queried(?string $schema): void
    {
        Schema::shouldReceive('getCurrentSchemaName')->once()->andReturn($schema);
        Schema::shouldReceive('getTableListing')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to determine the database schema for backup.');

        (new BackupTableClassifier)->tablesFor(BackupKind::Full, true);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingSchemas(): array
    {
        return ['null' => [null], 'empty' => [''], 'whitespace' => [" \t\n"]];
    }

    public function test_zero_schema_stays_scoped_in_the_real_mariadb_grammar(): void
    {
        $connection = new MariaDbConnection(DB::connection()->getPdo(), '0');
        $builder = Schema::getFacadeRoot();
        Schema::swap($connection->getSchemaBuilder());

        try {
            $queries = $connection->pretend(fn () => (new BackupTableClassifier)->tablesFor(BackupKind::Full, true));

            $this->assertCount(1, $queries);
            $this->assertStringContainsString("table_schema in ('0')", $queries[0]['query']);
            $this->assertStringNotContainsString('not in', $queries[0]['query']);
        } finally {
            Schema::swap($builder);
        }
    }
}
