<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;

class BinariesRunner extends BaseRunner
{
    public function binaries(int $maxPerGroup): void
    {
        $work = DB::select(
            sprintf(
                'SELECT name, %d AS max FROM usenet_groups WHERE active = 1',
                $maxPerGroup
            )
        );

        $maxProcesses = (int) Settings::settingValue('binarythreads');

        $count = count($work);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($work as $group) {
                $commands[] = PHP_BINARY.' artisan update:binaries '.$group->name.' '.$group->max;
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'binaries');

            return;
        }

        $this->headerStart('binaries', $count, $maxProcesses);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($work as $group) {
            $commands[$group->name] = PHP_BINARY.' artisan update:binaries '.$group->name.' '.$group->max;
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $maxProcesses);

        foreach ($results as $groupName => $output) {
            echo $output;
            cli()->primary('Updated group '.$groupName);
        }
    }

    public function safeBinaries(): void
    {
        // update group stats - Updated to use new script location (modernized)
        $this->executeCommand(PHP_BINARY.' app/Services/Tmux/Scripts/update_groups.php');

        $maxHeaders = (int) Settings::settingValue('max_headers_iteration') ?: 1000000;
        $maxMessages = (int) Settings::settingValue('maxmssgs');

        // Prevent division by zero - ensure maxmssgs is at least 1
        if ($maxMessages < 1) {
            $defaultMaxMessages = 20000;
            cli()->warning('maxmssgs setting is invalid or not set, using default of '.$defaultMaxMessages);
            $maxMessages = $defaultMaxMessages;
        }

        $maxProcesses = (int) Settings::settingValue('binarythreads');

        $groups = DB::select(
            '
            SELECT g.name AS groupname, g.last_record AS our_last,
                a.last_record AS their_last
            FROM usenet_groups g
            INNER JOIN short_groups a ON g.active = 1 AND g.name = a.name
            ORDER BY a.last_record DESC'
        );

        if (empty($groups)) {
            $this->headerNone();

            return;
        }

        $queues = $this->buildSafeBinariesQueue($groups, $maxHeaders, $maxMessages);

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                $commands[] = $this->buildDnrCommand($queue);
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'safe_binaries');

            return;
        }

        $this->headerStart('safe_binaries', count($queues), $maxProcesses);

        // Build commands array with group info for parallel execution
        $commands = [];
        $groupMapping = [];
        foreach ($queues as $idx => $queue) {
            preg_match('/alt\..+/i', $queue, $hit);
            $commands[$idx] = $this->buildDnrCommand($queue);
            $groupMapping[$idx] = $hit[0] ?? '';
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $maxProcesses);

        foreach ($results as $idx => $output) {
            $group = $groupMapping[$idx] ?? '';
            if (! empty($group)) {
                echo $output;
                cli()->primary('Updated group '.$group);
            }
        }
    }

    /**
     * @param  list<object{groupname: string, our_last: int|string, their_last: int|string}>  $groups
     * @return array<int, string>
     */
    public function buildSafeBinariesQueue(array $groups, int $maxHeaders, int $maxMessages): array
    {
        $queueIndex = 1;
        $queues = [];
        $rangesByGroup = [];

        foreach ($groups as $group) {
            $ourLast = (int) $group->our_last;
            $theirLast = (int) $group->their_last;

            if ($ourLast === 0) {
                $queues[$queueIndex] = sprintf('update_group_headers  %s', $group->groupname);
                $queueIndex++;

                continue;
            }

            $count = $theirLast - $ourLast - 20000; // skip first 20k
            if ($count <= $maxMessages * 2) {
                $queues[$queueIndex] = sprintf('update_group_headers  %s', $group->groupname);
                $queueIndex++;

                continue;
            }

            $queues[$queueIndex] = sprintf('part_repair  %s', $group->groupname);
            $queueIndex++;
            $rangeLimit = min($count, $maxHeaders);
            $fullRangeCount = (int) floor($rangeLimit / $maxMessages);
            $remaining = (int) ($rangeLimit - $fullRangeCount * $maxMessages);
            $ranges = [];

            for ($rangeIndex = 0; $rangeIndex < $fullRangeCount; $rangeIndex++) {
                $ranges[] = [
                    $ourLast + $rangeIndex * $maxMessages + 1,
                    $ourLast + $rangeIndex * $maxMessages + $maxMessages,
                ];
            }

            if ($remaining > 0) {
                $start = $ourLast + $fullRangeCount * $maxMessages + 1;
                $ranges[] = [$start, $start + $remaining];
            }

            $rangesByGroup[] = [$group->groupname, $ranges];
        }

        for ($rangeIndex = 0; ; $rangeIndex++) {
            $rangeAdded = false;
            foreach ($rangesByGroup as [$groupName, $ranges]) {
                if (! isset($ranges[$rangeIndex])) {
                    continue;
                }

                [$start, $end] = $ranges[$rangeIndex];
                $queues[$queueIndex] = sprintf(
                    'get_range  binaries  %s  %s  %s  %s',
                    $groupName,
                    $start,
                    $end,
                    $queueIndex,
                );
                $queueIndex++;
                $rangeAdded = true;
            }

            if (! $rangeAdded) {
                break;
            }
        }

        return $queues;
    }
}
