<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\PartHandler;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecoveryStorage
{
    public function __construct(
        private readonly CollectionHandler $collections,
        private readonly BinaryHandler $binaries,
        private readonly PartHandler $parts,
        private readonly int $chunkSize,
    ) {}

    public function store(RecoveryWorkClaim $claim, int $publicationId, RecoveryStorageBatch $batch): int
    {
        $this->collections->reset();
        $this->binaries->reset();
        $this->parts->reset();
        $this->parts->setAddToPartRepair(false);

        return DB::transaction(function () use ($claim, $publicationId, $batch): int {
            $bundle = (new RecoveryOwnership)->locked($claim);
            $plan = $batch->plan;
            if (! RecoveryAdmission::allows($batch->groupId, $plan->algorithm)) {
                throw new RecoveryAdmissionPending;
            }
            if ($bundle === null || $claim->stage !== RecoveryStage::Publish || $plan->bundleId !== $claim->bundleId
                || $plan->revision !== $claim->revision || $bundle->manifest_verified_at === null
                || json_decode($bundle->sealed_plan ?? 'null', true, flags: JSON_THROW_ON_ERROR) !== $plan->toArray()) {
                throw new RuntimeException('obsolete_recovery_plan');
            }
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->lockForUpdate()->first();
            if ($publication === null || ! in_array($publication->state, ['registered', 'materializing'], true)
                || $batch->offset > (int) $publication->materialized_parts
                || $publication->deleted_at !== null || $publication->manifest_digest !== $plan->manifestDigest
                || json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR) !== $plan->toArray()
                || ! DB::table('usenet_groups')->where('id', $batch->groupId)->where('name', $plan->group)->exists()) {
                throw new RuntimeException('recovery_publication_conflict');
            }
            $collection = DB::table('collections')->where('collectionhash', $publication->collection_projection)->lockForUpdate()->first();
            if ($collection !== null && ((int) $publication->collections_id !== (int) $collection->id
                || (int) $collection->groups_id !== $batch->groupId || (int) $collection->totalfiles !== $plan->plannedFiles()
                || (int) $collection->declaredfiles !== 0
                || ($collection->releases_id !== null && (int) $collection->releases_id !== (int) $publication->releases_id))) {
                throw new RuntimeException('recovery_collection_projection_conflict');
            }
            if ($collection === null && $publication->collections_id !== null) {
                throw new RuntimeException('recovery_collection_missing');
            }
            $collectionId = $collection === null ? $this->collections->getOrCreateRecoveredCollection(
                $publication->collection_projection, 'Recovered.'.substr($publication->identity, 0, 24),
                $batch->poster, $batch->postTimestamp, $batch->groupId, $plan->plannedFiles(), bin2hex(random_bytes(8)),
            ) : (int) $collection->id;
            DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->update([
                'collections_id' => $collectionId, 'state' => 'materializing', 'updated_at' => now(),
                'materialized_parts' => max((int) $publication->materialized_parts, $batch->offset + count($batch->segments)),
            ]);
            DB::table('collection_groups')->insertOrIgnore(['collections_id' => $collectionId, 'group_name' => $plan->group]);
            $binaryIds = $this->binaries($publication->identity, $collectionId, $plan);
            foreach ($batch->segments as $segment) {
                if (! $this->parts->addRecoveredPart($binaryIds[$segment->fileIdentity], $segment)) {
                    throw new RuntimeException('recovery_part_insert_failed');
                }
            }
            if (! $this->parts->flush()) {
                throw new RuntimeException('recovery_part_insert_failed');
            }
            $this->verifyParts($batch->segments, $binaryIds);
            if (! $this->binaries->refreshAggregates(array_values($binaryIds), $this->chunkSize)
                || ! $this->collections->refreshAggregates([$collectionId], $this->chunkSize, HeaderScanDirection::Repair)) {
                throw new RuntimeException('recovery_aggregate_failed');
            }

            return $collectionId;
        }, 1);
    }

    /** @return array<string,int> */
    private function binaries(string $publication, int $collectionId, RecoveryPlan $plan): array
    {
        $identity = new RecoveryIdentity;
        $existing = DB::table('binaries')->where('collections_id', $collectionId)->limit(34)->lockForUpdate()->get();
        $files = $plan->files;
        usort($files, static fn (RecoveryFilePlan $a, RecoveryFilePlan $b): int => strcmp($a->identity, $b->identity));
        $expected = [];
        foreach ($files as $index => $file) {
            $projection = $identity->binaryProjection($publication, $file->role->value, $file->identity);
            $hash = bin2hex($projection);
            if (isset($expected[$hash])) {
                throw new RuntimeException('recovery_binary_projection_conflict');
            }
            $expected[$hash] = [$file, $projection, $index + 1];
        }
        foreach ($existing as $binary) {
            $hash = DB::getDriverName() === 'sqlite' ? $binary->binaryhash : bin2hex($binary->binaryhash);
            $entry = $expected[$hash] ?? null;
            if ($entry === null || $binary->name !== '"'.$entry[0]->displayName.'" yEnc'
                || (int) $binary->totalparts !== $entry[0]->totalParts || (int) $binary->filenumber !== $entry[2]) {
                throw new RuntimeException('recovery_binary_projection_conflict');
            }
        }
        $ids = [];
        foreach ($expected as [$file, $projection, $number]) {
            $ids[$file->identity] = $this->binaries->getOrCreateRecoveredBinary($projection, '"'.$file->displayName.'" yEnc',
                $collectionId, $file->totalParts, $number);
        }

        return $ids;
    }

    /** @param list<RecoverySegment> $segments
     * @param  array<string,int>  $binaryIds
     */
    private function verifyParts(array $segments, array $binaryIds): void
    {
        $bindings = $expected = [];
        foreach ($segments as $segment) {
            $binaryId = $binaryIds[$segment->fileIdentity];
            $bindings[] = $binaryId;
            $bindings[] = $segment->ordinal;
            $expected[$binaryId.':'.$segment->ordinal] = $segment;
        }
        $rows = DB::table('parts')->whereRaw('(binaries_id, partnumber) IN ('.implode(',', array_fill(0, count($segments), '(?,?)')).')', $bindings)
            ->lockForUpdate()->get();
        if (count($rows) !== count($segments)) {
            throw new RuntimeException('recovery_part_conflict');
        }
        foreach ($rows as $row) {
            $segment = $expected[$row->binaries_id.':'.$row->partnumber];
            if ($row->messageid !== $segment->messageId || (int) $row->number !== $segment->articleNumber
                || (int) $row->size !== $segment->advertisedBytes) {
                throw new RuntimeException('recovery_part_conflict');
            }
        }
    }
}
