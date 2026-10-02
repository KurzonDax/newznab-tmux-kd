<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/**
 * One release row of the Books, Console and PC releases lists
 * (docs/proposals/books-console-pc-redesign/SPEC.md 5.4 and 5.5), ready to render. These lists show
 * no picture, so the preview and sample are always null and never count as chips.
 */
final readonly class ShelfReleaseRow
{
    use ReleaseRowParts;

    /**
     * @param  array{percent: int, band: string, repairing: bool}|null  $completion  null at 100% or when never measured
     * @param  array{thumb: ?string, full: ?string}|null  $preview  always null: the lists show no preview
     * @param  array{thumb: ?string, full: ?string}|null  $sample  always null: the lists show no sample
     * @param  string  $category  the release's sub-category title, the Category cell's text
     * @param  string  $categoryPath  the root and sub-category ("Books > Comics"), the Category cell's title
     * @param  int  $categoryId  the release's sub-category id; the details page's Similar releases sorts by its place in the Category menu's order
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
        public string $category,
        public string $categoryPath,
        public int $categoryId,
    ) {}

    /** Whether the release has a stored file count; without one the details page reads "Files" and "—". */
    public function hasFileCount(): bool
    {
        return $this->files > 0;
    }

    /** The file count as the details page shows it: "—" when none is stored. */
    public function filesShown(): string
    {
        return $this->hasFileCount() ? (string) $this->files : '—';
    }
}
