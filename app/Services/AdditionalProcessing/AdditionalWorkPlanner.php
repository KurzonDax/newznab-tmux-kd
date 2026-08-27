<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use Illuminate\Support\Facades\Log;

final readonly class AdditionalWorkPlanner
{
    public function __construct(
        private ProcessingConfiguration $config,
        private UnknownPayloadCandidateSelector $unknownPayloadSelector = new UnknownPayloadCandidateSelector,
    ) {}

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function plan(array $nzbContents, string $groupName): AdditionalWorkPlan
    {
        $sampleMessageIds = [];
        $jpgMessageIds = [];
        $mediaInfoMessageIds = [];
        $mediaInfoTailMessageIds = [];
        $mediaInfoTailExpansionMessageIds = [];
        $mediaInfoExpansionMessageIds = [];
        $mediaInfoContiguousHeadSegments = 0;
        $mediaInfoTailContiguous = true;
        $mediaInfoFileSizeBytes = 0;
        $archiveCandidates = [];
        $bookFileCount = 0;
        $duplicateMessageIdCount = 0;
        $seenMessageIds = [];

        foreach (array_values($nzbContents) as $sourceIndex => $file) {
            if (! is_array($file)) {
                continue;
            }

            try {
                $title = (string) ($file['title'] ?? '');
                $segments = is_array($file['segments'] ?? null) ? $file['segments'] : [];

                if (preg_match($this->config->ignoreBookRegex, $title) === 1) {
                    $bookFileCount++;
                }

                if (PostedFileClassifier::containsArchiveCandidate($title)) {
                    $archiveMessageIds = $this->extractSegments(
                        $segments,
                        $this->config->maximumRarSegments,
                        $seenMessageIds,
                        $duplicateMessageIdCount,
                    );
                    if ($archiveMessageIds !== []) {
                        $archiveCandidates[] = new ArchiveCandidate(
                            title: $title,
                            messageIds: $archiveMessageIds,
                            likelyFirstVolume: $this->isLikelyFirstVolume($title),
                            sourceIndex: $sourceIndex,
                        );
                    }
                }

                if ($this->isSupportFile($title)) {
                    continue;
                }

                if ($this->config->processThumbnails && $sampleMessageIds === [] && $segments !== []
                    && PostedFileClassifier::isExplicitVideoSample($title, $this->config->videoFileRegex)
                ) {
                    $sampleMessageIds = $this->extractSegments(
                        $segments,
                        $this->config->segmentsToDownload,
                        $seenMessageIds,
                        $duplicateMessageIdCount,
                    );
                }

                if ($this->config->processJPGSample && $jpgMessageIds === [] && $segments !== []
                    && preg_match('/flac|lossless|mp3|music|inner-sanctum|sound/i', $groupName) !== 1
                    && PostedFileClassifier::matchesTerminalExtension($title, '\.(?:jpe?g|png|webp)')
                ) {
                    $jpgMessageIds = $this->extractSegments(
                        $segments,
                        $this->config->segmentsToDownload,
                        $seenMessageIds,
                        $duplicateMessageIdCount,
                    );
                }

                if ($this->config->processMediaInfo && $mediaInfoMessageIds === [] && $segments !== []
                    && ! PostedFileClassifier::isExplicitVideoSample($title, $this->config->videoFileRegex)
                    && PostedFileClassifier::matchesTerminalExtension($title, $this->config->videoFileRegex)
                ) {
                    $mediaInfoMessageIds = $this->extractSegments(
                        $segments,
                        $this->config->segmentsToDownload,
                        $seenMessageIds,
                        $duplicateMessageIdCount,
                    );
                    $tailCandidates = $this->extractTailSegments(
                        $segments,
                        $this->config->mp4TailMaxSegments,
                    );
                    $initialTailCount = min($this->config->segmentsToDownload, count($tailCandidates));
                    $mediaInfoTailMessageIds = $initialTailCount > 0
                        ? array_slice($tailCandidates, -$initialTailCount)
                        : [];
                    $mediaInfoTailExpansionMessageIds = array_slice(
                        $tailCandidates,
                        0,
                        count($tailCandidates) - $initialTailCount,
                    );
                    $mediaInfoExpansionMessageIds = $this->extractExpansionSegments(
                        $segments,
                        count($mediaInfoMessageIds),
                    );
                    $segmentNumbers = $this->segmentNumbers($file);
                    $mediaInfoContiguousHeadSegments = $this->contiguousHeadSegmentCount($segments, $segmentNumbers);
                    $mediaInfoTailContiguous = $this->tailIsContiguous(
                        $segmentNumbers,
                        min($this->config->mp4TailMaxSegments, count($segments)),
                        (int) ($file['partstotal'] ?? 0),
                    );
                    $mediaInfoFileSizeBytes = max((int) ($file['size'] ?? 0), 0);
                }
            } catch (\ErrorException $e) {
                Log::debug($e->getTraceAsString());
            }
        }

        $bookFlood = $bookFileCount > 80 && ($bookFileCount * 2) >= count($nzbContents);
        $unsupportedReasons = [];
        if ($bookFlood) {
            $unsupportedReasons[] = 'book-flood';
        }
        $hasKnownCandidate = $archiveCandidates !== []
            || $sampleMessageIds !== []
            || $jpgMessageIds !== []
            || $mediaInfoMessageIds !== [];
        $unknownPayloadCandidates = [];
        if (! $hasKnownCandidate && $this->config->payloadSniffing) {
            $unknownPayloadCandidates = $this->unknownPayloadSelector->select(
                $nzbContents,
                $this->config->payloadSniffMaxCandidates,
                $this->config->payloadSniffByteBudget,
                $this->config->payloadSniffSmallSegmentLimit,
            );
        }

        if (! $hasKnownCandidate
            && $unknownPayloadCandidates === []
        ) {
            $unsupportedReasons[] = 'no-supported-candidates';
        }

        return new AdditionalWorkPlan(
            sampleMessageIds: $sampleMessageIds,
            jpgMessageIds: $jpgMessageIds,
            mediaInfoMessageIds: $mediaInfoMessageIds,
            mediaInfoTailMessageIds: $mediaInfoTailMessageIds,
            mediaInfoTailExpansionMessageIds: $mediaInfoTailExpansionMessageIds,
            mediaInfoExpansionMessageIds: $mediaInfoExpansionMessageIds,
            mediaInfoContiguousHeadSegments: $mediaInfoContiguousHeadSegments,
            mediaInfoTailContiguous: $mediaInfoTailContiguous,
            mediaInfoFileSizeBytes: $mediaInfoFileSizeBytes,
            archiveCandidates: $archiveCandidates,
            unknownPayloadCandidates: $unknownPayloadCandidates,
            bookFileCount: $bookFileCount,
            bookFlood: $bookFlood,
            duplicateMessageIdCount: $duplicateMessageIdCount,
            unsupportedReasons: $unsupportedReasons,
        );
    }

    private function isSupportFile(string $title): bool
    {
        return preg_match(
            '/(?:'.$this->config->supportFileRegex.'|nfo\b|inf\b|ofn\b)($|[ ")]|-])(?!.{20,})/i',
            $title,
        ) === 1;
    }

    private function isLikelyFirstVolume(string $title): bool
    {
        if (preg_match('/\.part0*(\d+)/i', $title, $part) === 1) {
            return (int) $part[1] === 1;
        }

        if (preg_match('/"[a-f0-9]{32}\.[1-9]\d{1,2}".*\((\d+)\/\d{2,}\)$/i', $title, $position) === 1) {
            return (int) $position[1] === 1;
        }

        return preg_match('/\.(rar|zip)($|[ ")]|-])/i', $title) === 1;
    }

    /**
     * @param  array<int|string, mixed>  $segments
     * @param  array<string, true>  $seenMessageIds
     * @return list<string>
     */
    private function extractSegments(
        array $segments,
        int $limit,
        array &$seenMessageIds,
        int &$duplicateMessageIdCount,
    ): array {
        $messageIds = [];
        $requestMessageIds = [];

        foreach (array_slice($segments, 0, max($limit, 0)) as $segment) {
            $messageId = (string) $segment;
            if ($messageId === '' || isset($requestMessageIds[$messageId])) {
                if ($messageId !== '') {
                    $duplicateMessageIdCount++;
                }

                continue;
            }

            $requestMessageIds[$messageId] = true;
            $messageIds[] = $messageId;
            $this->recordMessageId($messageId, $seenMessageIds, $duplicateMessageIdCount);
        }

        return $messageIds;
    }

    /**
     * @param  array<int|string, mixed>  $segments
     * @return list<string>
     */
    private function extractTailSegments(array $segments, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $messageIds = [];
        $seen = [];

        foreach (array_slice($segments, -$limit) as $segment) {
            $messageId = (string) $segment;
            if ($messageId === '' || isset($seen[$messageId])) {
                continue;
            }

            $seen[$messageId] = true;
            $messageIds[] = $messageId;
        }

        return $messageIds;
    }

    /**
     * Head segments after the fixed initial window, in posted order. These are
     * the dynamic segment budget's top-up pool; the fixed window itself is
     * untouched so toggled-off roots keep today's selection byte-for-byte.
     *
     * @param  array<int|string, mixed>  $segments
     * @return list<string>
     */
    private function extractExpansionSegments(array $segments, int $initialWindowCount): array
    {
        $messageIds = [];
        $seen = [];

        foreach (array_slice(array_values($segments), max($initialWindowCount, 0)) as $segment) {
            $messageId = (string) $segment;
            if ($messageId === '' || isset($seen[$messageId])) {
                continue;
            }

            $seen[$messageId] = true;
            $messageIds[] = $messageId;
        }

        return $messageIds;
    }

    /**
     * @param  array<int|string, mixed>  $file
     * @return list<int>|null Per-segment NZB numbering, or null when it is absent or unreliable.
     */
    private function segmentNumbers(array $file): ?array
    {
        $rawNumbers = is_array($file['segmentNumbers'] ?? null) ? array_values($file['segmentNumbers']) : [];
        $segmentCount = is_array($file['segments'] ?? null) ? count($file['segments']) : 0;
        if ($rawNumbers === [] || count($rawNumbers) !== $segmentCount) {
            return null;
        }

        $numbers = [];
        foreach ($rawNumbers as $number) {
            $number = (int) $number;
            if ($number <= 0) {
                return null;
            }
            $numbers[] = $number;
        }

        return $numbers;
    }

    /**
     * How many leading segments are provably gap-free from segment number 1.
     * Unknown numbering counts every segment: the contiguity gate may only
     * skip fetches that are provably pointless.
     *
     * @param  array<int|string, mixed>  $segments
     * @param  list<int>|null  $segmentNumbers
     */
    private function contiguousHeadSegmentCount(array $segments, ?array $segmentNumbers): int
    {
        if ($segmentNumbers === null) {
            return count($segments);
        }

        $contiguous = 0;
        foreach ($segmentNumbers as $index => $number) {
            if ($number !== $index + 1) {
                break;
            }
            $contiguous++;
        }

        return $contiguous;
    }

    /**
     * Whether the tail window an MP4 moov splice would fetch is provably
     * complete: consecutive numbering within the window, and — when the
     * subject declares a total — a last segment that actually reaches it.
     *
     * @param  list<int>|null  $segmentNumbers
     */
    private function tailIsContiguous(?array $segmentNumbers, int $tailWindowCount, int $declaredTotal): bool
    {
        if ($segmentNumbers === null || $tailWindowCount <= 0) {
            return true;
        }

        $tailNumbers = array_slice($segmentNumbers, -$tailWindowCount);
        foreach ($tailNumbers as $index => $number) {
            if ($index > 0 && $number !== $tailNumbers[$index - 1] + 1) {
                return false;
            }
        }

        $lastNumber = $tailNumbers === [] ? 0 : $tailNumbers[count($tailNumbers) - 1];
        if ($declaredTotal >= count($segmentNumbers) && $declaredTotal > 0 && $lastNumber !== $declaredTotal) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, true>  $seenMessageIds
     */
    private function recordMessageId(string $messageId, array &$seenMessageIds, int &$duplicateMessageIdCount): void
    {
        if (isset($seenMessageIds[$messageId])) {
            $duplicateMessageIdCount++;

            return;
        }

        $seenMessageIds[$messageId] = true;
    }
}
