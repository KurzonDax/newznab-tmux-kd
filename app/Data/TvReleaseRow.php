<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/** One release row of the TV screens (the releases list and the show page's release tables), ready to render. */
final readonly class TvReleaseRow
{
    /**
     * @param  array{percent: int, band: string, repairing: bool}|null  $completion  null at 100% or when never measured
     * @param  array{thumb: ?string, full: ?string}|null  $preview
     * @param  array{thumb: ?string, full: ?string}|null  $sample
     */
    public function __construct(
        public int $id,
        public string $guid,
        public string $name,
        public ?int $showId,
        public string $showTitle,
        public ?string $poster,
        public string $episodeLabel,
        public string $showUrl,
        public ReleaseResolution $resolution,
        public string $source,
        public string $size,
        public int $files,
        public string $date,
        public string $dateTitle,
        public string $day,
        public int $grabs,
        public int $comments,
        public ?array $completion,
        public bool $passworded,
        public ?string $mediaInfo,
        public bool $nfo,
        public ?array $preview,
        public ?array $sample,
        public bool $inCart,
        public bool $watched,
        public float $bytes,
        public int $postedAt,
        public string $postedOn,
        public string $group,
        public string $uploader,
    ) {}

    public function hasShow(): bool
    {
        return $this->showId !== null;
    }

    public function hasChips(): bool
    {
        return $this->completion !== null || $this->passworded || $this->mediaInfo !== null || $this->nfo || $this->preview !== null || $this->sample !== null;
    }

    /** `Show · S01E02 · Episode title`, or just the show when the release declares nothing. */
    public function showLine(): string
    {
        return implode(' · ', array_filter([$this->showTitle, $this->episodeLabel], static fn (string $part): bool => $part !== ''));
    }
}
