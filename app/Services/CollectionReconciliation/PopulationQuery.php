<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

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
    public function sourceWindow(object $source): ?array
    {
        if ($source->date === null || ($date = strtotime($source->date)) === false) {
            return null;
        }

        return ['group' => (int) $source->groups_id, 'poster' => (string) $source->fromname,
            'total' => (int) $source->declaredfiles, 'from' => gmdate('Y-m-d H:i:s', $date - self::WINDOW_SECONDS),
            'until' => gmdate('Y-m-d H:i:s', $date + self::WINDOW_SECONDS)];
    }

    /** @param array{group: int, poster: string, total: int, from: string, until: string} $window
     * @return array{rows: Collection<int, \stdClass>, complete: bool}
     */
    public function lockWindow(array $window): array
    {
        $rows = collect();
        foreach (self::STATES as $state) {
            $found = DB::table('collections')
                ->when(DB::getDriverName() !== 'sqlite', static fn ($query) => $query->forceIndex('collections_admission_window'))
                ->where('groups_id', $window['group'])->where('declaredfiles', $window['total'])
                ->where('fromname', $window['poster'])->where('filecheck', $state)
                ->whereBetween('date', [$window['from'], $window['until']])->orderBy('date')->orderBy('id')
                ->limit(self::LIMIT + 1 - $rows->count())->lockForUpdate()->get();
            $rows = $rows->concat($found);
            if ($rows->count() > self::LIMIT) {
                return ['rows' => $rows->sortBy('id')->values(), 'complete' => false];
            }
        }

        return ['rows' => $rows->sortBy('id')->values(), 'complete' => true];
    }
}
