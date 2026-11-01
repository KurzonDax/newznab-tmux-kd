<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;

/**
 * The bytes held of one archive part while its tail is read from the end. The
 * part's leading segments are already on hand, so reading stops at them and
 * no segment is requested twice.
 */
final class ArchivePartTail
{
    public readonly string $title;

    /** @var list<string> Segments still to read, in posted order; reading takes from the end. */
    private array $unreadSegments;

    private readonly int $segmentCount;

    private readonly ?int $videoStart;

    private readonly int $videoEnd;

    private string $tail = '';

    private ?int $totalBytes = null;

    /**
     * @param  string  $heldHead  The bytes of the part's leading segments.
     * @param  int  $heldSegments  How many segments $heldHead holds.
     * @param  array<string, mixed>|null  $videoEntry  The part's listing entry for the video; null when it lists none. Must satisfy {@see self::locatesVideo()}.
     */
    public function __construct(
        ArchiveCandidate $part,
        private readonly string $heldHead,
        private readonly int $heldSegments,
        ?array $videoEntry,
    ) {
        $this->title = $part->title;
        $segments = $part->segmentIds();
        $this->segmentCount = count($segments);
        $this->unreadSegments = array_slice($segments, $heldSegments);
        $this->videoStart = $videoEntry === null ? null : (int) explode('-', (string) $videoEntry['range'])[0];
        $this->videoEnd = (int) ($videoEntry['next_offset'] ?? 0);
    }

    /**
     * Whether an entry says where the video's bytes lie in its part. They end at
     * next_offset, never at the end of range, which RarInfo clamps to a read window.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function locatesVideo(array $entry): bool
    {
        return isset($entry['next_offset'], $entry['range']);
    }

    public function holdsVideo(): bool
    {
        return $this->videoStart !== null;
    }

    public function isWhole(): bool
    {
        return $this->unreadSegments === [];
    }

    /**
     * Up to $limit of the unread segments nearest the end, in posted order.
     *
     * @return list<string>
     */
    public function takeSegmentsFromEnd(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $start = max(count($this->unreadSegments) - $limit, 0);
        $taken = array_slice($this->unreadSegments, $start);
        $this->unreadSegments = array_slice($this->unreadSegments, 0, $start);

        return $taken;
    }

    /**
     * Add the bytes of the segments {@see self::takeSegmentsFromEnd()} last gave.
     * Every segment but the last has one size, so the first read states the
     * part's total size.
     */
    public function prependRead(string $bytes, int $segmentCount): void
    {
        $this->totalBytes ??= $this->heldSegmentBytes() * ($this->segmentCount - $segmentCount) + strlen($bytes);
        $this->tail = $bytes.$this->tail;
    }

    /**
     * The video's bytes among those read; none when the part does not hold it.
     * Any bytes past the entry's end belong to a following file or the archive's
     * end block, and those before its start to its headers.
     */
    public function videoBytes(): string
    {
        if ($this->videoStart === null) {
            return '';
        }

        $bytes = $this->isWhole() ? $this->heldHead.$this->tail : $this->tail;
        $totalBytes = $this->isWhole() ? strlen($bytes) : (int) $this->totalBytes;
        $trailingBytes = max($totalBytes - $this->videoEnd, 0);
        $bytes = substr($bytes, 0, max(strlen($bytes) - $trailingBytes, 0));

        return $this->isWhole() ? substr($bytes, $this->videoStart) : $bytes;
    }

    private function heldSegmentBytes(): int
    {
        return $this->heldSegments > 0 ? intdiv(strlen($this->heldHead), $this->heldSegments) : 0;
    }
}
