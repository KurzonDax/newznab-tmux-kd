<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NntmuxSearchRepair extends Command
{
    public const MAX_ATTEMPTS = 25;

    protected $signature = 'nntmux:search-repair
                            {--limit=100 : Maximum failed releases to retry}
                            {--dry-run : Report due failures without writing to Manticore}';

    protected $description = 'Retry failed release search-index updates up to 25 attempts';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $query = DB::table('search_index_failures')
            ->whereNull('resolved_at')
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit);

        $rows = $query->get(['release_id', 'operation', 'attempts']);
        if ($rows->isEmpty()) {
            $this->info('No failed release index updates are due for repair.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $releaseId = (int) $row->release_id;
            if ((bool) $this->option('dry-run')) {
                $this->line((string) $releaseId);

                continue;
            }

            try {
                if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
                    $markedTerminal = DB::table('search_index_failures')
                        ->where('release_id', $releaseId)
                        ->whereNull('resolved_at')
                        ->update([
                            'last_error' => 'gave_up',
                            'next_attempt_at' => null,
                            'resolved_at' => now(),
                            'updated_at' => now(),
                        ]);

                    if ($markedTerminal === 1) {
                        Log::error('Search index repair gave up on release after reaching the attempt cap.', [
                            'release_id' => $releaseId,
                            'attempts' => (int) $row->attempts,
                        ]);
                    }

                    continue;
                }

                if ($row->operation === 'delete') {
                    Search::deleteRelease($releaseId);
                } else {
                    Search::updateRelease($releaseId);
                }
            } catch (Throwable $exception) {
                Log::error('Search index repair failed for release.', [
                    'release_id' => $releaseId,
                    'operation' => $row->operation,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info(sprintf('%s release index failure(s) %s.', $rows->count(), $this->option('dry-run') ? 'reported' : 'processed'));

        return self::SUCCESS;
    }
}
