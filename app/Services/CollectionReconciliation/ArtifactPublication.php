<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\Par2Sidecar\SidecarMutationProtection;
use App\Services\ReleaseRepair\NzbRepairDocument;
use App\Services\ReleaseRepair\RecoveryLease;
use App\Support\Data\NzbCreationResult;
use App\Support\Data\NzbReplaceResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable intent precedes filesystem publication; replay adopts only recognized bytes. */
class ArtifactPublication
{
    public static function availableSql(string $alias = 'releases'): string
    {
        $artifacts = DB::connection()->getQueryGrammar()->wrapTable('reconciled_artifacts');

        return Schema::hasTable('reconciled_artifacts')
            ? "NOT EXISTS (SELECT 1 FROM {$artifacts} ra WHERE ra.release_id = {$alias}.id AND ra.pending_operation IS NOT NULL)" : '1 = 1';
    }

    public function resume(?int $groupId, ReconciliationRunBudget $budget): void
    {
        if (! Schema::hasTable('reconciled_artifacts')) {
            return;
        }
        $cursorKey = 'artifact-resume:'.($groupId ?? 'all');
        $cursor = (int) Cache::get($cursorKey, 0);
        $stopped = false;
        DB::table('reconciled_artifacts as a')->join('releases as r', 'r.id', '=', 'a.release_id')
            ->where('a.release_id', '>', $cursor)->when($groupId !== null, static fn ($q) => $q->where('r.groups_id', $groupId))
            ->where(static function ($q): void {
                $q->where('a.search_pending', true)->orWhereExists(static function ($operations): void {
                    $operations->selectRaw('1')->from('reconciled_artifact_operations as o')->whereColumn('o.release_id', 'a.release_id')
                        ->where(static fn ($state) => $state->where('o.state', 'prepared')->orWhereExists(static function ($sources): void {
                            $sources->selectRaw('1')->from('reconciled_artifact_sources as s')->whereColumn('s.operation_id', 'o.id')
                                ->where('o.state', 'committed')->where('s.cleanup_pending', true);
                        }));
                });
            })->select('a.*')->orderBy('a.release_id')->chunkById(100, function ($rows) use ($budget, &$cursor, &$stopped): bool {
                foreach ($rows as $row) {
                    if (! $budget->take()) {
                        $stopped = true;

                        return false;
                    }
                    $cursor = (int) $row->release_id;
                    if ($row->pending_operation !== null) {
                        $this->execute($row->pending_operation);
                    } else {
                        $this->syncSearch($cursor);
                        $operation = DB::table('reconciled_artifact_operations as o')->join('reconciled_artifact_sources as s', 's.operation_id', '=', 'o.id')
                            ->where('o.release_id', $cursor)->where('o.state', 'committed')->where('s.cleanup_pending', true)->orderBy('o.id')->value('o.id');
                        if ($operation !== null) {
                            $this->execute($operation);
                        }
                    }
                }

                return true;
            }, 'a.release_id', 'release_id');
        Cache::forever($cursorKey, $stopped ? $cursor : 0);
    }

    public static function handles(string $guid): bool
    {
        return Schema::hasTable('reconciled_artifacts') && DB::table('reconciled_postings as p')
            ->join('releases as r', 'r.id', '=', 'p.release_id')->where('r.guid', $guid)->exists();
    }

