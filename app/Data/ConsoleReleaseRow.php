<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/**
 * One release row of the Console releases list (docs/proposals/books-console-pc-redesign/SPEC.md
 * 5.4, 5.5, 5.7 and 5.8): the shelf row plus the release's game, read by `releases.consoleinfo_id`:
 * its cover, the game line under the name and the Genre cell. Like ShelfReleaseRow, the preview and
 * sample are always null.
 */
final readonly class ConsoleReleaseRow
{
    use ReleaseRowParts;

    /**
     * @param  array{percent: int, band: string, repairing: bool}|null  $completion  null at 100% or when never measured
     * @param  array{thumb: ?string, full: ?string}|null  $preview  always null: the lists show no preview
     * @param  array{thumb: ?string, full: ?string}|null  $sample  always null: the lists show no sample
     * @param  string  $category  the release's sub-category title, the Category cell's text
     * @param  string  $categoryPath  the root and sub-category ("Console > PS3"), the Category cell's title
     * @param  int|null  $gameId  the release's game (consoleinfo.id); null when it has none
     * @param  string  $gameTitle  the game's name; '' when the release has no game
     * @param  string  $gameYear  the year the game came out; '' when unknown or without a game
     * @param  string|null  $cover  the game's cover URL; null without a game, a stored cover or its file
     * @param  string  $genres  the game's genres in IGDB order, joined with ", "; '' when it has none
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
        public ?int $gameId = null,
        public string $gameTitle = '',
        public string $gameYear = '',
        public ?string $cover = null,
        public string $genres = '',
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

    public function hasGame(): bool
    {
        return $this->gameId !== null;
    }

    /** The game line under the name: "Game · Year", or the game alone without a year. */
    public function gameLine(): string
    {
        return $this->gameYear === '' ? $this->gameTitle : $this->gameTitle.' · '.$this->gameYear;
    }
}
