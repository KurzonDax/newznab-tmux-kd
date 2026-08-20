<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LogRetentionService;
use Illuminate\Console\Command;

class LogsPrune extends Command
{
    protected $signature = 'logs:prune {--dry-run : Report what would be rotated and pruned without touching any file}';

    protected $description = 'Rotate oversized log files Monolog does not manage, then delete log files past the retention window.';

    public function handle(LogRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $retention->sweep($dryRun);

        foreach ($result['rotated'] as $rotation) {
            $this->line(sprintf(
                '%s %s -> %s',
                $dryRun ? 'Would rotate' : 'Rotated',
                $rotation['from'],
                $rotation['to'],
            ));
        }

        foreach ($result['pruned'] as $name) {
            $this->line(sprintf('%s %s', $dryRun ? 'Would prune' : 'Pruned', $name));
        }

        $days = $retention->retentionDays();

        $this->info(sprintf(
            '%sRotated %d log file(s) and pruned %d log file(s)%s.',
            $dryRun ? '[dry run] ' : '',
            count($result['rotated']),
            count($result['pruned']),
            $days === 0 ? ' (age pruning disabled)' : sprintf(' older than %d day(s)', $days),
        ));

        return self::SUCCESS;
    }
}
