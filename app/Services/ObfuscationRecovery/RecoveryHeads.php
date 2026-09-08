<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\ObfuscationRecoveryProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryHeads
{
    public function __construct(private readonly RecoveryHeadIndex $index, private readonly RecoveryCachedReader $reader,
        private readonly RecoveryEvidence $evidence, private readonly RecoveryWork $work) {}

    public function read(int $releaseId, string $fileId, int $neededBytes = 716800, ?RecoveryInspection $inspection = null): RecoveryHeadResult
    {
        if ($neededBytes < 1 || $neededBytes > 4194304) {
            throw new InvalidArgumentException('invalid_head_request');
        }
        $publication = (new RecoveryIdentityPolicy)->publication($releaseId);
        if ($publication === null || $publication->state !== 'published' || $publication->deleted_at !== null) {
            return new RecoveryHeadResult($fileId, 'publication_unavailable', new RecoveryCachedPrefix('', false, []));
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $file = null;
        foreach ($plan->files as $candidate) {
            if ($candidate->identity === $fileId && $candidate->role !== RecoveryFileRole::Index) {
                $file = $candidate;
            }
        }
        if ($file === null) {
            throw new InvalidArgumentException('unknown_recovered_file');
        }
        $records = $this->index->forPublication($publication);
        $maximum = $plan->algorithm === RecoveryAlgorithm::Rar || $plan->multiMediaInventory() ? 2097152 : 4194304;
        $prefix = $this->reader->readRecords($records[$fileId], $file, $maximum);
        if (strlen($prefix->data) >= $neededBytes || $prefix->complete) {
            return new RecoveryHeadResult($fileId, 'head_available', $prefix);
        }
        $config = RecoveryConfig::fromSettings();
        $groupId = DB::table('obfuscation_recovery_bundles')->where('id', $plan->bundleId)->value('groups_id');
        $selection = ObfuscationRecoveryProfile::tryFrom(DB::table('usenet_groups')->where('id', $groupId)->value('obfuscation_recovery_profile') ?? '');
        if (! $config->enabled || ! $config->enrichmentEnabled || $config->enrichmentReleaseBytes === 0 || ! $selection?->permits($plan->algorithm->selection())) {
            return new RecoveryHeadResult($fileId, 'enrichment_disabled', $prefix);
        }
        $eligible = array_values(array_filter($plan->files, static fn (RecoveryFilePlan $entry): bool => $entry->role !== RecoveryFileRole::Index));
        usort($eligible, static fn (RecoveryFilePlan $a, RecoveryFilePlan $b): int => $plan->algorithm === RecoveryAlgorithm::Rar
            ? strnatcasecmp($a->displayName, $b->displayName) : strcmp($a->identity, $b->identity));
        $eligibleIds = [];
        foreach ($eligible as $entry) {
            $head = $this->reader->readRecords($records[$entry->identity], $entry, $maximum);
            if (! $head->complete) {
                $eligibleIds[] = $entry->identity;
            }
        }
        $ordered = $records[$fileId];
        usort($ordered, function (array $a, array $b) use ($prefix): int {
            $priority = function (array $record) use ($prefix): int {
                $cached = $this->evidence->get($record['message_id'], true);

                return $cached !== null && $cached->begin <= strlen($prefix->data) + 1 && ! $cached->complete ? 0 : 1;
            };

            return $priority($a) <=> $priority($b) ?: $a['ordinal'] <=> $b['ordinal'];
        });
        $complete = [];
        foreach ($ordered as $record) {
            $complete[$record['message_id']] = $this->evidence->get($record['message_id']) !== null;
        }
        $owned = $inspection === null;
        $inspection ??= RecoveryInspection::acquire($publication);
        if ($inspection === null) {
            return new RecoveryHeadResult($fileId, 'enrichment_pending', $prefix);
        }
        try {
            $inspection->assertPublication($publication);
            $outcome = $inspection->mutate(function () use ($publication, $plan, $fileId, $eligibleIds, $ordered, $complete): string {
                $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $plan->bundleId)->lockForUpdate()->first();
                $current = DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->lockForUpdate()->first();
                if ($bundle === null || $current === null || $current->state !== 'published' || $current->deleted_at !== null
                    || (int) $bundle->revision !== $plan->revision) {
                    return 'publication_unavailable';
                }
                $config = RecoveryConfig::fromSettings();
                $selection = ObfuscationRecoveryProfile::tryFrom(DB::table('usenet_groups')->where('id', $bundle->groups_id)->value('obfuscation_recovery_profile') ?? '');
                if (! $config->enabled || ! $config->enrichmentEnabled || $config->enrichmentReleaseBytes === 0 || ! $selection?->permits($plan->algorithm->selection())) {
                    return 'enrichment_disabled';
                }
                $files = DB::table('obfuscation_recovery_files')->where('bundle_id', $plan->bundleId)->where('revision', $plan->revision);
                if (! (clone $files)->where('file_id', $fileId)->exists()) {
                    return 'unknown_recovered_file';
                }
                $selected = (clone $files)->whereNotNull('enrichment_selected_at')->pluck('file_id')->all();
                $resolved = (clone $files)->where('enrichment_outcome', 'media_evidence_available')->pluck('file_id')->all();
                $allowed = $selected;
                foreach ($eligibleIds as $eligibleId) {
                    if (count($allowed) >= 2) {
                        break;
                    }
                    if (! in_array($eligibleId, $allowed, true) && ! in_array($eligibleId, $resolved, true)) {
                        $allowed[] = $eligibleId;
                    }
                }
                if (! in_array($fileId, $allowed, true)) {
                    return 'enrichment_file_limit';
                }
                $targets = DB::table('obfuscation_recovery_targets')->where('publication_id', $publication->id);
                if ((clone $targets)->where('status', 'pending')->exists()) {
                    return 'enrichment_pending';
                }
                if ($targets->count() >= 8) {
                    return 'enrichment_limit_reached';
                }
                foreach ($ordered as $record) {
                    if ($complete[$record['message_id']] || (clone $targets)->where('message_id', $record['message_id'])->exists()) {
                        continue;
                    }
                    (clone $files)->where('file_id', $fileId)->whereNull('enrichment_selected_at')->update(['enrichment_selected_at' => now()]);
                    DB::table('obfuscation_recovery_targets')->insert([
                        'publication_id' => $publication->id, 'file_id' => $fileId, 'message_id' => $record['message_id'],
                        'request_digest' => (new RecoveryIdentity)->digest(['request', $record['message_id']]), 'ordinal' => $record['ordinal'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    (new RecoveryReferences)->retain('publication', (string) $publication->id, 'evidence', hash('sha256', $record['message_id']));
                    $this->work->enqueueForBundle(RecoveryStage::Download, $plan->bundleId, $plan->revision, 'enrichment', [
                        'publication_id' => (int) $publication->id, 'file_id' => $fileId, 'message_id' => $record['message_id'],
                    ]);
                    DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
                        'enrichment_outcome' => 'enrichment_pending', 'enrichment_next_attempt_at' => now()->addMinute(), 'updated_at' => now(),
                    ]);

                    return 'enrichment_pending';
                }

                return 'bounded_head_unavailable';
            });
        } finally {
            if ($owned) {
                $inspection->release();
            }
        }

        return new RecoveryHeadResult($fileId, $outcome, $prefix);
    }
}
