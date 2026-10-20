<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionDeletionReason;
use App\Models\Settings;
use App\Services\CollectionCleanupService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\CollectionCompletionMeasurer;
use App\Services\Releases\CollectionDeletionSelection;
use App\Services\Releases\CollectionSweep;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use App\Services\Releases\ReleaseDuplicateFinder;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\TestCase;

class ReleaseFormationSelectionTest extends TestCase
{
    use CreatesPostingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('regexp', static fn ($pattern, $value): int => preg_match('/'.str_replace('/', '\\/', $pattern).'/', (string) $value) === 1 ? 1 : 0, 2);
        $this->createPostingSchema();
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        Cache::flush();
        DB::table('settings')->insert([
            ['name' => 'delaytime', 'value' => '1'],
            ['name' => 'maxnzbsprocessed', 'value' => '128'],
        ]);
        Settings::forgetCachedSettings();
        $this->travelTo(Carbon::parse('2026-01-01T12:00:00Z'));
    }

    public function test_an_ineligible_state_page_advances_without_running_empty_downstream_batches(): void
    {
        foreach (range(1, 128) as $id) {
            $this->source($id);
        }
        $processing = app(ReleaseProcessingService::class)->setEchoCLI(false);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $processing->formReleases(1);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(['releases' => 0, 'nzbs' => 0, 'dupes' => 0, 'iterations' => 1], $result);
        $this->assertLessThan(180, count($queries), 'A 128-row ineligible page must not run sixteen empty formation pipelines.');
        $this->assertSame(128, DB::table('collections')->count());
        $cursor = DB::table('collection_sweep_cursors')->where('scope', 'formation:0:group:1')->first();
        $this->assertSame(0, (int) $cursor->last_id);
        $this->assertSame(0, (int) $cursor->high_water_id);
        $this->assertNull($cursor->lease_token);
    }

    public function test_page_selection_preserves_each_readiness_route_and_live_ownership_checks(): void
    {
        foreach (range(1, 21) as $id) {
            $this->source($id);
        }
        DB::table('collections')->whereIn('id', [2, 14, 17, 18])->update(['totalfiles' => 1]);
        DB::table('collections')->where('id', 19)->update(['totalfiles' => 2]);
        foreach ([2 => 1, 14 => 1, 17 => 2, 18 => 3, 19 => 2] as $id => $files) {
            for ($file = 1; $file <= $files; $file++) {
                DB::table('binaries')->insert(['collections_id' => $id, 'totalparts' => 1,
                    'partcheck' => $id === 19 && $file === 2 ? 0 : 1]);
            }
        }
        foreach ([4 => 1, 5 => 15, 6 => 16, 7 => 10, 8 => 2, 9 => 3, 10 => 4, 11 => 5,
            13 => 2, 15 => 2, 16 => 2] as $id => $status) {
            DB::table('collections')->where('id', $id)->update(['filecheck' => $status]);
        }
        DB::table('collections')->whereIn('id', [3, 4, 5, 6, 7, 10, 11, 21])->update([
            'last_seen_at' => '2026-01-01 09:00:00', 'last_seen_head_postdate' => '2026-01-01 09:00:00',
        ]);
        DB::table('collections')->where('id', 21)->update(['last_seen_head_postdate' => null]);
        DB::table('collections')->where('id', 15)->update(['groups_id' => 2]);
        Schema::create('obfuscation_recovery_publications', static function (Blueprint $table): void {
            $table->unsignedBigInteger('collections_id')->primary();
            $table->string('state');
        });
        DB::table('obfuscation_recovery_publications')->insert(['collections_id' => 13, 'state' => 'prepared']);
        DB::table('reconciliation_admissions')->insert(['collection_id' => 14, 'decision_id' => 'held',
            'revision' => 'current', 'admitted_at' => now(), 'expires_at' => now()->addHour(), 'state' => 'admitted']);
        $processing = app(ReleaseProcessingService::class)->setEchoCLI(false);
        $selection = new ReflectionMethod(ReleaseProcessingService::class, 'formationCollectionIds');
        $page = array_values(array_diff(range(1, 21), [16]));

        foreach ([0 => [2, 3, 17, 21], 1 => [4], 2 => [8], 3 => [9], 10 => [7], 15 => [5], 16 => [6]] as $status => $ids) {
            $this->assertSame($ids, $selection->invoke($processing, 1, $page, $status));
        }

        DB::table('reconciliation_admissions')->insert(['collection_id' => 2, 'decision_id' => 'new-owner',
            'revision' => 'current', 'admitted_at' => now(), 'expires_at' => now()->addHour(), 'state' => 'admitted']);
        DB::table('collections')->where('id', 2)->update(['filesize' => 123]);
        $reconcile = new ReflectionMethod(ReleaseProcessingService::class, 'reconcileIncompleteCollections');
        $reconcile->invoke($processing, 1, [2]);
        $this->assertSame(123, DB::table('collections')->where('id', 2)->value('filesize'));
        $this->assertSame([3, 17, 21], $selection->invoke($processing, 1, $page, 0));
    }

    public function test_pending_states_do_not_scan_or_mutate_the_ready_prefix_owned_by_another_queue(): void
    {
        foreach (range(1, 128) as $id) {
            $this->source($id);
        }
        DB::table('collections')->update(['filecheck' => 2]);
        $this->source(129);
        DB::table('collection_sweep_cursors')->insert(['scope' => 'formation:2:group:1',
            'lease_token' => 'another-worker', 'lease_expires_at' => now()->addMinute()]);
        $processing = app(ReleaseProcessingService::class)->setEchoCLI(false);

        $first = $processing->formReleases(1);

        $this->assertSame(1, $first['iterations']);
        $this->assertSame(128, DB::table('collections')->where('filecheck', 2)->count());
        $pending = DB::table('collection_sweep_cursors')->where('scope', 'formation:0:group:1')->first();
        $this->assertSame(0, (int) $pending->high_water_id);
        $this->assertNull($pending->lease_token);
        $this->assertSame(0, DB::table('collections')->where('id', 129)->value('filecheck'));

        DB::table('collection_sweep_cursors')->where('scope', 'formation:2:group:1')
            ->update(['lease_token' => null, 'lease_expires_at' => null]);
        $processing->formReleases(1);
        $this->assertSame(128, DB::table('collections')->where('filecheck', 3)->count());
    }

    public function test_each_pending_state_gets_a_page_before_a_large_ready_queue_takes_another(): void
    {
        foreach (range(1, 259) as $id) {
            $this->source($id);
        }
        DB::table('collections')->whereBetween('id', [1, 129])->update(['filecheck' => 3]);
        DB::table('collections')->whereBetween('id', [130, 258])->update(['filecheck' => 2]);
        $readyPositionAtPendingStart = null;
        DB::listen(function ($event) use (&$readyPositionAtPendingStart): void {
            if ($readyPositionAtPendingStart === null && str_starts_with($event->sql, 'insert')
                && in_array('formation:0:group:1', $event->bindings, true)) {
                $readyPositionAtPendingStart = (int) DB::table('collection_sweep_cursors')
                    ->where('scope', 'formation:3:group:1')->value('last_id');
            }
        });

        app(ReleaseProcessingService::class)->setEchoCLI(false)->formReleases(1);

        $this->assertSame(128, $readyPositionAtPendingStart,
            'Pending sources must get their first page before the ready queue is exhausted.');
        $this->assertSame(258, DB::table('collections')->where('filecheck', 3)->count());
        $this->assertSame(0, DB::table('collections')->where('id', 259)->value('filecheck'));
        $this->assertSame(0, (int) DB::table('collection_sweep_cursors')
            ->where('scope', 'formation:3:group:1')->value('high_water_id'));
    }

    /** @return array<string, array{?string, int}> */
    public static function configuredFormationLimits(): array
    {
        return [
            'limit 100' => ['100', 100],
            'limit 500' => ['500', 500],
            'default 1000' => ['1000', 1000],
            'above 1000' => ['1500', 1500],
            'missing' => [null, 1000],
            'blank' => ['', 1000],
            'zero' => ['0', 1000],
            'negative' => ['-3', 1000],
        ];
    }

    #[DataProvider('configuredFormationLimits')]
    public function test_configured_limits_bound_actual_pages_without_stopping_at_deferred_sources(?string $stored, int $expected): void
    {
        if ($stored === null) {
            DB::table('settings')->where('name', 'maxnzbsprocessed')->delete();
        } else {
            DB::table('settings')->where('name', 'maxnzbsprocessed')->update(['value' => $stored]);
        }
        Settings::forgetCachedSettings();
        foreach (range(1, 1601) as $id) {
            $this->source($id);
        }
        $processing = app(ReleaseProcessingService::class)->setEchoCLI(false);
        $this->assertSame($expected, $processing->getReleaseCreationLimit());
        $pages = [];
        $pdo = DB::connection()->getPdo();
        $previous = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FormationPageRecordingStatement::class,
            [static function (array $rows) use (&$pages): void {
                if ($rows !== []) {
                    $pages[] = array_map(static fn (object $row): int => (int) $row->id, $rows);
                }
            }]]);
        try {
            $result = $processing->formReleases(1);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $previous);
        }
        $this->assertGreaterThan(1, count($pages));
        foreach ($pages as $page) {
            $this->assertLessThanOrEqual($expected, count($page));
        }
        $this->assertSame(range(1, 1601), array_merge(...$pages),
            'Deferred sources must advance the real query cursor without hiding later IDs or being selected twice.');
        $this->assertSame(count($pages), $result['iterations']);
        $this->assertSame(0, $result['releases']);
        $this->assertSame(0, $result['nzbs']);
        $this->assertSame(1601, DB::table('collections')->where('filecheck', 0)->count());
        $cursor = DB::table('collection_sweep_cursors')->where('scope', 'formation:0:group:1')->first();
        $this->assertSame(0, (int) $cursor->last_id);
        $this->assertSame(0, (int) $cursor->high_water_id);
        $this->assertNull($cursor->lease_token);
    }

    public function test_a_formation_run_forms_exactly_the_collections_each_readiness_route_admits(): void
    {
        $this->pinnedRouteFixtures();
        $creation = $this->recordingCreation();
        $processing = (new ReleaseProcessingService(releaseCreationService: $creation))->setEchoCLI(false);

        $processing->formReleases(1);
        $processing->formReleases(2);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 9, 13, 21], $creation->formed());
    }

    public function test_stuck_maintenance_deletes_exactly_the_collections_quiet_past_the_timeout(): void
    {
        $this->pinnedStuckFixtures();

        app(CollectionCleanupService::class)->runMaintenance(new CollectionDeletionSelection(CollectionDeletionReason::Stuck, 2), 1);

        $this->assertSame([1, 4, 5, 7, 8, 9, 10], DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    /**
     * One collection per readiness route on group 1 (active, frontier 12:00, one-hour wait) and
     * group 2 (inactive). Header clocks are relative to the database clock, which the quiet
     * predicate reads; head stamps are relative to the group frontier.
     */
    private function pinnedRouteFixtures(): void
    {
        DB::table('settings')->insert(['name' => 'collection_timeout', 'value' => '48']);
        Settings::forgetCachedSettings();
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.inactive', 'active' => 0,
            'last_record_postdate' => '2026-01-01 12:00:00']);
        $this->waiting(1, ['filecheck' => 3, 'filesize' => 100]);
        $this->waiting(2, ['filecheck' => 2]);
        // Counters complete: the evidence route, whatever the clocks say.
        $this->waiting(3, ['totalfiles' => 1]);
        // Head two hours behind the frontier: quiet in every waiting state the recount promotes.
        $this->waiting(4, ['last_seen_head_postdate' => '2026-01-01 10:00:00']);
        $this->waiting(5, ['filecheck' => 1, 'last_seen_head_postdate' => '2026-01-01 10:00:00']);
        $this->waiting(6, ['filecheck' => 10, 'last_seen_head_postdate' => '2026-01-01 10:00:00']);
        $this->waiting(7, ['filecheck' => 15, 'totalfiles' => 1]);
        // States 15 and 16 form on evidence only.
        $this->waiting(8, ['filecheck' => 16, 'last_seen_head_postdate' => '2026-01-01 10:00:00']);
        // No head stamp: the wall clock decides.
        $this->waiting(9, ['last_seen_head_postdate' => null, 'last_seen_at' => $this->databaseNow('-2 hours')]);
        $this->waiting(10, ['last_seen_head_postdate' => null]);
        // An inactive group's head route is the wall clock.
        $this->waiting(11, ['groups_id' => 2]);
        $this->waiting(21, ['groups_id' => 2, 'last_seen_at' => $this->databaseNow('-2 hours')]);
        // A late collection, whose release already exists, waits only 15 quiet minutes.
        $this->waiting(13, ['last_seen_at' => $this->databaseNow('-16 minutes')]);
        $this->waiting(14, ['last_seen_at' => $this->databaseNow('-14 minutes')]);
        foreach ([13 => 500, 14 => 501] as $id => $release) {
            $this->release($release, (string) DB::table('collections')->where('id', $id)->value('collectionhash'));
        }
        // A live secondary provider still reading the stretch holds a head that is due by provider 1.
        $this->waiting(15, ['last_seen_head_postdate' => '2026-01-01 10:30:00']);
        (require database_path('migrations/2026_10_02_000000_create_usenet_group_provider_cursors.php'))->up();
        DB::table('usenet_group_provider_cursors')->insert(['usenet_groups_id' => 1, 'provider' => 'second',
            'provider_host' => 'news.example.invalid', 'last_record' => 1, 'last_record_postdate' => '2026-01-01 11:00:00',
            'last_advanced_at' => $this->databaseNow('-5 minutes')]);
        foreach ([16, 17, 18] as $id) {
            $this->waiting($id, ['last_seen_head_postdate' => '2026-01-01 10:00:00']);
        }
        DB::table('reconciliation_admissions')->insert(['collection_id' => 16, 'decision_id' => 'held',
            'revision' => 'current', 'admitted_at' => now(), 'expires_at' => now()->addHour(), 'state' => 'admitted']);
        Schema::create('obfuscation_recovery_publications', static function (Blueprint $table): void {
            $table->unsignedBigInteger('collections_id')->primary();
            $table->string('state');
        });
        DB::table('obfuscation_recovery_publications')->insert(['collections_id' => 17, 'state' => 'prepared']);
        $this->release(502, sha1('claimed release'));
        DB::table('reconciled_postings')->insert(['release_id' => 502, 'digest' => 'claimed', 'state' => 'prepared',
            'inventory' => '[]', 'decision' => '[]']);
        DB::table('reconciliation_claims')->insert(['collection_id' => 18, 'deadline' => now()->addHour(),
            'reason' => 'accepted', 'release_id' => 502]);
        // Not due on any route.
        $this->waiting(19);
    }

    /** Collections waiting in group 1 with a two-hour stuck timeout: head at or before 10:00 is stuck. */
    private function pinnedStuckFixtures(): void
    {
        foreach ([0, 1, 10, 15, 16] as $index => $status) {
            $this->waiting(2 + $index, ['filecheck' => $status, 'last_seen_head_postdate' => '2026-01-01 09:00:00']);
        }
        DB::table('collections')->whereIn('id', [4, 5])->update(['last_seen_head_postdate' => '2026-01-01 10:30:00']);
        $this->waiting(1, ['filecheck' => 3, 'last_seen_head_postdate' => '2026-01-01 09:00:00']);
        $this->waiting(7, ['last_seen_head_postdate' => null]);
        $this->waiting(11, ['last_seen_head_postdate' => null, 'last_seen_at' => $this->databaseNow('-3 hours')]);
        foreach ([8, 9] as $id) {
            $this->waiting($id, ['last_seen_head_postdate' => '2026-01-01 09:00:00']);
        }
        DB::table('reconciliation_admissions')->insert(['collection_id' => 8, 'decision_id' => 'held',
            'revision' => 'current', 'admitted_at' => now(), 'expires_at' => now()->addHour(), 'state' => 'admitted']);
        Schema::create('obfuscation_recovery_publications', static function (Blueprint $table): void {
            $table->unsignedBigInteger('collections_id')->primary();
            $table->string('state');
        });
        DB::table('obfuscation_recovery_publications')->insert(['collections_id' => 9, 'state' => 'prepared']);
        $this->waiting(10);
    }

    /**
     * A waiting collection with one stored, complete file. By default its head is at the group
     * frontier and its last header was stored two minutes ago, so no route admits it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function waiting(int $id, array $attributes = []): void
    {
        $seen = $this->databaseNow('-2 minutes');
        DB::table('collections')->insert([...['id' => $id, 'groups_id' => 1, 'fromname' => 'Synthetic Poster',
            'subject' => 'Synthetic source '.$id, 'date' => $seen, 'dateadded' => $seen, 'added' => $seen,
            'last_seen_at' => $seen, 'last_seen_head_postdate' => '2026-01-01 12:00:00', 'totalfiles' => 0,
            'declaredfiles' => 1, 'filecheck' => 0, 'filesize' => 0, 'collectionhash' => sha1('source '.$id)], ...$attributes]);
        $binary = DB::table('binaries')->insertGetId(['binaryhash' => md5('binary '.$id, true), 'name' => 'source'.$id.'.rar',
            'collections_id' => $id, 'totalparts' => 1, 'currentparts' => 1, 'filenumber' => 1, 'partsize' => 100, 'partcheck' => 1]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'number' => $id, 'messageid' => 'source-'.$id.'@example.invalid',
            'partnumber' => 1, 'size' => 100]);
    }

    private function release(int $id, string $collectionHash): void
    {
        DB::table('releases')->insert(['id' => $id, 'name' => 'Release '.$id, 'searchname' => 'Release '.$id,
            'guid' => sha1('guid '.$id), 'groups_id' => 1, 'nzbstatus' => 1, 'completion' => 100, 'size' => 100,
            'collectionhash' => $collectionHash]);
    }

    /** The database clock the quiet predicate reads, moved by an SQLite modifier such as '-2 hours'. */
    private function databaseNow(string $modifier = '+0 seconds'): string
    {
        return (string) DB::selectOne('SELECT datetime(CURRENT_TIMESTAMP, ?) AS now', [$modifier])->now;
    }

    /** Release creation that records the sized collections it is handed instead of forming them. */
    private function recordingCreation(): ReleaseCreationService
    {
        return new class(new ReleaseCleaningService, new CollectionCleanupService, app(ReleaseDuplicateFinder::class), app(CollectionCompletionMeasurer::class), app(ReleaseDuplicateAbsorber::class)) extends ReleaseCreationService
        {
            /** @var list<int> */
            private array $formed = [];

            public function createSelectedCollections(int $groupId, array $collectionIds, bool $echoCLI): array
            {
                array_push($this->formed, ...DB::table('collections')->whereIn('id', $collectionIds)
                    ->where('filecheck', 3)->pluck('id')->map(static fn ($id): int => (int) $id)->all());

                return ['added' => 0, 'dupes' => 0];
            }

            /** @return list<int> */
            public function formed(): array
            {
                $formed = array_values(array_unique($this->formed));
                sort($formed);

                return $formed;
            }
        };
    }

    private function source(int $id): void
    {
        DB::table('collections')->insert(['id' => $id, 'groups_id' => 1, 'fromname' => 'Synthetic Poster',
            'subject' => 'Synthetic incomplete source', 'date' => now(), 'dateadded' => now(),
            'last_seen_at' => now(), 'last_seen_head_postdate' => now(), 'totalfiles' => 0,
            'declaredfiles' => 1, 'filecheck' => 0, 'collectionhash' => sha1((string) $id, true)]);
    }
}

/** Records rows returned by actual formation discovery without replaying or replacing its query. */
final class FormationPageRecordingStatement extends PDOStatement
{
    protected function __construct(private readonly Closure $capture) {}

    /** @return array<mixed> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        if (str_starts_with($this->queryString, 'select "id" from "collections"')
            && str_contains($this->queryString, '"filecheck" = ?')) {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
                if (($frame['class'] ?? '') === CollectionSweep::class && $frame['function'] === 'run') {
                    ($this->capture)($rows);
                    break;
                }
            }
        }

        return $rows;
    }
}
