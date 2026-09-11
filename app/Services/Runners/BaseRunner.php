<?php

declare(strict_types=1);

namespace App\Services\Runners;

use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

abstract class BaseRunner
{
    /**
     * Resolve the configured timeout (seconds) for Laravel's Concurrency::run() calls.
     */
    protected function concurrencyTimeout(): int
    {
        $configured = config('nntmux.concurrency_timeout');

        return (int) ($configured ?? config('nntmux.multiprocessing_max_child_time', 1800));
    }

    protected function buildDnrCommand(string $args): string
    {
        // Convert legacy command arguments to new artisan commands
        return $this->convertSwitchToArtisan($args);
    }

    /**
     * Convert legacy command format to new artisan commands.
     */
    private function convertSwitchToArtisan(string $args): string
    {
        if (trim($args) === '') {
            return '';
        }
        try {
            return implode(' ', array_map('escapeshellarg', $this->buildDnrArguments($args)));
        } catch (RuntimeException) {
            if (config('app.debug')) {
                Log::warning('Unrecognized multiprocessing command: '.$args);
            }

            return '';
        }
    }

    /**
     * Public wrapper for buildDnrCommand (used by ForkingService).
     */
    public function buildDnrCommandPublic(string $args): string
    {
        return $this->buildDnrCommand($args);
    }

    /** @param list<string>|string $command */
    protected function executeCommand(array|string $command): string
    {
        $process = $this->createProcess($command);
        $process->setTimeout($this->concurrencyTimeout());

        try {
            $process->run(function ($type, $buffer) {
                if ($type === Process::ERR) {
                    echo $buffer;
                }
            });
        } catch (ProcessTimedOutException $e) {
            // Rethrow as RuntimeException: Laravel's Concurrency ProcessDriver cannot
            // reconstruct ProcessTimedOutException (its constructor requires a Process
            // object), which would otherwise surface as an unrelated TypeError.
            throw new RuntimeException($e->getMessage());
        }

        return $process->getOutput();
    }

    /** @param list<string>|string $command */
    protected function createProcess(array|string $command): Process
    {
        return new OwnedProcess(is_array($command) ? $command : ['/bin/sh', '-c', $command]);
    }

    /**
     * @param  array<array-key, \Closure>  $tasks
     * @return array<array-key, mixed>
     */
    protected function runConcurrentTasks(array $tasks): array
    {
        $factory = new OwnedProcessFactory;
        try {
            return (new ProcessDriver($factory))->run($tasks, $this->concurrencyTimeout());
        } finally {
            $factory->stopAll();
        }
    }

    /** @param list<string> $command */
    protected function taskForCommand(array $command): \Closure
    {
        $timeout = $this->concurrencyTimeout();

        return static fn (): string => (new CommandRunner($timeout))->run($command);
    }

    /** @return list<string> */
    protected function buildDnrArguments(string $args): array
    {
        $parts = explode('  ', trim($args));
        $command = array_shift($parts);
        $first = $parts[0] ?? '';
        $rest = $parts[1] ?? '';
        $arguments = match ($command) {
            'backfill' => ['backfill:group', $first, $parts[1] ?? '1'],
            'backfill_all_quantity' => ['backfill:group', $first, '1', $rest],
            'backfill_all_quick' => ['backfill:group', $first, '1', '10000'],
            'get_range' => ['articles:get-range', $first, $rest, $parts[2] ?? '0', $parts[3] ?? '0'],
            'part_repair' => ['binaries:part-repair', $first],
            'releases' => ['releases:process', $first],
            'update_group_headers' => ['group:update-headers', $first],
            'update_per_group' => ['group:update-all', $first],
            'pp_additional' => ['postprocess:guid', 'additional', $first],
            'pp_nfo' => ['postprocess:guid', 'nfo', $first],
            'pp_movie' => ['postprocess:guid', 'movie', $first, ...($rest === '' ? [] : [$rest])],
            'pp_tv' => ['postprocess:guid', 'tv', $first, ...($rest === '' ? [] : [$rest])],
            default => throw new RuntimeException('Unrecognized multiprocessing command: '.$command),
        };

        return [PHP_BINARY, 'artisan', ...$arguments];
    }

