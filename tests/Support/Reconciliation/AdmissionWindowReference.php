<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The pre-#543 raw-window predicate, retained only for fixture comparisons. */
final class AdmissionWindowReference
{
    /**
     * @param  array{group: int, poster: string, total: int, from: string, until: string}  $window
     * @return array{rows: Collection<int, \stdClass>, complete: bool}
     */
    public static function read(array $window, bool $lock = false): array
    {
        $rows = collect();
        foreach ([0, 1, 2, 3, 10, 15, 16] as $state) {
            $rows = $rows->concat(DB::table('collections')
                ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), static fn ($query) => $query->forceIndex('collections_admission_window'))
                ->where('groups_id', $window['group'])->where('declaredfiles', $window['total'])
                ->where('fromname', $window['poster'])->where('filecheck', $state)
                ->whereBetween('date', [$window['from'], $window['until']])
                ->orderBy('date')->orderBy('id')->limit(257 - $rows->count())
                ->when($lock, static fn ($query) => $query->lockForUpdate())->get());
            if ($rows->count() > 256) {
                return ['rows' => $rows->sortBy('id')->values(), 'complete' => false];
            }
        }

        return ['rows' => $rows->sortBy('id')->values(), 'complete' => true];
    }
}
