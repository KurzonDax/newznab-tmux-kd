<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Release;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecoveryPublicationHandoff
{
    public function __construct(private readonly RecoveryArtifacts $artifacts) {}

    public function step(RecoveryWorkClaim $claim, int $publicationId, RecoveryPlan $old, RecoveryPlan $next): string
    {
        $snapshot = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->first();
        if ($snapshot === null) {
            return 'obsolete';
        }
        $batch = $this->batch($snapshot);

        return DB::transaction(function () use ($claim, $publicationId, $old, $next, $batch): string {
            $bundle = (new RecoveryOwnership)->locked($claim);
            if ($bundle === null) {
                return 'obsolete';
            }
            $owner = DB::table('obfuscation_recovery_bundles')->where('id', $old->bundleId)->lockForUpdate()->first();
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->lockForUpdate()->first();
            if ($publication === null || $publication->deleted_at !== null
                || ! in_array($publication->state, ['registered', 'materializing', 'materialized', 'policy_blocked', 'reconciling', 'created'], true)
                || json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR) !== $old->toArray()) {
                return 'reconcile_pending';
            }
            if (DB::table('obfuscation_recovery_work')->where('bundle_id', $old->bundleId)->where('id', '<>', $claim->id)
                ->where('status', 'claimed')->where('claim_expires_at', '>', now())->exists()) {
                return 'canonical_claim_pending';
            }
            if ($owner !== null && (int) $owner->revision === $old->revision && $owner->manifest_verified_at !== null
                && ! in_array($owner->state, RecoveryOwnership::INACTIVE_STATES, true)
                && json_decode($owner->sealed_plan ?? 'null', true, flags: JSON_THROW_ON_ERROR) === $old->toArray()
                && RecoveryAdmission::allows((int) $owner->groups_id, $old->algorithm)
                && (new RecoveryPublicationCoverage)->ready($owner)) {
                app(RecoveryWork::class)->enqueueForBundle(RecoveryStage::Publish, $old->bundleId, $old->revision, 'publish', []);

                return 'canonical_publication_pending';
            }
            $release = null;
            if ($publication->releases_id !== null) {
                $release = Release::query()->whereKey($publication->releases_id)->where('guid', $publication->guid)->lockForUpdate()->first();
                if ($release === null || $release->collectionhash !== $publication->collection_projection
                    || (int) $release->nzbstatus !== NzbService::NZB_NONE || $publication->initialization_state !== 'pending') {
                    throw new RuntimeException('recovery_publication_release_conflict');
                }
                $available = Release::query()->whereKey($release->id);
                NzbCreationCandidateQuery::applyClaimWindow($available, 'releases');
                if (! $available->exists()) {
                    return 'canonical_claim_pending';
                }
                $existing = app(NzbService::class)->nzbPath($release->guid);
                if (is_string($existing)) {
                    return $this->adoptFinalized($claim, $publication, $old, $bundle, $owner, $release, $existing);
                }
            }
            $collection = DB::table('collections')->where('id', $publication->collections_id)->lockForUpdate()->first();
            if ($collection !== null) {
                if ($collection->collectionhash !== $publication->collection_projection
                    || (int) $collection->totalfiles !== $old->plannedFiles() || (int) $collection->declaredfiles !== 0
                    || DB::table('usenet_groups')->where('id', $collection->groups_id)->value('name') !== $old->group) {
                    throw new RuntimeException('recovery_collection_projection_conflict');
                }
                DB::table('collections')->where('id', $collection->id)->update(['filecheck' => CollectionFileCheckStatus::Default->value]);
            }
            DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->update([
                'state' => 'reconciling', 'reason' => 'equivalent_provenance_rebuild', 'updated_at' => now(),
            ]);
            if (! $this->removeOwnedBatch($publication, $old, $batch)) {
                return 'canonical_reconciling';
            }
            DB::table('collection_groups')->where('collections_id', $publication->collections_id)->delete();
            if ($collection !== null) {
                DB::table('collections')->where('id', $collection->id)->where('collectionhash', $publication->collection_projection)->delete();
            }
            if ($owner !== null) {
                app(RecoveryBudgetOwners::class)->merge([$owner->owner_digest, $bundle->owner_digest]);
            }
            $references = new RecoveryReferences;
            $references->release('publication', (string) $publicationId);
            $references->plan('publication', $publicationId, $next);
            $references->release('bundle', (string) $next->bundleId);
            DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->update([
                'sealed_plan' => json_encode($next->toArray(), JSON_THROW_ON_ERROR), 'manifest_digest' => $next->manifestDigest,
                'canonical_bundle_id' => $next->bundleId, 'canonical_revision' => $next->revision,
                'group_name' => $next->group, 'source_epoch' => $next->sourceEpoch, 'collections_id' => null,
                'materialized_parts' => 0, 'reconciliation_cursor' => 0, 'state' => 'registered', 'reason' => 'equivalent_provenance_rebuilt', 'updated_at' => now(),
            ]);
            if ($release !== null) {
                Release::query()->whereKey($release->id)->update(['groups_id' => $bundle->groups_id]);
            }

            return 'canonical_reconciled';
        }, 1);
    }

    private function adoptFinalized(RecoveryWorkClaim $claim, object $publication, RecoveryPlan $old, object $incoming,
        ?object $previous, Release $release, string $path): string
    {
        $nzbDigest = app(RecoveryNzbVerifier::class)->verify($path, $old);
        $identity = new RecoveryIdentity;
        $ownerDigest = $identity->digest(['finalized-publication-owner', $publication->identity]);
        DB::table('obfuscation_recovery_bundles')->insertOrIgnore([
            'owner_digest' => $ownerDigest, 'publication_id' => $publication->id, 'kind' => 'publication',
            'groups_id' => $release->groups_id, 'profile' => $old->algorithm->value, 'source_epoch' => $old->sourceEpoch,
            'capture_generation' => $previous->capture_generation ?? null, 'revision' => 1, 'state' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $retained = DB::table('obfuscation_recovery_bundles')->where('owner_digest', $ownerDigest)->lockForUpdate()->first();
        $plan = new RecoveryPlan($old->algorithm, (int) $retained->id, 1, $old->group, $old->sourceEpoch,
            $old->setId, $old->files, $old->manifestDigest, $old->manifestBytes, $old->evidenceIds);
        $owners = [$ownerDigest, (string) $incoming->owner_digest];
        if ($previous !== null) {
            $owners[] = (string) $previous->owner_digest;
        }
        app(RecoveryBudgetOwners::class)->merge($owners);
        $files = DB::table('obfuscation_recovery_files')->where('bundle_id', $old->bundleId)->where('revision', $old->revision)->limit(34)->get();
        if ($files->count() > 33) {
            throw new RuntimeException('recovery_file_count_mismatch');
        }
        foreach ($files as $file) {
            $row = (array) $file;
            unset($row['id']);
            DB::table('obfuscation_recovery_files')->insertOrIgnore([...$row, 'bundle_id' => $retained->id, 'revision' => 1]);
        }
        DB::table('obfuscation_recovery_bundles')->where('id', $retained->id)->update([
            'sealed_plan' => json_encode($plan->toArray(), JSON_THROW_ON_ERROR), 'manifest_verified_at' => now(),
        ]);
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
            'sealed_plan' => json_encode($plan->toArray(), JSON_THROW_ON_ERROR), 'canonical_bundle_id' => $retained->id,
            'canonical_revision' => 1, 'state' => 'published', 'nzb_digest' => $nzbDigest,
            'reason' => 'finalized_output_adopted', 'updated_at' => now(),
        ]);
        (new RecoveryReferences)->plan('publication', (int) $publication->id, $plan);
        (new RecoveryReferences)->release('bundle', (string) $claim->bundleId);
        DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
            'reason' => 'equivalent_publication_retained', 'updated_at' => now(),
        ]);
        if (! app(NzbService::class)->createNzbForRelease($release)->success) {
            throw new RuntimeException('recovery_publication_finalization_pending');
        }

        return 'published';
    }

    /** @return array{records:list<array<string,mixed>>,offset:int,digest:string,start:int} */
    public function batch(object $publication): array
    {
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));

        return (new RecoveryManifest($this->artifacts))->page(new RecoveryArtifact($plan->manifestDigest, $plan->manifestBytes), (int) $publication->reconciliation_cursor);
    }

    /** @param array{records:list<array<string,mixed>>,offset:int,digest:string,start:int} $batch */
    public function retire(object $publication, array $batch): bool
    {
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $collection = DB::table('collections')->where('id', $publication->collections_id)->lockForUpdate()->first();
        if ($collection !== null && ($collection->collectionhash !== $publication->collection_projection
            || (int) $collection->totalfiles !== $plan->plannedFiles() || (int) $collection->declaredfiles !== 0
            || $collection->releases_id !== null
            || DB::table('usenet_groups')->where('id', $collection->groups_id)->value('name') !== $plan->group)) {
            throw new RuntimeException('recovery_collection_projection_conflict');
        }
        if (! $this->removeOwnedBatch($publication, $plan, $batch)) {
            return false;
        }
        DB::table('collection_groups')->where('collections_id', $publication->collections_id)->delete();
        DB::table('collections')->where('id', $publication->collections_id)->where('collectionhash', $publication->collection_projection)->delete();
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
            'collections_id' => null, 'materialized_parts' => 0, 'cleanup_outcome' => 'retired',
        ]);

        return true;
    }

    /** @param array{records:list<array<string,mixed>>,offset:int,digest:string,start:int} $batch */
    private function removeOwnedBatch(object $publication, RecoveryPlan $plan, array $batch): bool
    {
        if ($batch['digest'] !== $plan->manifestDigest || $batch['start'] !== (int) $publication->reconciliation_cursor) {
            return false;
        }
        $files = $plan->files;
        usort($files, static fn (RecoveryFilePlan $a, RecoveryFilePlan $b): int => strcmp($a->identity, $b->identity));
        $expected = [];
        $byId = [];
        foreach ($files as $index => $file) {
            $expected[bin2hex((new RecoveryIdentity)->binaryProjection($publication->identity, $file->role->value, $file->identity))] = [$file, $index + 1];
            $byId[$file->identity] = $file;
        }
        $records = [];
        foreach ($batch['records'] as $record) {
            $file = $byId[$record['file']] ?? null;
            if ($file === null || $record['ordinal'] < 1 || $record['ordinal'] > $file->totalParts
                || $record['role'] !== $file->role->value || $record['group'] !== $plan->group || $record['source_epoch'] !== $plan->sourceEpoch) {
                throw new RuntimeException('recovery_manifest_scope_mismatch');
            }
            $records[$file->identity][$record['ordinal']] = $record;
        }
        $binaries = DB::table('binaries')->where('collections_id', $publication->collections_id)->orderBy('id')->limit(34)->lockForUpdate()->get();
        if ($binaries->count() > $plan->plannedFiles()) {
            throw new RuntimeException('recovery_binary_count_mismatch');
        }
        foreach ($binaries as $binary) {
            $entry = $expected[DB::getDriverName() === 'sqlite' ? $binary->binaryhash : bin2hex($binary->binaryhash)] ?? null;
            if ($entry === null || $binary->name !== '"'.$entry[0]->displayName.'" yEnc'
                || (int) $binary->totalparts !== $entry[0]->totalParts || (int) $binary->filenumber !== $entry[1]) {
                throw new RuntimeException('recovery_binary_projection_conflict');
            }
            $members = $records[$entry[0]->identity] ?? [];
            $parts = DB::table('parts')->where('binaries_id', $binary->id)->whereIn('partnumber', array_keys($members))->lockForUpdate()->get();
            foreach ($parts as $part) {
                $record = $members[$part->partnumber];
                if ($record['message_id'] !== $part->messageid || $record['article_number'] !== (int) $part->number
                    || $record['advertised_bytes'] !== (int) $part->size) {
                    throw new RuntimeException('recovery_part_membership_mismatch');
                }
            }
            DB::table('parts')->where('binaries_id', $binary->id)->whereIn('partnumber', $parts->pluck('partnumber'))->delete();
        }
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update(['reconciliation_cursor' => $batch['offset']]);
        if ($batch['offset'] !== $plan->manifestBytes) {
            return false;
        }
        if (DB::table('parts')->whereIn('binaries_id', $binaries->pluck('id'))->exists()) {
            throw new RuntimeException('recovery_part_membership_mismatch');
        }
        DB::table('binaries')->whereIn('id', $binaries->pluck('id'))->where('collections_id', $publication->collections_id)->delete();

        return true;
    }
}