    protected function headerStart(string $workType, int $count, int $maxProcesses): void
    {
        if (config('nntmux.echocli')) {
            cli()->header(
                'Multi-processing started at '.now()->toRfc2822String().' for '.$workType.' with '.$count.
                ' job(s) to do using a max of '.max(1, $maxProcesses).' child process(es).'
            );
        }
    }

    protected function headerNone(): void
    {
        if (config('nntmux.echocli')) {
            cli()->header('No work to do!');
        }
    }

    /**
     * Run multiple commands in parallel using Symfony Process with configurable timeout.
     * This replaces Laravel Concurrency::run() which has a fixed 60-second timeout.
     *
     * @param  array<string|int, callable>  $tasks  Array of callables keyed by identifier
     * @param  int  $maxProcesses  Maximum concurrent processes
     * @param  int|null  $timeout  Timeout in seconds (null = use config default)
     * @return array<string|int, mixed> Results keyed by the same identifiers as $tasks
     */
    protected function runParallelProcesses(array $tasks, int $maxProcesses, ?int $timeout = null): array
    {
        $maxProcesses = max(1, $maxProcesses);
        $timeout = $timeout ?? (int) config('nntmux.multiprocessing_max_child_time', 1800);
        $results = [];
        $running = [];
        $queue = $tasks;

        $startNext = function () use (&$queue, &$running): ?string {
            if (empty($queue)) {
                return null;
            }
            $key = array_key_first($queue);
            $callable = $queue[$key];
            unset($queue[$key]);

            // Get the command string from the callable context
            // We need to execute the callable which returns the command result
            $running[$key] = [
                'callable' => $callable,
                'started' => microtime(true),
            ];

            return (string) $key;
        };

        // For small batch sizes, run synchronously to avoid overhead
        if (count($tasks) <= 1 || $maxProcesses <= 1) {
            foreach ($tasks as $key => $callable) {
                try {
                    $results[$key] = $callable();
                } catch (\Throwable $e) {
                    Log::error("Task {$key} failed: ".$e->getMessage());
                    $results[$key] = '';
                }
            }

            return $results;
        }

        // For parallel execution, we need to use Process directly
        // Convert callables to commands and run them in parallel
        $commands = [];
        $taskMapping = [];

        foreach ($tasks as $key => $callable) {
            // We need to extract the command from the callable
            // This is a bit tricky, but we can use reflection or run the callable
            // For now, let's store the callable and run them in batches
            $commands[$key] = $callable;
        }

        // Process in batches
        $batches = array_chunk($commands, $maxProcesses, true);

        foreach ($batches as $batch) {
            $batchProcesses = [];

            foreach ($batch as $key => $callable) {
                try {
                    $results[$key] = $callable();
                } catch (\Throwable $e) {
                    Log::error("Task {$key} failed: ".$e->getMessage());
                    $results[$key] = '';
                }
            }
        }

        return $results;
    }

