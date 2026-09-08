<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecoveryCbpVerifier
{
    public function __construct(private readonly RecoveryArtifacts $artifacts) {}

    public function verify(int $publicationId, RecoveryPlan $plan): int
    {
        $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->first();
        $collection = $publication === null ? null : DB::table('collections')->where('id', $publication->collections_id)->first();
        if ($collection === null || $publication->manifest_digest !== $plan->manifestDigest
            || $collection->collectionhash !== $publication->collection_projection
            || (int) $collection->totalfiles !== $plan->plannedFiles() || (int) $collection->declaredfiles !== 0
            || DB::table('usenet_groups')->where('id', $collection->groups_id)->value('name') !== $plan->group) {
            throw new RuntimeException('recovery_collection_mismatch');
        }
        $binaries = DB::table('binaries')->where('collections_id', $collection->id)->limit(34)->get();
        if ($binaries->count() !== $plan->plannedFiles()) {
            throw new RuntimeException('recovery_binary_count_mismatch');
        }
        $byHash = [];
        foreach ($binaries as $binary) {
            $hash = DB::getDriverName() === 'sqlite' ? $binary->binaryhash : bin2hex($binary->binaryhash);
            if (isset($byHash[$hash])) {
                throw new RuntimeException('recovery_binary_identity_mismatch');
            }
            $byHash[$hash] = $binary;
        }
        $records = (new RecoveryManifest($this->artifacts))->read(new RecoveryArtifact($plan->manifestDigest, $plan->manifestBytes));
        $files = $plan->files;
        usort($files, static fn (RecoveryFilePlan $a, RecoveryFilePlan $b): int => strcmp($a->identity, $b->identity));
        $identity = new RecoveryIdentity;
        $totalBytes = 0;
        foreach ($files as $index => $file) {
            $hash = bin2hex($identity->binaryProjection($publication->identity, $file->role->value, $file->identity));
            $binary = $byHash[$hash] ?? null;
            if ($binary === null || (int) $binary->totalparts !== $file->totalParts
                || $binary->name !== '"'.$file->displayName.'" yEnc' || (int) $binary->filenumber !== $index + 1) {
                throw new RuntimeException('recovery_binary_identity_mismatch');
            }
            $count = $bytes = 0;
            foreach ($this->parts((int) $binary->id) as $part) {
                $count++;
                $record = $records->valid() ? $records->current() : null;
                if ($record === null || $record['file'] !== $file->identity || $record['role'] !== $file->role->value
                    || $record['ordinal'] !== $count || (int) $part->partnumber !== $count
                    || $record['message_id'] !== $part->messageid || $record['article_number'] !== (int) $part->number
                    || $record['advertised_bytes'] !== (int) $part->size || $record['group'] !== $plan->group
                    || $record['group_id'] !== (int) $collection->groups_id || $record['source_epoch'] !== $plan->sourceEpoch
                    || $count > $file->totalParts) {
                    throw new RuntimeException('recovery_part_membership_mismatch');
                }
                $bytes += (int) $part->size;
                $records->next();
            }
            if ($count !== $file->totalParts || (int) $binary->currentparts !== $count || (int) $binary->partsize !== $bytes
                || (int) $binary->partcheck !== 1) {
                throw new RuntimeException('recovery_file_measurement_mismatch');
            }
            $totalBytes += $bytes;
        }
        if ($records->valid() || $totalBytes !== (int) $collection->filesize) {
            throw new RuntimeException('recovery_collection_measurement_mismatch');
        }

        return $totalBytes;
    }

    /** @return Generator<int,object> */
    private function parts(int $binaryId): Generator
    {
        $after = 0;
        do {
            $rows = DB::table('parts')->where('binaries_id', $binaryId)->where('partnumber', '>', $after)
                ->orderBy('partnumber')->limit(1000)->get(['partnumber', 'number', 'messageid', 'size']);
            foreach ($rows as $row) {
                yield $row;
                $after = (int) $row->partnumber;
            }
        } while ($rows->count() === 1000);
    }
}
