<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReleaseSearchIndexFailures
{
    public static function record(int $releaseId, string $phase, string $operation = 'upsert'): void
    {
        if ($releaseId <= 0) {
            return;
        }

        Cache::increment('search:index:failures:releases');

        try {
            $existing = DB::table('search_index_failures')
                ->where('release_id', $releaseId)
                ->first(['attempts']);
            $attempts = ((int) ($existing->attempts ?? 0)) + 1;

            DB::table('search_index_failures')->updateOrInsert(
                ['release_id' => $releaseId],
                [
                    'operation' => $operation,
                    'attempts' => $attempts,
                    'last_error' => $phase,
                    'next_attempt_at' => now()->addSeconds(min(3600, 2 ** min($attempts, 10))),
                    'resolved_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            Log::error('Unable to persist release search-index failure.', [
                'release_id' => $releaseId,
                'phase' => $phase,
                'error' => $exception->getMessage(),
            ]);
        }

        if (Cache::add('search:index:release_index_warn_lock', true, 60)) {
            Log::warning('Release search-index update failed.', [
                'release_id_sample' => $releaseId,
                'phase' => $phase,
                'failures_total' => (int) Cache::get('search:index:failures:releases', 0),
            ]);
        }
    }

    public static function resolve(int $releaseId): void
    {
        if ($releaseId <= 0) {
            return;
        }

        try {
            DB::table('search_index_failures')
                ->where('release_id', $releaseId)
                ->update([
                    'resolved_at' => now(),
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            Log::debug('Unable to resolve release search-index failure.', [
                'release_id' => $releaseId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
