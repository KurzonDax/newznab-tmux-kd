<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryPreparation
{
    public function __construct(private readonly RecoveryEvidence $evidence, private readonly RecoveryArtifacts $artifacts,
        private readonly RecoveryWork $work) {}

    public function run(RecoveryWorkClaim $claim): string
    {
        if ($claim->stage !== RecoveryStage::Discover) {
            return 'obsolete';
        }
        try {
            [$bundle, $runs] = $this->snapshot($claim);
            $algorithm = RecoveryAlgorithm::from($bundle->profile);
            $config = RecoveryConfig::fromSettings();
            $group = DB::table('usenet_groups')->where('id', $bundle->groups_id)->first();
            if ($group === null || ! $config->admits($group->obfuscation_recovery_profile, $algorithm->selection())) {
                return $this->waiting($claim, 'admission_pending');
            }
            $settled = (new RecoverySettlement)->assess($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation,
                min(array_column($runs, 'first_article')), max(array_column($runs, 'last_article')),
                min(array_column($runs, 'first_postdate')), max(array_column($runs, 'last_postdate')), $bundle->membership_changed_at, false, $bundle);
            if ($settled !== 'ready') {
                return $this->waiting($claim, $settled);
            }
            $nomination = null;
            if ($algorithm === RecoveryAlgorithm::Media) {
                $indexId = (new RecoveryAssociation)->mediaIndex($runs);
            } else {
                $nomination = (new RecoveryRarNomination)->nominate(fn (): Generator => $this->rows($bundle, $runs[0]));
                $indexId = $nomination['index_message_id'];
            }
            $targets = new RecoveryConstructionTargets;
            $targets->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => $indexId]]);
            $index = $this->evidence->get($indexId);
            if ($index === null) {
                $this->queue($claim, $indexId, 'index');

                return $this->waiting($claim, 'awaiting_index');
            }
            if (! $index->complete || $index->part !== 1 || $index->total !== 1 || $index->begin !== 1
                || $index->end !== $index->fileSize || $index->fileSize !== strlen($index->data)) {
                throw new InvalidArgumentException('invalid_index_declaration');
            }
            $inventory = (new RecoveryPar2)->parse($index->data);
            $this->inventory($claim, $inventory, $indexId);
            if ($algorithm === RecoveryAlgorithm::Media) {
                try {
                    $files = (new RecoveryAssociation)->mediaPreflight($inventory, $runs, fn (array $run): array => $this->headers($bundle, $run)->where('embedded_timestamp_ms', $run['start_ms'])->orderBy('message_id')->limit(9)
                        ->get(['message_id', 'embedded_timestamp_ms'])->map(static fn (object $row): array => ['message_id' => $row->message_id, 'embedded_timestamp_ms' => (int) $row->embedded_timestamp_ms])->all());
                } catch (InvalidArgumentException $exception) {
                    if ($exception->getMessage() === 'ambiguous_media_membership') {
                        return $this->waiting($claim, 'ambiguous_media_membership');
                    }
                    throw $exception;
                }
            } else {
                $blocks = (new RecoveryRarBlocks)->read($this->rows($bundle, $runs[0]), $nomination['blocks']);
                $files = (new RecoveryRarAssociation)->preflight($inventory, $nomination['block_length'], $nomination['train_length'],
                    $nomination['index_ordinal'] - 1, $indexId, $blocks);
                foreach ($files as &$file) {
                    $file['run'] = $runs[0];
                }
                unset($file);
            }
            $requests = [];
            foreach ($files as $file) {
                foreach ($file['targets'] as $id) {
                    $requests[] = ['kind' => 'anchor', 'file_id' => bin2hex($file['file']->id), 'message_id' => $id];
                }
                if (isset($file['terminal_target']) && ! in_array($file['terminal_target'], $file['targets'], true)) {
                    $requests[] = ['kind' => 'terminal', 'file_id' => bin2hex($file['file']->id), 'message_id' => $file['terminal_target']];
                }
            }
            $targets->register($claim, $requests);
            $evidence = [];
            $missing = false;
            foreach ($requests as $request) {
                $id = $request['message_id'];
                $article = $this->evidence->get($id, true);
                if ($article !== null && ($request['kind'] === 'terminal' || $article->complete || strlen($article->data) >= 16384)) {
                    $evidence[$id] = $article;
                } else {
                    $missing = true;
                    $this->queue($claim, $id, $request['kind']);
                }
            }
            if ($missing) {
                return $this->waiting($claim, 'awaiting_anchors');
            }
            if ($algorithm === RecoveryAlgorithm::Rar) {
                $files = (new RecoveryRarAssociation)->verify($files, $evidence);
            } else {
                foreach ($files as &$file) {
                    $file['anchor'] = (new RecoveryAssociation)->anchor($file['file'], $file['targets'], $evidence, $file['declared_format']);
                }
                unset($file);
            }

            return $this->seal($claim, $bundle, $group->name, $inventory, $files, $indexId, $index);
        } catch (InvalidArgumentException $exception) {
            if (in_array($exception->getMessage(), ['obsolete_work_revision', 'dirty_candidate_snapshot'], true)) {
                return $this->waiting($claim, $exception->getMessage());
            }

            return DB::transaction(function () use ($claim, $exception): string {
                if ((new RecoveryOwnership)->locked($claim) === null) {
                    return 'obsolete';
                }
                $state = $exception->getMessage() === 'construction_limit_reached' ? 'construction_limit_reached' : 'unsupported';
                $this->work->complete($claim, $state);
                DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
                    'state' => $state, 'reason' => $exception->getMessage(), 'updated_at' => now(),
                ]);

                return $exception->getMessage();
            }, 1);
        }
    }

    /** @return array{object,list<array<string,mixed>>} */
    private function snapshot(RecoveryWorkClaim $claim): array
    {
        $candidate = DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->first();
        if ($candidate === null) {
            throw new InvalidArgumentException('obsolete_work_revision');
        }

        return DB::transaction(function () use ($claim, $candidate): array {
            DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$candidate->groups_id)->lockForUpdate()->first();
            $bundle = (new RecoveryOwnership)->locked($claim);
            if ($bundle === null) {
                throw new InvalidArgumentException('obsolete_work_revision');
            }
            $ids = json_decode($bundle->candidate_runs ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            if (count($ids) < 1 || count($ids) > 256) {
                throw new InvalidArgumentException('invalid_candidate_snapshot');
            }
            $rows = DB::table('obfuscation_recovery_runs')->whereIn('id', $ids)->where('active', true)
                ->where('source_epoch', $bundle->source_epoch)->where('groups_id', $bundle->groups_id)
                ->where('capture_generation', $bundle->capture_generation)->where('profile', $bundle->profile)
                ->orderBy('start_ms')->orderBy('id')->get();
            $digest = (new RecoveryIdentity)->digest($rows->map(static fn (object $run): string => $run->scope_digest.$run->membership_digest)->all());
            $dirty = DB::table('obfuscation_recovery_dirty')->where('source_epoch', $bundle->source_epoch)->where('groups_id', $bundle->groups_id)
                ->where('capture_generation', $bundle->capture_generation)->where('profile', $bundle->profile)
                ->where('first_ms', '<=', (int) $bundle->end_ms + 30000)->where('last_ms', '>=', max(0, (int) $bundle->start_ms - 30000));
            if ($bundle->profile === RecoveryAlgorithm::Rar->value) {
                $dirty->where('partition_value', $bundle->key_digest);
            }
            if ($rows->count() !== count($ids) || $bundle->snapshot_digest !== $digest || $dirty->exists()) {
                throw new InvalidArgumentException('dirty_candidate_snapshot');
            }

            return [$bundle, $rows->map(static fn (object $row): array => json_decode($row->summary, true, flags: JSON_THROW_ON_ERROR))->all()];
        }, 1);
    }

    private function waiting(RecoveryWorkClaim $claim, string $reason): string
    {
        return DB::transaction(function () use ($claim, $reason): string {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                return 'obsolete';
            }
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
                'reason' => $reason, 'next_action_at' => now()->addMinute(), 'updated_at' => now(),
            ]);
            $this->work->defer($claim);

            return $reason;
        }, 1);
    }

    private function queue(RecoveryWorkClaim $claim, string $id, string $kind): void
    {
        $workId = $this->work->enqueueForBundle(RecoveryStage::Download, $claim->bundleId, $claim->revision, $kind, ['message_id' => $id]);
        $completed = DB::table('obfuscation_recovery_work')->where('id', $workId)->where('status', 'completed')->first();
        if ($completed !== null) {
            throw new InvalidArgumentException($completed->result === 'construction_limit_reached' ? 'construction_limit_reached' : 'required_evidence_unavailable');
        }
    }

    private function inventory(RecoveryWorkClaim $claim, RecoveryInventory $inventory, string $indexId): void
    {
        $data = ['set_id' => bin2hex($inventory->setId), 'slice_size' => $inventory->sliceSize,
            'files' => array_map(static fn (RecoveryProtectedFile $file): array => ['file_id' => bin2hex($file->id),
                'filename' => base64_encode($file->filename), 'bytes' => $file->size, 'md5' => bin2hex($file->md5),
                'prefix_md5' => bin2hex($file->prefixMd5)], $inventory->files)];
        DB::transaction(function () use ($claim, $data, $indexId): void {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                throw new InvalidArgumentException('obsolete_work_revision');
            }
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
                'index_message_id' => $indexId, 'inventory' => json_encode($data, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        }, 1);
    }

    /** @param array<string,mixed> $run */
    private function headers(object $bundle, array $run): Builder
    {
        $scope = (object) ['source_epoch' => $bundle->source_epoch, 'groups_id' => $bundle->groups_id,
            'capture_generation' => $bundle->capture_generation, 'profile' => $bundle->profile, 'partition_value' => $run['partition']];

        return (new RecoveryRunDiscovery)->headers($scope)->whereBetween('embedded_timestamp_ms', [$run['start_ms'], $run['end_ms']]);
    }

    /** @param array<string,mixed> $run
     * @return Generator<int,object>
     */
    private function rows(object $bundle, array $run): Generator
    {
        yield from (new RecoveryHeaderCursor)->rows($this->headers($bundle, $run));
    }

    /** @param list<array<string,mixed>> $files */
    private function seal(RecoveryWorkClaim $claim, object $bundle, string $group, RecoveryInventory $inventory,
        array $files, string $indexId, RecoveryArticle $index): string
    {
        $algorithm = RecoveryAlgorithm::from($bundle->profile);
        $plans = $sources = $records = $evidenceIds = [];
        foreach ($files as $file) {
            $protected = $file['file'];
            $id = bin2hex($protected->id);
            $format = $file['anchor']['format'];
            if ($file['declared_format'] === null) {
                $file['display_name'] = 'file-'.$id.'.'.$format;
            }
            $plans[] = new RecoveryFilePlan($id, $algorithm->payloadRole(), $protected->size, $file['expected_total'], $file['display_name'], $format);
            $source = $this->headers($bundle, $file['run']);
            if (isset($file['block'])) {
                $source->whereBetween('embedded_timestamp_ms', [$file['block']['start_ms'], $file['block']['end_ms']]);
            }
            $sources[$id] = $source;
            $evidenceIds = [...$evidenceIds, ...$file['targets']];
            if (isset($file['terminal_target'])) {
                $evidenceIds[] = $file['terminal_target'];
            }
            $records[] = ['bundle_id' => $claim->bundleId, 'revision' => $claim->revision, 'groups_id' => $bundle->groups_id,
                'profile' => $bundle->profile, 'run_digest' => hash('sha256', $id), 'advertised_total' => $file['run']['advertised_total'],
                'expected_total' => $file['expected_total'], 'observed_count' => $file['expected_total'],
                'start_ms' => $file['block']['start_ms'] ?? $file['run']['start_ms'], 'end_ms' => $file['block']['end_ms'] ?? $file['run']['end_ms'],
                'state' => 'association_verified', 'role' => $algorithm->payloadRole()->value, 'file_id' => $id, 'decoded_bytes' => $protected->size,
                'file_md5' => bin2hex($protected->md5), 'prefix_md5' => bin2hex($protected->prefixMd5), 'source_filename' => $protected->filename,
                'display_filename' => $file['display_name'], 'declared_format' => $file['declared_format'], 'observed_format' => $file['anchor']['observed_format'],
                'archive_ordinal' => $file['archive_ordinal'] ?? null,
                'anchor_evidence' => json_encode(['message_id' => $file['anchor']['message_id'], 'metadata' => $file['anchor']['article']->metadata(),
                    'small_file_verified' => $file['anchor']['small_file_verified']], JSON_THROW_ON_ERROR),
                'terminal_evidence' => isset($file['terminal_target']) ? json_encode(['message_id' => $file['terminal_target'],
                    'metadata' => $this->evidence->get($file['terminal_target'], true)->metadata()], JSON_THROW_ON_ERROR) : null,
                'contained_observations' => isset($file['archive_header']) ? json_encode([
                    'scope' => 'partial_volume_listing', 'volume_file_id' => $id, 'complete' => false,
                    'observed_bytes' => strlen($file['anchor']['article']->data), 'truncated_listing' => false,
                    'encrypted' => $file['archive_header']['encrypted'],
                    'files' => [['name' => base64_encode($file['archive_header']['contained_name']), 'declared_size' => null, 'crc32' => null]],
                ], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(), 'updated_at' => now()];
        }
        $plans[] = new RecoveryFilePlan($indexId, RecoveryFileRole::Index, strlen($index->data), 1, 'recovery.par2', 'par2');
        $sources[$indexId] = DB::table('obfuscation_recovery_headers')->where('source_epoch', $bundle->source_epoch)
            ->where('groups_id', $bundle->groups_id)->where('capture_generation', $bundle->capture_generation)
            ->where('message_id_digest', hash('sha256', $indexId))->where('message_id', $indexId);
        $indexHeader = (clone $sources[$indexId])->first();
        if ($indexHeader === null) {
            throw new InvalidArgumentException('dirty_candidate_snapshot');
        }
        $records[] = ['bundle_id' => $claim->bundleId, 'revision' => $claim->revision, 'groups_id' => $bundle->groups_id,
            'profile' => $bundle->profile, 'run_digest' => hash('sha256', $indexId), 'advertised_total' => $indexHeader->advertised_total,
            'expected_total' => 1, 'observed_count' => 1, 'start_ms' => $indexHeader->embedded_timestamp_ms, 'end_ms' => $indexHeader->embedded_timestamp_ms,
            'state' => 'association_verified', 'role' => 'index', 'file_id' => null, 'decoded_bytes' => strlen($index->data),
            'file_md5' => md5($index->data), 'prefix_md5' => md5(substr($index->data, 0, 16384)), 'source_filename' => $index->filename,
            'display_filename' => 'recovery.par2', 'declared_format' => 'par2', 'observed_format' => 'par2', 'archive_ordinal' => null,
            'anchor_evidence' => json_encode(['message_id' => $indexId, 'metadata' => $index->metadata()], JSON_THROW_ON_ERROR),
            'terminal_evidence' => null, 'contained_observations' => null, 'created_at' => now(), 'updated_at' => now()];
        $manifest = (new RecoveryManifest($this->artifacts))->write($plans, $group, $bundle->source_epoch, (int) $bundle->groups_id,
            (int) $bundle->capture_generation, fn (RecoveryFilePlan $file): Generator => (new RecoveryHeaderCursor)->rows($sources[$file->identity]));
        $evidenceIds[] = $indexId;
        $evidenceIds = array_values(array_unique($evidenceIds));
        sort($evidenceIds, SORT_STRING);
        $plan = new RecoveryPlan($algorithm, $claim->bundleId, $claim->revision, $group, $bundle->source_epoch,
            bin2hex($inventory->setId), $plans, $manifest->digest, $manifest->bytes, $evidenceIds);

        return DB::transaction(function () use ($claim, $records, $plan): string {
            [$bundle, $runs] = $this->snapshot($claim);
            $coverage = ['first_article' => min(array_column($runs, 'first_article')), 'last_article' => max(array_column($runs, 'last_article')),
                'first_postdate' => min(array_column($runs, 'first_postdate')), 'last_postdate' => max(array_column($runs, 'last_postdate')),
                'changed_at' => $bundle->membership_changed_at];
            $settled = (new RecoverySettlement)->assess($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation,
                $coverage['first_article'], $coverage['last_article'], $coverage['first_postdate'], $coverage['last_postdate'], $coverage['changed_at'], false, $bundle);
            if ($settled !== 'ready') {
                return $this->waiting($claim, $settled);
            }
            DB::table('obfuscation_recovery_files')->upsert($records, ['bundle_id', 'revision', 'run_digest'], ['updated_at']);
            (new RecoveryReferences)->plan('bundle', $claim->bundleId, $plan);
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
                'state' => 'ready', 'reason' => null, 'sealed_plan' => json_encode($plan->toArray(), JSON_THROW_ON_ERROR),
                'manifest_verified_at' => now(), 'next_action_at' => now(), 'updated_at' => now(),
                'coverage_evidence' => json_encode($coverage, JSON_THROW_ON_ERROR),
            ]);
            $this->work->enqueueForBundle(RecoveryStage::Publish, $claim->bundleId, $claim->revision, 'publish', []);
            $this->work->complete($claim, 'ready');

            return 'ready';
        }, 1);
    }
}
