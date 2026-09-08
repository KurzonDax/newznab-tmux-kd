<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class RecoveryPublications
{
    public function __construct(private readonly RecoveryIdentity $identity) {}

    public function register(RecoveryPlan $plan, ?RecoveryWorkClaim $claim = null): RecoveryPublicationResult
    {
        $files = $plan->files;
        usort($files, static fn (RecoveryFilePlan $a, RecoveryFilePlan $b): int => strcmp($a->identity, $b->identity));
        $fileIds = [];
        $index = '';
        foreach ($files as $file) {
            if ($file->role === RecoveryFileRole::Index) {
                $index = $this->identity->messageId($file->identity);
            } else {
                $fileIds[] = hex2bin($file->identity);
            }
        }
        $identity = $this->identity->publication($plan->algorithm->selection()->value, $index, hex2bin($plan->setId), $fileIds);
        $indexIdentity = $this->identity->digest(['index', $index, $plan->setId]);
        $projection = $this->identity->collectionProjection($identity);
        $data = $plan->toArray();
        unset($data['bundle_id'], $data['revision'], $data['group'], $data['source_epoch'],
            $data['manifest_digest'], $data['manifest_bytes'], $data['evidence_ids']);
        $data['files'] = array_map(static fn (RecoveryFilePlan $file): array => $file->toArray(), $files);
        $planDigest = $this->identity->digest([json_encode($data, JSON_THROW_ON_ERROR)]);
        $guid = (string) Str::uuid();
        $previous = DB::table('obfuscation_recovery_publications')->where('index_identity', $indexIdentity)->first();
        $membershipMatches = $previous === null || $previous->deleted_at !== null || $previous->state === 'quarantined' || $previous->manifest_digest === $plan->manifestDigest
            || ($previous->plan_digest === $planDigest && $this->membershipDigest($plan) === $this->membershipDigest(
                RecoveryPlan::fromArray(json_decode($previous->sealed_plan, true, flags: JSON_THROW_ON_ERROR))));

        return DB::transaction(function () use ($plan, $identity, $index, $indexIdentity, $projection, $planDigest, $guid, $previous, $membershipMatches, $claim): RecoveryPublicationResult {
            if ($claim !== null) {
                $owner = (new RecoveryOwnership)->locked($claim);
                if ($owner === null || $claim->stage !== RecoveryStage::Publish || $owner->manifest_verified_at === null
                    || json_decode($owner->sealed_plan ?? 'null', true, flags: JSON_THROW_ON_ERROR) !== $plan->toArray()) {
                    throw new RuntimeException('obsolete_recovery_plan');
                }
            }
            DB::table('obfuscation_recovery_publications')->upsert([[
                'identity' => $identity, 'index_identity' => $indexIdentity,
                'canonical_bundle_id' => $plan->bundleId, 'canonical_revision' => $plan->revision,
                'index_message_id' => $index, 'set_id' => $plan->setId, 'plan_digest' => $planDigest,
                'collection_projection' => $projection, 'profile' => $plan->algorithm->value,
                'group_name' => $plan->group, 'source_epoch' => $plan->sourceEpoch, 'guid' => $guid,
                'ordering_mode' => $plan->orderingMode(), 'inventory_scope' => $plan->inventoryScope(),
                'protected_files' => $plan->protectedFiles(), 'planned_files' => $plan->plannedFiles(),
                'planned_parts' => $plan->plannedParts(), 'sealed_plan' => json_encode($plan->toArray(), JSON_THROW_ON_ERROR),
                'manifest_digest' => $plan->manifestDigest, 'multi_media_inventory' => $plan->multiMediaInventory(),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['index_identity'], ['updated_at']);
            $row = DB::table('obfuscation_recovery_publications')
                ->where('index_identity', $indexIdentity)->orWhere('identity', $identity)
                ->orWhere('collection_projection', $projection)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('publication_registration_failed');
            }
            if ($row->deleted_at !== null) {
                return new RecoveryPublicationResult((int) $row->id, $row->identity, 'tombstoned');
            }
            if ($row->identity === $identity && $row->plan_digest === $planDigest
                && (($previous === null && $row->guid !== $guid && $row->manifest_digest !== $plan->manifestDigest)
                    || ($previous !== null && $row->manifest_digest !== $previous->manifest_digest))) {
                return new RecoveryPublicationResult((int) $row->id, $row->identity, 'reconcile_pending');
            }
            if ($row->identity !== $identity || $row->plan_digest !== $planDigest || ! $membershipMatches
                || $row->index_message_id !== $index || $row->set_id !== $plan->setId || $row->state === 'quarantined') {
                DB::table('obfuscation_recovery_publications')->where('id', $row->id)
                    ->update(['state' => 'quarantined', 'reason' => 'publication_identity_conflict', 'updated_at' => now()]);

                return new RecoveryPublicationResult((int) $row->id, $row->identity, 'conflict');
            }

            (new RecoveryReferences)->plan('publication', (int) $row->id, RecoveryPlan::fromArray(json_decode($row->sealed_plan, true, flags: JSON_THROW_ON_ERROR)));
            $retainedPlan = RecoveryPlan::fromArray(json_decode($row->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
            (new RecoveryReferences)->release('bundle', (string) $retainedPlan->bundleId);
            if ($claim !== null && $retainedPlan->bundleId !== $claim->bundleId
                && in_array($row->state, ['published', 'absorbed', 'duplicate_policy_discarded'], true)) {
                (new RecoveryReferences)->release('bundle', (string) $claim->bundleId);
                DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
                    'reason' => 'equivalent_publication_retained', 'updated_at' => now(),
                ]);
            }

            if ($claim !== null && $row->manifest_digest === $plan->manifestDigest
                && in_array($row->state, ['registered', 'materializing', 'materialized', 'policy_blocked', 'reconciling', 'created'], true)) {
                $oldPlan = RecoveryPlan::fromArray(json_decode($row->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
                if ($oldPlan->bundleId === $plan->bundleId && $oldPlan->revision < $plan->revision) {
                    DB::table('obfuscation_recovery_publications')->where('id', $row->id)->update([
                        'sealed_plan' => json_encode($plan->toArray(), JSON_THROW_ON_ERROR), 'canonical_revision' => $plan->revision, 'updated_at' => now(),
                    ]);
                }
            }

            return new RecoveryPublicationResult((int) $row->id, $identity, $row->guid === $guid ? 'registered' : 'existing');
        }, 1);
    }

    private function membershipDigest(RecoveryPlan $plan): string
    {
        $hash = hash_init('sha256');
        $files = [];
        foreach ($plan->files as $file) {
            $files[$file->identity] = ['file' => $file, 'count' => 0];
        }
        $manifest = new RecoveryManifest(app(RecoveryArtifacts::class));
        foreach ($manifest->read(new RecoveryArtifact($plan->manifestDigest, $plan->manifestBytes)) as $record) {
            if (! isset($files[$record['file']]) || $record['ordinal'] !== ++$files[$record['file']]['count']
                || $record['group'] !== $plan->group || $record['source_epoch'] !== $plan->sourceEpoch) {
                throw new RuntimeException('publication_manifest_conflict');
            }
            hash_update($hash, $this->identity->digest([$record['file'], (string) $record['ordinal'],
                $record['message_id'], (string) $record['advertised_bytes']]));
        }
        foreach ($files as $entry) {
            if ($entry['count'] !== $entry['file']->totalParts) {
                throw new RuntimeException('publication_manifest_incomplete');
            }
        }

        return hash_final($hash);
    }

    public static function tombstoneRelease(int $releaseId, string $guid): void
    {
        if (! Schema::hasTable('obfuscation_recovery_publications')) {
            return;
        }
        DB::table('obfuscation_recovery_publications')->where('releases_id', $releaseId)->where('guid', $guid)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'state' => DB::raw("CASE WHEN state IN ('absorbed', 'duplicate_policy_discarded') THEN state ELSE 'tombstoned' END"), 'updated_at' => now()]);
    }

    public function tombstone(int $publicationId): void
    {
        DB::table('obfuscation_recovery_publications')->where('id', $publicationId)
            ->whereNull('deleted_at')->update(['deleted_at' => now(), 'state' => 'tombstoned', 'updated_at' => now()]);
    }
}
