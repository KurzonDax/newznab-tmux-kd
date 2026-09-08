<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\ObfuscationRecoveryProfile;
use App\Models\Release;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\ProviderCircuitBreaker;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryEnrichmentDownload
{
    public function __construct(private readonly RecoveryEvidence $evidence, private readonly RecoverySlots $slots,
        private readonly RecoveryBudget $budget, private readonly RecoveryWork $work, private readonly RecoveryHeadIndex $index) {}

    /** @param list<NntpProvider>|null $providers */
    public function run(RecoveryWorkClaim $claim, ?array $providers = null): string
    {
        $bundle = DB::transaction(fn (): ?object => (new RecoveryOwnership)->locked($claim), 1);
        if ($bundle === null || $claim->stage !== RecoveryStage::Download || $claim->purpose !== 'enrichment') {
            return 'obsolete';
        }
        $publication = DB::table('obfuscation_recovery_publications')->where('id', $claim->payload['publication_id'] ?? 0)->first();
        $release = $publication === null ? null : Release::query()->where('id', $publication->releases_id)->where('guid', $publication->guid)->first();
        if ($publication === null || $release === null || $publication->state !== 'published' || $publication->deleted_at !== null) {
            return $this->finish($claim, 'publication_unavailable');
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        if ($plan->bundleId !== $claim->bundleId || $plan->revision !== $claim->revision) {
            return $this->finish($claim, 'obsolete_publication');
        }
        $file = null;
        foreach ($plan->files as $entry) {
            if ($entry->identity === ($claim->payload['file_id'] ?? '') && $entry->role !== RecoveryFileRole::Index) {
                $file = $entry;
            }
        }
        $messageId = (new RecoveryIdentity)->messageId($claim->payload['message_id'] ?? '');
        $records = $this->index->forPublication($publication)[$file->identity ?? ''] ?? [];
        if ($file === null || ! in_array($messageId, array_column($records, 'message_id'), true)) {
            return $this->finish($claim, 'unplanned_target');
        }
        if ($this->evidence->get($messageId) !== null) {
            return $this->finish($claim, 'cache_hit');
        }
        $config = RecoveryConfig::fromSettings();
        $selection = ObfuscationRecoveryProfile::tryFrom(DB::table('usenet_groups')->where('id', $bundle->groups_id)->value('obfuscation_recovery_profile') ?? '');
        $providers = array_values(array_filter($providers ?? NntpProviderPool::configuredProviders(), static fn (NntpProvider $provider): bool => $provider->enabled));
        usort($providers, static fn (NntpProvider $a, NntpProvider $b): int => $a->position <=> $b->position);
        if (! $config->enabled || ! $config->enrichmentEnabled || ! $selection?->permits($plan->algorithm->selection()) || $providers === []) {
            $this->work->defer($claim);

            return 'admission_pending';
        }
        if (! app(RecoveryProviderBackoff::class)->allows($providers)) {
            $this->work->defer($claim, ProviderCircuitBreaker::COOLDOWN_SECONDS);

            return 'provider_backoff';
        }
        $lease = RecoveryLease::acquire($release);
        if ($lease === null) {
            $this->work->defer($claim);

            return 'release_busy';
        }
        $slot = null;
        try {
            $slot = $this->slots->acquire($config);
            if ($slot === null) {
                $this->work->defer($claim);

                return 'capacity_pending';
            }
            $reservation = $this->budget->reserveEnrichment($claim, $slot);
            if ($reservation === null) {
                if (! $this->work->heartbeat($claim)) {
                    return 'obsolete';
                }
                if (! $this->slots->canStart($slot, RecoveryConfig::fromSettings())) {
                    $this->work->defer($claim);

                    return 'capacity_pending';
                }
                if (! RecoveryAdmission::allows((int) $bundle->groups_id, $plan->algorithm) || ! RecoveryConfig::fromSettings()->enrichmentEnabled) {
                    $this->work->defer($claim);

                    return 'admission_pending';
                }

                return $this->finish($claim, 'enrichment_limit_reached');
            }
            $provider = $providers[min($reservation->physicalAttempt - 1, count($providers) - 1)];
            DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->where('token', $reservation->token)
                ->update(['provider' => 'position:'.$provider->position]);
            $transfer = app(RecoveryWire::class)->observeConnections($this->budget->connectionObserver($reservation))->fetch($provider, $messageId, 1048576, false, 2097152, 65536, false);
            $this->budget->recordTransfer($reservation, $transfer, $provider->ssl, 65536);
            app(RecoveryProviderBackoff::class)->record($provider, $transfer);
            if (! $lease->owns((int) $release->id)) {
                return 'obsolete';
            }

            if ($transfer->outcome === 'success' && $transfer->article !== null) {
                try {
                    $this->validate($file, $records, $messageId, $transfer->article);
                    $this->evidence->store($messageId, $transfer->article, $reservation->attemptId);
                } catch (InvalidArgumentException) {
                    return $this->finish($claim, 'evidence_conflict');
                }

                return $this->finish($claim, 'downloaded');
            }
            if ($transfer->outcome === 'transport_failure' && $reservation->physicalAttempt < 2) {
                $this->work->defer($claim);

                return 'retry_pending';
            }

            return $this->finish($claim, $transfer->outcome);
        } finally {
            if ($slot !== null) {
                $this->slots->release($slot);
            }
            $lease->release();
        }
    }

    /** @param list<array<string,mixed>> $records */
    private function validate(RecoveryFilePlan $file, array $records, string $messageId, RecoveryArticle $article): void
    {
        if (! $article->complete || ($article->crcPresent && ! $article->crcVerified) || $article->fileSize !== $file->decodedBytes
            || $article->total !== $file->totalParts || $article->begin !== ($article->part - 1) * RecoveryFilePlan::CHUNK_BYTES + 1
            || $article->end !== min($article->part * RecoveryFilePlan::CHUNK_BYTES, $file->decodedBytes)) {
            throw new InvalidArgumentException('enrichment_declaration_conflict');
        }
        foreach ($records as $record) {
            $cached = $this->evidence->get($record['message_id'], true);
            if ($cached !== null && ($cached->filename !== $article->filename
                || ($record['message_id'] !== $messageId && $cached->part === $article->part))) {
                throw new InvalidArgumentException('enrichment_file_conflict');
            }
        }
    }

    private function finish(RecoveryWorkClaim $claim, string $outcome): string
    {
        return DB::transaction(function () use ($claim, $outcome): string {
            if (! $this->work->complete($claim, $outcome)) {
                return 'obsolete';
            }
            DB::table('obfuscation_recovery_targets')->where('publication_id', $claim->payload['publication_id'] ?? 0)
                ->where('message_id', $claim->payload['message_id'] ?? '')->update(['status' => 'completed', 'outcome' => $outcome, 'updated_at' => now()]);
            DB::table('obfuscation_recovery_publications')->where('id', $claim->payload['publication_id'] ?? 0)->where('state', 'published')->update([
                'enrichment_outcome' => in_array($outcome, ['cache_hit', 'downloaded'], true) ? 'evidence_ready' : $outcome,
                'enrichment_next_attempt_at' => null, 'updated_at' => now(),
            ]);

            return $outcome;
        }, 1);
    }
}
