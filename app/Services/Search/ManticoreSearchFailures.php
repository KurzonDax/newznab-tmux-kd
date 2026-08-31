<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ManticoreSearchFailures
{
    public static function record(string $failureType, string $index, ?string $search, string $error): void
    {
        Cache::increment('search:failures:manticore');

        if (Cache::add('search:manticore_failure_warn_lock', true, 60)) {
            Log::warning('ManticoreSearch query failed.', [
                'failure_type' => $failureType,
                'index' => $index,
                'search' => $search,
                'error_sample' => $error,
                'failures_total' => (int) Cache::get('search:failures:manticore', 0),
            ]);
        }
    }
}
