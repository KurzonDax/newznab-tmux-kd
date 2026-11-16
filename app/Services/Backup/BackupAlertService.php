<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Mail\BackupFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BackupAlertService
{
    public function failure(string $error, ?string $setId = null): void
    {
        $adminEmail = (string) config('nntmux.admin_email');
        if ($adminEmail === '' || $adminEmail === 'admin@example.com') {
            return;
        }

        try {
            Mail::to($adminEmail)->send(new BackupFailed($error, $setId));
        } catch (\Throwable $mailError) {
            Log::warning('Failed to send database backup alert: '.$mailError->getMessage());
        }
    }

    public function offsiteFailure(string $error, ?string $setId = null): void
    {
        $adminEmail = (string) config('nntmux.admin_email');
        if ($adminEmail === '' || $adminEmail === 'admin@example.com') {
            return;
        }

        try {
            Mail::to($adminEmail)->send(new BackupFailed($error, $setId, true));
        } catch (\Throwable $mailError) {
            Log::warning('Failed to send off-site copy alert: '.$mailError->getMessage());
        }
    }
}
