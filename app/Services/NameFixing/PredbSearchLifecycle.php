<?php

namespace App\Services\NameFixing;

use App\Enums\PredbSearchStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PredbSearchLifecycle
{
    public function rearmForBackfillWindow(CarbonInterface $oldest, CarbonInterface $newest): int
    {
        $from = ($oldest->lessThanOrEqualTo($newest) ? $oldest : $newest)
            ->toImmutable()
            ->subDay();
        $to = ($oldest->greaterThan($newest) ? $oldest : $newest)
            ->toImmutable()
            ->addDay();

        return DB::table('predb')
            ->whereIn('searched', PredbSearchStatus::rearmableValues())
            ->whereBetween('predate', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->update([
                'searched' => PredbSearchStatus::Unsearched->value,
                'next_predb_search_at' => null,
            ]);
    }
}
