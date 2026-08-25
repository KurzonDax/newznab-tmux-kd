<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\ReleaseSearchIndexReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NntmuxSearchMaintain extends Command
{
    protected $signature = 'nntmux:search-maintain
                            {--limit= : Override the configured documents per run}';

    protected $description = 'Repair a bounded DB-to-search-index drift sample and delete orphan documents';

    public function handle(ReleaseSearchIndexReconciler $reconciler): int
    {
        $configuredLimit = (int) config('search.reconciliation.batch_size', 100);
        $limit = $this->option('limit') === null ? $configuredLimit : (int) $this->option('limit');
        $limit = max(1, min(10000, $limit));
        $cursor = max(0, (int) Cache::get(ReleaseSearchIndexReconciler::CURSOR_CACHE_KEY, 0));

        try {
            $result = $reconciler->reconcile($cursor, $limit);
        } catch (Throwable $exception) {
            Log::error('Release search-index maintenance failed.', [
                'cursor' => $cursor,
                'limit' => $limit,
                'error' => $exception->getMessage(),
            ]);
            $this->error('Search-index maintenance failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        Cache::forever(ReleaseSearchIndexReconciler::CURSOR_CACHE_KEY, $result['cursor']);

        $this->info(sprintf(
            'Scanned %d document id(s); re-synced %d, deleted %d orphan(s); cursor=%d%s.',
            $result['scanned'],
            $result['resynced'],
            $result['deleted'],
            $result['cursor'],
            $result['wrapped'] ? ' (wrapped)' : '',
        ));

        return self::SUCCESS;
    }
}
