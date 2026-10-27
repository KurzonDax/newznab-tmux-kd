<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Runners;

use App\Services\Runners\BaseRunner;
use App\Services\Tmux\TmuxPaneSupervisor;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

use function Termwind\renderUsing;

class BaseRunnerTest extends TestCase
{
    #[Test]
    public function execute_command_returns_output_on_success(): void
    {
        $runner = new BaseRunnerTestDouble;

        $this->assertSame('hello', trim($runner->runCommand('echo hello')));
    }

    #[Test]
    public function execute_command_throws_runtime_exception_with_clear_message_on_timeout(): void
    {
        config(['nntmux.concurrency_timeout' => 1]);

        $runner = new BaseRunnerTestDouble;

        try {
            $runner->runCommand('sleep 5');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            // Laravel's Concurrency ProcessDriver cannot reconstruct
            // ProcessTimedOutException, so executeCommand() must surface a
            // RuntimeException carrying the original timeout message instead.
            $this->assertStringContainsString('exceeded the timeout', $e->getMessage());
        }
    }

    #[Test]
    public function concurrency_timeout_prefers_concurrency_timeout_config(): void
    {
        config(['nntmux.concurrency_timeout' => 60]);
        config(['nntmux.multiprocessing_max_child_time' => 42]);

        $this->assertSame(60, (new BaseRunnerTestDouble)->timeout());
    }

    #[Test]
    public function concurrency_timeout_falls_back_to_multiprocessing_max_child_time(): void
    {
        config(['nntmux.concurrency_timeout' => null]);
        config(['nntmux.multiprocessing_max_child_time' => 42]);

        $this->assertSame(42, (new BaseRunnerTestDouble)->timeout());
    }

    #[Test]
    public function parallel_commands_refill_finished_slots_and_report_each_exit_code(): void
    {
        $factory = new FakeProcessFactory([
            'first' => ['iterations' => 2, 'output' => 'first output', 'exitCode' => 0],
            'second' => ['iterations' => 0, 'output' => 'second output', 'exitCode' => 1],
            'third' => ['iterations' => 0, 'output' => 'third output', 'exitCode' => 0],
        ]);
        $runner = new ParallelBaseRunnerTestDouble($factory);
        $completed = [];
        $runningAtCompletion = [];

        $results = $runner->runCommands(
            ['first' => 'first', 'second' => 'second', 'third' => 'third'],
            2,
            function (string $key, string $output, int $exitCode) use (&$completed, &$runningAtCompletion, $factory): void {
                $completed[] = [$key, $output, $exitCode];
                $runningAtCompletion[$key] = $factory->running;
            },
        );

        $this->assertSame(2, $factory->maximumRunning);
        $this->assertSame(['first', 'second', 'third'], $factory->startedCommands);
        $this->assertSame([
            ['second', 'second output', 1],
            ['third', 'third output', 0],
            ['first', 'first output', 0],
        ], $completed);
        $this->assertSame([
            'second' => 'second output',
            'third' => 'third output',
            'first' => 'first output',
        ], $results);
        $this->assertSame(2, $runningAtCompletion['second']);
    }

    #[Test]
    public function parallel_commands_stop_timed_out_children_and_continue_the_queue(): void
    {
        $factory = new FakeProcessFactory([
            'timed-out' => ['iterations' => 10, 'output' => '', 'exitCode' => 1, 'timedOut' => true],
            'next' => ['iterations' => 0, 'output' => 'next output', 'exitCode' => 0],
        ]);
        $runner = new ParallelBaseRunnerTestDouble($factory);
        $completed = [];

        ob_start();
        $runner->runCommands(
            ['timed-out' => 'timed-out', 'next' => 'next'],
            1,
            function (string $key, string $output, int $exitCode) use (&$completed): void {
                $completed[] = [$key, $exitCode];
            },
        );
        $output = (string) ob_get_clean();

        $this->assertSame(['timed-out', 'next'], $factory->startedCommands);
        $this->assertSame([['timed-out', 124], ['next', 0]], $completed);
        $this->assertStringContainsString('exceeded the timeout', $output);
    }

