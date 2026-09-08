<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

use App\Models\Release;
use App\Services\AdditionalProcessing\DTO\UnknownPayloadCandidate;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\DTO\YencArticleMetadata;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SidecarEvidence
{
    public function queuePrefix(ReleaseProcessingContext $context, UnknownPayloadCandidate $candidate, string $data, ?YencArticleMetadata $metadata): void
    {
        if ($metadata === null || $metadata->length !== strlen($data) || $candidate->nzbFileIndex < 0
            || $candidate->fingerprint === '' || $candidate->declaredSegments !== $metadata->total) {
            return;
        }
        $row = ['releases_id' => (int) $context->release->id, 'nzb_file_index' => $candidate->nzbFileIndex,
            'prefix_hash' => md5(substr($data, 0, min(16384, $metadata->fileSize))),
            'raw_size' => $metadata->fileSize, 'decoded_length' => strlen($data),
            'segment_number' => $metadata->part, 'segment_offset' => $metadata->offset,
            'observed_segments' => $candidate->segmentCount, 'declared_segments' => $metadata->total,
            'segment_numbers' => $candidate->segmentNumbers, 'fingerprint' => $candidate->fingerprint];
        if (! (new SidecarLinkResolver)->validPrefix($row)) {
            return;
        }
        $context->pendingPayloadPrefixes[] = $row + ['first_message_id' => $candidate->firstMessageId,
            'leftguid' => substr((string) $context->release->guid, 0, 1), 'captured_at' => now()];
    }

    public function queueClassification(ReleaseProcessingContext $context, UnknownPayloadCandidate $candidate, string $classification, string $data): void
    {
        $inventory = $classification === 'par2' ? (new SidecarDescriptorInventory)->parse($data) : null;
        $context->sidecarClassifications[$candidate->sourceIndex] = ['classification' => $classification,
            'nzb_file_index' => $candidate->nzbFileIndex, 'inventory' => $inventory];
        if ($inventory !== null) {
            array_push($context->pendingPar2Descriptors, ...$inventory['descriptors']);
            $context->sidecarDescriptorAmbiguous = $context->sidecarDescriptorAmbiguous || SidecarDescriptorInventory::ambiguous($inventory);
        }
    }

    public function queueDescriptors(ReleaseProcessingContext $context, string $data): void
    {
        $inventory = (new SidecarDescriptorInventory)->parse($data);
        array_push($context->pendingPar2Descriptors, ...$inventory['descriptors']);
        $context->sidecarDescriptorAmbiguous = $context->sidecarDescriptorAmbiguous || SidecarDescriptorInventory::ambiguous($inventory);
    }

    public function storeParsedDescriptors(int $releaseId, string $data): void
    {
        $inventory = (new SidecarDescriptorInventory)->parse($data);
        $this->storeDescriptors($releaseId, $inventory['descriptors'], ambiguous: SidecarDescriptorInventory::ambiguous($inventory));
    }

    /** @param list<array<string, mixed>> $descriptors */
    public function storeDescriptors(int $releaseId, array $descriptors, ?int $originId = null, ?string $fingerprint = null, bool $ambiguous = false): void
    {
        if (($descriptors === [] && ! $ambiguous) || ! Schema::hasTable('par2_file_descriptors')) {
            return;
        }
        if ($fingerprint === null) {
            $guid = Release::query()->whereKey($releaseId)->value('guid');
            $xml = is_string($guid) ? app(NzbService::class)->readNzbContents($guid) : false;
            $fingerprint = $xml === false ? '' : hash('sha256', $xml);
        }
        if ($ambiguous) {
            $current = DB::table('par2_sidecar_inventories')->where('releases_id', $releaseId)->first();
            if ($current !== null && $current->fingerprint === $fingerprint) {
                DB::table('par2_sidecar_inventories')->where('releases_id', $releaseId)->update(['naming_ambiguous' => true]);
            } else {
                DB::table('par2_sidecar_inventories')->updateOrInsert(['releases_id' => $releaseId], [
                    'fingerprint' => $fingerprint, 'total_files' => 0, 'files' => '[]', 'pure' => false,
                    'complete' => false, 'naming_ambiguous' => true, 'reason' => 'ambiguous_descriptor_capture', 'captured_at' => now(),
                ]);
            }
        }
        foreach ($descriptors as $descriptor) {
            $values = array_intersect_key($descriptor, array_flip(['set_id', 'file_id', 'hash16k', 'full_hash', 'raw_size', 'filename']));
            $values['fingerprint'] = $fingerprint;
            $values['naming_ambiguous'] = $ambiguous || (bool) ($descriptor['naming_ambiguous'] ?? false);
            $values['identity'] = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            DB::table('par2_file_descriptors')->insertOrIgnore($values + ['releases_id' => $releaseId,
                'origin_release_id' => $originId ?? $releaseId, 'captured_at' => now()]);
        }
    }

    public function flush(ReleaseProcessingContext $context): void
    {
        if ($context->releaseDiscarded || ! Schema::hasTable('payload_prefix_hashes')) {
            $this->clear($context);

            return;
        }
        foreach ($context->pendingPayloadPrefixes as $row) {
            $key = ['releases_id' => $row['releases_id'], 'nzb_file_index' => $row['nzb_file_index']];
            $existing = DB::table('payload_prefix_hashes')->where($key)->lockForUpdate()->first();
            $row['segment_numbers'] = json_encode($row['segment_numbers'], JSON_THROW_ON_ERROR);
            if ($existing === null) {
                DB::table('payload_prefix_hashes')->insert($row);
            } elseif ($existing->operation_id === null && ($existing->fingerprint !== $row['fingerprint']
                || $existing->prefix_hash !== $row['prefix_hash'] || (int) $existing->raw_size !== $row['raw_size'])) {
                DB::table('payload_prefix_hashes')->where($key)->update($row + ['state' => 'pending',
                    'reason' => null, 'evaluated_at' => null, 'retry_at' => null]);
            }
        }
        $this->storeDescriptors((int) $context->release->id, $context->pendingPar2Descriptors, fingerprint: $context->nzbContents[0]['membershipFingerprint'] ?? null, ambiguous: $context->sidecarDescriptorAmbiguous);
        if ($context->sidecarClassifications !== []) {
            $first = $context->nzbContents[0] ?? [];
            $fingerprint = $first['membershipFingerprint'] ?? '';
            if ($fingerprint !== '') {
                $files = array_values($context->sidecarClassifications);
                $complete = $context->purePar2Sidecar && array_all($files,
                    static fn (array $file): bool => $file['inventory']['complete'] ?? false);
                $ambiguous = DB::table('par2_sidecar_inventories')->where('releases_id', $context->release->id)
                    ->where('fingerprint', $fingerprint)->where('naming_ambiguous', true)->exists();
                DB::table('par2_sidecar_inventories')->updateOrInsert(['releases_id' => $context->release->id], [
                    'fingerprint' => $fingerprint, 'total_files' => $first['nzbFileCount'],
                    'files' => json_encode($files, JSON_THROW_ON_ERROR), 'pure' => $context->purePar2Sidecar,
                    'complete' => $complete, 'naming_ambiguous' => $ambiguous || $context->sidecarDescriptorAmbiguous, 'reason' => $complete ? null : 'incomplete_or_mixed_inventory', 'captured_at' => now(),
                ]);
            }
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->clear($context));
        } else {
            $this->clear($context);
        }
    }

    private function clear(ReleaseProcessingContext $context): void
    {
        $context->pendingPayloadPrefixes = [];
        $context->pendingPar2Descriptors = [];
        $context->sidecarClassifications = [];
        $context->sidecarDescriptorAmbiguous = false;
    }
}
