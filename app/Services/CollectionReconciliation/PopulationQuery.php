<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Current reads whose index access bounds collection contention locally. */
final class PopulationQuery
{
    public const int LIMIT = 256;

    public const array STATES = [0, 1, 2, 3, 10, 15, 16];

    public const int WINDOW_SECONDS = 3600;

    /** @param list<int> $ids
     * @return Collection<int, \stdClass>
     */
    public function lockIds(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return DB::table('collections')->when(DB::getDriverName() !== 'sqlite', static fn ($query) => $query->forceIndex('PRIMARY'))
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    /** @return array{group: int, poster: string, total: int, from: string, until: string}|null */
    public function sourceWindow(object $source, int $radius = self::WINDOW_SECONDS): ?array
    {
        if ($source->date === null) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($source->date, config('app.timezone'));
        } catch (InvalidFormatException) {
            return null;
        }

        return ['group' => (int) $source->groups_id, 'poster' => (string) $source->fromname,
            'total' => (int) $source->declaredfiles, 'from' => $date->subSeconds($radius)->toDateTimeString(),
            'until' => $date->addSeconds($radius)->toDateTimeString()];
    }

    /** @param array{group: int, poster: string, total: int, from: string, until: string} $window
     * @return array{rows: Collection<int, \stdClass>, complete: bool}
     */
    public function lockWindow(array $window): array
    {
        return $this->window($window, true);
    }

    /** @param array{group: int, poster: string, total: int, from: string, until: string} $window
     * @return array{rows: Collection<int, \stdClass>, complete: bool}
     */
    public function readWindow(array $window): array
    {
        return $this->window($window, false);
    }

    /**
     * Historical discovery deliberately spans posters and bounds raw rows before state filtering.
     *
     * @return array{rows: Collection<int, \stdClass>, complete: bool}
     */
    public function readHistoricalWindow(int $group, int $total, int $epoch): array
    {
        $date = CarbonImmutable::createFromTimestamp($epoch, config('app.timezone'));
        $rows = DB::table('collections')
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), static fn ($query) => $query->forceIndex('collections_reconciliation_discovery'))
            ->where('groups_id', $group)->where('declaredfiles', $total)
            ->whereBetween('date', [$date->subSeconds(1800)->toDateTimeString(), $date->addSeconds(1800)->toDateTimeString()])
            ->orderBy('date')->orderBy('id')->limit(self::LIMIT + 1)->get();

        return ['rows' => $rows->sortBy('id')->values(), 'complete' => $rows->count() <= self::LIMIT];
    }

    /** @param array{group: int, poster: string, total: int, from: string, until: string} $window
     * @return array{rows: Collection<int, \stdClass>, complete: bool}
     */
    private function window(array $window, bool $lock): array
    {
        $rows = collect();
        foreach (self::STATES as $state) {
            $found = DB::table('collections')
                ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), static fn ($query) => $query->forceIndex('collections_admission_window'))
                ->where('groups_id', $window['group'])->where('declaredfiles', $window['total'])
                ->where('fromname', $window['poster'])->where('filecheck', $state)
                ->whereBetween('date', [$window['from'], $window['until']])->orderBy('date')->orderBy('id')
                ->limit(self::LIMIT + 1 - $rows->count())->when($lock, static fn ($query) => $query->lockForUpdate())->get();
            $rows = $rows->concat($found);
            if ($rows->count() > self::LIMIT) {
                return ['rows' => $rows->sortBy('id')->values(), 'complete' => false];
            }
        }

        return ['rows' => $rows->sortBy('id')->values(), 'complete' => true];
    }
}