    #[Test]
    public function streaming_commands_stop_timed_out_children_and_report_each_exit_code(): void
    {
        config(['nntmux.echocli' => false]);
        $factory = new FakeProcessFactory([
            'timed-out' => ['iterations' => 10, 'output' => '', 'exitCode' => 1, 'timedOut' => true],
            'failed' => ['iterations' => 0, 'output' => 'failed output', 'exitCode' => 3],
            'next' => ['iterations' => 0, 'output' => 'next output', 'exitCode' => 0],
        ]);
        $runner = new ParallelBaseRunnerTestDouble($factory);
        $completed = [];

        ob_start();
        $runner->streamCommands(
            ['timed-out' => 'timed-out', 'failed' => 'failed', 'next' => 'next'],
            1,
            function (string $key, string $output, int $exitCode) use (&$completed): void {
                $completed[] = [$key, $output, $exitCode];
            },
        );
        $output = (string) ob_get_clean();

        $this->assertSame([['timed-out', '', 124], ['failed', 'failed output', 3], ['next', 'next output', 0]], $completed);
        $this->assertStringContainsString('exceeded the timeout', $output);
    }

    #[Test]
    public function parallel_mode_echoes_only_stderr_returns_outputs_and_prints_no_progress_lines(): void
    {
        config(['nntmux.echocli' => true, 'nntmux.multiprocessing_max_child_time' => 42]);
        $factory = new FakeProcessFactory([
            'first' => ['iterations' => 1, 'output' => 'first output', 'exitCode' => 0,
                'emit' => [[Process::OUT, 'parallel stdout'], [Process::ERR, 'parallel stderr']]],
        ]);
        $runner = new ParallelBaseRunnerTestDouble($factory);
        $progress = new BufferedOutput;
        renderUsing($progress);

        try {
            ob_start();
            $results = $runner->runCommands(['first' => 'first'], 2, static function (): void {}, timeout: 7);
            $echoed = (string) ob_get_clean();
        } finally {
            renderUsing(null);
        }

        $this->assertSame(['first' => 'first output'], $results);
        $this->assertStringContainsString('parallel stderr', $echoed);
        $this->assertStringNotContainsString('parallel stdout', $echoed);
        $this->assertSame('', $progress->fetch(), 'no header and no "Finished task" line');
        $this->assertSame([7.0], $factory->timeouts, 'the timeout argument wins over the config');
        $this->assertNotSame([], $runner->pauses);
        $this->assertSame([50000], array_values(array_unique($runner->pauses)), 'parallel polls every 50 ms');
    }

    #[Test]
    public function parallel_mode_falls_back_to_the_configured_child_timeout(): void
    {
        config(['nntmux.multiprocessing_max_child_time' => 42]);
        $factory = new FakeProcessFactory(['first' => ['iterations' => 0, 'output' => '', 'exitCode' => 0]]);

        (new ParallelBaseRunnerTestDouble($factory))->runCommands(['first' => 'first'], 1, static function (): void {});

        $this->assertSame([42.0], $factory->timeouts);
    }

    #[Test]
    public function streaming_mode_echoes_both_streams_and_prints_the_header_and_finished_lines(): void
    {
        config(['nntmux.echocli' => true, 'nntmux.multiprocessing_max_child_time' => 42]);
        $factory = new FakeProcessFactory([
            'first' => ['iterations' => 1, 'output' => 'first output', 'exitCode' => 0,
                'emit' => [[Process::OUT, 'streamed stdout'], [Process::ERR, 'streamed stderr']]],
            'second' => ['iterations' => 0, 'output' => 'second output', 'exitCode' => 0],
        ]);
        $runner = new ParallelBaseRunnerTestDouble($factory);
        $progress = new BufferedOutput;
        renderUsing($progress);

        try {
            ob_start();
            $runner->streamCommands(['first' => 'first', 'second' => 'second'], 1, static function (): void {});
            $echoed = (string) ob_get_clean();
        } finally {
            renderUsing(null);
        }

        $this->assertStringContainsString('streamed stdout', $echoed);
        $this->assertStringContainsString('streamed stderr', $echoed);
        $lines = $progress->fetch();
        $this->assertStringContainsString('Multi-processing started', $lines);
        $this->assertStringContainsString('for postprocess: fixture with 2 job(s) to do using a max of 1 child process(es).', $lines);
        $this->assertSame(1, substr_count($lines, 'Finished task #2 for fixture'));
        $this->assertSame(1, substr_count($lines, 'Finished task #1 for fixture'));
        $this->assertSame([42.0, 42.0], $factory->timeouts, 'streaming always uses the configured child timeout');
        $this->assertNotSame([], $runner->pauses);
        $this->assertSame([100000], array_values(array_unique($runner->pauses)), 'streaming polls every 100 ms');
    }

