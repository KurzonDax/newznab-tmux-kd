<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Tmux\TmuxOutput;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ReconciliationPaneTest extends TestCase
{
    public function test_full_monitor_output_keeps_reconciliation_and_recovery_readable_at_108_columns(): void
    {
        Process::fake(['git *' => Process::result(output: 'fixture')]);
        $run = ['settings' => ['monitor' => 0, 'show_query' => 0, 'is_running' => 1, 'post' => 0],
            'timers' => ['timer1' => time(), 'newOld' => ['newestrelname' => 'Synthetic posting']],
            'constants' => ['delaytime' => 1], 'connections' => [],
            'reconciliation' => ['available' => true,
                'hour' => ['used' => 1048576, 'limit' => 268435456, 'deferrals' => 3],
                'day' => ['used' => 2097152, 'limit' => 2147483648, 'deferrals' => 4]],
            'recovery' => ['available' => true, 'enabled' => true, 'occupied_slots' => 1, 'worker_limit' => 2,
                'opens_per_second' => 1.25, 'observed_bytes_per_second' => 1024, 'accounted_bytes_per_second' => 2048]];
        ob_start();
        try {
            (new TmuxOutput)->updateMonitorPane($run);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $plain = preg_replace('/\e\[[0-?]*[ -\/]*[@-~]/', '', $output);
        foreach (['Monitor Running', 'Synthetic posting', 'Reconciliation', 'This Hour', 'Today', 'Budget Deferrals', 'Recovery', '1 / 2', '1.25/s', '1 KiB/s', '2 KiB/s'] as $value) {
            $this->assertStringContainsString($value, $plain);
        }
        foreach (explode("\n", $plain) as $line) {
            $this->assertLessThanOrEqual(108, mb_strwidth($line));
        }
    }

    public function test_operational_tables_preserve_all_values_within_the_existing_columns(): void
    {
        $text = (new TmuxOutput)->renderOperationalStatistics([
            'available' => true,
            'hour' => ['used' => 1048576, 'limit' => 268435456, 'deferrals' => 1],
            'day' => ['used' => PHP_INT_MAX, 'limit' => PHP_INT_MAX, 'deferrals' => 2],
        ], [
            'available' => true, 'enabled' => true, 'occupied_slots' => 1, 'worker_limit' => 2,
            'opens_per_second' => 1.25, 'observed_bytes_per_second' => 1024,
            'accounted_bytes_per_second' => 2048,
        ]);
        $plain = preg_replace('/\e\[[0-9;]*m/', '', $text);
        $this->assertStringContainsString('Reconciliation', $plain);
        $this->assertStringContainsString('1 MiB / 256 MiB', $plain);
        $this->assertStringContainsString('8 EiB / 8 EiB', $plain);
        foreach (['Recovery', 'enabled', '1 / 2', '1.25/s', '1 KiB/s', '2 KiB/s'] as $value) {
            $this->assertStringContainsString($value, $plain);
        }
        foreach (explode("\n", trim($plain)) as $line) {
            $this->assertLessThanOrEqual(68, strlen($line));
        }
    }
}
