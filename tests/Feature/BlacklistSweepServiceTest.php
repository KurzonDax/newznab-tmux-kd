<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BlacklistSweepService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

final class BlacklistSweepServiceTest extends TestCase
{
    public function test_starts_full_dry_run_and_per_rule_delete_as_detached_processes(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(output: "4321\n");
        });

        $service = new BlacklistSweepService($this->makeTempDirectory('blacklist-sweeps'));

        $dryRun = $service->start('dry-run');
        $this->assertSame('dry-run', $dryRun['mode']);
        $this->assertNull($dryRun['rule_id']);
        $this->assertSame(4321, $dryRun['pid']);
        $this->assertStringContainsString('releases:remove-crap --type=blacklist --time=full', $commands[0]);
        $this->assertStringContainsString(escapeshellarg(PHP_BINDIR.DIRECTORY_SEPARATOR.'php'), $commands[0]);
        $this->assertStringNotContainsString('--delete', $commands[0]);
        $this->assertStringContainsString('nohup', $commands[0]);

        $service->complete($dryRun['id'], 0);

        $delete = $service->start('delete', 27);
        $this->assertSame('delete', $delete['mode']);
        $this->assertSame(27, $delete['rule_id']);
        $this->assertStringContainsString('--blacklist-id=27', $commands[1]);
        $this->assertStringContainsString('--delete', $commands[1]);
    }

    public function test_refuses_a_second_admin_sweep_while_one_is_running(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $service = new BlacklistSweepService($this->makeTempDirectory('blacklist-sweeps'));
        $service->start('dry-run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already running');

        $service->start('delete');
    }

    public function test_status_reads_live_counts_and_completed_summary(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $directory = $this->makeTempDirectory('blacklist-sweeps');
        $service = new BlacklistSweepService($directory);
        $run = $service->start('dry-run', 5);

        file_put_contents($run['log_path'], "Would be deleting: Blacklist [5]: One\nWould be deleting: Blacklist [5]: Two\n");

        $running = $service->status();
        $this->assertTrue($running['running']);
        $this->assertSame(2, $running['current']['matched_count']);
        $this->assertSame(0, $running['current']['removed_count']);

        file_put_contents($run['log_path'], "Would have deleted 2 release(s). This script ran for\n", FILE_APPEND);
        $service->complete($run['id'], 0);

        $completed = $service->status();
        $this->assertFalse($completed['running']);
        $this->assertSame($run['id'], $completed['last']['id']);
        $this->assertSame(2, $completed['last']['matched_count']);
        $this->assertSame(0, $completed['last']['exit_code']);
        $this->assertNotNull($completed['last']['finished_at']);
    }

    public function test_retains_only_the_latest_twenty_statuses_and_logs(): void
    {
        Process::fake(fn () => Process::result(output: "99\n"));
        $directory = $this->makeTempDirectory('blacklist-sweeps');
        $service = new BlacklistSweepService($directory);

        for ($runNumber = 1; $runNumber <= 22; $runNumber++) {
            $run = $service->start('dry-run');
            file_put_contents($run['log_path'], "Would have deleted {$runNumber} release(s).\n");
            $service->complete($run['id'], 0);
        }

        $this->assertCount(20, glob($directory.'/*.json') ?: []);
        $this->assertCount(20, glob($directory.'/*.log') ?: []);
    }

    public function test_recovers_an_orphaned_run_before_starting_a_replacement(): void
    {
        $launches = 0;
        Process::fake(function () use (&$launches) {
            $launches++;

            return Process::result(output: ($launches === 1 ? 999999999 : getmypid())."\n");
        });
        $service = new BlacklistSweepService($this->makeTempDirectory('blacklist-sweeps'));

        $orphaned = $service->start('dry-run');
        $replacement = $service->start('delete');
        $status = $service->status();

        $this->assertSame($replacement['id'], $status['current']['id']);
        $this->assertSame($orphaned['id'], $status['last']['id']);
        $this->assertSame(255, $status['last']['exit_code']);
        $this->assertFalse($status['last']['running']);
    }
}
