<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionDeletionReason;
use App\Services\Releases\CollectionDeletionSelection;
use App\Services\Releases\CollectionQuietPredicate;
use App\Support\DatabaseClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class CollectionQuietPredicateSecondaryCursorTest extends TestCase
{
    /** The collection's newest header date. */
    private const string HEAD = '2026-10-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        // The predicate's wall clock is the database's own (UTC on SQLite), so fixture clocks
        // are relative to the real current time.
        config(['app.timezone' => 'UTC']);

        $tables = ProductionTables::fromAuthority();
        $tables->create('collections', ['id', 'groups_id', 'filecheck', 'dateadded', 'added', 'last_seen_at',
            'last_seen_head_postdate', 'last_seen_tail_postdate']);
        $tables->create('usenet_groups', ['id', 'name', 'active', 'backfill', 'backfill_settled_at',
            'first_record_postdate', 'last_record_postdate']);
        $tables->create('usenet_group_provider_cursors');
    }

    /** @param  array{at_hours: float|string, advanced_minutes_ago: int}|null  $cursor */
    #[DataProvider('cursors')]
    public function test_a_live_caught_up_secondary_cursor_holds_formation_until_it_passes_the_limit(?array $cursor, bool $quiet): void
    {
        $this->collectionInGroupWith(12, $cursor);

        $predicate = CollectionQuietPredicate::build(12);
        $ids = DB::table('collections')->whereRaw($predicate['sql'], $predicate['bindings'])->pluck('id')->all();

        $this->assertSame($quiet ? [1] : [], array_map('intval', $ids));
    }

    /** @param  array{at_hours: float|string, advanced_minutes_ago: int}|null  $cursor */
    #[DataProvider('cursors')]
    public function test_stuck_collection_deletion_waits_for_the_same_cursors(?array $cursor, bool $quiet): void
    {
        $this->collectionInGroupWith(48, $cursor);

        $ids = (new CollectionDeletionSelection(CollectionDeletionReason::Stuck, 48))->query([1])->pluck('id')->all();

        $this->assertSame($quiet ? [1] : [], array_map('intval', $ids));
    }

    /**
     * Cursor post dates are hours after the collection's head; provider 1 is one hour past
     * head + limit.
     *
     * @return array<string, array{?array{at_hours: float|string, advanced_minutes_ago: int}, bool}>
     */
    public static function cursors(): array
    {
        return [
            'no secondary cursor' => [null, true],
            'live cursor behind the limit' => [['at_hours' => -1.0, 'advanced_minutes_ago' => 5], false],
            'cursor past the limit' => [['at_hours' => 0.5, 'advanced_minutes_ago' => 5], true],
            'cursor not advanced for over an hour' => [['at_hours' => -1.0, 'advanced_minutes_ago' => 61], true],
            'cursor further behind provider 1 than the limit' => [['at_hours' => 'lagging', 'advanced_minutes_ago' => 5], true],
        ];
    }

    public function test_without_the_cursor_table_the_fragment_is_unchanged(): void
    {
        Schema::drop('usenet_group_provider_cursors');

        $predicate = CollectionQuietPredicate::build(12);

        $cutoff = DatabaseClock::cutoff(now()->subHours(12))['bindings'][0];
        $this->assertSame([$cutoff, 12, $cutoff, -12, $cutoff], $predicate['bindings']);
        $this->assertStringNotContainsString('usenet_group_provider_cursors', $predicate['sql']);
        $this->assertStringContainsString("OR (g.active = 1 AND g.last_record_postdate >= datetime(collections.last_seen_head_postdate, ? || ' hours'))\n", $predicate['sql']);
    }

    /** @param  array{at_hours: float|string, advanced_minutes_ago: int}|null  $cursor */
    private function collectionInGroupWith(int $hours, ?array $cursor): void
    {
        $head = Carbon::parse(self::HEAD);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test', 'active' => 1, 'backfill' => 0,
            'last_record_postdate' => $head->copy()->addHours($hours + 1)->format('Y-m-d H:i:s')]);
        DB::table('collections')->insert(['id' => 1, 'groups_id' => 1, 'filecheck' => 0,
            'dateadded' => $head->format('Y-m-d H:i:s'), 'added' => $head->format('Y-m-d H:i:s'),
            'last_seen_at' => $head->format('Y-m-d H:i:s'), 'last_seen_head_postdate' => $head->format('Y-m-d H:i:s')]);
        if ($cursor === null) {
            return;
        }

        // 'lagging' is half an hour further behind provider 1's position than the limit.
        $postdate = $cursor['at_hours'] === 'lagging'
            ? $head->copy()->addMinutes(30)
            : $head->copy()->addMinutes((int) (($hours + $cursor['at_hours']) * 60));
        DB::table('usenet_group_provider_cursors')->insert([
            'usenet_groups_id' => 1, 'provider' => 'super', 'provider_host' => 'super.example.invalid', 'last_record' => 10,
            'last_record_postdate' => $postdate->format('Y-m-d H:i:s'),
            'last_advanced_at' => now()->subMinutes($cursor['advanced_minutes_ago'])->format('Y-m-d H:i:s'),
        ]);
    }
}
