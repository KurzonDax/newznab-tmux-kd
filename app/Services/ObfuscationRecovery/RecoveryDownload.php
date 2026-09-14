<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\ProviderCircuitBreaker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryDownload
{
    public function __construct(private readonly RecoveryEvidence $evidence, private readonly RecoverySlots $slots,
        private readonly RecoveryBudget $budget, private readonly RecoveryWork $work) {}

    /** @param list<NntpProvider>|null $providers */
    public function run(RecoveryWorkClaim $claim, ?array $providers = null): string
    {
        if (in_array($claim->purpose, ['gap', RecoveryFrontierRebuild::PURPOSE], true)) {
            return app(RecoveryGapDownload::class)->run($claim, $providers);
        }
        if ($claim->purpose === 'enrichment') {
            return app(RecoveryEnrichmentDownload::class)->run($claim, $providers);
        }
        $bundle = DB::transaction(fn (): ?object => (new RecoveryOwnership)->locked($claim), 1);
        if ($bundle === null || $claim->stage !== RecoveryStage::Download) {
            return 'obsolete';
        }
        $messageId = (new RecoveryIdentity)->messageId($claim->payload['message_id'] ?? '');
        $target = RecoveryConstructionTargets::find($bundle, $messageId);
        $algorithm = RecoveryAlgorithm::tryFrom($bundle->profile ?? '');
        if ($target === null || $algorithm === null) {
            $this->work->complete($claim, 'unplanned_target');

            return 'unplanned_target';
        }
        $allowance = RecoveryConstructionTargets::allowance($target['kind'], $algorithm);
        try {
            $this->evidence->resume($claim, $messageId);
            $cached = $this->evidence->get($messageId, $allowance['prefix']);
        } catch (InvalidArgumentException) {
            $this->work->complete($claim, 'evidence_conflict');

            return 'evidence_conflict';
        }
        if ($cached !== null && RecoveryConstructionTargets::sufficient($target['kind'], $cached)) {
            return $this->work->complete($claim, 'cache_hit') ? 'cache_hit' : 'obsolete';
        }
        $config = RecoveryConfig::fromSettings();
        $selection = DB::table('usenet_groups')->where('id', $bundle->groups_id)->value('obfuscation_recovery_profile');
        $providers = array_values(array_filter($providers ?? NntpProviderPool::configuredProviders(), static fn (NntpProvider $provider): bool => $provider->enabled));
        usort($providers, static fn (NntpProvider $a, NntpProvider $b): int => $a->position <=> $b->position);
        if (! $config->admits($selection, $algorithm->selection()) || $providers === []) {
            $this->work->defer($claim);

            return 'admission_pending';
        }
        if (! app(RecoveryProviderBackoff::class)->allows($providers)) {
            $this->work->defer($claim, ProviderCircuitBreaker::COOLDOWN_SECONDS);

            return 'provider_backoff';
        }
        $slot = $this->slots->acquire($config);
        if ($slot === null) {
            $this->work->defer($claim);

            return 'capacity_pending';
        }
        try {
            $reservation = $this->budget->reserveConstruction($claim, $slot, $messageId, $allowance['reservation']);
            if ($reservation === null) {
                if (! $this->work->heartbeat($claim)) {
                    return 'obsolete';
                }
                if (! $this->slots->canStart($slot, RecoveryConfig::fromSettings())) {
                    $this->work->defer($claim);

                    return 'capacity_pending';
                }
                if (! RecoveryAdmission::allows((int) $bundle->groups_id, $algorithm)) {
                    $this->work->defer($claim);

                    return 'admission_pending';
                }
                $this->work->complete($claim, 'construction_limit_reached');

                return 'construction_limit_reached';
            }
            $provider = $providers[min($reservation->physicalAttempt - 1, count($providers) - 1)];
            DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->where('token', $reservation->token)
                ->update(['provider' => 'position:'.$provider->position]);
            $transfer = app(RecoveryWire::class)->observeConnections($this->budget->connectionObserver($reservation))->fetch($provider, $messageId, $allowance['decoded'], $allowance['prefix'],
                $allowance['reservation'], $allowance['close'], $allowance['declaration'], $target['kind'] === 'anchor');
            $this->evidence->receipts()->record($claim, $reservation, $transfer, $provider->ssl, $allowance['close'],
                'article', $messageId, $transfer->article?->metadata() ?? [], $transfer->article->data ?? '');
            app(RecoveryProviderBackoff::class)->record($provider, $transfer);
            if ($transfer->outcome === 'success' && $transfer->article !== null) {
                try {
                    $this->evidence->resume($claim, $messageId);
                } catch (InvalidArgumentException) {
                    $this->work->complete($claim, 'evidence_conflict');

                    return 'evidence_conflict';
                }

                return $this->work->complete($claim, 'downloaded') ? 'downloaded' : 'obsolete';
            }
            if ($transfer->outcome === 'transport_failure' && $reservation->physicalAttempt < 2) {
                return $this->work->defer($claim) ? 'retry_pending' : 'obsolete';
            }
            $this->work->complete($claim, $transfer->outcome);

            return $transfer->outcome;
        } finally {
            $this->slots->release($slot);
        }
    }
}
