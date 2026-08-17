<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use App\Services\Backfill\BackfillService;

class BackfillRunner extends BaseRunner
{
    public function backfill(): void
    {
        $startedAt = microtime(true);

        $this->executeCommand(PHP_BINARY.' app/Services/Tmux/Scripts/update_groups.php');

        $groups = (new BackfillService)->eligibleGroups();
        $quantity = (int) Settings::settingValue('backfill_qty');
        $maxProcesses = (int) Settings::settingValue('backfillthreads');
        $streamOutput = (bool) config('nntmux.stream_fork_output', false);

        if ($groups === []) {
            $this->headerNone();
            $this->printSummary(0, 0, [], 0, $startedAt);

            return;
        }

        $this->headerStart('backfill', count($groups), $maxProcesses);

        $commands = [];
        $workByGroup = [];
        foreach ($groups as $group) {
            $commands[$group->name] = sprintf(
                '%s artisan backfill:group "%s" 2 %d',
                PHP_BINARY,
                addcslashes($group->name, '"\\'),
                $quantity,
            );
            $workByGroup[$group->name] = $group;
        }

        $ok = 0;
        $articles = 0;
        $failedGroups = [];
        $onComplete = function (string|int $key, string $output, int $exitCode) use (
            &$ok,
            &$articles,
            &$failedGroups,
            $quantity,
            $streamOutput,
            $workByGroup,
        ): void {
            $groupName = (string) $key;

            if (! $streamOutput) {
                echo '['.$groupName.']'.PHP_EOL;
                echo $output;
                if ($output !== '' && ! str_ends_with($output, PHP_EOL)) {
                    echo PHP_EOL;
                }
            }

            if ($exitCode === 0) {
                $ok++;
                $articles += min($quantity, $workByGroup[$groupName]->remaining);

                return;
            }

            $failedGroups[] = $groupName;
        };

        if ($streamOutput) {
            $this->runStreamingCommands($commands, $maxProcesses, 'backfill', $onComplete);
        } else {
            $this->runParallelCommands($commands, $maxProcesses, onComplete: $onComplete);
        }

        $this->printSummary(count($groups), $ok, $failedGroups, $articles, $startedAt);
    }

    /**
     * @param  list<string>  $failedGroups
     */
    private function printSummary(
        int $eligible,
        int $ok,
        array $failedGroups,
        int $articles,
        float $startedAt,
    ): void {
        $failed = count($failedGroups);
        $seconds = (int) round(microtime(true) - $startedAt);

        echo sprintf(
            '%d groups eligible, %d ok, %d failed, %d articles, %ds',
            $eligible,
            $ok,
            $failed,
            $articles,
            $seconds,
        ).PHP_EOL;

        if ($failedGroups !== []) {
            echo 'Failed groups: '.implode(', ', $failedGroups).PHP_EOL;
        }
    }
}
