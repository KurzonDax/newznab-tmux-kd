<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Services\Tmux\TmuxPaneSupervisor;
use Illuminate\Container\Attributes\Singleton;

/**
 * Counts the pool children one worker run starts and how many of them fail.
 *
 * The count adds up across every pool call in the run. When the run ends it
 * prints one summary line if any child failed and, inside a supervised tmux
 * pane, writes `failed/total` to the file the pane step names, so the step's
 * status line can carry it. Child failures never change the worker's exit
 * code.
 */
#[Singleton]
final class ChildProcessTally
{
    private int $failed = 0;

    private int $total = 0;

    private bool $finishRegistered = false;

    private bool $finished = false;

    public function record(int $exitCode): void
    {
        $this->total++;
        if ($exitCode !== 0) {
            $this->failed++;
        }

        if (! $this->finishRegistered) {
            $this->finishRegistered = true;
            app()->terminating(fn () => $this->finish());
        }
    }

    /**
     * Report the run's children once.
     */
    public function finish(): void
    {
        if ($this->finished || $this->total === 0) {
            return;
        }
        $this->finished = true;

        if ($this->failed > 0) {
            echo "{$this->failed} of {$this->total} child processes failed".PHP_EOL;
        }

        // Set per step by the pane's shell command, not by .env, so it is read
        // from the process environment rather than from (cacheable) config.
        $file = getenv(TmuxPaneSupervisor::CHILDREN_ENV);
        if (is_string($file) && $file !== '') {
            file_put_contents($file, "{$this->failed}/{$this->total}");
        }
    }
}
