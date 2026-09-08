<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Release;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Support\Utf8;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecoveryMaterialization
{
    public function __construct(
        private readonly RecoveryArtifacts $artifacts,
        private readonly HeaderStorageService $storage,
        private readonly RecoveryWork $work,
    ) {}

    public function run(RecoveryWorkClaim $claim): string
    {
        try {
            return $this->materialize($claim);
        } catch (RecoveryAdmissionPending) {
            $this->work->defer($claim);

            return 'admission_pending';
        } catch (RuntimeException $error) {
            if (preg_match('/^recovery_(?:collection|binary|part|file|manifest|publication)_/', $error->getMessage()) !== 1) {
                throw $error;
            }

            return $this->quarantine($claim, $error->getMessage());
        }
    }

    private function materialize(RecoveryWorkClaim $claim): string
    {
        $bundle = DB::transaction(fn (): ?object => (new RecoveryOwnership)->locked($claim));
        if ($bundle === null || $claim->stage !== RecoveryStage::Publish || $bundle->manifest_verified_at === null
            || $bundle->sealed_plan === null) {
            return 'obsolete';
        }
        $plan = RecoveryPlan::fromArray(json_decode($bundle->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        if (! RecoveryAdmission::allows((int) $bundle->groups_id, $plan->algorithm)) {
            throw new RecoveryAdmissionPending;
        }
        if (! (new RecoveryPublicationCoverage)->ready($bundle)) {
            $this->work->defer($claim);

            return 'publication_coverage_pending';
        }
        $result = app(RecoveryPublications::class)->register($plan, $claim);
        if ($result->outcome === 'conflict') {
            return $this->quarantine($claim, 'recovery_publication_identity_conflict');
        }
        if (! in_array($result->outcome, ['registered', 'existing'], true)) {
            return $result->outcome;
        }
        $linked = DB::transaction(function () use ($claim, $result): bool {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                return false;
            }
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update(['publication_id' => $result->id]);

            return true;
        }, 1);
        if (! $linked) {
            return 'obsolete';
        }
        $registered = DB::table('obfuscation_recovery_publications')->where('id', $result->id)->first();
        $canonical = RecoveryPlan::fromArray(json_decode($registered->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        if (($canonical->bundleId !== $claim->bundleId || $canonical->revision !== $claim->revision)
            && ! in_array($registered->state, ['published', 'absorbed', 'duplicate_policy_discarded', 'tombstoned'], true)) {
            return app(RecoveryPublicationHandoff::class)->step($claim, $result->id, $canonical, $plan);
        }
        $publication = $this->resume($claim, $result->id);
        if ($publication === null) {
            return 'obsolete';
        }
        if (in_array($publication->state, ['materialized', 'policy_blocked', 'created'], true)) {
            (new RecoveryCbpVerifier($this->artifacts))->verify($result->id, $plan);
        }
        if (! in_array($publication->state, ['registered', 'materializing'], true)) {
            return $publication->state;
        }
        $started = hrtime(true);
        $segments = [];
        $seen = 0;
        $offset = (int) $publication->materialized_parts;
        $groupId = null;
        $poster = '';
        $postTimestamp = 0;
        foreach ((new RecoveryManifest($this->artifacts))->read(new RecoveryArtifact($plan->manifestDigest, $plan->manifestBytes)) as $record) {
            if ($record['group'] !== $plan->group || $record['source_epoch'] !== $plan->sourceEpoch
                || ($groupId !== null && $record['group_id'] !== $groupId)) {
                throw new RuntimeException('recovery_manifest_scope_mismatch');
            }
            $groupId = $record['group_id'];
            if ($seen === 0) {
                $poster = mb_strcut(Utf8::clean(base64_decode($record['poster_identity'], true)), 0, 255, 'UTF-8');
                $postTimestamp = (int) strtotime($record['postdate']);
            }
            if ($seen++ < $offset) {
                continue;
            }
            $segments[] = RecoverySegment::fromRecord($record);
            if (count($segments) === 500) {
                $this->storage->storeRecovered($claim, $result->id, new RecoveryStorageBatch($plan, $groupId, $poster, $postTimestamp, $segments, $offset));
                $offset += count($segments);
                $segments = [];
                if (! $this->work->heartbeat($claim)) {
                    return 'obsolete';
                }
                if (hrtime(true) - $started > 50000000000) {
                    $this->work->defer($claim, 1);

                    return 'materializing';
                }
            }
        }
        if ($seen !== $plan->plannedParts() || $groupId === null) {
            throw new RuntimeException('recovery_manifest_count_mismatch');
        }
        if ($segments !== []) {
            $this->storage->storeRecovered($claim, $result->id, new RecoveryStorageBatch($plan, $groupId, $poster, $postTimestamp, $segments, $offset));
        }
        (new RecoveryCbpVerifier($this->artifacts))->verify($result->id, $plan);

        return DB::transaction(function () use ($claim, $result): string {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                return 'obsolete';
            }
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $result->id)->lockForUpdate()->first();
            if ($publication->state !== 'materializing' || $publication->deleted_at !== null) {
                return $publication->state;
            }
            $collection = DB::table('collections')->where('id', $publication->collections_id)->lockForUpdate()->first();
            if ($collection === null || ! RecoveryAdmission::allows((int) $collection->groups_id, RecoveryAlgorithm::from($publication->profile))) {
                throw new RecoveryAdmissionPending;
            }
            $state = 'materialized';
            $reason = null;
            $collectionValues = ['filecheck' => CollectionFileCheckStatus::Sized->value];
            if ($publication->releases_id !== null) {
                $this->pendingRelease($publication);
                $state = 'created';
                $collectionValues = ['filecheck' => CollectionFileCheckStatus::Inserted->value, 'releases_id' => $publication->releases_id];
                $reason = (new RecoveryFormationPolicy)->blockedReason($collection);
                if ($reason !== null) {
                    $state = 'policy_blocked';
                }
            }
            DB::table('collections')->where('id', $publication->collections_id)->update($collectionValues);
            DB::table('obfuscation_recovery_publications')->where('id', $result->id)->update(['state' => $state, 'reason' => $reason, 'updated_at' => now()]);
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update(['state' => 'publishing', 'updated_at' => now()]);

            return $state;
        }, 1);
    }

    private function resume(RecoveryWorkClaim $claim, int $publicationId): ?object
    {
        return DB::transaction(function () use ($claim, $publicationId): ?object {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                return null;
            }
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->lockForUpdate()->first();
            if ($publication === null || ! in_array($publication->state, ['materializing', 'materialized', 'policy_blocked', 'reconciling', 'created'], true)) {
                return $publication;
            }
            $collection = DB::table('collections')->where('id', $publication->collections_id)->lockForUpdate()->first();
            if ($publication->releases_id !== null) {
                $this->pendingRelease($publication);
            }
            if ($collection === null) {
                return $this->missingCollection($publication);
            }
            $parts = DB::table('parts')->join('binaries', 'binaries.id', '=', 'parts.binaries_id')
                ->where('binaries.collections_id', $publication->collections_id)->count();
            if ($parts < (int) $publication->materialized_parts) {
                $publication->materialized_parts = 0;
                $publication->state = 'materializing';
                DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->update([
                    'state' => 'materializing', 'materialized_parts' => 0, 'reason' => 'missing_parts_replay', 'updated_at' => now(),
                ]);
                DB::table('collections')->where('id', $collection->id)->update(['filecheck' => CollectionFileCheckStatus::Default->value]);
            }

            return $publication;
        }, 1);
    }

    private function missingCollection(object $publication): object
    {
        if (DB::table('collections')->where('collectionhash', $publication->collection_projection)->exists()
            || DB::table('releases')->where('collectionhash', $publication->collection_projection)
                ->when($publication->releases_id !== null, fn ($query) => $query->where('id', '<>', $publication->releases_id))->exists()) {
            throw new RuntimeException('recovery_collection_projection_conflict');
        }
        $binaries = DB::table('binaries')->where('collections_id', $publication->collections_id)->orderBy('id')->limit(34)->lockForUpdate()->get();
        if ($binaries->count() > 33) {
            throw new RuntimeException('recovery_binary_count_mismatch');
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $projections = [];
        foreach ($plan->files as $file) {
            $projections[bin2hex((new RecoveryIdentity)->binaryProjection($publication->identity, $file->role->value, $file->identity))] = $file;
        }
        foreach ($binaries as $binary) {
            $file = $projections[DB::getDriverName() === 'sqlite' ? $binary->binaryhash : bin2hex($binary->binaryhash)] ?? null;
            if ($file === null || $binary->name !== '"'.$file->displayName.'" yEnc' || (int) $binary->totalparts !== $file->totalParts) {
                throw new RuntimeException('recovery_binary_projection_conflict');
            }
            $ordinals = DB::table('parts')->where('binaries_id', $binary->id)->orderBy('partnumber')->limit(1000)->pluck('partnumber');
            if ($ordinals->isNotEmpty()) {
                DB::table('parts')->where('binaries_id', $binary->id)->whereIn('partnumber', $ordinals)->delete();
                $publication->state = 'reconciling';
                DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
                    'state' => 'reconciling', 'reason' => 'missing_collection_replay', 'updated_at' => now(),
                ]);

                return $publication;
            }
            DB::table('binaries')->where('id', $binary->id)->where('collections_id', $publication->collections_id)->delete();
        }
        DB::table('collection_groups')->where('collections_id', $publication->collections_id)->delete();
        $publication->collections_id = null;
        $publication->materialized_parts = 0;
        $publication->state = 'registered';
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
            'collections_id' => null, 'materialized_parts' => 0, 'state' => 'registered', 'updated_at' => now(),
        ]);

        return $publication;
    }

    private function pendingRelease(object $publication): void
    {
        $release = Release::query()->whereKey($publication->releases_id)->where('guid', $publication->guid)->lockForUpdate()->first();
        if ($release === null || $release->collectionhash !== $publication->collection_projection
            || (int) $release->nzbstatus !== NzbService::NZB_NONE || $publication->initialization_state !== 'pending') {
            throw new RuntimeException('recovery_publication_release_conflict');
        }
        $available = Release::query()->whereKey($release->id);
        NzbCreationCandidateQuery::applyClaimWindow($available, 'releases');
        if (! $available->exists()) {
            throw new RecoveryAdmissionPending;
        }
    }

    private function quarantine(RecoveryWorkClaim $claim, string $reason): string
    {
        return DB::transaction(function () use ($claim, $reason): string {
            $bundle = (new RecoveryOwnership)->locked($claim);
            if ($bundle === null) {
                return 'obsolete';
            }
            DB::table('obfuscation_recovery_publications')->where('id', $bundle->publication_id)
                ->whereNotIn('state', ['published', 'absorbed', 'duplicate_policy_discarded', 'tombstoned'])
                ->update(['state' => 'quarantined', 'reason' => $reason, 'updated_at' => now()]);
            DB::table('obfuscation_recovery_work')->where('bundle_id', $bundle->id)->whereIn('status', ['claimed', 'pending'])
                ->update(['status' => 'obsolete', 'claim_token' => null, 'claim_expires_at' => null, 'result' => 'quarantined', 'updated_at' => now()]);
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update([
                'state' => 'quarantined', 'reason' => $reason, 'updated_at' => now(),
            ]);

            return 'quarantined';
        }, 1);
    }
}
