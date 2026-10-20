<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\CollectionSweepOutcome;
use App\Support\DatabaseClock;
use App\Support\SchemaCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Find the waiting collections of one group and state that may have become due, without reading
 * the ones that cannot. A pass serves, in order:
 *
 * 1. frontier-due: no head stamp, or a head at least $hours behind the group frontier. An
 *    inactive group's head route is the wall clock, so there it is no head stamp, no last
 *    header, or none stored for $hours;
 * 2. touched: a header stored since the previous pass started, less an overlap, so a stamp
 *    whose transaction committed after that pass started is still returned;
 * 3. late: the last header crossed the late line since the previous pass.
 *
 * The caller's readiness predicate decides every row it is handed, so a lookup may return extra
 * rows. Each lookup walks in (stamp, id) order from a persisted position, so due rows that stay
 * held cannot starve the rows behind them; once the late lookup is exhausted, the pass is
 * complete and the next one starts again at the front.
 */
/** Not final: tests replace it to hide rows from every lookup and reach the safety-net walk. */
class DueCollectionLookup
{
    /** How far behind its start a pass may still see stamps from a transaction that was open when it started. */
    public const int OVERLAP_SECONDS = 300;

    /** The full walk behind the lookups starts a new pass at most this often per group and state. */
    public const int SAFETY_NET_WALK_SECONDS = 3600;

    private const int UNSTAMPED = 0;

    private const int UNSEEN = 1;

    private const int DUE = 2;

    private const int TOUCHED = 3;

    private const int LATE = 4;

    public static function available(): bool
    {
        return SchemaCapabilities::hasColumns('collection_sweep_cursors', ['pass_started_at', 'lookup_stage', 'lookup_stamp',
            'lookup_id', 'lookup_started_at', 'touched_since', 'late_since'])
            && SchemaCapabilities::hasColumns('collections', ['last_seen_at', 'last_seen_head_postdate']);
    }

