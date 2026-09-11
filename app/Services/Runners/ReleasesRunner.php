<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\Releases\ReleaseFormationGroupQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleasesRunner extends BaseRunner
{
    public function __construct(
        private readonly NameFixingQueryService $queries = new NameFixingQueryService,
    ) {}

    public function releases(): void
    {
        $uGroups = $this->pendingGroups();
        $maxProcesses = (int) Settings::settingValue('releasethreads');

        $count = count($uGroups);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($uGroups as $group) {
                $commands[] = [PHP_BINARY, 'artisan', 'releases:process', (string) $group['id'], '--orchestrated'];
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'releases');

            return;
        }

        $this->headerStart('releases', $count, $maxProcesses);

        // Process in batches using Laravel's native Concurrency facade
        $batches = array_chunk($uGroups, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $group) {
                $command = [PHP_BINARY, 'artisan', 'releases:process', (string) $group['id'], '--orchestrated'];
                $tasks[$group['id']] = $this->taskForCommand($command);
            }

            try {
                $results = $this->runConcurrentTasks($tasks);

                foreach ($results as $groupId => $output) {
                    echo $output;
                    cli()->primary('Finished performing release processing for group ID: '.$groupId);
                }
            } catch (\Throwable $e) {
                Log::error('Release processing batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }

    public function updatePerGroup(): void
    {
        $groups = DB::table('usenet_groups')->where(static fn ($query) => $query->where('active', 1)->orWhere('backfill', 1))
            ->get(['id', 'name'])->map(static fn ($group): object => (object) ['id' => (int) $group->id, 'name' => $group->name, 'releaseOnly' => false])->all();
        foreach ($this->pendingGroups(array_map(static fn ($group): int => $group->id, $groups)) as $group) {
            $groups[] = (object) [...$group, 'releaseOnly' => true];
        }
        $maxProcesses = (int) Settings::settingValue('releasethreads');

        $count = count($groups);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($groups as $group) {
                $commands[] = [PHP_BINARY, 'artisan', $group->releaseOnly ? 'releases:process' : 'group:update-all', (string) $group->id, '--orchestrated'];
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'update_per_group');

            return;
        }

        $this->headerStart('update_per_group', $count, $maxProcesses);

        // Process in batches using Laravel's native Concurrency facade
        $batches = array_chunk($groups, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $group) {
                $command = [PHP_BINARY, 'artisan', $group->releaseOnly ? 'releases:process' : 'group:update-all', (string) $group->id, '--orchestrated'];
                $tasks[$group->id] = $this->taskForCommand($command);
            }

            try {
                $results = $this->runConcurrentTasks($tasks);

                foreach ($results as $groupId => $output) {
                    echo $output;
                    $name = UsenetGroup::getNameByID($groupId);
                    cli()->primary('Finished updating binaries, processing releases and additional postprocessing for group: '.$name);
                }
            } catch (\Throwable $e) {
                Log::error('Update per group batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }

    /**
     * @param  list<int>  $alreadyProcessed
     * @return list<array{id: int, name: string}>
     */
    private function pendingGroups(array $alreadyProcessed = []): array
    {
        return ReleaseFormationGroupQuery::query()->whereNotIn('id', $alreadyProcessed)->orderBy('id')->get(['id', 'name'])
            ->map(static fn (object $group): array => ['id' => (int) $group->id, 'name' => (string) $group->name])->all();
    }

    public function fixRelNames(string $mode, int $maxPerRun, int $maxThreads): void
    {
        $maxThreads = max(1, min(16, $maxThreads));

        $leftGuids = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f'];

        if ($mode === 'predbft') {
            $preCount = $this->queries->predbCandidateCount();
            if ($preCount > 0 && $maxPerRun > 0) {
                $workerCount = min($maxThreads, (int) ceil($preCount / $maxPerRun));
                $leftGuids = \array_slice($leftGuids, 0, $workerCount);
            } else {
                $leftGuids = [];
            }
        }

        $queues = [];
        $idx = 0;
        $workerCount = count($leftGuids);
        foreach ($leftGuids as $leftGuid) {
            if ($maxPerRun > 0) {
                $idx++;
                $queues[$idx] = sprintf('%s %s %s %s %s', $mode, $leftGuid, $maxPerRun, $idx, $workerCount);
            }
        }

        $count = count($queues);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                // Updated to use new script location (modernized)
                $commands[] = [PHP_BINARY, 'app/Services/Tmux/Scripts/groupfixrelnames.php', $queue, 'true'];
            }
            $this->runStreamingCommands($commands, $maxThreads, 'fixRelNames_'.$mode);

            return;
        }

        $this->headerStart('fixRelNames_'.$mode, $count, $maxThreads);

        // Process in batches using Laravel's native Concurrency facade
        $batches = array_chunk($queues, max(1, $maxThreads), true);

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $idx => $queue) {
                // Updated to use new script location (modernized)
                $command = [PHP_BINARY, 'app/Services/Tmux/Scripts/groupfixrelnames.php', $queue, 'true'];
                $tasks[$idx] = $this->taskForCommand($command);
            }

            try {
                $results = $this->runConcurrentTasks($tasks);

                foreach ($results as $taskIdx => $output) {
                    echo $output;
                    cli()->primary('Task #'.$taskIdx.' Finished fixing releases names');
                }
            } catch (\Throwable $e) {
                Log::error('Fix rel names batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }
}
