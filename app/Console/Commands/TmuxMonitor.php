<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\Tmux\TmuxOutput;
use App\Services\Tmux\TmuxSessionManager;
use App\Services\Tmux\TmuxTaskRunner;
use Illuminate\Console\Command;

class TmuxMonitor extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tmux:monitor
                            {--session= : Tmux session name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor and manage tmux processing panes (modernized)';

    protected TmuxSessionManager $sessionManager;

    protected TmuxMonitorService $monitor;

    protected TmuxTaskRunner $taskRunner;

    protected TmuxOutput $tmuxOutput;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {

        try {
            // Initialize services
            $sessionName = $this->option('session')
                ?? Settings::settingValue('tmux_session')
                ?? config('tmux.session.default_name', 'nntmux');

            $this->bootMonitorServices($sessionName);

            // Verify session exists
            if (! $this->sessionManager->sessionExists()) {
                $this->error("❌ Tmux session '{$sessionName}' does not exist.");
                $this->info("💡 Run 'php artisan tmux:start' to create the session first.");

                return Command::FAILURE;
            }

            cli()->header('Starting Tmux Monitor');
            $this->info("📊 Monitoring session: {$sessionName}");

            // Initialize monitor
            $this->monitor->initializeMonitor();

            // Main monitoring loop
            $iteration = 0;
            while ($this->monitor->shouldContinue()) {
                $iteration++;

                try {
                    $this->runMonitorIteration($iteration);
                } catch (\Throwable $e) {
                    // A programming error in one pane task must not take the
                    // whole monitor down: log it and let the next pass retry.
                    $this->error('⚠️  Monitor pass failed: '.$e->getMessage());
                    logger()->error('Tmux monitor pass error', [
                        'iteration' => $iteration,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                // Increment iteration and sleep
                $this->monitor->incrementIteration();
                $this->pauseBetweenIterations();
            }

            $this->info('🛑 Monitor stopped by exit flag');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('❌ Monitor failed: '.$e->getMessage());
            logger()->error('Tmux monitor error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Build the services the monitor loop drives.
     */
    protected function bootMonitorServices(string $sessionName): void
    {
        $this->sessionManager = new TmuxSessionManager($sessionName);
        $this->monitor = new TmuxMonitorService;
        $this->taskRunner = new TmuxTaskRunner($sessionName);
        $this->tmuxOutput = new TmuxOutput;
    }

    /**
     * One monitoring pass: refresh statistics, redraw the monitor pane, and
     * dispatch the pane tasks. Anything it throws is contained by the loop.
     */
    protected function runMonitorIteration(int $iteration): void
    {
        // Collect statistics
        $runVar = $this->monitor->collectStatistics();

        // Update display
        $this->tmuxOutput->updateMonitorPane($runVar);

        // Run pane tasks if tmux is running
        if ((int) ($runVar['settings']['is_running'] ?? 0) === 1) {
            $this->runPaneTasks($runVar);
        } elseif ($iteration % 60 === 0) { // Log every 10 minutes
            $this->info('⏸️  Tmux is not running. Waiting...');
        }
    }

    /**
     * Wait out the configured delay before the next monitoring pass.
     */
    protected function pauseBetweenIterations(): void
    {
        sleep(max(1, (int) config('tmux.monitor.delay', 10)));
    }

    /**
     * Run tasks in appropriate panes
     *
     * @param  array<string, mixed>  $runVar
     */
    private function runPaneTasks(array $runVar): void
    {
        $sequential = (int) ($runVar['constants']['sequential'] ?? 0);

        // Always run IRC scraper
        $this->runIRCScraper($runVar);

        // Run main tasks based on sequential mode
        if ($sequential === 1) {
            // Basic sequential mode
            $this->runBasicTasks($runVar);
        } else {
            // Full non-sequential mode
            $this->runFullTasks($runVar);
        }
    }

    /**
     * Run IRC scraper
     *
     * @param  array<string, mixed>  $runVar
     */
    private function runIRCScraper(array $runVar): void
    {
        $this->taskRunner->runIRCScraper([
            'constants' => $runVar['constants'],
        ]);
    }

    /**
     * Run full non-sequential tasks
     *
     * @param  array<string, mixed>  $runVar
     */
    private function runFullTasks(array $runVar): void
    {
        // Update binaries
        $this->taskRunner->runBinariesUpdate($runVar);

        // Backfill
        $this->taskRunner->runBackfill($runVar);

        // Update releases
        $this->taskRunner->runReleasesUpdate($runVar);

        // Post-processing and cleanup tasks
        $this->runPostProcessingTasks($runVar);
    }

    /**
     * Run basic sequential tasks
     *
     * @param  array<string, mixed>  $runVar
     */
    private function runBasicTasks(array $runVar): void
    {
        // Update releases
        $this->taskRunner->runReleasesUpdate($runVar);

        // Post-processing and cleanup tasks
        $this->runPostProcessingTasks($runVar);
    }

    /**
     * Run post-processing tasks (common to most modes)
     *
     * @param  array<string, mixed>  $runVar
     */
    private function runPostProcessingTasks(array $runVar): void
    {
        // Run utility tasks (window 1)
        $this->taskRunner->runPaneTask('fixnames', [], $runVar);
        $this->taskRunner->runPaneTask('removecrap', [], $runVar);

        // Run post-processing tasks (window 2)
        $this->taskRunner->runPaneTask('ppadditional', [], $runVar);
        $this->taskRunner->runPaneTask('tv', [], $runVar);
        $this->taskRunner->runPaneTask('movies', [], $runVar);
        $this->taskRunner->runPaneTask('amazon', [], $runVar);
    }
}
