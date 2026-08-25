<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AudioProcessing\AudioCandidateQuery;
use App\Services\MetadataProcessing\AnimeProcessingCandidateQuery;
use App\Services\MetadataProcessing\BookProcessingCandidateQuery;
use App\Services\MetadataProcessing\ConsoleProcessingCandidateQuery;
use App\Services\MetadataProcessing\GameProcessingCandidateQuery;
use App\Services\MetadataProcessing\MovieProcessingCandidateQuery;
use App\Services\MetadataProcessing\MusicProcessingCandidateQuery;
use App\Services\MetadataProcessing\NfoProcessingCandidateQuery;
use App\Services\TempWorkspaceService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PostProcessRunner extends BaseRunner
{
    public const int ADDITIONAL_WORKER_MAX_BATCHES = 4;

    private function guidBucketExpression(string $column = 'leftguid'): string
    {
        return DB::getDriverName() === 'sqlite'
            ? 'substr('.$column.', 1, 1)'
            : 'LEFT('.$column.', 1)';
    }

    /**
     * @param  array<int, object{id?: mixed}>  $releases
     */
    private function runPostProcess(array $releases, int $maxProcesses, string $type, string $desc): void
    {
        if (empty($releases)) {
            $this->headerNone();

            return;
        }

        // Additional post-processing can run for a long time per bucket. Always
        // stream it so tmux shows active parallel children instead of buffering
        // all output until the entire pool finishes.
        if ((bool) config('nntmux.stream_fork_output', false) === true || $type === 'additional') {
            $commands = [];
            foreach ($releases as $release) {
                // id may already be a single GUID bucket char; if not, take first char defensively
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                // Use postprocess:guid command which accepts the GUID character
                $command = PHP_BINARY.' artisan postprocess:guid '.$type.' '.$char;
                if ($type === 'additional') {
                    $command .= ' --worker --max-batches='.self::ADDITIONAL_WORKER_MAX_BATCHES;
                }
                $commands[] = $command;
            }
            $this->runStreamingCommands($commands, $maxProcesses, $desc);

            return;
        }

        $count = count($releases);
        $this->headerStart('postprocess: '.$desc, $count, $maxProcesses);

        if ($count <= 1 || $maxProcesses <= 1) {
            foreach ($releases as $release) {
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                $command = PHP_BINARY.' artisan postprocess:guid '.$type.' '.$char;
                echo $this->executeCommand($command);
                cli()->primary('Finished task for '.$desc);
            }

            return;
        }

        $commands = [];
        foreach ($releases as $idx => $release) {
            $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
            $commands[$idx] = PHP_BINARY.' artisan postprocess:guid '.$type.' '.$char;
        }

        try {
            $results = $this->runParallelCommands($commands, $maxProcesses, $this->concurrencyTimeout());

            foreach ($results as $output) {
                echo $output;
                cli()->primary('Finished task for '.$desc);
            }
        } catch (\Throwable $e) {
            Log::error('Postprocess batch failed: '.$e->getMessage());
            cli()->error('Postprocess batch failed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, object{type: string, id: string}>  $tasks
     */
    private function runPostProcessMixed(array $tasks, int $maxProcesses, string $desc): void
    {
        if ($tasks === []) {
            $this->headerNone();

            return;
        }

        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($tasks as $task) {
                $char = substr((string) $task->id, 0, 1);
                $commands[] = PHP_BINARY.' artisan postprocess:guid '.$task->type.' '.$char;
            }
            $this->runStreamingCommands($commands, $maxProcesses, $desc);

            return;
        }

        $count = count($tasks);
        $this->headerStart('postprocess: '.$desc, $count, $maxProcesses);

        if ($count <= 1 || $maxProcesses <= 1) {
            foreach ($tasks as $task) {
                $char = substr((string) $task->id, 0, 1);
                $command = PHP_BINARY.' artisan postprocess:guid '.$task->type.' '.$char;
                echo $this->executeCommand($command);
                cli()->primary('Finished task for '.$desc);
            }

            return;
        }

        $batches = array_chunk($tasks, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $runTasks = [];
            foreach ($batch as $idx => $task) {
                $char = substr((string) $task->id, 0, 1);
                $command = PHP_BINARY.' artisan postprocess:guid '.$task->type.' '.$char;
                $runTasks[$idx] = fn () => $this->executeCommand($command);
            }

            try {
                $results = Concurrency::run($runTasks, $this->concurrencyTimeout());

                foreach ($results as $output) {
                    echo $output;
                    cli()->primary('Finished task for '.$desc);
                }
            } catch (\Throwable $e) {
                Log::error('Postprocess mixed batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getBooksBuckets(int $lookupMode): array
    {
        $bucketExpr = $this->guidBucketExpression();

        return BookProcessingCandidateQuery::query(lookupMode: $lookupMode)
            ->selectRaw($bucketExpr.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getMusicBuckets(int $lookupMode): array
    {
        $bucketExpr = $this->guidBucketExpression();

        return MusicProcessingCandidateQuery::query(lookupMode: $lookupMode)
            ->selectRaw($bucketExpr.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getConsoleBuckets(int $lookupMode): array
    {
        $bucketExpr = $this->guidBucketExpression();

        return ConsoleProcessingCandidateQuery::query(lookupMode: $lookupMode)
            ->selectRaw($bucketExpr.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getGamesBuckets(int $lookupMode): array
    {
        $bucketExpr = $this->guidBucketExpression();

        return GameProcessingCandidateQuery::query(lookupMode: $lookupMode)
            ->selectRaw($bucketExpr.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();
    }

    public function processAdditional(): void
    {
        // Bucket-selection predicates and size filters are owned by
        // AdditionalCandidateQuery so they cannot drift away from the
        // per-worker fetch in AdditionalProcessingOrchestrator::fetchReleases().
        $bucketCounts = AdditionalCandidateQuery::availableBucketCounts();
        $chars = array_column($bucketCounts, 'bucket');

        $maxProcesses = (int) Settings::settingValue('postthreads');
        $queryLimit = (int) (Settings::settingValue('maxaddprocessed') ?: 25);

        // Normalize to the shape the rest of runPostProcess() expects:
        // an array of objects with an `id` (first GUID char) property. If the
        // backlog is concentrated in fewer buckets than configured threads,
        // repeat buckets so claimBatch() can split one hot bucket across
        // multiple workers.
        $queue = $this->additionalQueue($bucketCounts, $maxProcesses, $queryLimit);
        if (! $this->prepareAdditionalTempBuckets($chars)) {
            return;
        }

        if ($maxProcesses <= 1) {
            if ($queue === []) {
                $this->headerNone();

                return;
            }

            $this->headerStart('postprocess: additional postprocessing', count($queue), 1);
            $orchestrator = app(AdditionalProcessingOrchestrator::class);
            foreach ($chars as $char) {
                $workerToken = bin2hex(random_bytes(16));
                $excludedReleaseIds = [];
                try {
                    for ($batch = 0; $batch < self::ADDITIONAL_WORKER_MAX_BATCHES; $batch++) {
                        $result = $orchestrator->start('', $char, $workerToken, $excludedReleaseIds);
                        if ($result->claimedCount() === 0) {
                            break;
                        }
                        $excludedReleaseIds = array_values(array_unique([
                            ...$excludedReleaseIds,
                            ...$result->claimedIds,
                        ]));
                    }
                } finally {
                    $orchestrator->finish();
                }
                cli()->primary('Finished task for additional postprocessing');
            }

            return;
        }

        $this->runPostProcess($queue, $maxProcesses, 'additional', 'additional postprocessing');
    }

    /**
     * Fan the pending music backlog out per GUID bucket.
     *
     * Deliberately plainer than {@see self::processAdditional()}: an audio
     * worker fetches the head of one file and stops, so there is no worker mode
     * draining batch after batch, and no reason to stack several workers onto
     * one hot bucket.
     */
    public function processAudio(): void
    {
        $chars = array_column(AudioCandidateQuery::availableBucketCounts(), 'bucket');

        if ($chars === []) {
            $this->headerNone();

            return;
        }

        if (! $this->prepareAdditionalTempBuckets($chars)) {
            return;
        }

        $queue = array_map(static fn (string $char): object => (object) ['id' => $char], $chars);
        $maxProcesses = max(1, (int) Settings::settingValue('postthreadsaudio'));

        $this->runPostProcess($queue, $maxProcesses, 'aud', 'audio postprocessing');
    }

    /**
     * @param  list<array{bucket: string, count: int}>  $bucketCounts
     * @return list<object{id: string}>
     */
    private function additionalQueue(array $bucketCounts, int $maxProcesses, int $queryLimit): array
    {
        if ($bucketCounts === []) {
            return [];
        }

        $chars = array_column($bucketCounts, 'bucket');
        $queue = array_map(static fn (string $c): object => (object) ['id' => $c], $chars);
        $allocations = array_fill(0, count($bucketCounts), 1);
        $demand = [];
        foreach ($bucketCounts as $index => $bucketCount) {
            // Demand is intentionally measured in immediately claimable batches:
            // postthreads remains the hard connection cap while hot buckets use
            // available parallelism instead of draining all batches serially.
            $demand[$index] = max(1, (int) ceil($bucketCount['count'] / max(1, $queryLimit)));
        }

        $target = max(count($queue), min(max(1, $maxProcesses), array_sum($demand)));

        while (count($queue) < $target) {
            $nextIndex = null;
            $largestRemainingDemand = 0;

            foreach ($chars as $index => $char) {
                $remainingDemand = $demand[$index] - $allocations[$index];
                if ($remainingDemand > $largestRemainingDemand) {
                    $largestRemainingDemand = $remainingDemand;
                    $nextIndex = $index;
                }
            }

            if ($nextIndex === null) {
                break;
            }

            $queue[] = (object) ['id' => $chars[$nextIndex]];
            $allocations[$nextIndex]++;
        }

        return $queue;
    }

    /**
     * @param  list<string>  $chars
     */
    private function prepareAdditionalTempBuckets(array $chars): bool
    {
        if ($chars === []) {
            return true;
        }

        $tempWorkspace = app(TempWorkspaceService::class);
        $basePath = (string) config('nntmux.tmp_unrar_path');

        try {
            foreach ($chars as $char) {
                $bucketPath = $tempWorkspace->ensureMainTempPath($basePath, $char);
                $tempWorkspace->pruneStaleWorkerDirectories(
                    $bucketPath,
                    max(
                        3600,
                        (int) config('nntmux.multiprocessing_max_child_time', 1800) * 2,
                    ),
                );
            }
        } catch (\Throwable $e) {
            cli()->warning('Additional post-processing skipped: '.$e->getMessage());
            Log::error('Additional post-processing temp bucket preflight failed', [
                'tmp_unrar_path' => $basePath,
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }

    public function processNfo(): void
    {
        $lookupMode = (int) Settings::settingValue('lookupnfo');
        if ($lookupMode !== 1) {
            $this->headerNone();

            return;
        }

        if (! NfoProcessingCandidateQuery::query(lookupMode: $lookupMode)->exists()) {
            $this->headerNone();

            return;
        }

        $bucketExpr = $this->guidBucketExpression('r.leftguid');
        $queue = NfoProcessingCandidateQuery::query(lookupMode: $lookupMode)
            ->selectRaw($bucketExpr.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();

        $maxProcesses = (int) Settings::settingValue('nfothreads');
        $this->runPostProcess($queue, $maxProcesses, 'nfo', 'nfo postprocessing');
    }

    public function processMovies(bool $renamedOnly): void
    {
        $lookupMode = (int) Settings::settingValue('lookupimdb');
        if ($lookupMode <= 0) {
            $this->headerNone();

            return;
        }

        $candidateQuery = MovieProcessingCandidateQuery::query(
            lookupMode: $lookupMode,
            renamedOnly: $renamedOnly,
        );
        if (! $candidateQuery->exists()) {
            $this->headerNone();

            return;
        }

        $renamedFlag = ($renamedOnly ? 2 : 1);
        $bucketExpr = $this->guidBucketExpression();
        $queue = MovieProcessingCandidateQuery::query(
            lookupMode: $lookupMode,
            renamedOnly: $renamedOnly,
        )
            ->selectRaw($bucketExpr.' AS id, ? AS renamed', [$renamedFlag])
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();

        $maxProcesses = (int) Settings::settingValue('postthreadsnon');
        $this->runPostProcess($queue, $maxProcesses, 'movie', 'movies postprocessing');
    }

    public function processTv(bool $renamedOnly): void
    {
        $lookupMode = (int) Settings::settingValue('lookuptv');
        if ($lookupMode <= 0) {
            $this->headerNone();

            return;
        }

        if (! TvProcessingCandidateQuery::query(processTv: $lookupMode, renamedOnly: $renamedOnly)->exists()) {
            $this->headerNone();

            return;
        }

        $renamedFlag = ($renamedOnly ? 2 : 1);
        $queue = TvProcessingCandidateQuery::buckets($renamedOnly, $lookupMode);
        foreach ($queue as $bucket) {
            $bucket->renamed = $renamedFlag;
        }

        $maxProcesses = (int) Settings::settingValue('postthreadsnon');

        // Use pipelined TV processing for better efficiency
        $this->runPostProcessTvPipeline($queue, $maxProcesses, 'tv postprocessing (pipelined)', $renamedOnly);
    }

    /**
     * Run pipelined TV post-processing across multiple GUID buckets in parallel.
     * Each parallel process runs the full provider pipeline sequentially.
     *
     * @param  list<object{id: string, renamed?: int}>  $releases
     */
    private function runPostProcessTvPipeline(array $releases, int $maxProcesses, string $desc, bool $renamedOnly): void
    {
        if (empty($releases)) {
            $this->headerNone();

            return;
        }

        // If streaming is enabled, run commands with real-time output
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($releases as $release) {
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                $renamed = isset($release->renamed) ? $release->renamed : '';
                // Use the pipelined TV command
                $commands[] = PHP_BINARY.' artisan postprocess:tv-pipeline '.$char.($renamed ? ' '.$renamed : '').' --mode=pipeline';
            }
            $this->runStreamingCommands($commands, $maxProcesses, $desc);

            return;
        }

        $count = count($releases);
        $this->headerStart('postprocess: '.$desc, $count, $maxProcesses);

        // Process in batches using Laravel's native Concurrency facade
        $batches = array_chunk($releases, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $idx => $release) {
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                $renamed = isset($release->renamed) ? $release->renamed : '';
                // Use the pipelined TV command for each GUID bucket
                $command = PHP_BINARY.' artisan postprocess:tv-pipeline '.$char.($renamed ? ' '.$renamed : '').' --mode=pipeline';
                $tasks[$idx] = fn () => $this->executeCommand($command);
            }

            try {
                $results = Concurrency::run($tasks, $this->concurrencyTimeout());

                foreach ($results as $taskIdx => $output) {
                    echo $output;
                    cli()->primary('Finished task for '.$desc);
                }
            } catch (\Throwable $e) {
                Log::error('TV pipeline batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Lightweight check to determine if there is any TV work to process.
     */
    public function hasTvWork(bool $renamedOnly): bool
    {
        $lookupMode = (int) Settings::settingValue('lookuptv');
        if ($lookupMode <= 0) {
            return false;
        }

        return TvProcessingCandidateQuery::query(processTv: $lookupMode, renamedOnly: $renamedOnly)->exists();
    }

    public function processAnime(): void
    {
        $lookupMode = (int) Settings::settingValue('lookupanidb');
        if ($lookupMode <= 0) {
            $this->headerNone();

            return;
        }

        if (! AnimeProcessingCandidateQuery::query(lookupMode: $lookupMode)->exists()) {
            $this->headerNone();

            return;
        }

        $bucketExpr = $this->guidBucketExpression();
        $queue = AnimeProcessingCandidateQuery::query(lookupMode: $lookupMode)
            ->selectRaw($bucketExpr.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();

        $maxProcesses = (int) Settings::settingValue('postthreadsnon');
        $this->runPostProcess($queue, $maxProcesses, 'anime', 'anime postprocessing');
    }

    public function processBooks(): void
    {
        $lookupMode = (int) Settings::settingValue('lookupbooks');
        if ($lookupMode <= 0) {
            $this->headerNone();

            return;
        }

        if (! BookProcessingCandidateQuery::query(lookupMode: $lookupMode)->exists()) {
            $this->headerNone();

            return;
        }

        $queue = $this->getBooksBuckets($lookupMode);

        $maxProcesses = (int) Settings::settingValue('postthreadsamazon');
        $this->runPostProcess($queue, $maxProcesses, 'books', 'books postprocessing');
    }

    public function processMusic(): void
    {
        $lookupMode = (int) Settings::settingValue('lookupmusic');
        if ($lookupMode <= 0) {
            $this->headerNone();

            return;
        }

        if (! MusicProcessingCandidateQuery::query(lookupMode: $lookupMode)->exists()) {
            $this->headerNone();

            return;
        }

        $queue = $this->getMusicBuckets($lookupMode);
        $maxProcesses = (int) Settings::settingValue('postthreadsamazon');
        $this->runPostProcess($queue, $maxProcesses, 'music', 'music postprocessing');
    }

    public function processConsoles(): void
    {
        $lookupMode = (int) Settings::settingValue('lookupgames');
        if ($lookupMode <= 0) {
            $this->headerNone();

            return;
        }

        if (! ConsoleProcessingCandidateQuery::query(lookupMode: $lookupMode)->exists()) {
            $this->headerNone();

            return;
        }

        $queue = $this->getConsoleBuckets($lookupMode);
        $maxProcesses = (int) Settings::settingValue('postthreadsamazon');
        $this->runPostProcess($queue, $maxProcesses, 'console', 'console postprocessing');
    }

    public function processGames(): void
    {
        $lookupMode = (int) Settings::settingValue('lookupgames');
        if ($lookupMode <= 0) {
            $this->headerNone();

            return;
        }

        if (! GameProcessingCandidateQuery::query(lookupMode: $lookupMode)->exists()) {
            $this->headerNone();

            return;
        }

        $queue = $this->getGamesBuckets($lookupMode);
        $maxProcesses = (int) Settings::settingValue('postthreadsamazon');
        $this->runPostProcess($queue, $maxProcesses, 'games', 'games postprocessing');
    }

    public function processAmazon(): void
    {
        $maxProcesses = (int) Settings::settingValue('postthreadsamazon');
        $tasks = [];

        $bookLookupMode = (int) Settings::settingValue('lookupbooks');
        if ($bookLookupMode > 0) {
            foreach ($this->getBooksBuckets($bookLookupMode) as $row) {
                $tasks[] = (object) ['type' => 'books', 'id' => (string) $row->id];
            }
        }

        $musicLookupMode = (int) Settings::settingValue('lookupmusic');
        if ($musicLookupMode > 0) {
            foreach ($this->getMusicBuckets($musicLookupMode) as $row) {
                $tasks[] = (object) ['type' => 'music', 'id' => (string) $row->id];
            }
        }

        $gameLookupMode = (int) Settings::settingValue('lookupgames');
        if ($gameLookupMode > 0) {
            foreach ($this->getConsoleBuckets($gameLookupMode) as $row) {
                $tasks[] = (object) ['type' => 'console', 'id' => (string) $row->id];
            }

            foreach ($this->getGamesBuckets($gameLookupMode) as $row) {
                $tasks[] = (object) ['type' => 'games', 'id' => (string) $row->id];
            }
        }

        $this->runPostProcessMixed($tasks, $maxProcesses, 'amazon (books+music+console+games)');
    }
}
