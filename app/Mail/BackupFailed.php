<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BackupFailed extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public function __construct(
        public readonly string $kind,
        public readonly string $error,
        public readonly ?string $setId = null,
        public readonly bool $offsite = false,
    ) {
        $this->onQueue((string) config('mail.brand.incident_queue', 'incidents'));
    }

    public function build(): static
    {
        $operation = $this->offsite ? 'Off-site copy' : ucfirst($this->kind).' backup';

        return $this->from((string) config('mail.from.address'))
            ->brandedSubject("{$operation} failed")
            ->view('emails.backup-failed')
            ->text('emails.text.backup-failed')
            ->with([
                'operation' => $operation,
                'error' => $this->error,
                'setId' => $this->setId,
                'offsite' => $this->offsite,
                'site' => (string) config('app.name'),
                'preheader' => "{$operation} failed: {$this->error}",
                'backupsUrl' => route('admin.backups.index'),
            ]);
    }
}
