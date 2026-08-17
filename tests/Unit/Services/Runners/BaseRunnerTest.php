<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Runners;

use App\Services\Runners\BaseRunner;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

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
    public function __construct(private readonly FakeProcessFactory $factory) {}

    /**
     * @param  array<string, string>  $commands
     * @param  callable(string, string, int): void  $onComplete
     * @return array<string, string>
     */
    public function runCommands(array $commands, int $maxProcesses, callable $onComplete): array
    {
        return $this->runParallelCommands($commands, $maxProcesses, onComplete: $onComplete);
    }

    protected function createProcess(string $command): Process
    {
        return $this->factory->create($command);
    }
}

class FakeProcessFactory
{
    /** @var array<string, array{iterations: int, output: string, exitCode: int, timedOut?: bool}> */
    private array $definitions;

    /** @var list<string> */
    public array $startedCommands = [];

    public int $running = 0;

    public int $maximumRunning = 0;

    /**
     * @param  array<string, array{iterations: int, output: string, exitCode: int, timedOut?: bool}>  $definitions
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
     * @param  array{iterations: int, output: string, exitCode: int, timedOut?: bool}  $definition
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
        $this->factory->running++;
        $this->factory->maximumRunning = max($this->factory->maximumRunning, $this->factory->running);
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
