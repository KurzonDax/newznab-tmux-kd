<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class ImportedFlagMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        // Rows that predate the migration: `releases` has no is_imported yet.
        ProductionTables::fromAuthority()->create('releases', ['id', 'guid']);
        ProductionTables::fromAuthority()->create('release_regexes');
        ProductionTables::fromAuthority()->create('reconciled_postings', ['id', 'release_id', 'digest', 'review_digest', 'state']);
    }

    public function test_a_release_with_no_regex_row_and_no_reconciled_posting_is_marked_imported(): void
    {
        $this->insertRelease(1);

        $this->migration()->up();

        $this->assertSame([1 => 1], $this->importedByRelease());
    }

    public function test_a_release_formed_from_headers_stays_not_imported(): void
    {
        $this->insertRelease(1);
        DB::table('release_regexes')->insert(['releases_id' => 1, 'collection_regex_id' => 4, 'naming_regex_id' => 0]);

        $this->migration()->up();

        $this->assertSame([1 => 0], $this->importedByRelease());
    }

    public function test_a_release_formed_by_pending_reconciliation_stays_not_imported(): void
    {
        $this->insertRelease(1);
        $this->insertReconciledPosting(1, null);

        $this->migration()->up();

        $this->assertSame([1 => 0], $this->importedByRelease());
    }

    public function test_a_posting_attached_by_historical_reconciliation_does_not_hide_an_import(): void
    {
        $this->insertRelease(1);
        $this->insertReconciledPosting(1, str_repeat('b', 64));

        $this->migration()->up();

        $this->assertSame([1 => 1], $this->importedByRelease());
    }

    public function test_each_release_is_judged_on_its_own_rows(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->insertRelease($id);
        }
        DB::table('release_regexes')->insert(['releases_id' => 2, 'collection_regex_id' => 4, 'naming_regex_id' => 0]);
        $this->insertReconciledPosting(3, null);
        $this->insertReconciledPosting(4, str_repeat('b', 64));

        $this->migration()->up();

        $this->assertSame([1 => 1, 2 => 0, 3 => 0, 4 => 1], $this->importedByRelease());
    }

    public function test_rolling_back_drops_the_column(): void
    {
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('releases', 'is_imported'));

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('releases', 'is_imported'));
    }

    private function insertRelease(int $id): void
    {
        DB::table('releases')->insert(['id' => $id, 'guid' => str_pad((string) $id, 40, '0', STR_PAD_LEFT)]);
    }

    private function insertReconciledPosting(int $releaseId, ?string $reviewDigest): void
    {
        DB::table('reconciled_postings')->insert([
            'release_id' => $releaseId,
            'digest' => str_repeat('a', 63).$releaseId,
            'review_digest' => $reviewDigest,
            'state' => 'published',
        ]);
    }

    /** @return array<int, int> */
    private function importedByRelease(): array
    {
        return DB::table('releases')->orderBy('id')->pluck('is_imported', 'id')
            ->map(static fn (mixed $imported): int => (int) $imported)->all();
    }

    private function migration(): Migration
    {
        $paths = glob(database_path('migrations/*_add_is_imported_to_releases_table.php')) ?: [];
        $this->assertCount(1, $paths);

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
