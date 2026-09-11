<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\CollectionSweepOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/** Resume discovery by IDs examined, independently of whether a page contains eligible work. */
final class CollectionSweep
{
    /** @param callable(list<int>, CollectionSweepLease): int $page */
    public function run(string $kind, ?int $group, callable $page): CollectionSweepResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Collection maintenance must commit each mutation independently.');
        }
        $deadline = microtime(true) + 5;
        $scope = $kind.':'.($group === null ? 'global' : 'group:'.$group);
        $token = (string) Str::uuid();
        $cursor = DB::transaction(function () use ($scope, $token, $group): ?object {
            DB::table('collection_sweep_cursors')->insertOrIgnore(['scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('collection_sweep_cursors')->where('scope', $scope)->lockForUpdate()->first();
            if ($row->lease_token !== null && $row->lease_expires_at !== null
                && CarbonImmutable::parse($row->lease_expires_at, config('app.timezone'))->greaterThan(now())) {
                return null;
            }
            if ((int) $row->high_water_id === 0) {
                $row->last_id = 0;
                $row->high_water_id = (int) $this->population($group)->max('id');
            }
            DB::table('collection_sweep_cursors')->where('scope', $scope)->update([
                'last_id' => $row->last_id, 'high_water_id' => $row->high_water_id,
                'lease_token' => $token, 'lease_expires_at' => now()->addSeconds(60), 'updated_at' => now(),
            ]);

            return $row;
        }, 3);
        if ($cursor === null) {
            return new CollectionSweepResult(CollectionSweepOutcome::LeaseBusy, 0, 0);
        }
        $lease = new CollectionSweepLease($scope, $token);
        $deleted = 0;
        $examined = 0;
        $outcome = CollectionSweepOutcome::BudgetYielded;
        try {
            for ($index = 0; $index < 20 && microtime(true) < $deadline; $index++) {
                $lease->renew();
                $ids = $this->population($group)->where('id', '>', $cursor->last_id)->where('id', '<=', $cursor->high_water_id)
                    ->orderBy('id')->limit(1000)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($ids !== []) {
                    $examined += count($ids);
                    $deleted += $page($ids, $lease);
                    $cursor->last_id = end($ids);
                }
                $finished = count($ids) < 1000 || $cursor->last_id >= $cursor->high_water_id;
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
