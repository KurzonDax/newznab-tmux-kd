<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\UnknownPayloadCandidate;

final readonly class UnknownPayloadCandidateSelector
{
    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<UnknownPayloadCandidate>
     */
    public function select(array $nzbContents, int $maximumCandidates, int $byteBudget, int $smallSegmentLimit): array
    {
        if ($maximumCandidates <= 0 || $byteBudget <= 0) {
            return [];
        }

        $candidates = $this->eligibleFiles($nzbContents);
        if ($candidates === []) {
            return [];
        }

        usort($candidates, static fn (UnknownPayloadCandidate $left, UnknownPayloadCandidate $right): int => $right->estimatedSizeBytes <=> $left->estimatedSizeBytes ?: $left->sourceIndex <=> $right->sourceIndex
        );

        $largest = array_shift($candidates);
        $smallCandidates = array_values(array_filter(
            $candidates,
            static fn (UnknownPayloadCandidate $candidate): bool => $candidate->segmentCount <= $smallSegmentLimit,
        ));
        usort($smallCandidates, static fn (UnknownPayloadCandidate $left, UnknownPayloadCandidate $right): int => $left->estimatedSizeBytes <=> $right->estimatedSizeBytes ?: $left->sourceIndex <=> $right->sourceIndex
        );

        $selected = [];
        $usedBytes = 0;
        foreach ([$largest, ...$smallCandidates] as $candidate) {
            if (count($selected) >= $maximumCandidates || $usedBytes + $candidate->estimatedFirstSegmentBytes > $byteBudget) {
                continue;
            }

            $selected[] = $candidate;
            $usedBytes += $candidate->estimatedFirstSegmentBytes;
        }

        return $selected;
    }

    /**
     * Every file sniffing may open, in NZB order: a first message-id and an
     * extensionless, .bin, .dat or .file title. Source indexes are positions
     * in the NZB file list.
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<UnknownPayloadCandidate>
     */
    public function eligibleFiles(array $nzbContents): array
    {
        $candidates = [];
        foreach (array_values($nzbContents) as $sourceIndex => $file) {
            if (! is_array($file)) {
                continue;
            }

            $title = (string) ($file['title'] ?? '');
            $segments = is_array($file['segments'] ?? null) ? array_values($file['segments']) : [];
            $firstMessageId = (string) ($segments[0] ?? '');
            if ($firstMessageId === '' || ! $this->isEligibleTitle($title, (string) ($file['ext'] ?? ''), ['', 'bin', 'dat', 'file'])) {
                continue;
            }

            $segmentCount = max((int) ($file['partsactual'] ?? count($segments)), 1);
            $estimatedSizeBytes = max((int) ($file['size'] ?? 0), 0);
            $candidates[] = new UnknownPayloadCandidate(
                title: $title,
                firstMessageId: $firstMessageId,
                segmentCount: $segmentCount,
                estimatedSizeBytes: $estimatedSizeBytes,
                estimatedFirstSegmentBytes: max((int) ceil($estimatedSizeBytes / $segmentCount), 1),
                sourceIndex: $sourceIndex,
                segmentNumbers: ($file['segmentNumbersValid'] ?? false) ? array_values($file['segmentNumbers'] ?? []) : [],
                declaredSegments: (int) ($file['declaredSegmentTotal'] ?? 0),
                nzbFileIndex: (int) ($file['nzbFileIndex'] ?? -1),
                fingerprint: (string) ($file['membershipFingerprint'] ?? ''),
            );
        }

        return $candidates;
    }

    /**
     * The split-set files: the eligible files at the largest eligible segment
     * count, in NZB order. Every part of a split archive but the last has
     * that count, so its first part is among them. Fewer than two such files
     * are no split set.
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<UnknownPayloadCandidate>
     */
    public function splitSetFiles(array $nzbContents): array
    {
        $eligible = $this->eligibleFiles($nzbContents);
        if ($eligible === []) {
            return [];
        }

        $largest = max(array_map(static fn (UnknownPayloadCandidate $file): int => $file->segmentCount, $eligible));
        $files = array_values(array_filter(
            $eligible,
            static fn (UnknownPayloadCandidate $file): bool => $file->segmentCount === $largest,
        ));

        return count($files) < 2 ? [] : $files;
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function hasEligibleFile(array $nzbContents): bool
    {
        foreach ($nzbContents as $file) {
            if (is_array($file) && $this->isEligibleTitle((string) ($file['title'] ?? ''), (string) ($file['ext'] ?? ''), ['', 'bin', 'dat', 'file'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The historical repair command is intentionally narrower than live
     * planning: only extensionless and explicit .bin entries qualify.
     *
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function hasRequeueEligibleFile(array $nzbContents): bool
    {
        foreach ($nzbContents as $file) {
            if (is_array($file) && $this->isEligibleTitle((string) ($file['title'] ?? ''), (string) ($file['ext'] ?? ''), ['', 'bin'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedExtensions
     */
    private function isEligibleTitle(string $title, string $parsedExtension, array $allowedExtensions): bool
    {
        if (trim($title) === '') {
            return false;
        }

        $extension = strtolower($parsedExtension);
        if ($extension === '' && preg_match('/\.([a-z0-9]{1,8})(?=$|[" )-])/i', $title, $match) === 1) {
            $extension = strtolower($match[1]);
        }

        return in_array($extension, $allowedExtensions, true);
    }
}
