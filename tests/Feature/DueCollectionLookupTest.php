<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionSweepOutcome;
use App\Services\Releases\CollectionSweepLease;
use App\Services\Releases\CollectionSweepResult;
use App\Services\Releases\DueCollectionLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class DueCollectionLookupTest extends TestCase
{
    private const string FRONTIER = '2026-01-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        // Header clocks are the database's own (UTC on SQLite), so fixtures are relative to it.
        config(['app.timezone' => 'UTC']);
        $tables = ProductionTables::fromAuthority();
        $tables->create('collections', ['id', 'groups_id', 'filecheck', 'dateadded', 'added', 'last_seen_at',
            'last_seen_head_postdate', 'last_seen_tail_postdate']);
        $tables->create('usenet_groups', ['id', 'name', 'active', 'backfill', 'backfill_settled_at',
            'first_record_postdate', 'last_record_postdate']);
        $tables->create('collection_sweep_cursors');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.boneless', 'active' => 1, 'backfill' => 0,
            'last_record_postdate' => self::FRONTIER]);
    }

    public function test_frontier_due_rows_resume_in_head_order_and_wrap_once_the_pass_is_complete(): void
    {
        $this->collection(5, null);
        $this->collection(3, '2026-01-01 09:00:00');
        $this->collection(1, '2026-01-01 10:00:00');
        $this->collection(2, '2026-01-01 10:00:00');
        // Less than the hour behind the frontier, or another state.
        $this->collection(4, '2026-01-01 11:30:00');
        $this->collection(6, '2026-01-01 08:00:00', ['filecheck' => 1]);

        $pages = [];
        $outcomes = [];
        for ($run = 1; $run <= 6; $run++) {
            $found = [];
            $outcomes[] = $this->lookup($found, pageSize: 1, maxPages: 1)->outcome;
            $pages[] = $found;
        }

        $this->assertSame([[5], [3], [1], [2], [], [5]], $pages);
        $this->assertSame(CollectionSweepOutcome::BudgetYielded, $outcomes[3]);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $outcomes[4]);
    }

    public function test_an_inactive_group_is_served_by_the_wall_clock(): void
    {
        DB::table('usenet_groups')->update(['active' => 0]);
        $this->collection(1, self::FRONTIER, ['last_seen_at' => null]);
        $this->collection(2, self::FRONTIER, ['last_seen_at' => $this->databaseNow('-2 hours')]);
        // Far behind the frontier, but the head route of an inactive group is the wall clock.
        $this->collection(3, '2026-01-01 01:00:00');
        // No head stamp: a backfilling group's tail route needs no wall-clock wait.
        $this->collection(4, null);

        $found = [];
        $this->lookup($found);
        sort($found);

        $this->assertSame([1, 2, 4], $found);
    }

    public function test_touched_and_late_rows_since_the_previous_pass_are_returned_with_an_overlap(): void
    {
        $found = [];
        $this->lookup($found, touched: true, lateMinutes: 15);
        $started = (string) DB::selectOne("SELECT datetime(touched_since, '+300 seconds') AS started FROM collection_sweep_cursors")->started;
        $at = static fn (string $modifier): string => (string) DB::selectOne('SELECT datetime(?, ?) AS at', [$started, $modifier])->at;
        // Stamped before that pass started by a transaction that committed after it did.
        $this->collection(1, self::FRONTIER, ['last_seen_at' => $at('-4 minutes')]);
        $this->collection(2, self::FRONTIER, ['last_seen_at' => $at('-6 minutes')]);
        // Crossed the late line since that pass.
        $this->collection(3, self::FRONTIER, ['last_seen_at' => $at('-16 minutes')]);
        $this->collection(4, self::FRONTIER, ['last_seen_at' => $at('-25 minutes')]);

        $this->lookup($found, touched: true, lateMinutes: 15);
        sort($found);

        $this->assertSame([1, 3], $found);
    }

    /** @param list<int> $found */
    private function lookup(array &$found, int $pageSize = 100, int $maxPages = 20, bool $touched = false, ?int $lateMinutes = null): CollectionSweepResult
    {
        return app(DueCollectionLookup::class)->run('formation:0', 1, 0, 1,
            static function (array $ids, CollectionSweepLease $lease) use (&$found): int {
                array_push($found, ...$ids);

                return 0;
            }, $pageSize, $maxPages, touched: $touched, lateMinutes: $lateMinutes);
    }

    /** @param array<string, mixed> $attributes */
    private function collection(int $id, ?string $head, array $attributes = []): void
    {
        DB::table('collections')->insert([...['id' => $id, 'groups_id' => 1, 'filecheck' => 0,
            'last_seen_at' => $this->databaseNow('-10 minutes'), 'last_seen_head_postdate' => $head], ...$attributes]);
    }

    private function databaseNow(string $modifier): string
    {
        return (string) DB::selectOne('SELECT datetime(CURRENT_TIMESTAMP, ?) AS now', [$modifier])->now;
    }
}
