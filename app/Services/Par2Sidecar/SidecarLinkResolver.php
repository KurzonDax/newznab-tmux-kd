<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

/**
 * Prefix equality is a naming heuristic; unseen tail bytes are never verified.
 *
 * @phpstan-type Prefix array{releases_id:int,nzb_file_index:int,prefix_hash:string,raw_size:int,decoded_length:int,segment_number:int,segment_offset:int,observed_segments:int,declared_segments:int,segment_numbers:list<int>,fingerprint:string}
 * @phpstan-type Descriptor array{releases_id:int,set_id:string,file_id:string,hash16k:string,raw_size:int,full_hash:?string,filename:string,fingerprint:string,naming_ambiguous?:bool|int}
 * @phpstan-type Eligibility array{eligible:bool,completion:int|float,postdate:string,fingerprint:string,nzb_complete:bool}
 * @phpstan-type Inventory array{pure:bool,complete:bool,unambiguous?:bool,fingerprint:string,descriptors:list<Descriptor>,owners:list<int>}
 */
final class SidecarLinkResolver
{
    public const int CANDIDATE_LIMIT = 32;

    /**
     * @param  list<Prefix>  $payloads  All captured files in one target, not just one matching file.
     * @param  list<Descriptor>  $descriptors  At most 33 candidates; the extra row proves overflow.
     * @param  array<int, Inventory>  $inventories
     * @param  array<int, Eligibility>  $eligibility
     */
    public function resolve(array $payloads, array $descriptors, array $inventories, array $eligibility): SidecarLinkDecision
    {
        if (count($descriptors) > self::CANDIDATE_LIMIT) {
            return new SidecarLinkDecision(reason: 'descriptor_overflow');
        }
        if (count($payloads) > 1024) {
            return new SidecarLinkDecision(reason: 'payload_overflow');
        }
        $matches = [];
        $targetId = $payloads[0]['releases_id'] ?? 0;
        $target = $eligibility[$targetId] ?? null;
        if ($target === null || ! $target['eligible']) {
            return new SidecarLinkDecision(reason: 'ineligible');
        }
        foreach ($payloads as $payload) {
            if ($payload['releases_id'] !== $targetId || ! $this->validPrefix($payload)
                || $payload['fingerprint'] !== $target['fingerprint']) {
                return new SidecarLinkDecision(reason: 'invalid_prefix');
            }
            foreach ($descriptors as $descriptor) {
                $sourceId = $descriptor['releases_id'];
                if ($sourceId === $targetId || ! ($eligibility[$sourceId]['eligible'] ?? false)
                    || $descriptor['fingerprint'] !== $eligibility[$sourceId]['fingerprint']
                    || ! $this->matches($payload, $descriptor)) {
                    continue;
                }
                if (($descriptor['naming_ambiguous'] ?? false) || ! ($inventories[$sourceId]['unambiguous'] ?? true)) {
                    return new SidecarLinkDecision(reason: 'ambiguous_inventory');
                }
                $matches[] = $descriptor;
            }
        }
        if ($matches === []) {
            return new SidecarLinkDecision;
        }
        $names = array_unique(array_column($matches, 'filename'));
        $hashes = array_unique(array_filter(array_column($matches, 'full_hash')));
        if (count($names) !== 1 || count($hashes) > 1) {
            return new SidecarLinkDecision(reason: 'conflicting_identity');
        }
        usort($matches, static fn (array $a, array $b): int => strcmp($eligibility[$a['releases_id']]['postdate'], $eligibility[$b['releases_id']]['postdate'])
            ?: $a['releases_id'] <=> $b['releases_id']);
        $selected = $matches[0];
        $sourceId = $selected['releases_id'];
        $source = $eligibility[$sourceId];
        $inventory = $inventories[$sourceId] ?? null;
        $combine = $inventory !== null && $inventory['pure'] && $inventory['complete']
            && $inventory['fingerprint'] === $source['fingerprint'] && $inventory['owners'] === [$targetId]
            && $inventory['descriptors'] !== [] && $target['nzb_complete'] && $source['nzb_complete']
            && (float) $target['completion'] === 100.0 && (float) $source['completion'] === 100.0;
        if ($combine) {
            foreach ($inventory['descriptors'] as $descriptor) {
                if (! array_any($payloads, fn (array $payload): bool => $this->matches($payload, $descriptor))) {
                    $combine = false;
                    break;
                }
            }
        }

        return new SidecarLinkDecision($selected['filename'], $sourceId, $combine, $combine ? 'matched' : 'naming_only');
    }

    /** @param Prefix $prefix */
    public function validPrefix(array $prefix): bool
    {
        $numbers = $prefix['segment_numbers'];

        return $prefix['raw_size'] > 0 && preg_match('/^[a-f0-9]{32}$/D', $prefix['prefix_hash']) === 1
            && $prefix['segment_number'] === 1 && $prefix['segment_offset'] === 0
            && $prefix['decoded_length'] >= min(16384, $prefix['raw_size'])
            && $prefix['decoded_length'] <= $prefix['raw_size']
            && $prefix['declared_segments'] > 0 && $prefix['observed_segments'] > 0
            && $prefix['observed_segments'] === count($numbers) && ($numbers[0] ?? 0) === 1
            && count(array_unique($numbers)) === count($numbers) && min($numbers) > 0
            && max($numbers) <= $prefix['declared_segments']
            && ($prefix['declared_segments'] !== 1 || $prefix['decoded_length'] === $prefix['raw_size']);
    }

    /**
     * @param  Prefix  $prefix
     * @param  Descriptor  $descriptor
     */
    private function matches(array $prefix, array $descriptor): bool
    {
        return $prefix['prefix_hash'] === $descriptor['hash16k'] && $prefix['raw_size'] === $descriptor['raw_size']
            && $descriptor['filename'] !== '' && strlen($descriptor['filename']) <= 1024;
    }
}