    public function duplicateReceipt(string $guid, ?string $xml = null, ?int $sourceId = null): ?NzbReplaceResult
    {
        if (! Schema::hasTable('reconciled_artifact_operations')) {
            return null;
        }
        $query = DB::table('reconciled_artifact_operations as o')->join('releases as r', 'r.id', '=', 'o.release_id')
            ->where('o.guid', $guid)->where('r.guid', $guid)->where('o.kind', 'duplicate')->whereIn('o.state', ['prepared', 'committed']);
        if ($xml !== null) {
            $query->where('o.target_digest', hash('sha256', $xml));
        }
        if ($sourceId !== null) {
            $revision = ArtifactSourceRevision::capture($sourceId);
            if ($revision === null) {
                return null;
            }
            $query->join('reconciled_artifact_sources as s', 's.operation_id', '=', 'o.id')
                ->where('s.collection_id', $sourceId)->where('s.revision', $revision);
        }
        $operation = $query->orderByDesc('o.created_at')->first(['o.id', 'o.state', 'o.result']);
        if ($operation === null) {
            return null;
        }
        if ($operation->state === 'committed') {
            if (DB::transactionLevel() === 0) {
                $this->cleanSources($operation->id);
            }

            return NzbReplaceResult::success($operation->id, json_decode($operation->result, true, flags: JSON_THROW_ON_ERROR));
        }

        return DB::transactionLevel() === 0 ? $this->execute($operation->id) : NzbReplaceResult::deferred($operation->id);
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  array{version: int, epoch: int, proof_revision: int, population?: array{group: int, poster: string, total: int, from: string, until: string}}|null  $expectedSnapshot
     * @param  array<int, string|null>  $expectedSources
     * @param  array<string, mixed>|null  $proof
     */
    public function replace(string $guid, string $xml, ?RecoveryLease $owner = null, ?string $expectedDigest = null, ?ArtifactReleaseUpdate $update = null, array $sourceIds = [], ?array $expectedSnapshot = null, array $expectedSources = [], ?array $proof = null): NzbReplaceResult
    {
        $target = ArtifactInventory::load($xml);
        $update ??= new ArtifactReleaseUpdate;
        $prepared = DB::transaction(function () use ($guid, $xml, $owner, $expectedDigest, $target, $update, $sourceIds, $expectedSnapshot, $expectedSources, $proof): NzbReplaceResult {
            $observedIds = array_values(array_unique([...$sourceIds, ...array_keys($expectedSources)]));
            if ($observedIds !== []) {
                $locked = $this->lockSources($observedIds, $expectedSnapshot['population'] ?? null);
                if (array_diff($locked, $observedIds) !== [] || array_diff($observedIds, $locked) !== []) {
                    return NzbReplaceResult::writeFailure('source_population_changed_during_verification');
                }
            }
            $revisions = [];
            foreach ($observedIds as $sourceId) {
                $revision = ArtifactSourceRevision::capture($sourceId);
                if ($revision === null) {
                    return NzbReplaceResult::writeFailure('source_disappeared');
                }
                if (isset($expectedSources[$sourceId]) && $expectedSources[$sourceId] !== $revision) {
                    return NzbReplaceResult::writeFailure('source_changed_during_verification');
                }
                $revisions[$sourceId] = $revision;
            }
            $release = Release::query()->where('guid', $guid)->lockForUpdate()->first();
            if ($release === null) {
                return NzbReplaceResult::missingNzb('release_disappeared');
            }
            $journal = DB::table('reconciled_postings')->where('release_id', $release->id)->lockForUpdate()->first();
            if ($journal === null || $journal->state !== 'published') {
                return NzbReplaceResult::writeFailure('reconciliation_publication_pending');
            }
            if (($owner !== null && ! $owner->owns((int) $release->id))
                || ($owner === null && ! RecoveryLease::applyAvailable(Release::query()->whereKey($release->id))->exists())
                || ! SidecarMutationProtection::apply(Release::query()->whereKey($release->id), 'releases', $owner?->operationId())->exists()
                || ($release->additional_pp_claimed_at !== null && Carbon::parse($release->additional_pp_claimed_at)->greaterThanOrEqualTo(ReleaseClaimant::claimStaleBefore()))) {
                return NzbReplaceResult::writeFailure('artifact_owned_by_another_worker');
            }
            if (Schema::hasTable('obfuscation_recovery_publications') && DB::table('obfuscation_recovery_publications')
                ->where('releases_id', $release->id)->where('guid', $guid)
                ->whereNotIn('state', ['absorbed', 'duplicate_policy_discarded'])->exists()) {
                return NzbReplaceResult::writeFailure('recovered_membership_requires_manifest_restoration');
            }
            if (! $update->allows($release)) {
                return NzbReplaceResult::writeFailure('trusted_bundle_identity_conflict');
            }
            $current = app(NzbService::class)->readNzbContents($guid);
            if ($current === false) {
                return $this->preparationConflict($release, $journal, $xml, $update, 'adopted_artifact_missing');
            }
            $digest = hash('sha256', $current);
            if ($expectedDigest !== null && ! hash_equals($expectedDigest, $digest)) {
                return NzbReplaceResult::writeFailure('artifact_changed_before_preparation');
            }
            $artifact = DB::table('reconciled_artifacts')->where('release_id', $release->id)->lockForUpdate()->first();
            if ($artifact === null) {
                if ($journal->artifact_digest !== $digest) {
                    return $this->preparationConflict($release, $journal, $xml, $update, 'legacy_artifact_conflict');
                }
                DB::table('reconciled_artifacts')->insert(['release_id' => $release->id, 'guid' => $guid,
                    'version' => 1, 'epoch' => 1, 'proof_revision' => 1, 'xml' => $current, 'digest' => $digest,
                    'provenance' => json_encode(ArtifactProvenance::initial(ArtifactInventory::load($current), $journal->inventory, (int) $release->id), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('reconciled_proof_revisions')->insert(['release_id' => $release->id, 'revision' => 1,
                    'epoch' => 1, 'inventory' => $journal->inventory, 'decision' => $journal->decision]);
                $artifact = DB::table('reconciled_artifacts')->where('release_id', $release->id)->first();
            }
            if ($expectedSnapshot !== null && ((int) $artifact->version !== $expectedSnapshot['version']
                || (int) $artifact->epoch !== $expectedSnapshot['epoch'] || (int) $artifact->proof_revision !== $expectedSnapshot['proof_revision'])) {
                return NzbReplaceResult::writeFailure('artifact_changed_during_verification');
            }
            if ($artifact->cancelled) {
                return NzbReplaceResult::writeFailure('artifact_cancelled');
            }
            $targetDigest = hash('sha256', $xml);
            if ($artifact->pending_operation !== null) {
                $operation = DB::table('reconciled_artifact_operations')->where('id', $artifact->pending_operation)->lockForUpdate()->first();

                return $operation !== null && $operation->target_digest === $targetDigest
                    ? NzbReplaceResult::deferred($operation->id)
                    : NzbReplaceResult::writeFailure('another_artifact_operation_pending');
            }
            if ($sourceIds !== [] && DB::table('collections')->whereIn('id', $sourceIds)
                ->tap(static fn ($query) => CollectionOwnership::excludeArtifactSources($query))->lockForUpdate()->count() !== count($sourceIds)) {
                return NzbReplaceResult::writeFailure('source_reserved_by_artifact');
            }
            if ($artifact->digest !== $digest || $artifact->guid !== $guid) {
                return $this->preparationConflict($release, $journal, $xml, $update, 'adopted_artifact_conflict');
            }
            if ($update->kind === 'duplicate' && (NzbRepairDocument::load($xml)?->measure($update->declaredFiles)->percentage() ?? 0) <= (float) $release->completion) {
                return NzbReplaceResult::writeFailure('duplicate_not_better');
            }
            $id = (string) Str::uuid();
            DB::table('reconciled_artifact_operations')->insert(['id' => $id, 'release_id' => $release->id, 'guid' => $guid,
                'kind' => $update->kind, 'expected_version' => $artifact->version, 'expected_epoch' => $artifact->epoch,
                'expected_proof_revision' => $artifact->proof_revision, 'expected_digest' => $digest,
                'target_digest' => $targetDigest, 'target_xml' => $xml,
                'change_kind' => $target->classifyAgainst(ArtifactInventory::load($current)),
                'proof' => $proof === null ? null : json_encode($proof, JSON_THROW_ON_ERROR),
                'population' => isset($expectedSnapshot['population']) ? json_encode($expectedSnapshot['population'], JSON_THROW_ON_ERROR) : null,
                'delta' => json_encode($target->deltaAgainst(ArtifactInventory::load($current)), JSON_THROW_ON_ERROR), 'updates' => $update->encode(), 'source_revisions' => json_encode($revisions, JSON_THROW_ON_ERROR), 'state' => 'prepared',
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($sourceIds as $sourceId) {
                DB::table('reconciled_artifact_sources')->insert(['operation_id' => $id, 'collection_id' => $sourceId, 'revision' => $revisions[$sourceId]]);
            }
            DB::table('reconciled_artifacts')->where('release_id', $release->id)->update(['pending_operation' => $id]);

            return NzbReplaceResult::deferred($id);
        }, 3);

        return $prepared->operationId !== null && DB::transactionLevel() === 0
            ? $this->execute($prepared->operationId) : $prepared;
    }

    /**
     * @param  array{group: int, poster: string, total: int, from: string, until: string}|null  $population
     * @param  list<int>  $observedSourceIds
     */
    public function writePosting(Release $caller, ?string $xml, ?RecoveryLease $lease = null, ?array $population = null, array $observedSourceIds = []): NzbCreationResult
    {
        $ids = DB::table('collections')->where('releases_id', $caller->id)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $observedSourceIds = array_values(array_unique([...$ids, ...$observedSourceIds]));
        $prepared = DB::transaction(function () use ($caller, $xml, $lease, $ids, $population, $observedSourceIds): NzbReplaceResult {
            $locked = $this->lockSources($observedSourceIds, $population);
            if (array_diff($locked, $observedSourceIds) !== [] || array_diff($observedSourceIds, $locked) !== []) {
                return NzbReplaceResult::writeFailure('initial_population_changed');
            }
            $release = Release::query()->whereKey($caller->id)->lockForUpdate()->first();
            $journal = DB::table('reconciled_postings')->where('release_id', $caller->id)->lockForUpdate()->first();
            $artifact = DB::table('reconciled_artifacts')->where('release_id', $caller->id)->lockForUpdate()->first();
            if ($release === null || $release->guid !== $caller->guid || $journal === null || $artifact?->cancelled) {
                return NzbReplaceResult::writeFailure('posting_identity_changed');
            }
            $token = $caller->getAttribute(NzbCreationCandidateQuery::CLAIM_TOKEN_COLUMN);
            $initial = (int) $release->nzbstatus === NzbService::NZB_NONE;
            $owns = $initial ? NzbCreationCandidateQuery::ownedPendingBuilder((int) $release->id, $token)->exists()
                : ($lease !== null && $lease->owns((int) $release->id));
            if ($artifact?->pending_operation !== null) {
                if ($initial && $owns) {
                    DB::table('reconciled_artifact_operations')->where('id', $artifact->pending_operation)
                        ->update(['ownership' => json_encode(['initial' => true, 'token' => $token], JSON_THROW_ON_ERROR)]);
                }

                return NzbReplaceResult::deferred($artifact->pending_operation);
            }
            $current = app(NzbService::class)->readNzbContents($caller->guid);
            if ($artifact !== null && $journal->state === 'published') {
                return $current !== false && hash('sha256', $current) === $artifact->digest
                    ? NzbReplaceResult::success() : NzbReplaceResult::writeFailure('published_artifact_conflict');
            }
            if ($ids !== [] && DB::table('collections')->whereIn('id', $ids)
                ->tap(static fn ($query) => CollectionOwnership::excludeArtifactSources($query))->lockForUpdate()->count() !== count($ids)) {
                return NzbReplaceResult::writeFailure('source_reserved_by_artifact');
            }
            if (! $owns || $xml === null || app(CollectionAdmission::class)->expired($ids)) {
                return NzbReplaceResult::writeFailure('posting_preparation_not_owned');
            }
            if ($journal->source_digest !== null && $ids !== [] && PendingInventory::digest((new PendingInventory)->load($ids)) !== $journal->source_digest) {
                return NzbReplaceResult::writeFailure('posting_source_changed');
            }
            $priorXml = $journal->original_nzb === null ? false : base64_decode($journal->original_nzb, true);
            if (($priorXml === false && ($current !== false || app(NzbService::class)->nzbPath($caller->guid) !== false)) || ($priorXml !== false && $current !== $priorXml)) {
                return NzbReplaceResult::writeFailure('unexpected_initial_artifact');
            }
            $files = PendingInventory::decode($journal->inventory);
            $decisionData = json_decode($journal->decision, true, flags: JSON_THROW_ON_ERROR);
            $decisionData = $decisionData['decision'] ?? $decisionData;
            if ($initial && $journal->review_digest === null) {
                $decision = new PostingDecision($files, (int) $release->declaredfiles, null, '', [], [], 'prepared');
                if (! $decision->complete()) {
                    return NzbReplaceResult::writeFailure('initial_union_incomplete');
                }
            }
            if ($artifact === null) {
                $version = $priorXml === false ? 0 : 1;
                DB::table('reconciled_artifacts')->insert(['release_id' => $release->id, 'guid' => $release->guid,
                    'version' => $version, 'epoch' => 1, 'proof_revision' => 0, 'xml' => $priorXml === false ? null : $priorXml,
                    'digest' => $priorXml === false ? null : hash('sha256', $priorXml), 'provenance' => '{"files":{},"components":[]}',
                    'created_at' => now(), 'updated_at' => now()]);
                $artifact = DB::table('reconciled_artifacts')->where('release_id', $release->id)->first();
            } elseif ($artifact->digest !== ($current === false ? null : hash('sha256', $current))) {
                return NzbReplaceResult::writeFailure('posting_artifact_version_changed');
            }
            $revisions = [];
            foreach ($observedSourceIds as $id) {
                $revisions[$id] = ArtifactSourceRevision::capture($id);
            }
            $id = (string) Str::uuid();
            $target = ArtifactInventory::load($xml);
            $change = $priorXml === false ? 'initial' : $target->classifyAgainst(ArtifactInventory::load($priorXml));
            $update = new ArtifactReleaseUpdate('reconciliation', ['nzbstatus' => NzbService::NZB_ADDED,
                'size' => array_sum(array_map(static fn (PostingFile $file): int => array_sum(array_column($file->segments, 'bytes')), $files))],
                declaredFiles: (int) $release->declaredfiles, label: $decisionData['label'], independentVideos: (bool) $journal->independent_videos);
            if (! $update->allows($release)) {
                return NzbReplaceResult::writeFailure('trusted_bundle_identity_conflict');
            }
            DB::table('reconciled_artifact_operations')->insert(['id' => $id, 'release_id' => $release->id, 'guid' => $release->guid,
                'kind' => 'reconciliation', 'expected_version' => $artifact->version, 'expected_epoch' => $artifact->epoch,
                'expected_proof_revision' => $artifact->proof_revision, 'expected_digest' => $artifact->digest,
                'target_digest' => hash('sha256', $xml), 'target_xml' => $xml, 'change_kind' => $change,
                'delta' => '{}', 'updates' => $update->encode(), 'source_revisions' => json_encode($revisions, JSON_THROW_ON_ERROR),
                'population' => $population === null ? null : json_encode($population, JSON_THROW_ON_ERROR),
                'ownership' => json_encode(['initial' => $initial, 'token' => $token], JSON_THROW_ON_ERROR),
                'proof' => json_encode(['journal_id' => $journal->id, 'digest' => $journal->digest, 'inventory' => $journal->inventory,
                    'decision' => $journal->decision], JSON_THROW_ON_ERROR), 'state' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($ids as $sourceId) {
                DB::table('reconciled_artifact_sources')->insert(['operation_id' => $id, 'collection_id' => $sourceId, 'revision' => $revisions[$sourceId]]);
            }
            DB::table('reconciled_artifacts')->where('release_id', $release->id)->update(['pending_operation' => $id]);

            return NzbReplaceResult::deferred($id);
        }, 3);
        $result = $prepared->operationId !== null && DB::transactionLevel() === 0 ? $this->execute($prepared->operationId) : $prepared;
        $path = app(NzbService::class)->nzbPath($caller->guid);

        return $result->success && $path !== false ? NzbCreationResult::success($path, $ids) : NzbCreationResult::deferred($result->reason);
    }

    public function resumeForRelease(int $releaseId, string $kind): ?NzbReplaceResult
    {
        if (! Schema::hasTable('reconciled_artifact_operations')) {
            return null;
        }
        $operation = DB::table('reconciled_artifact_operations')->where('release_id', $releaseId)
            ->where('state', 'prepared')->first();
        if ($operation === null) {
            return null;
        }
        if ($operation->kind !== $kind) {
            return NzbReplaceResult::deferred($operation->id, 'another_artifact_writer_pending');
        }

        return $this->execute($operation->id);
    }

    public function execute(string $id): NzbReplaceResult
    {
        if (DB::transactionLevel() !== 0) {
            return NzbReplaceResult::deferred($id, 'enclosing_transaction');
        }
        $operation = DB::table('reconciled_artifact_operations')->where('id', $id)->first();
        if ($operation === null) {
            return NzbReplaceResult::writeFailure('artifact_operation_missing');
        }
        $worker = (string) Str::uuid();
        if ($operation->state === 'prepared' && ! $this->claimOperation($operation, $worker)) {
            return NzbReplaceResult::deferred($id, 'artifact_worker_busy');
        }
        $temporary = null;
        try {
            $result = DB::transaction(function () use ($operation, $id, $worker, &$temporary): NzbReplaceResult {
                $sources = json_decode($operation->source_revisions, true, flags: JSON_THROW_ON_ERROR);
                $population = $operation->population === null ? null : json_decode($operation->population, true, flags: JSON_THROW_ON_ERROR);
                $lockedSources = $this->lockSources(array_keys($sources), $population);
                $populationChanged = array_diff($lockedSources, array_keys($sources)) !== [] || array_diff(array_keys($sources), $lockedSources) !== [];
                $release = Release::query()->whereKey($operation->release_id)->lockForUpdate()->first();
                DB::table('reconciled_postings')->where('release_id', $operation->release_id)->lockForUpdate()->first();
                $artifact = DB::table('reconciled_artifacts')->where('release_id', $operation->release_id)->lockForUpdate()->first();
                $receipt = DB::table('reconciled_artifact_operations')->where('id', $id)->lockForUpdate()->first();
                if ($receipt === null || $release === null || $release->guid !== $operation->guid || $artifact?->cancelled) {
                    DB::table('reconciled_artifact_operations')->where('id', $id)->update(['state' => 'abandoned', 'target_xml' => null]);

                    return NzbReplaceResult::writeFailure('release_identity_changed');
                }
                if ($receipt->state === 'committed') {
                    return NzbReplaceResult::success($id, json_decode($receipt->result, true, flags: JSON_THROW_ON_ERROR));
                }
                if ($receipt->state === 'prepared' && ($receipt->worker !== $worker || strtotime($receipt->lease_until ?? '') <= now()->timestamp)) {
                    return NzbReplaceResult::deferred($id, 'artifact_worker_lease_lost');
                }
                if ($receipt->state !== 'prepared') {
                    return NzbReplaceResult::writeFailure('artifact_operation_'.$receipt->state);
                }
                if ($artifact === null || $artifact->pending_operation !== $id
                    || (int) $artifact->version !== (int) $receipt->expected_version
                    || (int) $artifact->epoch !== (int) $receipt->expected_epoch
                    || (int) $artifact->proof_revision !== (int) $receipt->expected_proof_revision) {
                    return $this->conflict($id, 'artifact_version_changed');
                }
                $nzbs = app(NzbService::class);
                $current = $nzbs->readNzbContents($receipt->guid);
                $path = $nzbs->nzbPath($receipt->guid);
                $initial = (int) $receipt->expected_version === 0 && $receipt->expected_digest === null;
                if (($current === false || $path === false) && ! $initial) {
                    return $this->conflict($id, 'adopted_artifact_missing');
                }
                if ($path === false) {
                    $path = $nzbs->getNzbPath($receipt->guid, $nzbs->getNzbSplitLevel(), true);
                }
                clearstatcache(true, $path);
                if ($initial && $current === false && (file_exists($path) || is_link($path))) {
                    return $this->conflict($id, 'unexpected_existing_initial_artifact');
                }
                $physical = $current === false ? null : hash('sha256', $current);
                if ($physical !== $receipt->target_digest) {
                    if ($physical !== $receipt->expected_digest) {
                        return $this->conflict($id, 'unexpected_artifact_digest');
                    }
                    if ($populationChanged) {
                        $this->abandon($release, $receipt, $sources, $initial);

                        return NzbReplaceResult::writeFailure('source_population_changed_before_publication');
                    }
                    if (! ArtifactReleaseUpdate::decode($receipt->updates)->allows($release)) {
                        $this->abandon($release, $receipt, $sources, $initial);

                        return NzbReplaceResult::writeFailure('trusted_bundle_identity_conflict');
                    }
                    $ownership = $receipt->ownership === null ? [] : json_decode($receipt->ownership, true, flags: JSON_THROW_ON_ERROR);
                    if (($ownership['initial'] ?? false) && ! NzbCreationCandidateQuery::ownedPendingBuilder((int) $release->id, $ownership['token'])->exists()) {
                        return NzbReplaceResult::deferred($id, 'initial_publication_claim_changed');
                    }
                    foreach ($sources as $sourceId => $revision) {
                        if (ArtifactSourceRevision::capture((int) $sourceId) !== $revision) {
                            $this->abandon($release, $receipt, $sources, $initial);

                            return NzbReplaceResult::writeFailure('source_changed_before_publication');
                        }
                    }
                    $temporary = $path.'.artifact-'.$id.'.tmp';
                    $compressed = gzencode($receipt->target_xml, 6);
                    if ($compressed === false || file_put_contents($temporary, $compressed, LOCK_EX) !== strlen($compressed)
                        || gzdecode((string) file_get_contents($temporary)) !== $receipt->target_xml) {
                        throw new RuntimeException('artifact_temporary_write_failed');
                    }
                    if (! $this->publish($temporary, $path)) {
                        throw new RuntimeException('artifact_rename_failed');
                    }
                }
                if ($receipt->proof !== null) {
                    $proof = json_decode($receipt->proof, true, flags: JSON_THROW_ON_ERROR);
                    $revision = (int) $artifact->proof_revision + 1;
                    $membership = ['provenance' => ArtifactProvenance::initial(ArtifactInventory::load($receipt->target_xml), $proof['inventory'], (int) $release->id)];
                    if ($proof['preserve_provenance'] ?? false) {
                        $membership = ArtifactProvenance::advance(json_decode($artifact->provenance, true, flags: JSON_THROW_ON_ERROR),
                            ArtifactInventory::load($artifact->xml), ArtifactInventory::load($receipt->target_xml), $id, $receipt->change_kind);
                        foreach ($proof['incoming_groups'] as $key => $groups) {
                            $membership['provenance']['files'][$key] = $groups;
                        }
                    }
                    DB::table('reconciled_proof_revisions')->insert(['release_id' => $release->id, 'revision' => $revision,
                        'epoch' => $artifact->epoch, 'inventory' => $proof['inventory'], 'decision' => $proof['decision']]);
                    DB::table('reconciled_postings')->where('id', $proof['journal_id'])->update(['state' => 'published',
                        'artifact_digest' => $receipt->target_digest, 'inventory' => $proof['inventory'], 'decision' => $proof['decision'],
                        'digest' => $proof['digest'], 'independent_videos' => PostingDecision::hasIndependentVideos(PendingInventory::decode($proof['inventory'])), 'updated_at' => now()]);
                    if (Schema::hasTable('reconciliation_admissions')) {
                        DB::table('reconciliation_admissions')->whereIn('collection_id', DB::table('reconciled_artifact_sources')->where('operation_id', $id)->select('collection_id'))->update(['state' => 'published']);
                    }
                } else {
                    $membership = ArtifactProvenance::advance(json_decode($artifact->provenance, true, flags: JSON_THROW_ON_ERROR),
                        ArtifactInventory::load($artifact->xml), ArtifactInventory::load($receipt->target_xml), $id, $receipt->change_kind);
                    $revision = (int) $artifact->proof_revision;
                    if ($receipt->change_kind !== 'serialization') {
                        $previousProof = DB::table('reconciled_proof_revisions')->where('release_id', $release->id)->where('revision', $revision)->first();
                        $retained = $receipt->change_kind === 'replacement' ? [] : array_values(array_filter(
                            PendingInventory::decode($previousProof->inventory),
                            static function (PostingFile $file) use ($artifact, $membership): bool {
                                $key = array_key_first(ArtifactInventory::load((new PostingNzb)->render([$file]))->files());
                                $prior = json_decode($artifact->provenance, true, flags: JSON_THROW_ON_ERROR);

                                return array_intersect($prior['files'][$key] ?? [], $membership['invalidated']) === [];
                            },
                        ));
                        $revision++;
                        DB::table('reconciled_proof_revisions')->insert(['release_id' => $release->id, 'revision' => $revision,
                            'epoch' => (int) $artifact->epoch + (int) ($receipt->change_kind === 'replacement'),
                            'inventory' => PendingInventory::encode($retained),
                            'decision' => json_encode(['status' => 'partially_unproved', 'invalidated' => $membership['invalidated']], JSON_THROW_ON_ERROR)]);
                    }
                }
                $recordedResult = ArtifactReleaseUpdate::decode($receipt->updates)->apply($release, $receipt->target_xml);
                DB::table('reconciled_artifacts')->where('release_id', $release->id)->update([
                    'version' => (int) $receipt->expected_version + 1,
                    'epoch' => (int) $receipt->expected_epoch + (int) ($receipt->change_kind === 'replacement'),
                    'proof_revision' => $revision, 'provenance' => json_encode($membership['provenance'], JSON_THROW_ON_ERROR),
                    'xml' => $receipt->target_xml, 'digest' => $receipt->target_digest,
                    'pending_operation' => null, 'search_pending' => true, 'updated_at' => now(),
                    ...ArtifactInventory::load($receipt->target_xml)->discovery($release->groups_id === null ? null : (int) $release->groups_id),
                ]);
                DB::table('reconciled_artifact_operations')->where('id', $id)->update([
                    'state' => 'committed', 'worker' => null, 'lease_until' => null, 'target_xml' => null, 'result' => json_encode($recordedResult, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);

                return NzbReplaceResult::success($id, $recordedResult);
            }, 3);
            if ($result->success) {
                $this->syncSearch((int) $operation->release_id);
                $this->cleanSources($id);
            }

            return $result;
        } catch (\Throwable $exception) {
            Log::warning('Artifact publication deferred', ['operation_id' => $id, 'reason' => $exception->getMessage()]);

            return NzbReplaceResult::deferred($id, $exception->getMessage());
        } finally {
            DB::table('reconciled_artifact_operations')->where('id', $id)->where('worker', $worker)->update(['worker' => null, 'lease_until' => null]);
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function claimOperation(object $operation, string $worker): bool
    {
        return DB::transaction(function () use ($operation, $worker): bool {
            $release = Release::query()->whereKey($operation->release_id)->lockForUpdate()->first();
            DB::table('reconciled_postings')->where('release_id', $operation->release_id)->lockForUpdate()->first();
            $artifact = DB::table('reconciled_artifacts')->where('release_id', $operation->release_id)->lockForUpdate()->first();
            $receipt = DB::table('reconciled_artifact_operations')->where('id', $operation->id)->lockForUpdate()->first();
            if ($release === null || $release->guid !== $operation->guid || $artifact?->cancelled) {
                DB::table('reconciled_artifact_operations')->where('id', $operation->id)->update(['state' => 'abandoned', 'target_xml' => null]);
                DB::table('reconciled_artifacts')->where('release_id', $operation->release_id)->where('pending_operation', $operation->id)
                    ->update(['pending_operation' => null]);
                DB::table('reconciled_artifact_sources')->where('operation_id', $operation->id)->update(['cleanup_pending' => false]);

                return false;
            }
            if ($artifact === null
                || $receipt === null || $receipt->state !== 'prepared' || $artifact->pending_operation !== $receipt->id
                || ($receipt->worker !== null && strtotime($receipt->lease_until ?? '') > now()->timestamp)) {
                return false;
            }
            DB::table('reconciled_artifact_operations')->where('id', $operation->id)->update([
                'worker' => $worker, 'lease_until' => now()->addSeconds(120),
            ]);

            return true;
        }, 3);
    }

    public function cancel(string $guid): void
    {
        DB::transaction(function () use ($guid): void {
            $release = Release::query()->where('guid', $guid)->lockForUpdate()->first();
            if ($release === null) {
                return;
            }
            DB::table('reconciled_postings')->where('release_id', $release->id)->lockForUpdate()->first();
            DB::table('reconciled_artifacts')->insertOrIgnore(['release_id' => $release->id, 'guid' => $guid,
                'version' => 0, 'epoch' => 1, 'proof_revision' => 0, 'provenance' => '{"files":{},"components":[]}',
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('reconciled_artifacts')->where('release_id', $release->id)->lockForUpdate()->first();
            DB::table('reconciled_artifact_operations')->where('release_id', $release->id)->orderBy('id')->lockForUpdate()->get();
            DB::table('reconciled_artifacts')->where('release_id', $release->id)->update([
                'cancelled' => true, 'pending_operation' => null, 'search_pending' => false, 'xml' => null,
            ]);
            DB::table('reconciled_artifact_operations')->where('release_id', $release->id)->where('state', '!=', 'committed')
                ->update(['state' => 'abandoned', 'target_xml' => null, 'updated_at' => now()]);
            DB::table('reconciled_artifact_sources')->whereIn('operation_id', DB::table('reconciled_artifact_operations')->where('release_id', $release->id)->select('id'))
                ->update(['cleanup_pending' => false]);
        }, 3);
    }

    public function cleanSources(string $operationId): void
    {
        $sources = DB::table('reconciled_artifact_sources')->where('operation_id', $operationId)->where('cleanup_pending', true)
            ->orderBy('collection_id')->get();
        if ($sources->isEmpty()) {
            return;
        }
        DB::transaction(function () use ($operationId, $sources): void {
            DB::table('collections')->whereIn('id', $sources->pluck('collection_id')->all())->orderBy('id')->lockForUpdate()->get();
            $operation = DB::table('reconciled_artifact_operations')->where('id', $operationId)->lockForUpdate()->first();
            if ($operation === null || $operation->state !== 'committed') {
                return;
            }
            foreach ($sources as $source) {
                $unchanged = ArtifactSourceRevision::capture((int) $source->collection_id) === $source->revision;
                DB::table('reconciled_artifact_sources')->where('operation_id', $operationId)->where('collection_id', $source->collection_id)
                    ->update(['cleanup_pending' => false]);
                if ($unchanged) {
                    DB::table('reconciliation_claims')->where('collection_id', $source->collection_id)->update([
                        'owner' => null, 'lease_until' => null, 'reason' => 'associated', 'release_id' => $operation->release_id,
                    ]);
                    app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([(int) $source->collection_id], 'Artifact receipt cleanup', screenAdmission: false);
                    if (DB::table('collections')->where('id', $source->collection_id)->exists()) {
                        throw new RuntimeException('artifact_source_cleanup_pending');
                    }
                } elseif (Schema::hasColumn('collections', 'releases_id')) {
                    DB::table('collections')->where('id', $source->collection_id)->where('releases_id', $operation->release_id)
                        ->update(['releases_id' => null, 'filecheck' => 0]);
                }
            }
        }, 3);
    }

    public function syncSearch(int $releaseId): void
    {
        $version = DB::table('reconciled_artifacts')->where('release_id', $releaseId)->where('search_pending', true)->value('version');
        if ($version === null) {
            return;
        }
        try {
            Search::updateRelease($releaseId);
            DB::table('reconciled_artifacts')->where('release_id', $releaseId)->where('version', $version)->update(['search_pending' => false]);
        } catch (\Throwable $exception) {
            Log::warning('Artifact search synchronization pending', ['release_id' => $releaseId, 'reason' => $exception->getMessage()]);
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array{group: int, poster: string, total: int, from: string, until: string}|null  $population
     * @return list<int>
     */
    public function lockSources(array $ids, ?array $population): array
    {
        if ($ids === [] && $population === null) {
            return [];
        }

        return DB::table('collections')->where(static function ($query) use ($ids, $population): void {
            $query->whereIn('id', $ids);
            if ($population !== null) {
                $query->orWhere(static fn ($window) => $window->where('groups_id', $population['group'])
                    ->where('fromname', $population['poster'])->where('declaredfiles', $population['total'])
                    ->whereIn('filecheck', [0, 1, 2, 3, 10, 15, 16])->whereBetween('date', [$population['from'], $population['until']])
                    ->tap(static fn ($eligible) => RecoveryCollectionOwnership::exclude($eligible)));
            }
        })->orderBy('id')->limit(258)->lockForUpdate()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @param array<int, string|null> $sources */
    private function abandon(Release $release, object $receipt, array $sources, bool $initial): void
    {
        DB::table('reconciled_artifact_operations')->where('id', $receipt->id)->update(['state' => 'abandoned', 'target_xml' => null]);
        DB::table('reconciled_artifacts')->where('release_id', $release->id)->update(['pending_operation' => null]);
        DB::table('reconciled_artifact_sources')->where('operation_id', $receipt->id)->update(['cleanup_pending' => false]);
        DB::table('collections')->whereIn('id', array_keys($sources))->where('releases_id', $release->id)
            ->update(['releases_id' => null, 'filecheck' => 0]);
        if ($initial) {
            DB::table('reconciliation_claims')->whereIn('collection_id', array_keys($sources))
                ->update(['release_id' => null, 'owner' => null, 'lease_until' => null, 'reason' => 'changed_inventory']);
            DB::table('reconciled_postings')->where('release_id', $release->id)->update(['state' => 'abandoned']);
            DB::table('reconciled_artifacts')->where('release_id', $release->id)->update(['cancelled' => true]);
            if (Schema::hasTable('reconciliation_admissions')) {
                DB::table('reconciliation_admissions')->whereIn('collection_id', array_keys($sources))->update(['state' => 'abandoned']);
            }
        }
    }

    private function preparationConflict(Release $release, object $journal, string $xml, ArtifactReleaseUpdate $update, string $reason): NzbReplaceResult
    {
        DB::table('reconciled_artifacts')->insertOrIgnore(['release_id' => $release->id, 'guid' => $release->guid,
            'version' => 1, 'epoch' => 1, 'proof_revision' => 0, 'digest' => $journal->artifact_digest,
            'provenance' => '{"files":{},"components":[]}', 'created_at' => now(), 'updated_at' => now()]);
        $artifact = DB::table('reconciled_artifacts')->where('release_id', $release->id)->lockForUpdate()->first();
        if ($artifact->pending_operation === null) {
            $id = (string) Str::uuid();
            DB::table('reconciled_artifact_operations')->insert(['id' => $id, 'release_id' => $release->id, 'guid' => $release->guid,
                'kind' => $update->kind, 'expected_version' => $artifact->version, 'expected_epoch' => $artifact->epoch,
                'expected_proof_revision' => $artifact->proof_revision, 'expected_digest' => $artifact->digest,
                'target_digest' => hash('sha256', $xml), 'target_xml' => $xml, 'change_kind' => 'unknown',
                'delta' => '{}', 'updates' => $update->encode(), 'source_revisions' => '{}', 'state' => 'conflict',
                'result' => json_encode(['success' => false, 'reason' => $reason], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('reconciled_artifacts')->where('release_id', $release->id)->update(['pending_operation' => $id]);
        }
        Log::warning('Reconciled artifact conflict', ['release_id' => $release->id, 'reason' => $reason]);

        return NzbReplaceResult::writeFailure($reason);
    }

    private function conflict(string $id, string $reason): NzbReplaceResult
    {
        DB::table('reconciled_artifact_operations')->where('id', $id)->update(['state' => 'conflict',
            'result' => json_encode(['success' => false, 'reason' => $reason], JSON_THROW_ON_ERROR), 'updated_at' => now()]);

        return NzbReplaceResult::writeFailure($reason);
    }

    protected function publish(string $temporary, string $path): bool
    {
        return rename($temporary, $path);
    }
}
