<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryProcess;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

final class ObfuscationDownload extends Command
{
    protected $signature = 'obfuscation:download {--worker : Execute one serial request} {--engine}';

    protected $description = 'Run a bounded recovery download supervisor or serial worker';

    public function handle(RecoveryScheduler $scheduler): int
    {
        $engine = (bool) $this->option('engine');
        if ($this->option('worker')) {
            $this->line($scheduler->download($engine));

            return self::SUCCESS;
        }
        if (! $scheduler->allowed($engine) || ! ($config = RecoveryConfig::fromSettings())->enabled) {
            $this->line('admission_pending');

            return self::SUCCESS;
        }
        app(RecoverySlots::class)->reap();
        app(RecoveryWork::class)->reclaimExpired();
        foreach (['slot' => ['obfuscation_recovery_slots', 'owner_', 'worker_token', 'expires_at'],
            'work' => ['obfuscation_recovery_work', 'claim_owner_', 'claim_token', 'claim_expires_at']] as $kind => [$table, $prefix, $token, $expiry]) {
            foreach (DB::table($table)->whereNotNull($token)->where($expiry, '<=', now())->orderBy('id')->limit(10)->get() as $row) {
                if (RecoveryProcess::fromRow($row, $prefix)->status() === 'unknown') {
                    $this->warn('Unknown recovery '.$kind.' owner: '.$row->id.'. Stop its owning worker, then use obfuscation:reclaim '.$kind.' '.$row->id.' '.$row->{$token}.' --confirmed-stopped (slot before work).');
                }
            }
        }
        $children = [];
        $stopped = false;
        $this->trap([SIGTERM, SIGINT], function () use (&$stopped): void {
            $stopped = true;
        });
        $deadline = hrtime(true) + 60000000000;
        try {
            for ($i = 0; $i < $config->threads && ! $stopped; $i++) {
                $child = new Process([PHP_BINARY, base_path('artisan'), 'obfuscation:download', '--worker', ...($engine ? ['--engine'] : [])], base_path(), timeout: 45);
                $child->start();
                $children[] = $child;
            }
            do {
                $running = false;
                foreach ($children as $child) {
                    if ($child->isRunning()) {
                        $running = true;
                        $child->checkTimeout();
                    }
                }
                if ($running) {
                    usleep(100000);
                }
            } while ($running && ! $stopped && hrtime(true) < $deadline && $scheduler->allowed($engine));
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop(1, SIGKILL);
                }
            }
            $this->untrap();
        }
        foreach ($children as $child) {
            $result = trim($child->getOutput());
            $this->line($child->isSuccessful() && preg_match('/^[a-z_]{1,48}$/D', $result) === 1 ? $result : 'worker_failed');
        }
        $this->line('slice_complete');

        return self::SUCCESS;
    }
}
