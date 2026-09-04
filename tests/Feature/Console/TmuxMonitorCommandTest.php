<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\TmuxMonitor;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\Tmux\TmuxOutput;
use App\Services\Tmux\TmuxSessionManager;
use App\Services\Tmux\TmuxTaskRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class TmuxMonitorCommandTest extends TestCase
{
    /**
     * A pane task that throws an \Error -- a TypeError from a bad argument, say
     * -- used to escape the loop's \Exception-only catch and kill the monitor.
     */
    public function test_an_error_thrown_by_a_pass_is_logged_and_the_loop_keeps_going(): void
    {
        Log::spy();

        $command = $this->monitorRunningPasses(3, static function (int $iteration): void {
            if ($iteration === 1) {
                throw new \TypeError('showsleep expects int, float given');
            }
        });

        $output = new BufferedOutput;
        $command->setLaravel($this->app);

        $this->assertSame(Command::SUCCESS, $command->run(new ArrayInput([]), $output));
        $this->assertSame([1, 2, 3], $command->passes, 'the loop ran every pass');
        $this->assertStringContainsString('showsleep expects int, float given', $output->fetch());

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Tmux monitor pass error'
                && $context['iteration'] === 1
                && str_contains($context['message'], 'showsleep expects int, float given'))
            ->once();
    }

    /**
     * A monitor command wired to stubs, running exactly $passes times and
     * handing each pass to $onPass.
     *
     * @param  callable(int): void  $onPass
     */
    private function monitorRunningPasses(int $passes, callable $onPass): TmuxMonitor
    {
        return new class($passes, $onPass) extends TmuxMonitor
        {
            /** @var list<int> */
            public array $passes = [];

            /**
             * @param  callable(int): void  $onPass
             */
            public function __construct(private readonly int $passLimit, private $onPass)
            {
                parent::__construct();
            }

            protected function bootMonitorServices(string $sessionName): void
            {
                $this->sessionManager = new class($sessionName) extends TmuxSessionManager
                {
                    public function sessionExists(): bool
                    {
                        return true;
                    }
                };

                $this->monitor = new class($this->passLimit) extends TmuxMonitorService
                {
                    private int $completed = 0;

                    public function __construct(private readonly int $passLimit) {}

                    public function initializeMonitor(): array
                    {
                        return [];
                    }

                    public function shouldContinue(): bool
                    {
                        return $this->completed < $this->passLimit;
                    }

                    public function incrementIteration(): void
                    {
                        $this->completed++;
                    }
                };

                $this->taskRunner = new TmuxTaskRunner($sessionName);
                $this->tmuxOutput = new class extends TmuxOutput
                {
                    public function __construct() {}

                    public function updateMonitorPane(mixed &$runVar): void {}
                };
            }

            protected function runMonitorIteration(int $iteration): void
            {
                $this->passes[] = $iteration;
                ($this->onPass)($iteration);
            }

            protected function pauseBetweenIterations(): void {}
        };
    }
}