    #[Test]
    public function a_failed_child_is_reported_once_when_the_worker_finishes_across_all_its_pool_calls(): void
    {
        config(['nntmux.echocli' => false]);
        $childrenFile = $this->makeTempPath('nntmux-children');
        putenv(TmuxPaneSupervisor::CHILDREN_ENV.'='.$childrenFile);
        $factory = new FakeProcessFactory([
            'first' => ['iterations' => 0, 'output' => '', 'exitCode' => 0],
            'failed' => ['iterations' => 0, 'output' => '', 'exitCode' => 2],
            'third' => ['iterations' => 0, 'output' => '', 'exitCode' => 0],
        ]);
        $runner = new ParallelBaseRunnerTestDouble($factory);

        try {
            ob_start();
            $runner->runCommands(['first' => 'first', 'failed' => 'failed'], 2, static function (): void {});
            $runner->streamCommands(['third' => 'third'], 1, static function (): void {});
            $duringRun = (string) ob_get_clean();
            $this->assertStringNotContainsString('child processes failed', $duringRun);
            $this->assertFileDoesNotExist($childrenFile, 'nothing is written before the worker finishes');

            ob_start();
            $this->app->terminate();
            $this->app->terminate();
            $atFinish = (string) ob_get_clean();
        } finally {
            putenv(TmuxPaneSupervisor::CHILDREN_ENV);
        }

        $this->assertSame('1 of 3 child processes failed'.PHP_EOL, $atFinish, 'one summary line, once');
        $this->assertSame('1/3', file_get_contents($childrenFile));
        $this->assertSame(
            array_fill(0, 3, [TmuxPaneSupervisor::CHILDREN_ENV => false]),
            $factory->environments,
            'children never inherit the pane step\'s file',
        );
    }

    #[Test]
    public function a_timed_out_child_counts_as_failed(): void
    {
        $childrenFile = $this->makeTempPath('nntmux-children');
        putenv(TmuxPaneSupervisor::CHILDREN_ENV.'='.$childrenFile);
        $factory = new FakeProcessFactory([
            'timed-out' => ['iterations' => 10, 'output' => '', 'exitCode' => 0, 'timedOut' => true],
            'next' => ['iterations' => 0, 'output' => '', 'exitCode' => 0],
        ]);

        try {
            ob_start();
            (new ParallelBaseRunnerTestDouble($factory))->runCommands(['timed-out' => 'timed-out', 'next' => 'next'], 1, static function (): void {});
            $this->app->terminate();
            $output = (string) ob_get_clean();
        } finally {
            putenv(TmuxPaneSupervisor::CHILDREN_ENV);
        }

        $this->assertStringContainsString('1 of 2 child processes failed', $output);
        $this->assertSame('1/2', file_get_contents($childrenFile));
    }

    #[Test]
    public function a_worker_whose_children_all_succeed_prints_no_summary_and_outside_a_pane_writes_no_file(): void
    {
        $factory = new FakeProcessFactory(['ok' => ['iterations' => 0, 'output' => '', 'exitCode' => 0]]);

        ob_start();
        (new ParallelBaseRunnerTestDouble($factory))->runCommands(['ok' => 'ok'], 1, static function (): void {});
        $this->app->terminate();

        $this->assertSame('', (string) ob_get_clean());
    }
}

