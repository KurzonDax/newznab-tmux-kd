<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Runners;

use App\Services\ForkingService;
use App\Services\Runners\BackfillRunner;
use App\Services\Runners\BaseRunner;
use App\Services\Runners\BinariesRunner;
use App\Services\Runners\OwnedProcess;
use App\Services\Runners\PostProcessRunner;
use App\Services\Runners\ReleasesRunner;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\TestCase;

class OwnedProcessTest extends TestCase
{
    public function test_an_immediately_exiting_parent_cannot_abandon_a_descendant(): void
    {
        $pids = $this->makeTempPath('early-exit-pids');
        $process = new OwnedProcess([PHP_BINARY, '-r',
            '$child = pcntl_fork(); if ($child === 0) { fclose(STDOUT); fclose(STDERR); sleep(30); exit; } file_put_contents($argv[1], json_encode([$child]));', $pids]);
        $process->setTimeout(2);
        try {
            $this->assertSame(0, $process->run());
            foreach (json_decode(file_get_contents($pids), true) as $pid) {
                $this->assertFileDoesNotExist('/proc/'.$pid.'/stat');
            }
        } finally {
            $process->stop(0);
            if (is_file($pids)) {
                foreach (json_decode(file_get_contents($pids), true) as $pid) {
                    @posix_kill($pid, 9);
                }
            }
        }
    }

    public function test_all_shared_callers_preserve_real_argv_and_nonzero_process_output(): void
    {
        config(['nntmux.concurrency_timeout' => 2, 'nntmux.multiprocessing_max_child_time' => 2]);
        foreach ([ReleasesRunner::class, BinariesRunner::class,
            BackfillRunner::class, PostProcessRunner::class,
            ForkingService::class] as $class) {
            $runner = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $output = (new \ReflectionMethod($runner, 'executeCommand'))->invoke($runner,
                [PHP_BINARY, '-r', 'echo $argv[1]; exit(7);', 'literal "quoted" $HOME']);
            $this->assertSame('literal "quoted" $HOME', $output, $class);
        }
    }

    public function test_real_streaming_queue_reaps_before_refill_and_reports_each_exit_once(): void
    {
        config(['nntmux.multiprocessing_max_child_time' => 1, 'nntmux.echocli' => false]);
        $pids = $this->makeTempPath('stream-pids');
        $commands = [
            'timeout' => [PHP_BINARY, '-r', '$child = pcntl_fork(); if ($child === 0) { sleep(30); exit; } file_put_contents($argv[1], json_encode([getmypid(), $child])); echo "started"; sleep(30);', $pids],
            'nonzero' => [PHP_BINARY, '-r', 'foreach (json_decode(file_get_contents($argv[1]), true) as $pid) { if (file_exists("/proc/".$pid)) { exit(99); } } echo $argv[2]; exit(7);', $pids, 'literal "quoted" $HOME'],
        ];
        $runner = new class extends BaseRunner
        {
            public function run(array $commands, callable $complete): void
            {
                $this->runStreamingCommands($commands, 1, 'fixture', $complete);
            }
        };
        $completed = [];
        ob_start();
        try {
            $runner->run($commands, static function ($key, $output, $code) use (&$completed): void {
                $completed[] = [$key, $output, $code];
            });
            $this->assertSame([['timeout', 'started', 124], ['nonzero', 'literal "quoted" $HOME', 7]], $completed);
        } finally {
            $output = (string) ob_get_clean();
            if (is_file($pids)) {
                foreach (json_decode(file_get_contents($pids), true) as $pid) {
                    @posix_kill($pid, 9);
                }
            }
        }
        $this->assertStringContainsString('started', $output);
        $this->assertStringContainsString('literal "quoted" $HOME', $output);
    }

    public function test_argv_preserves_quotes_and_timeout_ends_descendant_work(): void
    {
        $argument = 'literal "quotes" $HOME `echo nope`';
        $echo = new OwnedProcess([PHP_BINARY, '-r', 'echo $argv[1];', $argument]);
        $this->assertSame(0, $echo->run());
        $this->assertSame($argument, $echo->getOutput());
        $pidsPath = $this->makeTempPath('owned-pids');
        $process = new OwnedProcess([PHP_BINARY, '-r',
            '$pid = pcntl_fork(); if ($pid === 0) { sleep(30); exit; } file_put_contents($argv[1], json_encode([getmypid(), $pid])); sleep(30);', $pidsPath]);
        $process->setTimeout(1);
        try {
            try {
                $process->run();
                $this->fail('Expected timeout.');
            } catch (ProcessTimedOutException) {
                $this->assertFileExists($pidsPath);
            }
            $pids = json_decode(file_get_contents($pidsPath), true, flags: JSON_THROW_ON_ERROR);
            foreach ($pids as $pid) {
                $stat = @file_get_contents('/proc/'.$pid.'/stat');
                $state = $stat === false ? null : explode(' ', substr($stat, strrpos($stat, ')') + 2))[0];
                $this->assertContains($state, [null], 'Owned work survived its deadline: '.$pid);
            }
        } finally {
            $process->stop(0);
            if (is_file($pidsPath)) {
                foreach (json_decode(file_get_contents($pidsPath), true) as $pid) {
                    @posix_kill($pid, 9);
                }
            }
        }
    }
}
