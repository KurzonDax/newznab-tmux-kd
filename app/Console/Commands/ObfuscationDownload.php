<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryPaneText;
use App\Services\ObfuscationRecovery\RecoveryProcess;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ObfuscationDownload extends Command
{
    protected $signature = 'obfuscation:download {--worker : Execute one serial request} {--engine}';

    protected $description = 'Run a bounded recovery download supervisor or serial worker';

    public function handle(RecoveryScheduler $scheduler, RecoveryPaneText $text): int
    {
        $engine = (bool) $this->option('engine');
        if ($this->option('worker')) {
            $details = $scheduler->downloadDetailed($engine);
            $group = $details['bundle_id'] === null ? null : DB::table('obfuscation_recovery_bundles as b')
                ->join('usenet_groups as g', 'g.id', '=', 'b.groups_id')->where('b.id', $details['bundle_id'])->value('g.name');
            $this->line(json_encode(['outcome' => $details['outcome'], 'bundle_id' => $details['bundle_id'], 'purpose' => $details['purpose'],
                'group' => $group, 'first' => is_int($details['payload']['first'] ?? null) ? $details['payload']['first'] : null,
                'last' => is_int($details['payload']['last'] ?? null) ? $details['payload']['last'] : null,
                'seconds' => $details['seconds']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if (! $scheduler->allowed($engine) || ! ($config = RecoveryConfig::fromSettings())->enabled) {
            $text->say('Recovery downloads: engine stopped or recovery disabled.', 'warning');

            return self::SUCCESS;
        }
        $text->say($text->title('downloads', $config->threads), 'header');
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
                        try {
                            $child->checkTimeout();
                        } catch (ProcessTimedOutException) {
                            // checkTimeout stops the child; report its failed result below.
                        }
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
        $idle = 0;
        foreach ($children as $child) {
            $result = json_decode(trim($child->getOutput()), true);
            if (! $child->isSuccessful() || ! is_array($result) || ! is_string($result['outcome'] ?? null)
                || preg_match('/^[a-z_]{1,48}$/D', $result['outcome']) !== 1) {
                $text->say('Worker failed: no result within 45 s or invalid output', 'error');
            } elseif ($result['outcome'] === 'idle') {
                $idle++;
            } else {
                $text->say($text->workerLine($result), $text->level($result['outcome']));
            }
        }
        if ($idle > 0) {
            $text->say(number_format($idle).' workers idle');
        }
        $text->say($text->queueLine());

        return self::SUCCESS;
    }
}
