<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoveryPlan
{
    /** @param list<RecoveryFilePlan> $files
     * @param  list<string>  $evidenceIds
     */
    public function __construct(
        public RecoveryAlgorithm $algorithm,
        public int $bundleId,
        public int $revision,
        public string $group,
        public string $sourceEpoch,
        public string $setId,
        public array $files,
        public string $manifestDigest,
        public int $manifestBytes = 0,
        public array $evidenceIds = [],
    ) {
        if ($bundleId < 1 || $revision < 1 || $group === '' || $sourceEpoch === ''
            || preg_match('/^[a-f0-9]{32}$/D', $setId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $manifestDigest) !== 1
            || $manifestBytes < 0 || $manifestBytes > 8192000000 || count($evidenceIds) > 97
            || count($files) < ($algorithm === RecoveryAlgorithm::Rar ? 5 : 2) || count($files) > 33) {
            throw new InvalidArgumentException('invalid_plan');
        }
        $canonical = new RecoveryIdentity;
        foreach ($evidenceIds as $id) {
            if (! is_string($id) || $canonical->messageId($id) !== $id) {
                throw new InvalidArgumentException('invalid_plan_evidence');
            }
        }
        $identities = $names = $totals = [];
        $indexes = $parts = 0;
        foreach ($files as $file) {
            $name = mb_strtolower($file->displayName);
            if (isset($identities[$file->identity]) || isset($names[$name])) {
                throw new InvalidArgumentException('inventory_overlap');
            }
            $identities[$file->identity] = $names[$name] = true;
            $parts += $file->totalParts;
            if ($file->role === RecoveryFileRole::Index) {
                $indexes++;

                continue;
            }
            if ($file->role !== $algorithm->payloadRole()) {
                throw new InvalidArgumentException('unsupported_inventory');
            }
            if ($algorithm === RecoveryAlgorithm::Media && isset($totals[$file->totalParts])) {
                throw new InvalidArgumentException('ambiguous_expected_total');
            }
            $totals[$file->totalParts] = true;
        }
        if ($indexes !== 1 || $parts > 500000) {
            throw new InvalidArgumentException('invalid_inventory_counts');
        }
    }

    public function plannedFiles(): int
    {
        return count($this->files);
    }

    public function protectedFiles(): int
    {
        return $this->plannedFiles() - 1;
    }

    public function plannedParts(): int
    {
        return array_sum(array_map(static fn (RecoveryFilePlan $file): int => $file->totalParts, $this->files));
    }

    public function declaredFiles(): int
    {
        return 0;
    }

    public function orderingMode(): string
    {
        return 'header_ordinal_yenc_offsets';
    }

    public function inventoryScope(): string
    {
        return $this->algorithm === RecoveryAlgorithm::Media ? 'protected_media_plus_index' : 'protected_rar_volumes_plus_index';
    }

    public function multiMediaInventory(): bool
    {
        return $this->algorithm === RecoveryAlgorithm::Media && $this->protectedFiles() > 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'algorithm' => $this->algorithm->value, 'bundle_id' => $this->bundleId, 'revision' => $this->revision,
            'group' => $this->group, 'source_epoch' => $this->sourceEpoch, 'set_id' => $this->setId,
            'files' => array_map(static fn (RecoveryFilePlan $file): array => $file->toArray(), $this->files),
            'manifest_digest' => $this->manifestDigest, 'manifest_bytes' => $this->manifestBytes, 'evidence_ids' => $this->evidenceIds,
            'ordering_mode' => $this->orderingMode(), 'inventory_scope' => $this->inventoryScope(),
            'verification_scope' => 'inventory_and_boundary_evidence',
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['algorithm', 'bundle_id', 'revision', 'group', 'source_epoch', 'set_id', 'files', 'manifest_digest'] as $key) {
            if (! isset($data[$key])) {
                throw new InvalidArgumentException('invalid_plan_serialization');
            }
        }
        if (! is_array($data['files']) || ! array_is_list($data['files']) || count($data['files']) > 33) {
            throw new InvalidArgumentException('invalid_plan_serialization');
        }
        try {
            $files = [];
            foreach ($data['files'] as $file) {
                foreach (['identity', 'role', 'decoded_bytes', 'total_parts', 'display_name', 'format'] as $key) {
                    if (! is_array($file) || ! isset($file[$key])) {
                        throw new InvalidArgumentException('invalid_plan_serialization');
                    }
                }
                $files[] = new RecoveryFilePlan($file['identity'], RecoveryFileRole::from($file['role']), $file['decoded_bytes'], $file['total_parts'], $file['display_name'], $file['format']);
            }
            $plan = new self(RecoveryAlgorithm::from($data['algorithm']), $data['bundle_id'], $data['revision'], $data['group'], $data['source_epoch'], $data['set_id'], $files, $data['manifest_digest'], $data['manifest_bytes'] ?? 0, $data['evidence_ids'] ?? []);
            if (($data['verification_scope'] ?? null) !== 'inventory_and_boundary_evidence' || ($data['ordering_mode'] ?? null) !== $plan->orderingMode() || ($data['inventory_scope'] ?? null) !== $plan->inventoryScope()) {
                throw new InvalidArgumentException('invalid_plan_scope');
            }

            return $plan;
        } catch (\TypeError|\ValueError $exception) {
            throw new InvalidArgumentException('invalid_plan_serialization', previous: $exception);
        }
    }
}
