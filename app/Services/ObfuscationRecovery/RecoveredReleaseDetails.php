<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use stdClass;

final readonly class RecoveredReleaseDetails
{
    public string $method;

    public string $naming;

    public string $namingNote;

    public string $inspection;

    public string $inspectionNote;

    /** @var list<array{name: string, role: string}> */
    public array $files;

    public int $payloadFileCount;

    public string $contents;

    public string $missingFiles;

    /** @param array<string, string|null> $outcomes */
    public function __construct(stdClass $publication, array $outcomes)
    {
        $algorithm = RecoveryAlgorithm::tryFrom($publication->profile);
        $this->method = match ($algorithm) {
            RecoveryAlgorithm::Media => 'Media',
            RecoveryAlgorithm::Rar => 'RAR',
            null => 'Unknown',
        };
        [$this->naming, $this->namingNote] = match ($publication->identity_outcome) {
            'identified' => ['Named by recovery', 'Recovery established a name from the recovered file inventory.'],
            'descriptive_bundle' => ['Bundle name', 'Recovery established a descriptive name for the group of files; it did not assign one episode identity to the whole release.'],
            'par2_naming_disabled' => ['Naming disabled', 'Naming was disabled during recovery.'],
            'identity_unresolved' => ['Name unresolved', 'Recovery could not establish a usable release name. The recovered release is retained.'],
            default => ['Naming result unavailable', 'No recovery naming result is recorded.'],
        };

        $plan = json_decode($publication->sealed_plan, true);
        $files = [];
        $mediaFound = 0;
        $archiveFound = false;
        if ($publication->detail_retired_at === null && is_array($plan) && is_array($plan['files'] ?? null)) {
            foreach ($plan['files'] as $file) {
                if (! is_array($file) || ! is_string($file['display_name'] ?? null)
                    || ! in_array($file['role'] ?? null, ['media', 'rar_volume', 'index'], true)) {
                    continue;
                }
                $files[] = ['name' => $file['display_name'], 'role' => $file['role']];
                $outcome = is_string($file['identity'] ?? null) ? ($outcomes[$file['identity']] ?? null) : null;
                if ($file['role'] === 'media' && $outcome === 'media_evidence_available') {
                    $mediaFound++;
                }
                $archiveFound = $archiveFound || ($file['role'] === 'rar_volume' && $outcome === 'partial_archive_listing');
            }
        }
        usort($files, static function (array $a, array $b): int {
            return ($a['role'] === 'index') <=> ($b['role'] === 'index') ?: strnatcasecmp($a['name'], $b['name']);
        });
        $this->files = $files;
        $this->payloadFileCount = count(array_filter($files, static fn (array $file): bool => $file['role'] !== 'index'));
        $count = (int) $publication->protected_files;
        $unit = $algorithm === RecoveryAlgorithm::Rar ? 'RAR volume' : 'media file';
        $this->contents = $algorithm === null ? 'File inventory unavailable' : $count.' '.$unit.($count === 1 ? '' : 's').' + PAR2 index';
        $this->missingFiles = $publication->detail_retired_at === null
            ? 'Recovered filenames are unavailable.' : 'Recovered file details are no longer available.';

        [$this->inspection, $this->inspectionNote] = match (true) {
            $publication->enrichment_outcome === 'enrichment_pending' => [
                'Inspection pending', 'Additional content inspection is waiting for more data.',
            ],
            $mediaFound > 0 => [
                'Media information found', 'Media information was read from downloaded portions of '.$mediaFound.' of '.$count.' media files. The complete payload has not been verified.',
            ],
            $archiveFound => [
                'Partial archive listing', 'Some archive filenames were read from the downloaded portions. The full archive contents have not been inspected.',
            ],
            $publication->enrichment_outcome === 'bounded_evidence_unavailable' => [
                'No information within limits', 'The downloaded portions did not provide usable content information. This does not establish that the release is broken.',
            ],
            default => [
                'Inspection details unavailable', 'No detailed content-inspection result is available for this release.',
            ],
        };
    }
}
