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
            );
        }

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
