<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use App\Models\Settings;
use App\Services\CollectionReconciliation\PopulationQuery;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Original admission traversal from e8f7abee, used only by the isolated benchmark runner. */
trait AdmissionBaseline
{
    /**
     * Lock the bounded discovery population before screening and ordinary mutation.
     * The caller keeps these locks through its mutation; ingestion uses the same rows.
     *
     * @param  list<int>  $ids
     */
    public function lockAndScreen(array $ids, ?int $quietHours = null): bool
    {
        if (count(array_unique($ids)) > self::MUTATION_BATCH_SIZE) {
            return false;
        }
        if (! Schema::hasTable('reconciliation_admissions')) {
            return true;
        }
        $queries = new PopulationQuery;
        $sources = $queries->lockIds($ids);
        if ($sources->count() !== count(array_unique($ids))) {
            return false;
        }
        $windows = [];
        $sourceWindows = [];
        foreach ($sources as $source) {
            $window = $queries->sourceWindow($source);
            if ($window === null) {
                continue;
            }
            $key = json_encode($window, JSON_THROW_ON_ERROR);
            $windows[$key] = $window;
            $sourceWindows[(int) $source->id] = $key;
        }
        ksort($windows);
        $populations = [];
        foreach ($windows as $key => $window) {
            $populations[$key] = $queries->lockWindow($window);
        }
        foreach ($sourceWindows as $id => $key) {
            $population = $populations[$key];
            if (! $population['complete']) {
                continue;
            }
            $rows = $population['rows']->concat($sources->where('id', $id))->unique('id')->sortBy('id')->values();
            if (! $this->screen([$id], $quietHours, $rows)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, \stdClass>|null  $lockedPopulation
     */
    public function screen(array $ids, ?int $quietHours = null, ?Collection $lockedPopulation = null): bool
    {
        if (! Schema::hasTable('reconciliation_admissions')) {
            return true;
        }
        $quietHours ??= ProcessReleasesSettings::forDatabase(['delaytime' => Settings::settingValue('delaytime')])->collectionDelayTime;
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $page) {
            $seen = [];
            foreach (($lockedPopulation?->whereIn('id', $page) ?? DB::table('collections')->whereIn('id', $page)->orderBy('id')->get()) as $source) {
                if ($source->date === null || isset($seen[$source->id])) {
                    continue;
                }
                $queries = new PopulationQuery;
                $window = $queries->sourceWindow($source);
                if ($window === null) {
                    continue;
                }
                $population = $lockedPopulation === null ? $queries->readWindow($window) : null;
                if ($population !== null && ! $population['complete']) {
                    continue;
                }
                $nearby = $population['rows'] ?? $lockedPopulation->filter(
                    static fn ($candidate): bool => (int) $candidate->groups_id === (int) $source->groups_id
                        && (int) $candidate->declaredfiles === (int) $source->declaredfiles && $candidate->fromname === $source->fromname
                        && $candidate->date !== null && $candidate->date >= $window['from'] && $candidate->date <= $window['until']
                        && in_array((int) $candidate->filecheck, PopulationQuery::STATES, true));
                if ($nearby->count() > PopulationQuery::LIMIT) {
                    continue;
                }
                if ($nearby->count() < 2) {
                    continue;
                }
                $populationIds = $nearby->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $snapshots = $this->snapshots($populationIds, $quietHours, $lockedPopulation !== null);
                if ($snapshots === null) {
                    continue;
                }
                $seen[$source->id] = true;
                foreach ($this->families($snapshots) as $group) {
                    if (! isset($group[$source->id])) {
                        continue;
                    }
                    if (! $this->persist($group, $quietHours)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