class BaseRunnerTestDouble extends BaseRunner
{
    public function runCommand(string $command): string
    {
        return $this->executeCommand($command);
    }

    public function timeout(): int
    {
        return $this->concurrencyTimeout();
    }
}

class ParallelBaseRunnerTestDouble extends BaseRunner
{
    /** @var list<int> */
    public array $pauses = [];

    public function __construct(private readonly FakeProcessFactory $factory) {}

    /**
     * @param  array<string, string>  $commands
     * @param  callable(string, string, int): void  $onComplete
     * @return array<string, string>
     */
    public function runCommands(array $commands, int $maxProcesses, callable $onComplete, ?int $timeout = null): array
    {
        return $this->runParallelCommands($commands, $maxProcesses, $timeout, $onComplete);
    }

    /**
     * @param  array<string, string>  $commands
     * @param  callable(string, string, int): void  $onComplete
     */
    public function streamCommands(array $commands, int $maxProcesses, callable $onComplete): void
    {
        $this->runStreamingCommands($commands, $maxProcesses, 'fixture', $onComplete);
    }

    protected function pauseBetweenPolls(int $microseconds): void
    {
        $this->pauses[] = $microseconds;
    }

    protected function createProcess(array|string $command): Process
    {
        return $this->factory->create(is_array($command) ? json_encode($command, JSON_THROW_ON_ERROR) : $command);
    }
}

class FakeProcessFactory
{
    /** @var array<string, array{iterations: int, output: string, exitCode: int, timedOut?: bool, emit?: list<array{string, string}>}> */
    private array $definitions;

    /** @var list<string> */
    public array $startedCommands = [];

    /** @var list<float|null> */
    public array $timeouts = [];

    /** @var list<array<string, string|false>> */
    public array $environments = [];

    public int $running = 0;

    public int $maximumRunning = 0;

    /**
     * @param  array<string, array{iterations: int, output: string, exitCode: int, timedOut?: bool, emit?: list<array{string, string}>}>  $definitions
     */
    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    public function create(string $command): Process
    {
        return new FakeProcess($command, $this->definitions[$command], $this);
    }
}

class FakeProcess extends Process
{
    private bool $completed = false;

    private bool $started = false;

    private int $remainingIterations;

    /**
     * @param  array{iterations: int, output: string, exitCode: int, timedOut?: bool, emit?: list<array{string, string}>}  $definition
     */
    public function __construct(
        private readonly string $fakeCommand,
        private readonly array $definition,
        private readonly FakeProcessFactory $factory,
    ) {
        $this->remainingIterations = $definition['iterations'];
        parent::__construct([PHP_BINARY, '-r', '']);
    }

    public function start(?callable $callback = null, array $env = []): void
    {
        $this->started = true;
        $this->factory->startedCommands[] = $this->fakeCommand;
        $this->factory->timeouts[] = $this->getTimeout();
        $this->factory->environments[] = $env;
        $this->factory->running++;
        $this->factory->maximumRunning = max($this->factory->maximumRunning, $this->factory->running);
        foreach ($this->definition['emit'] ?? [] as [$type, $buffer]) {
            if ($callback !== null) {
                $callback($type, $buffer);
            }
        }
    }

    public function isRunning(): bool
    {
        if (! $this->started) {
            return false;
        }

        if ($this->remainingIterations > 0) {
            $this->remainingIterations--;

            return true;
        }

        if (! $this->completed) {
            $this->completed = true;
            $this->started = false;
            $this->factory->running--;
        }

        return false;
    }

    public function checkTimeout(): void
    {
        if (($this->definition['timedOut'] ?? false) !== true) {
            return;
        }

        if (! $this->completed) {
            $this->completed = true;
            $this->started = false;
            $this->factory->running--;
        }

        throw new ProcessTimedOutException($this, ProcessTimedOutException::TYPE_GENERAL);
    }

    public function getOutput(): string
    {
        return $this->definition['output'];
    }

    public function getErrorOutput(): string
    {
        return '';
    }

    public function getExitCode(): ?int
    {
        return $this->definition['exitCode'];
    }
}