    /**
     * Run multiple commands in parallel with real process forking and configurable timeout.
     *
     * @param  array<string|int, list<string>|string>  $commands  Array of shell commands keyed by identifier
     * @param  int  $maxProcesses  Maximum concurrent processes
     * @param  int|null  $timeout  Timeout in seconds (null = use config default)
     * @param  callable(string|int, string, int): void|null  $onComplete
     * @return array<string|int, string> Command outputs keyed by the same identifiers
     */
    protected function runParallelCommands(
        array $commands,
        int $maxProcesses,
        ?int $timeout = null,
        ?callable $onComplete = null,
    ): array {
        $maxProcesses = max(1, $maxProcesses);
        $timeout = $timeout ?? (int) config('nntmux.multiprocessing_max_child_time', 1800);
        $results = [];
        $running = [];
        $queue = $commands;

        $startNext = function () use (&$queue, &$running, $timeout) {
            if (empty($queue)) {
                return;
            }
            $key = array_key_first($queue);
            $cmd = $queue[$key];
            unset($queue[$key]);

            $proc = $this->createProcess($cmd);
            $proc->setTimeout($timeout);
            $proc->start(function ($type, $buffer): void {
                if ($type === Process::ERR) {
                    echo $buffer;
                }
            });
            $running[$key] = $proc;
        };

        // Prime initial processes
        for ($i = 0; $i < $maxProcesses && ! empty($queue); $i++) {
            $startNext();
        }

        // Event loop
        while (! empty($running)) {
            foreach ($running as $key => $proc) {
                $timedOut = false;
                try {
                    $proc->checkTimeout();
                    $isRunning = $proc->isRunning();
                } catch (ProcessTimedOutException $e) {
                    echo $e->getMessage().PHP_EOL;
                    $isRunning = false;
                    $timedOut = true;
                }

                if (! $isRunning) {
                    $results[$key] = $proc->getOutput();
                    $exitCode = $timedOut ? 124 : ($proc->getExitCode() ?? 1);
                    unset($running[$key]);
                    if (! empty($queue)) {
                        $startNext();
                    }
                    if ($onComplete !== null) {
                        $onComplete($key, $results[$key], $exitCode);
                    }
                }
            }
            usleep(50000); // 50ms
        }

        return $results;
    }

    /**
     * Run multiple shell commands concurrently and stream their output in real-time.
     * Uses Symfony Process start() with a small event loop to enforce max concurrency.
     *
     * @param  array<string|int, list<string>|string>  $commands
     * @param  callable(string|int, string, int): void|null  $onComplete
     */
    protected function runStreamingCommands(
        array $commands,
        int $maxProcesses,
        string $desc,
        ?callable $onComplete = null,
    ): void {
        $maxProcesses = max(1, (int) $maxProcesses);
        $running = [];
        $queue = $commands;
        $total = \count($commands);
        $started = 0;
        $finished = 0;

        $this->headerStart('postprocess: '.$desc, $total, $maxProcesses);

        $startNext = function () use (&$queue, &$running, &$started) {
            if (empty($queue)) {
                return;
            }
            $key = array_key_first($queue);
            $cmd = $queue[$key];
            unset($queue[$key]);
            $proc = $this->createProcess($cmd);
            $proc->setTimeout((int) config('nntmux.multiprocessing_max_child_time', 1800));
            $proc->start(function ($type, $buffer) {
                // Stream both STDOUT and STDERR
                echo $buffer;
            });
            $running[$key] = $proc;
            $started++;
        };

        // Prime initial processes
        for ($i = 0; $i < $maxProcesses && ! empty($queue); $i++) {
            $startNext();
        }

        // Event loop
        while (! empty($running)) {
            foreach ($running as $key => $proc) {
                $timedOut = false;
                try {
                    $proc->checkTimeout();
                    $isRunning = $proc->isRunning();
                } catch (ProcessTimedOutException $e) {
                    echo $e->getMessage().PHP_EOL;
                    $isRunning = false;
                    $timedOut = true;
                }

                if (! $isRunning) {
                    $exitCode = $timedOut ? 124 : ($proc->getExitCode() ?? 1);
                    $output = $proc->getOutput();
                    unset($running[$key]);
                    $finished++;
                    if (! empty($queue)) {
                        $startNext();
                    }
                    if ($onComplete !== null) {
                        $onComplete($key, $output, $exitCode);
                    }
                    if (config('nntmux.echocli')) {
                        cli()->primary('Finished task #'.($total - $finished + 1).' for '.$desc);
                    }
                }
            }
            usleep(100000); // 100ms
        }
    }
}
