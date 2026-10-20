<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\CollectionSweepOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/** Resume discovery by IDs examined, independently of whether a page contains eligible work. */
final class CollectionSweep
{
    /**
     * With $passEverySeconds, a new pass starts at most that long after the previous one started;
     * a pass already under way resumes regardless.
     *
     * @param  callable(list<int>, CollectionSweepLease): int  $page
     */
    public function run(
        string $kind,
        ?int $group,
        callable $page,
        ?Builder $population = null,
        int $pageSize = 1000,
        int $maxPages = 20,
        float $seconds = 5,
        ?int $passEverySeconds = null,
    ): CollectionSweepResult {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Collection maintenance must commit each mutation independently.');
        }
        $deadline = microtime(true) + $seconds;
        $population ??= $this->population($group);
        $scope = $kind.':'.($group === null ? 'global' : 'group:'.$group);
        $cursor = null;
        $resting = false;
        $lease = CollectionSweepLease::acquire($scope, static function (object $row) use (&$cursor, &$resting, $population, $passEverySeconds): ?array {
            $values = [];
            if ((int) $row->high_water_id === 0) {
                if ($passEverySeconds !== null && $row->pass_started_at !== null
                    && CarbonImmutable::parse($row->pass_started_at, config('app.timezone'))->greaterThan(now()->subSeconds($passEverySeconds))) {
                    $resting = true;

                    return null;
                }
                $row->last_id = 0;
                $row->high_water_id = (int) (clone $population)->max('id');
                if ($passEverySeconds !== null) {
                    $values['pass_started_at'] = now();
                }
            }
            $cursor = $row;

            return [...$values, 'last_id' => $row->last_id, 'high_water_id' => $row->high_water_id];
        });
        if ($lease === null || $cursor === null) {
            return new CollectionSweepResult($resting ? CollectionSweepOutcome::Resting : CollectionSweepOutcome::LeaseBusy, 0, 0);
        }
        $deleted = 0;
        $examined = 0;
        $outcome = CollectionSweepOutcome::BudgetYielded;
        try {
            for ($index = 0; $index < $maxPages && microtime(true) < $deadline; $index++) {
                $lease->renew();
                $ids = (clone $population)->where('id', '>', $cursor->last_id)->where('id', '<=', $cursor->high_water_id)
                    ->orderBy('id')->limit($pageSize)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($ids !== []) {
                    $examined += count($ids);
                    $deleted += $page($ids, $lease);
                    $cursor->last_id = end($ids);
                }
                $finished = count($ids) < $pageSize || $cursor->last_id >= $cursor->high_water_id;
                $lease->advance((int) $cursor->last_id, $finished);
                if ($finished) {
                    $outcome = CollectionSweepOutcome::Exhausted;
                    break;
                }
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

    private function population(?int $group): Builder
    {
        return DB::table('collections')->when($group !== null, static fn ($query) => $query->where('groups_id', $group))
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true),
                static fn ($query) => $query->forceIndex($group === null ? 'PRIMARY' : 'groups_id'));
    }
}
