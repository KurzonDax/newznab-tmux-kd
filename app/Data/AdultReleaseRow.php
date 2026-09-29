<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/** One release row of the Adult releases screen (docs/proposals/adult-redesign/SPEC.md 5.4-5.7 and 5.10), ready to render. */
final readonly class AdultReleaseRow
{
    use ReleaseRowParts {
        hasChips as private hasReleaseChips;
    }

    /**
     * @param  array{percent: int, band: string, repairing: bool}|null  $completion  null at 100% or when never measured
     * @param  array{thumb: ?string, full: ?string}|null  $preview
     * @param  array{thumb: ?string, full: ?string}|null  $sample
     * @param  array{url: string, type: string}|null  $clip  the video clip (videostatus = 1): today's player route and its type
     */
    public function __construct(
        public int $id,
        public string $guid,
        public string $name,
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
        public ?array $clip,
    ) {}

    public function hasChips(): bool
    {
        return $this->hasReleaseChips() || $this->clip !== null;
    }

    /** The file count as the details page shows it: "—" when none is stored (SPEC 5A.2, appendix A). */
    public function filesShown(): string
    {
        return $this->files === 0 ? '—' : (string) $this->files;
    }

    /**
     * The row's picture (SPEC 5.6): the preview thumbnail, else the sample thumbnail, each only
     * when its file exists; null for the "No picture" tile.
     *
     * @return array{url: string, kind: 'preview'|'sample'}|null
     */
    public function picture(): ?array
    {
        if (($this->preview['thumb'] ?? null) !== null) {
            return ['url' => $this->preview['thumb'], 'kind' => 'preview'];
        }
        if (($this->sample['thumb'] ?? null) !== null) {
            return ['url' => $this->sample['thumb'], 'kind' => 'sample'];
        }

        return null;
    }
}
