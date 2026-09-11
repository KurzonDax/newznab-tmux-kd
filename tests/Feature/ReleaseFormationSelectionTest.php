<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\CollectionSweep;
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
