<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\DTO\YencArticleMetadata;
use Closure;
use RuntimeException;

/** Resolves decoded ranges by yEnc geometry, never by NZB transfer byte counts. */
final class ArchiveRanges
{
    /** @var array<string, array{data: string, metadata: YencArticleMetadata}> */
    private array $articles = [];

    /** @var list<array{start: int, size: int, ids: list<string>}> */
    private array $volumes = [];

    private int $bytes = 0;

    private int $requests = 0;

    /**
     * @param  list<ArchiveCandidate>  $candidates
     * @param  Closure(string): array<string, mixed>  $fetch
     */
    public function __construct(
        private readonly array $candidates,
        private readonly Closure $fetch,
        private readonly InspectionBudget $budget,
    ) {}

    public function read(int $offset, int $length): string
    {
        $this->checkTime();
        if ($offset < 0 || $length < 1 || $length > Inspector::MAX_HEADER_BYTES || $offset > PHP_INT_MAX - $length) {
            throw new RuntimeException('invalid-range');
        }
        $end = $offset + $length;
        $result = '';
        for ($index = 0; $offset < $end; $index++) {
            $volume = $this->volume($index);
            $volumeEnd = $volume['start'] + $volume['size'];
            if ($offset >= $volumeEnd) {
                continue;
            }
            while ($offset < min($end, $volumeEnd)) {
                $article = $this->locate($volume['ids'], $offset - $volume['start'], $volume['size']);
                $metadata = $article['metadata'];
                $within = $offset - $volume['start'] - $metadata->offset;
                $take = min($metadata->length - $within, $end - $offset);
                $result .= substr($article['data'], $within, $take);
                $offset += $take;
            }
        }

        return $result;
    }

    /** @return array{start: int, size: int, ids: list<string>} */
    private function volume(int $index): array
    {
        if (isset($this->volumes[$index])) {
            return $this->volumes[$index];
        }
        $candidate = $this->candidates[$index] ?? null;
        if ($candidate === null) {
            throw new RuntimeException('missing-volume');
        }
        $ids = [...$candidate->messageIds, ...$candidate->expansionMessageIds];
        if ($ids === [] || count(array_unique($ids)) !== count($ids)) {
            throw new RuntimeException('invalid-volume-segments');
        }
        $head = $this->article($ids, 0);
        $metadata = $head['metadata'];
        if ($metadata->offset !== 0) {
            throw new RuntimeException('missing-volume-head');
        }
        $start = $index === 0 ? 0 : $this->volumes[$index - 1]['start'] + $this->volumes[$index - 1]['size'];
        if ($metadata->fileSize > PHP_INT_MAX - $start) {
            throw new RuntimeException('volume-size-overflow');
        }

        return $this->volumes[$index] = ['start' => $start, 'size' => $metadata->fileSize, 'ids' => $ids];
    }

    /**
     * @param  list<string>  $ids
     * @return array{data: string, metadata: YencArticleMetadata}
     */
    private function locate(array $ids, int $offset, int $fileSize): array
    {
        foreach ($ids as $id) {
            $cached = $this->articles[$id] ?? null;
            if ($cached !== null && $cached['metadata']->offset <= $offset
                && $offset < $cached['metadata']->offset + $cached['metadata']->length) {
                return $cached;
            }
        }
        $low = 0;
        $high = count($ids) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $article = $this->article($ids, $middle);
            $metadata = $article['metadata'];
            if ($metadata->fileSize !== $fileSize) {
                throw new RuntimeException('inconsistent-volume-size');
            }
            if ($offset < $metadata->offset) {
                $high = $middle - 1;
            } elseif ($offset >= $metadata->offset + $metadata->length) {
                $low = $middle + 1;
            } else {
                return $article;
            }
        }

        throw new RuntimeException('missing-range');
    }

    /**
     * @param  list<string>  $ids
     * @return array{data: string, metadata: YencArticleMetadata}
     */
    private function article(array $ids, int $index): array
    {
        $this->checkTime();
        $id = $ids[$index];
        if (! isset($this->articles[$id])) {
            if ($this->requests >= $this->budget->maxArticles || $this->bytes >= $this->budget->maxBytes) {
                throw new RuntimeException('article-budget');
            }
            $this->requests++;
            $result = ($this->fetch)($id);
            $this->checkTime();
            $data = $result['data'] ?? null;
            $metadata = $result['metadata'] ?? null;
            if (! ($result['success'] ?? false) || ($result['crcFailed'] ?? false)
                || ! is_string($data) || ! $metadata instanceof YencArticleMetadata) {
                throw new RuntimeException('unavailable-article-geometry');
            }
            $this->bytes += strlen($data);
            if ($this->bytes > $this->budget->maxBytes) {
                throw new RuntimeException('byte-budget');
            }
            if ($metadata->fileSize < 1 || $metadata->length !== strlen($data) || $metadata->length < 1
                || $metadata->offset < 0 || $metadata->offset > $metadata->fileSize - $metadata->length
                || $metadata->part !== $index + 1 || $metadata->total !== count($ids)
                || ($index === 0 && $metadata->offset !== 0)
                || ($index === count($ids) - 1 && $metadata->offset + $metadata->length !== $metadata->fileSize)) {
                throw new RuntimeException('invalid-article-geometry');
            }
            foreach ($ids as $otherIndex => $otherId) {
                if (! isset($this->articles[$otherId])) {
                    continue;
                }
                $other = $this->articles[$otherId]['metadata'];
                if ($other->fileSize !== $metadata->fileSize
                    || ($otherIndex < $index && $other->offset + $other->length > $metadata->offset)
                    || ($otherIndex > $index && $metadata->offset + $metadata->length > $other->offset)
                    || ($otherIndex === $index - 1 && $other->offset + $other->length !== $metadata->offset)
                    || ($otherIndex === $index + 1 && $metadata->offset + $metadata->length !== $other->offset)) {
                    throw new RuntimeException('discontinuous-article-geometry');
                }
            }
            $this->articles[$id] = ['data' => $data, 'metadata' => $metadata];
        }

        return $this->articles[$id];
    }

    private function checkTime(): void
    {
        if (microtime(true) >= $this->budget->deadline) {
            throw new RuntimeException('inspection-time-budget');
        }
    }
}
