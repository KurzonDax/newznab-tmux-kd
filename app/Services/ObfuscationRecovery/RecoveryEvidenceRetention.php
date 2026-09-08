<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class RecoveryEvidenceRetention
{
    public function __construct(private readonly RecoveryArtifacts $artifacts) {}

    /** @return array{owners:int,evidence:int,artifacts:int} */
    public function step(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('invalid_evidence_retention_batch');
        }
        $cutoff = now()->subDays(RecoveryCompaction::DETAIL_DAYS);
        $report = ['owners' => 0, 'evidence' => 0, 'artifacts' => 0];
        $partialPrepared = false;
        foreach (['bundle', 'publication'] as $type) {
            $table = 'obfuscation_recovery_'.($type === 'bundle' ? 'bundles' : 'publications');
            foreach ($this->page($table, 'id', $type, $limit) as $id) {
                $batch = null;
                if ($type === 'publication' && ! $partialPrepared) {
                    $snapshot = DB::table($table)->where('id', $id)->first();
                    if ($snapshot !== null && $snapshot->state === 'quarantined' && $snapshot->releases_id === null
                        && $snapshot->collections_id !== null && $snapshot->detail_retired_at === null && $snapshot->updated_at <= $cutoff) {
                        $partialPrepared = true;
                        try {
                            $batch = app(RecoveryPublicationHandoff::class)->batch($snapshot);
                        } catch (\RuntimeException|InvalidArgumentException) {
                            DB::table($table)->where('id', $id)->update(['cleanup_outcome' => 'evidence_unavailable']);
                        }
                    }
                }
                try {
                    $report['owners'] += DB::transaction(function () use ($type, $table, $id, $cutoff, $batch): int {
                        $owner = null;
                        $canonical = null;
                        if ($type === 'publication') {
                            $canonical = DB::table($table)->where('id', $id)->value('canonical_bundle_id');
                            $owner = DB::table('obfuscation_recovery_bundles')->where('id', $canonical)->lockForUpdate()->first();
                        }
                        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
                        if ($row === null || $row->detail_retired_at !== null) {
                            return 0;
                        }
                        if ($type === 'publication') {
                            if ($row->canonical_bundle_id !== $canonical) {
                                return 0;
                            }
                            if ($row->deleted_at === null && $row->releases_id !== null
                                && in_array($row->state, ['published', 'absorbed', 'duplicate_policy_discarded'], true)
                                && Schema::hasTable('releases') && ! DB::table('releases')->where('id', $row->releases_id)->where('guid', $row->guid)->exists()) {
                                DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);

                                return 0;
                            }
                            $terminal = $row->state === 'quarantined' && $row->releases_id === null && $row->updated_at <= $cutoff;
                            if (! $terminal && ($row->deleted_at === null || $row->deleted_at > $cutoff)) {
                                return 0;
                            }
                            if ($terminal) {
                                if ($owner !== null && (DB::table('obfuscation_recovery_work')->where('bundle_id', $owner->id)
                                    ->where('status', 'claimed')->where('claim_expires_at', '>', now())->exists()
                                    || ($owner->claim_token !== null && $owner->claim_expires_at !== null && $owner->claim_expires_at > now()))) {
                                    return 0;
                                }
                                if ($row->collections_id !== null && ($batch === null || ! app(RecoveryPublicationHandoff::class)->retire($row, $batch))) {
                                    return 0;
                                }
                            }
                        } else {
                            $publication = $row->publication_id === null ? null : DB::table('obfuscation_recovery_publications')->where('id', $row->publication_id)->first();
                            $terminal = in_array($row->state, RecoveryOwnership::INACTIVE_STATES, true)
                                || $row->reason === 'equivalent_publication_retained'
                                || ($publication !== null && $publication->deleted_at !== null);
                            if (! $terminal) {
                                return 0;
                            }
                            if ($row->inactive_since === null) {
                                $row->inactive_since = $publication->deleted_at ?? $row->updated_at;
                                DB::table($table)->where('id', $id)->update(['inactive_since' => $row->inactive_since]);
                            }
                            if ($row->inactive_since > $cutoff) {
                                return 0;
                            }
                        }
                        if ($type === 'bundle' && DB::table('obfuscation_recovery_work')->where('bundle_id', $id)->where('status', 'claimed')->exists()) {
                            return 0;
                        }
                        if ($type === 'bundle') {
                            $retained = DB::table('obfuscation_recovery_publications')->where('canonical_bundle_id', $id)
                                ->whereNull('detail_retired_at')->exists();
                            if ($retained) {
                                return 0;
                            }
                            $files = DB::table('obfuscation_recovery_files')->where('bundle_id', $id)->whereNull('detail_retired_at')
                                ->orderBy('id')->limit(100)->pluck('id');
                            DB::table('obfuscation_recovery_files')->whereIn('id', $files)->update([
                                'source_filename' => null, 'display_filename' => null, 'anchor_evidence' => null, 'terminal_evidence' => null,
                                'contained_observations' => null, 'identity_evidence' => null, 'media_evidence' => null, 'detail_retired_at' => now(),
                            ]);
                            if ($files->count() === 100) {
                                return 0;
                            }
                        }
                        (new RecoveryReferences)->release($type, (string) $id);
                        $detail = $type === 'bundle'
                            ? ['sealed_plan' => null, 'inventory' => null, 'candidate_runs' => null, 'coverage_evidence' => null, 'manifest_verified_at' => null]
                            : ['sealed_plan' => '{}', 'head_membership' => null];
                        DB::table($table)->where('id', $id)->update(['detail_retired_at' => now(), ...$detail]);

                        return 1;
                    }, 1);
                } catch (\RuntimeException $error) {
                    if ($type !== 'publication' || preg_match('/^recovery_(?:collection|binary|part|file|manifest)_/', $error->getMessage()) !== 1) {
                        throw $error;
                    }
                    DB::table($table)->where('id', $id)->update(['cleanup_outcome' => 'ownership_conflict']);
                }
            }
        }
        $evidence = $this->page('obfuscation_recovery_evidence', 'message_id_digest', 'evidence', $limit);
        foreach ($evidence as $digest) {
            $report['evidence'] += DB::transaction(function () use ($digest, $cutoff): int {
                $row = DB::table('obfuscation_recovery_evidence')->where('message_id_digest', $digest)->lockForUpdate()->first();
                if ($row === null || ($row->prefix_evidence === null && $row->full_evidence === null)
                    || $row->updated_at > $cutoff || $this->referenced('evidence', $digest)) {
                    return 0;
                }
                (new RecoveryReferences)->release('evidence', $digest);
                DB::table('obfuscation_recovery_evidence')->where('message_id_digest', $digest)->update([
                    'prefix_evidence' => null, 'full_evidence' => null, 'state' => $row->state === 'conflict' ? 'conflict' : 'evicted',
                    'updated_at' => now(),
                ]);

                return 1;
            }, 1);
        }
        $artifacts = $this->page('obfuscation_recovery_artifacts', 'digest', 'artifacts', $limit);
        foreach ($artifacts as $digest) {
            $report['artifacts'] += DB::transaction(function () use ($digest, $cutoff): int {
                $row = DB::table('obfuscation_recovery_artifacts')->where('digest', $digest)->lockForUpdate()->first();
                if ($row === null || $row->retained_at > $cutoff || $this->referenced('artifact', $digest)
                    || ! $this->artifacts->remove(new RecoveryArtifact($digest, (int) $row->bytes))) {
                    return 0;
                }
                DB::table('obfuscation_recovery_artifacts')->where('digest', $digest)->delete();

                return 1;
            }, 1);
        }

        $report['artifacts'] += $this->artifacts->collectOrphans($limit);

        return $report;
    }

    /** @return list<string> */
    private function page(string $table, string $column, string $scope, int $limit): array
    {
        return DB::transaction(function () use ($table, $column, $scope, $limit): array {
            DB::table('obfuscation_recovery_housekeeping')->insertOrIgnore(['scope' => $scope]);
            $cursor = DB::table('obfuscation_recovery_housekeeping')->where('scope', $scope)->lockForUpdate()->first();
            $keys = DB::table($table)->when($cursor->after_key !== null, fn ($query) => $query->where($column, '>', $cursor->after_key))
                ->orderBy($column)->limit($limit)->pluck($column)->map(strval(...))->all();
            DB::table('obfuscation_recovery_housekeeping')->where('scope', $scope)->update([
                'after_key' => count($keys) === $limit ? $keys[array_key_last($keys)] : null,
            ]);

            return $keys;
        }, 1);
    }

    private function referenced(string $type, string $digest): bool
    {
        return DB::table('obfuscation_recovery_references')->where('resource_type', $type)->where('resource_digest', $digest)->exists();
    }
}
