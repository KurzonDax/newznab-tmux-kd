<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

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
                $commands[] = [PHP_BINARY, 'artisan', 'update:binaries', $group->name, (string) $group->max];
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'binaries');

            return;
        }

        $this->headerStart('binaries', $count, $maxProcesses);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($work as $group) {
            $commands[$group->name] = [PHP_BINARY, 'artisan', 'update:binaries', $group->name, (string) $group->max];
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
        $this->executeCommand([PHP_BINARY, 'app/Services/Tmux/Scripts/update_groups.php']);

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

        $ingested = [];
        foreach (DB::table('usenet_group_ingested_ranges as r')
            ->join('usenet_groups as g', 'g.id', '=', 'r.usenet_groups_id')
            ->whereIn('g.name', array_column($groups, 'groupname'))
            ->get(['g.name', 'r.first_record', 'r.last_record']) as $range) {
            $ingested[$range->name][] = [(int) $range->first_record, (int) $range->last_record];
        }

        $secondaryRanges = [];
        foreach (NntpProviderPool::secondaryProviders() as $provider) {
            array_push($secondaryRanges, ...$this->discoverSecondaryRanges($provider, $maxHeaders, $maxMessages));
        }
        $queues = $this->buildSafeBinariesQueue($groups, $maxHeaders, $maxMessages, $ingested, $secondaryRanges);

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                $commands[] = $this->buildDnrArguments($queue);
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
            $commands[$idx] = $this->buildDnrArguments($queue);
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
     * Refresh a secondary provider's server positions, then read the ranges its cursors are
     * behind by. A provider whose refresh fails or stalls queues nothing this pass.
     *
     * @return list<array{provider: string, group: string, ranges: list<array{int, int}>}>
     */
    private function discoverSecondaryRanges(NntpProvider $provider, int $maxHeaders, int $maxMessages): array
    {
        $checkedSince = now()->format('Y-m-d H:i:s');
        // LIST ACTIVE takes about 1.4 s and a first pass's initialisations about 37 s, so this
        // bounds a stalled provider's delay to provider 1's work at about three times that.
        $process = $this->createProcess([PHP_BINARY, 'artisan', 'groups:update', '--provider='.$provider->name]);
        $process->setTimeout(120);
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                Log::warning('Secondary NNTP provider position refresh failed.', [
                    'provider' => $provider->name, 'exit_code' => $process->getExitCode(),
                    'output' => trim($process->getErrorOutput().$process->getOutput()),
                ]);
            }
        } catch (ProcessTimedOutException) {
            Log::warning('Secondary NNTP provider position refresh timed out.', ['provider' => $provider->name]);
        }

        return $this->secondaryRanges($provider, $checkedSince, $maxHeaders, $maxMessages);
    }

    /**
     * The article ranges each of a secondary provider's cursors is behind its server's newest
     * article, read from positions refreshed since $checkedSince. Database reads only.
     *
     * @return list<array{provider: string, group: string, ranges: list<array{int, int}>}>
     */
    public function secondaryRanges(NntpProvider $provider, string $checkedSince, int $maxHeaders, int $maxMessages): array
    {
        $cursors = DB::table('usenet_group_provider_cursors as c')
            ->join('usenet_groups as g', 'g.id', '=', 'c.usenet_groups_id')
            ->where('c.provider', $provider->name)
            ->where('c.provider_host', $provider->host)
            ->where('c.server_checked_at', '>=', $checkedSince)
            ->where('g.active', 1)
            ->orderByDesc('c.server_last')
            ->get(['c.usenet_groups_id', 'g.name', 'c.last_record', 'c.server_last']);

        $parked = [];
        foreach (DB::table('usenet_group_provider_ingested_ranges')
            ->where('provider', $provider->name)
            ->whereIn('usenet_groups_id', $cursors->pluck('usenet_groups_id'))
            ->orderBy('first_record')
            ->get(['usenet_groups_id', 'first_record', 'last_record']) as $range) {
            $parked[(int) $range->usenet_groups_id][] = [(int) $range->first_record, (int) $range->last_record];
        }

        $work = [];
        foreach ($cursors as $cursor) {
            $last = (int) $cursor->last_record;
            $count = min((int) $cursor->server_last - $last, $maxHeaders);
            if ($count <= 0) {
                continue;
            }

            $ranges = $this->sliceRanges($last, $count, $maxMessages);
            // The remainder slice runs one article long; never ask past the server's newest.
            $tail = array_key_last($ranges);
            $ranges[$tail][1] = min($ranges[$tail][1], $last + $count);

            $work[] = [
                'provider' => $provider->name,
                'group' => (string) $cursor->name,
                'ranges' => $this->subtractRanges($ranges, $parked[(int) $cursor->usenet_groups_id] ?? []),
            ];
        }

        return $work;
    }

    /**
     * @param  list<object{groupname: string, our_last: int|string, their_last: int|string}>  $groups
     * @param  array<string, list<array{int, int}>>  $ingestedRanges
     * @param  list<array{provider: string, group: string, ranges: list<array{int, int}>}>  $secondaryRanges
     * @return array<int, string>
     */
    public function buildSafeBinariesQueue(array $groups, int $maxHeaders, int $maxMessages, array $ingestedRanges = [], array $secondaryRanges = []): array
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
            $ranges = $this->sliceRanges($ourLast, min($count, $maxHeaders), $maxMessages);
            $rangesByGroup[] = [$group->groupname, $this->subtractRanges($ranges, $ingestedRanges[$group->groupname] ?? [])];
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

        // Secondary providers' ranges start after every provider-1 entry, so provider 1 keeps
        // the head of each pass.
        for ($rangeIndex = 0; ; $rangeIndex++) {
            $rangeAdded = false;
            foreach ($secondaryRanges as $entry) {
                if (! isset($entry['ranges'][$rangeIndex])) {
                    continue;
                }

                [$start, $end] = $entry['ranges'][$rangeIndex];
                $queues[$queueIndex] = sprintf(
                    'get_range  binaries  %s  %s  %s  %s  %s',
                    $entry['group'],
                    $start,
                    $end,
                    $queueIndex,
                    $entry['provider'],
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

    /**
     * Cut $count articles after $last into $maxMessages-wide ranges.
     *
     * @return list<array{int, int}>
     */
    private function sliceRanges(int $last, int $count, int $maxMessages): array
    {
        $fullRangeCount = (int) floor($count / $maxMessages);
        $remaining = (int) ($count - $fullRangeCount * $maxMessages);
        $ranges = [];

        for ($rangeIndex = 0; $rangeIndex < $fullRangeCount; $rangeIndex++) {
            $ranges[] = [
                $last + $rangeIndex * $maxMessages + 1,
                $last + $rangeIndex * $maxMessages + $maxMessages,
            ];
        }

        if ($remaining > 0) {
            $start = $last + $fullRangeCount * $maxMessages + 1;
            $ranges[] = [$start, $start + $remaining];
        }

        return $ranges;
    }

    /**
     * Drop the parts of $ranges already ingested ahead of the cursor.
     *
     * @param  list<array{int, int}>  $ranges
     * @param  list<array{int, int}>  $covered
     * @return list<array{int, int}>
     */
    private function subtractRanges(array $ranges, array $covered): array
    {
        foreach ($covered as [$coveredFirst, $coveredLast]) {
            $uncovered = [];
            foreach ($ranges as [$start, $end]) {
                if ($coveredLast < $start || $coveredFirst > $end) {
                    $uncovered[] = [$start, $end];

                    continue;
                }
                if ($start < $coveredFirst) {
                    $uncovered[] = [$start, $coveredFirst - 1];
                }
                if ($end > $coveredLast) {
                    $uncovered[] = [$coveredLast + 1, $end];
                }
            }
            $ranges = $uncovered;
        }

        return $ranges;
    }
}
