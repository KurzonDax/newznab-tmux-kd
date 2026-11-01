<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ArchiveMoovTail;
use App\Services\AdditionalProcessing\DTO\Mp4MoovSpliceResult;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\Mp4MoovSpliceStatus;
use App\Services\AdditionalProcessing\State\ArchivePartTail;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;

/**
 * One moov tail recovery for an MP4 stored in a RAR set: stored volumes keep
 * the file's bytes in order, so a moov at the end of the file sits at the end
 * of the set. Reads the final part from its end, joining the part before it
 * when the moov starts there. The video is told apart from any other in the
 * set by the size its entries list, because volumes can list it under
 * different names.
 */
final class ArchiveMoovTailFetcher
{
    /** Tail segments fetched across both parts; the part's held first segment counts. */
    private int $tailSegments = 0;

    /** @var list<string> */
    private array $fetchedMessageIds = [];

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly UsenetDownloadService $downloadService,
        private readonly ArchiveExtractionService $archiveService,
        private readonly Mp4MoovSplicer $splicer,
        private readonly ConsoleOutputService $output,
        private readonly ReleaseProcessingContext $context,
        private readonly string $fragment,
        private readonly int $videoSize,
    ) {}

    /**
     * @param  ArchivePartTail  $anchorTail  The anchor part, with its fetched head held.
     */
    public function fetch(AdditionalWorkPlan $plan, ArchiveCandidate $anchor, ArchivePartTail $anchorTail): ArchiveMoovTail
    {
        $laterParts = array_values(array_filter(
            $plan->archivePartsAfter($anchor),
            static fn (ArchiveCandidate $part): bool => ! PostedFileClassifier::isPar2($part->title),
        ));
        $finalPart = $laterParts === [] ? $anchor : $laterParts[count($laterParts) - 1];

        if (! $finalPart->tailContiguous) {
            $this->context->recordSegmentGapSkip();
            $this->output->echoSegmentGapSkip();

            return new ArchiveMoovTail;
        }

        if ($laterParts === []) {
            $splice = $this->spliceFromTail($anchorTail, '');
        } else {
            $previousPart = count($laterParts) >= 2 ? $laterParts[count($laterParts) - 2] : $anchor;
            $splice = $this->spliceFromLastParts($finalPart, $previousPart, $anchor, $anchorTail);
        }

        if ($splice?->status !== Mp4MoovSpliceStatus::Spliced || $splice->data === null) {
            $this->context->recordMp4MoovMissing();
            $this->output->echoMp4MoovMissing();

            return new ArchiveMoovTail(fetchedMessageIds: $this->fetchedMessageIds);
        }

        $this->context->recordMp4MoovFound();
        $this->output->echoMp4MoovFound();

        return new ArchiveMoovTail(
            $splice->data,
            // rewriteMdatSize() preserves the head's length, so the moov is
            // exactly the bytes appended after it.
            substr($splice->data, strlen($this->fragment)),
            $this->fetchedMessageIds,
        );
    }

    private function spliceFromLastParts(
        ArchiveCandidate $finalPart,
        ArchiveCandidate $previousPart,
        ArchiveCandidate $anchor,
        ArchivePartTail $anchorTail,
    ): ?Mp4MoovSpliceResult {
        $finalTail = $this->openPart($finalPart);
        if ($finalTail === null) {
            return null;
        }

        $previousTail = null;
        if ($finalTail->holdsVideo()) {
            $splice = $this->spliceFromTail($finalTail, '');
            if ($splice->status !== Mp4MoovSpliceStatus::NeedMore) {
                return $splice;
            }
        } else {
            $previousTail = $this->openPreviousPart($previousPart, $anchor, $anchorTail);
            if ($previousTail === null) {
                return null;
            }
        }

        // The moov starts in the part before: only parts complete from their
        // first segment have the offsets the join is computed from.
        if (! $finalPart->isCompleteFromFirstSegment() || ! $previousPart->isCompleteFromFirstSegment()) {
            return null;
        }

        $previousTail ??= $this->openPreviousPart($previousPart, $anchor, $anchorTail);

        return $previousTail === null ? null : $this->spliceFromTail($previousTail, $finalTail->videoBytes());
    }

    /**
     * The part before the final one when it holds the video. The anchor's head
     * is already on hand.
     */
    private function openPreviousPart(
        ArchiveCandidate $previousPart,
        ArchiveCandidate $anchor,
        ArchivePartTail $anchorTail,
    ): ?ArchivePartTail {
        $previousTail = $previousPart === $anchor ? $anchorTail : $this->openPart($previousPart);

        return $previousTail?->holdsVideo() ? $previousTail : null;
    }

    /**
     * Fetch a part's first segment and find the video's entry in its listing.
     */
    private function openPart(ArchiveCandidate $part): ?ArchivePartTail
    {
        $firstSegment = $this->fetchTailSegments(array_slice($part->segmentIds(), 0, 1), $part->title);
        if ($firstSegment === null) {
            return null;
        }

        foreach ($this->archiveService->listArchiveContents($firstSegment)['files'] as $entry) {
            if ((int) ($entry['size'] ?? -1) === $this->videoSize && ArchivePartTail::locatesVideo($entry)) {
                return new ArchivePartTail($part, $firstSegment, 1, $entry);
            }
        }

        return new ArchivePartTail($part, $firstSegment, 1, null);
    }

    /**
     * Read a part from its end, growing by segmentsToDownload, until the moov
     * turns up in its video bytes joined with $followingBytes. NeedMore means
     * the whole part was read below the segment cap.
     */
    private function spliceFromTail(ArchivePartTail $tail, string $followingBytes): Mp4MoovSpliceResult
    {
        while (true) {
            $batch = $tail->takeSegmentsFromEnd(min(
                max($this->config->segmentsToDownload, 1),
                $this->config->mp4TailMaxSegments - $this->tailSegments,
            ));
            if ($batch !== []) {
                $bytes = $this->fetchTailSegments($batch, $tail->title);
                if ($bytes === null) {
                    return new Mp4MoovSpliceResult(Mp4MoovSpliceStatus::Missing);
                }
                $tail->prependRead($bytes, count($batch));
            }

            $splice = $this->splicer->splice(
                $this->fragment,
                $tail->videoBytes().$followingBytes,
                $this->tailSegments >= $this->config->mp4TailMaxSegments,
            );
            if ($splice->status !== Mp4MoovSpliceStatus::NeedMore || $tail->isWhole()) {
                return $splice;
            }
        }
    }

    /**
     * @param  list<string>  $messageIds
     */
    private function fetchTailSegments(array $messageIds, string $partTitle): ?string
    {
        $download = $this->downloadService->download(
            DownloadKind::CompressedTail,
            $messageIds,
            $this->context->releaseGroupName,
            $this->context->release->id,
            $partTitle,
        );
        if ($download['groupUnavailable']) {
            $this->context->groupUnavailable = true;
            $this->output->echoGroupUnavailable();
        }
        if (! $download['success'] || ! is_string($download['data'])) {
            return null;
        }

        array_push($this->fetchedMessageIds, ...$messageIds);
        $this->tailSegments += count($messageIds);
        $this->context->recordMp4TailFetch(strlen($download['data']));
        $this->output->echoMp4TailFetched(strlen($download['data']));

        return $download['data'];
    }
}
