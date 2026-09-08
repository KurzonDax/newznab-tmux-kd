<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\ProviderCircuitBreaker;
use Illuminate\Support\Facades\DB;

final class RecoveryProviderBackoff
{
    /** @param list<NntpProvider> $providers */
    public function allows(array $providers): bool
    {
        return ! DB::table('obfuscation_recovery_provider_backoff')->whereIn('provider_digest', array_map($this->key(...), $providers))
            ->where('blocked_until', '>', now())->exists();
    }

    public function record(NntpProvider $provider, RecoveryTransfer $transfer): void
    {
        if ($transfer->outcome !== 'transport_failure' || $transfer->reason === 'article_absent') {
            return;
        }
        DB::table('obfuscation_recovery_provider_backoff')->upsert([
            'provider_digest' => $this->key($provider), 'blocked_until' => now()->addSeconds(ProviderCircuitBreaker::COOLDOWN_SECONDS),
            'reason' => $transfer->reason ?? 'transport_failure', 'updated_at' => now(),
        ], ['provider_digest'], ['blocked_until', 'reason', 'updated_at']);
    }

    private function key(NntpProvider $provider): string
    {
        return (new RecoveryIdentity)->digest([(string) $provider->position, $provider->host, (string) $provider->port, $provider->username]);
    }
}
