<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Runners;

use App\Services\Runners\OwnedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OwnedProcessShutdownTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function terminationSignals(): iterable
    {
        yield 'terminal hangup' => [SIGHUP, 'owner'];
        yield 'termination' => [SIGTERM, 'owner'];
        yield 'interrupt' => [SIGINT, 'owner'];
        yield 'termination during existing cleanup' => [SIGTERM, 'stopping-owner'];
    }

    #[DataProvider('terminationSignals')]
    public function test_owner_termination_reaps_all_owned_trees_and_preserves_unrelated_processes(int $signal, string $mode): void
    {
        $directory = $this->makeTempDirectory('owner-shutdown');
        $readyPath = $directory.'/ready';
        // Establish this test as a subreaper so failed regressions can also clean up their orphans.
        (new OwnedProcess([PHP_BINARY, '-r', 'exit(0);']))->run();
        $unrelated = new Process([PHP_BINARY, '-r', 'sleep(20);']);
        $owner = new Process([PHP_BINARY, base_path('tests/Fixtures/owned-process-signal.php'), $mode, $readyPath]);
        $owner->setTimeout(10);
        $pids = [];
        try {
            $unrelated->start();
            $owner->start();
            $this->awaitFile($readyPath, $owner);
            $pids = json_decode(file_get_contents($readyPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(4, $pids);
            if ($mode === 'stopping-owner') {
                $deadline = microtime(true) + 5;
                while ($this->processState($pids[0]) !== 'T' && microtime(true) < $deadline) {
                    usleep(1000);
                }
                $this->assertSame('T', $this->processState($pids[0]), 'Signal must interrupt active tree cleanup.');
            }
            $owner->signal($signal);
            $owner->wait();
            foreach ($pids as $pid) {
                $this->assertFileDoesNotExist('/proc/'.$pid.'/stat', 'Work survived owner signal '.$signal.': '.$pid);
            }
            $this->assertTrue($unrelated->isRunning(), 'Shutdown must only target this owner\'s workers.');
        } finally {
            $owner->stop(0);
            $unrelated->stop(0);
            $this->cleanUpPids($directory, $pids);
        }
    }

    public function test_signal_handlers_and_async_mode_are_restored_after_the_last_owned_process(): void
    {
        $originalAsync = pcntl_async_signals();
        $originals = [];
        $handler = static function (int $signal): void {};
        foreach ([SIGHUP, SIGTERM, SIGINT] as $signal) {
            $originals[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals(false);
        $first = new OwnedProcess([PHP_BINARY, '-r', 'sleep(20);']);
        $second = new OwnedProcess([PHP_BINARY, '-r', 'sleep(20);']);
        try {
            $first->start();
            $second->start();
            $this->assertTrue(pcntl_async_signals());
            $first->stop(0);
            $this->assertNotSame($handler, pcntl_signal_get_handler(SIGTERM));
            $second->stop(0);
            foreach ([SIGHUP, SIGTERM, SIGINT] as $signal) {
                $this->assertSame($handler, pcntl_signal_get_handler($signal));
            }
            $this->assertFalse(pcntl_async_signals());
        } finally {
            $first->stop(0);
            $second->stop(0);
            foreach ($originals as $signal => $original) {
                pcntl_signal($signal, $original);
            }
            pcntl_async_signals($originalAsync);
        }
    }

    public function test_a_raw_fork_only_terminates_its_own_new_workers(): void
    {
        $directory = $this->makeTempDirectory('fork-owner-shutdown');
        $readyPath = $directory.'/ready';
        (new OwnedProcess([PHP_BINARY, '-r', 'exit(0);']))->run();
        $owner = new Process([PHP_BINARY, base_path('tests/Fixtures/owned-process-signal.php'), 'fork-owner', $readyPath]);
        $owner->setTimeout(10);
        $pids = [];
        try {
            $owner->start();
            $this->awaitFile($readyPath, $owner);
            $this->awaitFile($readyPath.'.fork', $owner);
            $pids = json_decode(file_get_contents($readyPath), true, flags: JSON_THROW_ON_ERROR);
            [$fork, $worker] = json_decode(file_get_contents($readyPath.'.fork'), true, flags: JSON_THROW_ON_ERROR);
            posix_kill($fork, SIGTERM);
            $deadline = microtime(true) + 5;
            while (is_file('/proc/'.$worker.'/stat') && microtime(true) < $deadline) {
                usleep(1000);
                clearstatcache();
            }
            $this->assertFileDoesNotExist('/proc/'.$worker.'/stat');
            foreach ($pids as $pid) {
                $this->assertFileExists('/proc/'.$pid.'/stat', 'Fork cleanup killed work owned by its parent.');
                $this->assertNotContains($this->processState($pid), ['Z', 'X']);
            }
            $this->assertTrue($owner->isRunning());
            $owner->signal(SIGTERM);
            $owner->wait();
        } finally {
            $owner->stop(0);
            $this->cleanUpPids($directory, $pids);
        }
    }

    private function awaitFile(string $path, Process $owner): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($path) && microtime(true) < $deadline && $owner->isRunning()) {
            usleep(1000);
        }
        $this->assertFileExists($path, $owner->getErrorOutput());
    }

    private function processState(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');

        return $stat === false ? null : explode(' ', substr($stat, strrpos($stat, ')') + 2))[0];
    }

    /** @param list<int> $pids */
    private function cleanUpPids(string $directory, array $pids): void
    {
        foreach (glob($directory.'/ready*') ?: [] as $path) {
            $recorded = json_decode(file_get_contents($path), true);
            if (is_array($recorded)) {
                $pids = [...$pids, ...$recorded];
            } elseif (is_int($recorded)) {
                $pids[] = $recorded;
            }
        }
        $pids = array_filter(array_unique($pids), static fn (mixed $pid): bool => is_int($pid) && $pid > 1);
        foreach ($pids as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }
}
