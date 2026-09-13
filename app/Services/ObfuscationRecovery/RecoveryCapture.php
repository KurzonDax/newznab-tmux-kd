<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Services\Binaries\HeaderStorageReport;
use App\Services\BlacklistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class RecoveryCapture
{
    public function __construct(private readonly RecoveryConfig $config, private readonly BlacklistService $policy) {}

    public function recordOrdinary(?RecoveryScanContext $context, HeaderStorageReport $report, int $submitted): void
    {
        if ($context === null) {
            return;
        }
        $connection = null;
        try {
            $connection = RecoveryConnection::open();
            $outcome = match (true) {
                $report->rolledBackChunks > 0 => 'rolled_back',
                $report->failedNumbers !== [] => 'stored_with_repair',
                $report->recoveredChunks > 0 => 'stored_on_retry',
                $submitted === 0 => 'filtered_noop',
                default => 'stored',
            };
            $connection->table('obfuscation_recovery_scans')->where('scan_id', $context->scanId)->where('chunk_ordinal', $context->chunkOrdinal)
                ->update(['ordinary_outcome' => $outcome, 'ordinary_report' => json_encode([
                    'submitted' => $submitted, 'repair_articles' => count($report->uniqueFailedNumbers()),
                    'unresolved' => $report->unresolvedHeaders, 'rejected' => $report->rejectedHeaders,
                    'rolled_back_chunks' => $report->rolledBackChunks, 'recovered_chunks' => $report->recoveredChunks,
                ], JSON_THROW_ON_ERROR)]);
        } catch (\Throwable) {
            Log::warning('Recovery storage accounting is unavailable; ordinary scanning continues.', ['group_id' => $context->groupId]);
        } finally {
            RecoveryConnection::close($connection);
        }
    }

    public function capture(RecoveryCaptureBatch $batch, RecoveryScanContext $context, ?RecoveryWorkClaim $claim = null): RecoveryCaptureReport
    {
        if (! $this->config->enabled) {
            return new RecoveryCaptureReport('disabled');
        }
        $connection = null;
        try {
            $selection = DB::table('usenet_groups')->where('id', $context->groupId)->value('obfuscation_recovery_profile');
            if (! $this->config->admits($selection, RecoveryAlgorithm::Media->selection())
                && ! $this->config->admits($selection, RecoveryAlgorithm::Rar->selection())) {
                return new RecoveryCaptureReport('disabled');
            }
            $selector = new RecoveryHeaderSelector(new RecoveryIdentity);
            $rows = $exclusions = [];
            foreach (RecoveryAlgorithm::cases() as $algorithm) {
                if (! $this->config->admits($selection, $algorithm->selection())) {
                    continue;
                }
                $headers = $algorithm === RecoveryAlgorithm::Media ? $batch->acceptedHeaders : $batch->rawHeaders;
                foreach ($headers as $header) {
                    try {
                        $row = $selector->select($header, $algorithm, $context);
                        if ($row === null) {
                            $exclusions['profile_excluded'] = ($exclusions['profile_excluded'] ?? 0) + 1;

                            continue;
                        }
                        if ($algorithm === RecoveryAlgorithm::Rar && $this->policy->isBlackListed($header, $context->groupName)) {
                            $exclusions['policy_excluded'] = ($exclusions['policy_excluded'] ?? 0) + 1;

                            continue;
                        }
                        $key = $row['message_id_digest'];
                        if (isset($rows[$key]) && $rows[$key] !== $row) {
                            $rows[$key]['metadata_conflict'] = true;
                        } else {
                            $rows[$key] = $row;
                        }
                    } catch (InvalidArgumentException) {
                        $exclusions['invalid_header'] = ($exclusions['invalid_header'] ?? 0) + 1;
                    }
                }
            }
            $ids = $this->policy->getAndClearIdsToUpdate();
            $coverage = RecoveryCoverage::overview($batch->rawHeaders, $context->first, $context->last);
            $connection = RecoveryConnection::open();

            return $connection->transaction(function () use ($connection, $rows, $exclusions, $context, $coverage, $claim, $ids): RecoveryCaptureReport {
                $this->policy->updateBlacklistUsage($ids, $connection);
                $operation = bin2hex(random_bytes(16));
                $now = now();
                $sourceQuery = $connection->table('obfuscation_recovery_controls')->where('scope', 'primary');
                $source = $claim?->purpose === RecoveryFrontierRebuild::PURPOSE ? $sourceQuery->sharedLock()->first() : $sourceQuery->first();
                $control = $connection->table('obfuscation_recovery_controls')->where('scope', 'group:'.$context->groupId)->lockForUpdate()->first();
                if (($source !== null && $source->epoch !== $context->sourceEpoch)
                    || ($control !== null && (int) $control->generation !== $context->generation)) {
                    throw new InvalidArgumentException('obsolete_capture_context');
                }
                $frontierTargets = null;
                $frontier = $claim?->purpose === RecoveryFrontierRebuild::PURPOSE;
                if ($claim === null) {
                    RecoveryScanWindow::record($connection, $context, $this->config);
                } else {
                    $owner = $connection->table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->lockForUpdate()->first();
                    $owned = $connection->table('obfuscation_recovery_work')->where('id', $claim->id)->where('claim_token', $claim->token)
                        ->where('status', 'claimed')->where('revision', $claim->revision)->where('claim_expires_at', '>', now())->exists();
                    if (! $owned || $owner === null || (int) $owner->revision !== $claim->revision
                        || $owner->kind !== ($frontier ? 'frontier' : 'gap') || ! in_array($claim->purpose, ['gap', RecoveryFrontierRebuild::PURPOSE], true)
                        || (int) $owner->groups_id !== $context->groupId || $owner->source_epoch !== $context->sourceEpoch
                        || (int) $owner->capture_generation !== $context->generation
                        || ($claim->payload['first'] ?? null) !== $context->first || ($claim->payload['last'] ?? null) !== $context->last
                        || in_array($owner->state, RecoveryOwnership::INACTIVE_STATES, true)) {
                        throw new InvalidArgumentException('obsolete_capture_claim');
                    }
                    if ($frontier) {
                        $frontierTargets = (new RecoveryFrontierTargets)->authorized($connection, $owner, $claim->payload, true);
                        if ($frontierTargets === null) {
                            throw new InvalidArgumentException('obsolete_frontier_target');
                        }
                    }
                }
                $digest = (new RecoveryIdentity)->digest(array_map(strval(...), [
                    $context->sourceEpoch, $context->groupId, $context->generation, $context->first, $context->last,
                    $context->direction->name, $context->expectedChunks,
                ]));
                $connection->table('obfuscation_recovery_scan_batches')->upsert([
                    'scan_id' => $context->scanId, 'context_digest' => $digest, 'created_at' => $now,
                ], ['scan_id'], ['scan_id']);
                $batch = $connection->table('obfuscation_recovery_scan_batches')->where('scan_id', $context->scanId)->lockForUpdate()->first();
                if ($batch->context_digest !== $digest) {
                    throw new InvalidArgumentException('conflicting_scan_context');
                }
                (new RecoveryFrontierMembers)->invalidateRaw($connection, $context, $coverage);
                $captured = $duplicates = 0;
                $dirty = $membershipChanges = [];
                ksort($rows, SORT_STRING);
                foreach (array_chunk($rows, 250, true) as $chunk) {
                    $expired = $connection->table('obfuscation_recovery_expired_headers')->where('source_epoch', $context->sourceEpoch)
                        ->where('groups_id', $context->groupId)->whereIn('message_id_digest', array_keys($chunk))->pluck('message_id_digest')->all();
                    foreach ($expired as $digest) {
                        unset($chunk[$digest]);
                        $exclusions['retention_expired'] = ($exclusions['retention_expired'] ?? 0) + 1;
                    }
                    if ($chunk === []) {
                        continue;
                    }

                    $previousGenerations = $connection->table('obfuscation_recovery_headers')->where('source_epoch', $context->sourceEpoch)
                        ->where('groups_id', $context->groupId)->whereIn('message_id_digest', array_keys($chunk))
                        ->pluck('capture_generation', 'message_id_digest');
                    $insert = [];
                    foreach ($chunk as $row) {
                        $insert[] = $row + ['capture_token' => $operation, 'first_observed_at' => $now, 'last_observed_at' => $now, 'metadata_conflict' => false];
                    }
                    $connection->table('obfuscation_recovery_headers')->upsert($insert,
                        ['source_epoch', 'groups_id', 'message_id_digest'], $frontier ? ['last_observed_at'] : ['last_observed_at', 'capture_generation']);
                    $stored = $connection->table('obfuscation_recovery_headers')->where('source_epoch', $context->sourceEpoch)
                        ->where('groups_id', $context->groupId)->whereIn('message_id_digest', array_keys($chunk))->get();
                    foreach ($stored as $record) {
                        $incoming = $chunk[$record->message_id_digest];
                        $conflict = (bool) ($incoming['metadata_conflict'] ?? false);
                        foreach (['message_id', 'article_number', 'raw_subject', 'parsed_name', 'poster_identity', 'source_date', 'advertised_bytes', 'original_part', 'advertised_total'] as $field) {
                            if ((string) $record->{$field} !== (string) $incoming[$field]) {
                                $conflict = true;
                            }
                        }
                        if ($conflict) {
                            $connection->table('obfuscation_recovery_headers')->where('id', $record->id)->update(['metadata_conflict' => true]);
                        }
                        if ($record->capture_token === $operation) {
                            $captured++;
                            $dirty[] = $incoming;
                            $membershipChanges[] = $incoming;
                        } else {
                            $duplicates++;
                            if (($conflict && ! $record->metadata_conflict) || (! $frontier && (int) ($previousGenerations[$record->message_id_digest] ?? 0) !== $context->generation)) {
                                $dirty[] = $incoming;
                            }
                            if ($conflict && ! $record->metadata_conflict) {
                                $membershipChanges[] = $incoming;
                            }
                        }
                    }
                }
                RecoveryLateCapture::invalidate($connection, $dirty);
                foreach ($frontierTargets ?? [] as $target) {
                    if ((int) $target->capture_generation !== $context->generation) {
                        RecoveryLateCapture::invalidate($connection, array_map(static fn (array $row): array => [
                            ...$row, 'capture_generation' => (int) $target->capture_generation,
                        ], $membershipChanges));
                    }
                }
                RecoveryDirty::mark($connection, $dirty);
                $connection->table('obfuscation_recovery_scans')->insertOrIgnore([
                    'scan_id' => $context->scanId, 'groups_id' => $context->groupId, 'source_epoch' => $context->sourceEpoch,
                    'capture_generation' => $context->generation, 'requested_first' => $context->first, 'requested_last' => $context->last,
                    'chunk_ordinal' => $context->chunkOrdinal, 'expected_chunks' => $context->expectedChunks,
                    'direction' => $context->direction->name, 'capture_outcome' => isset($exclusions['retention_expired']) ? 'raw_expired' : 'captured',
                    'returned_ranges' => json_encode($coverage['returned'], JSON_THROW_ON_ERROR),
                    'date_points' => json_encode($coverage['date_points'], JSON_THROW_ON_ERROR),
                    'date_conflicts' => json_encode($coverage['date_conflicts'], JSON_THROW_ON_ERROR),
                    'invalid_date_articles' => json_encode($coverage['invalid_date_articles'], JSON_THROW_ON_ERROR),
                    'evidence_version' => RecoveryFrontiers::VERSION,
                    'first_postdate' => $coverage['first_postdate'], 'last_postdate' => $coverage['last_postdate'],
                    'earliest_date_article' => $coverage['earliest_date_article'], 'latest_date_article' => $coverage['latest_date_article'],
                    'date_order_consistent' => $coverage['date_order_consistent'],
                    'created_at' => $now,
                ]);
                $chunks = $connection->table('obfuscation_recovery_scans')->where('scan_id', $context->scanId)->orderBy('chunk_ordinal')->get();
                foreach ($chunks as $chunk) {
                    if ((int) $chunk->chunk_ordinal >= $context->expectedChunks || (int) $chunk->expected_chunks !== $context->expectedChunks
                        || (int) $chunk->requested_first !== $context->first || (int) $chunk->requested_last !== $context->last
                        || (int) $chunk->groups_id !== $context->groupId || $chunk->source_epoch !== $context->sourceEpoch
                        || (int) $chunk->capture_generation !== $context->generation || $chunk->direction !== $context->direction->name) {
                        throw new InvalidArgumentException('conflicting_scan_context');
                    }
                }
                $retainedRepair = $frontierTargets !== null && collect($frontierTargets)->every(static fn (object $target): bool => $target->plan_digest !== null);
                $complete = $chunks->count() === $context->expectedChunks && $chunks->every(static fn (object $chunk): bool => ($chunk->capture_outcome === 'captured' || $retainedRepair) && ($chunk->date_points !== null || $chunk->complete));
                if ($complete) {
                    if ($frontier) {
                        $frontierTargets = (new RecoveryFrontierTargets)->authorized($connection, $owner, $claim->payload, true);
                        if ($frontierTargets === null) {
                            return new RecoveryCaptureReport('frontier_membership_changed', $captured, $duplicates, false, $exclusions);
                        }
                    }
                    if ($frontierTargets !== null && ! (new RecoveryFrontierMembers)->validate($connection, (new RecoveryFrontiers)->combine($chunks), $frontierTargets)) {
                        return new RecoveryCaptureReport('frontier_membership_changed', $captured, $duplicates, false, $exclusions);
                    }
                    if (! isset($exclusions['retention_expired'])) {
                        (new RecoveryPositiveCoverage)->record($connection, $context);
                    }
                    (new RecoveryFrontiers)->recordBatch($connection, $chunks);
                    if ($frontierTargets !== null) {
                        (new RecoveryFrontierTargets)->install($connection, $chunks, $frontierTargets);
                    }
                    $returned = [];
                    foreach ($chunks as $chunk) {
                        $returned = [...$returned, ...json_decode($chunk->returned_ranges, true, flags: JSON_THROW_ON_ERROR)];
                    }
                    $missing = RecoveryCoverage::holes($context->first, $context->last, $returned);
                    $connection->table('obfuscation_recovery_scans')->where('scan_id', $context->scanId)
                        ->update(['complete' => true, 'date_points' => null, 'date_conflicts' => null, 'invalid_date_articles' => null, 'coverage_verified_at' => now(), 'missing_ranges' => json_encode($missing, JSON_THROW_ON_ERROR)]);
                }

                return new RecoveryCaptureReport('captured', $captured, $duplicates, $complete, $exclusions);
            }, 1);
        } catch (\Throwable) {
            Log::warning('Recovery capture storage is unavailable; coverage remains unknown.', ['group_id' => $context->groupId]);

            return new RecoveryCaptureReport('capture_failed');
        } finally {
            RecoveryConnection::close($connection);
        }
    }
}