    /**
     * @param  callable(list<int>, CollectionSweepLease): int  $page
     * @param  bool  $touched  Serve the touched lookup.
     * @param  int|null  $lateMinutes  Serve the late lookup for this late line.
     */
    public function run(string $kind, int $group, int $status, int $hours, callable $page, int $pageSize = 1000,
        int $maxPages = 20, float $seconds = 5, bool $touched = false, ?int $lateMinutes = null): CollectionSweepResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Collection maintenance must commit each mutation independently.');
        }
        $deadline = microtime(true) + $seconds;
        $scope = $kind.':group:'.$group;
        $cursor = null;
        $lease = CollectionSweepLease::acquire($scope, static function (object $row) use (&$cursor): array {
            if ($row->lookup_started_at === null) {
                $row->lookup_started_at = DatabaseClock::now();
                $row->lookup_stage = self::UNSTAMPED;
                $row->lookup_stamp = null;
                $row->lookup_id = null;
            }
            $cursor = $row;

            return ['lookup_started_at' => $row->lookup_started_at, 'lookup_stage' => $row->lookup_stage,
                'lookup_stamp' => $row->lookup_stamp, 'lookup_id' => $row->lookup_id];
        });
        if ($lease === null || $cursor === null) {
            return new CollectionSweepResult(CollectionSweepOutcome::LeaseBusy, 0, 0);
        }

        $usenetGroup = DB::table('usenet_groups')->where('id', $group)->first(['active', 'last_record_postdate']);
        $active = $usenetGroup !== null && (int) $usenetGroup->active === 1;
        $started = (string) $cursor->lookup_started_at;
        $lateLine = $lateMinutes === null ? null : self::shift($started, -60 * $lateMinutes);
        $stage = (int) $cursor->lookup_stage;
        $stamp = $cursor->lookup_stamp === null ? null : (string) $cursor->lookup_stamp;
        $lastId = $cursor->lookup_id === null ? null : (int) $cursor->lookup_id;
        $touchedSince = $touched ? ($cursor->touched_since ?? self::shift($started, -self::OVERLAP_SECONDS)) : null;
        $lateSince = $lateLine === null ? null : ($cursor->late_since ?? self::shift($lateLine, -self::OVERLAP_SECONDS));
        $examined = 0;
        $deleted = 0;
        $outcome = CollectionSweepOutcome::BudgetYielded;
        try {
            $unrecorded = false;
            for ($index = 0; $stage <= self::LATE && $index < $maxPages && microtime(true) < $deadline;) {
                $lookup = $this->lookup($stage, $group, $status, $hours, $active, $usenetGroup?->last_record_postdate,
                    $touchedSince, $started, $lateSince, $lateLine);
                $rows = [];
                $column = null;
                if ($lookup !== null) {
                    [$query, $column] = $lookup;
                    $lease->renew();
                    $rows = $this->after($query, $column, $stamp, $lastId)->limit($pageSize)
                        ->get($column === null ? ['id'] : ['id', $column.' as stamp'])->all();
                }
                if ($rows !== []) {
                    $index++;
                    $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
                    $examined += count($ids);
                    $deleted += $page($ids, $lease);
                    $last = end($rows);
                    $stamp = $column === null ? null : (string) $last->stamp;
                    $lastId = (int) $last->id;
                }
                if (count($rows) < $pageSize) {
                    $stage++;
                    $stamp = null;
                    $lastId = null;
                }
                $unrecorded = true;
                if ($rows !== [] && $stage <= self::LATE) {
                    $lease->record(['lookup_stage' => $stage, 'lookup_stamp' => $stamp, 'lookup_id' => $lastId]);
                    $unrecorded = false;
                }
            }
            if ($stage > self::LATE) {
                $lease->record(['lookup_stage' => null, 'lookup_stamp' => null, 'lookup_id' => null, 'lookup_started_at' => null,
                    'touched_since' => $touched ? self::shift($started, -self::OVERLAP_SECONDS) : null,
                    'late_since' => $lateLine === null ? null : self::shift($lateLine, -self::OVERLAP_SECONDS)]);
                $outcome = CollectionSweepOutcome::Exhausted;
            } elseif ($unrecorded) {
                $lease->record(['lookup_stage' => $stage, 'lookup_stamp' => $stamp, 'lookup_id' => $lastId]);
            }
        } catch (CollectionSweepLeaseLost $exception) {
            $deleted += $exception->deleted;
            $outcome = CollectionSweepOutcome::LeaseLost;
            Log::info('Collection maintenance yielded', ['scope' => $scope, 'reason' => 'lease_lost']);
        } finally {
            $lease->release();
        }

        return new CollectionSweepResult($outcome, $examined, $deleted);
    }

    /**
     * The query for one lookup and the stamp column its order starts with (null: ID order only),
     * or null when the lookup has nothing to return.
     *
     * @return array{Builder, ?string}|null
     */
    private function lookup(int $stage, int $group, int $status, int $hours, bool $active, ?string $frontier,
        ?string $touchedSince, string $started, ?string $lateSince, ?string $lateLine): ?array
    {
        $head = $stage === self::UNSTAMPED || ($active && $stage === self::DUE);
        $quiet = DatabaseClock::cutoff(now()->subHours($hours));
        $query = DB::table('collections')->where('groups_id', $group)->where('filecheck', $status)
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), static fn ($query) => $query->forceIndex(
                $head ? 'ix_collections_group_filecheck_head_id' : 'ix_collections_group_filecheck_seen_id'));

        return match ($stage) {
            self::UNSTAMPED => [$query->whereNull('last_seen_head_postdate'), null],
            self::UNSEEN => $active ? null : [$query->whereNull('last_seen_at'), null],
            self::DUE => $active
                ? ($frontier === null ? null : [$query->whereNotNull('last_seen_head_postdate')
                    ->where('last_seen_head_postdate', '<=', self::shift($frontier, -3600 * $hours)), 'last_seen_head_postdate'])
                : [$query->whereRaw('last_seen_at < '.$quiet['sql'], $quiet['bindings']), 'last_seen_at'],
            self::TOUCHED => $touchedSince === null ? null
                : [$query->where('last_seen_at', '>=', $touchedSince)->where('last_seen_at', '<', $started), 'last_seen_at'],
            default => $lateSince === null || $lateLine === null ? null
                : [$query->where('last_seen_at', '>=', $lateSince)->where('last_seen_at', '<', $lateLine), 'last_seen_at'],
        };
    }

    /** Rows after the last one returned, in the lookup's order. */
    private function after(Builder $query, ?string $column, ?string $stamp, ?int $lastId): Builder
    {
        if ($column === null) {
            return $query->when($lastId !== null, static fn ($query) => $query->where('id', '>', $lastId))->orderBy('id');
        }
        if ($stamp !== null && $lastId !== null) {
            $query->where($column, '>=', $stamp)
                ->where(static fn ($after) => $after->where($column, '>', $stamp)->orWhere('id', '>', $lastId));
        }

        return $query->orderBy($column)->orderBy('id');
    }

    /** Move a stored DATETIME by whole seconds, as the database's own date arithmetic does. */
    private static function shift(string $stamp, int $seconds): string
    {
        return CarbonImmutable::parse($stamp, 'UTC')->addSeconds($seconds)->format('Y-m-d H:i:s');
    }
}
