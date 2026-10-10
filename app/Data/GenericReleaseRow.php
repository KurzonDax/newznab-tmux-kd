<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/**
 * One release row of the generic release lists (docs/proposals/generic-release-lists/SPEC.md
 * 5.4 and 5.5), ready to render: the shelf row with its "Root > Sub" Category cell, the entity
 * line of a film, show, album or console game, the Follow target of a film or show, the report
 * counts behind the Reported and Response chips, and the Preview / Sample / Clip pictures as the
 * Adult list carries them.
 */
final readonly class GenericReleaseRow
{
    use ReleaseRowParts {
        hasChips as private hasReleaseChips;
    }

    /**
     * @param  array{percent: int, band: string, repairing: bool}|null  $completion  null at 100% or when never measured
     * @param  array{thumb: ?string, full: ?string}|null  $preview
     * @param  array{thumb: ?string, full: ?string}|null  $sample
     * @param  string  $category  the release's sub-category title ("Misc"), the Other list's Category cell
     * @param  string  $categoryPath  the root's header label and the sub-category ("TV > HD"), the other lists' Category cell
     * @param  int  $categoryId  the release's sub-category id
     * @param  'film'|'show'|'album'|'game'|null  $entityKind  what the entity line names
     * @param  string  $entityLine  the entity line's text ("Title · Year", "Show · S01E07", "Artist – Album · Year"); '' without one
     * @param  string|null  $entityUrl  the film or show page the line links to; null for plain text
     * @param  'movies'|'tv'|null  $followRoot  the Follow button's root; null when the row cannot be followed
     * @param  string  $followId  the Follow button's id (the film's imdbid, the show's videos id)
     * @param  string  $followTitle  the followed title's name
     * @param  int  $reports  the release's report count (the Reported chip)
     * @param  int  $publicResponses  the public staff responses (the Response chip)
     * @param  array{url: string, type: string, poster: ?string}|null  $clip  the video clip, as the Adult list carries it
     * @param  array{url: string, type: string, title: ?string, artist: ?string, seconds: ?int}|null  $listen  the playable audio preview, as the Audio list carries it (the Listen chip)
     * @param  string|null  $cover  the Listen dialog's cover: none on these lists (no picture column)
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
        public ?string $entityKind = null,
        public string $entityLine = '',
        public ?string $entityUrl = null,
        public ?string $followRoot = null,
        public string $followId = '',
        public string $followTitle = '',
        public int $reports = 0,
        public int $publicResponses = 0,
        public ?array $clip = null,
        public ?array $listen = null,
        public ?string $cover = null,
    ) {}

    public function hasChips(): bool
    {
        return $this->hasReleaseChips() || $this->clip !== null || $this->listen !== null || $this->reports > 0 || $this->publicResponses > 0;
    }

    /** The Listen chip's title (AudioReleaseRow::listenTitle()): "Play the 30-second preview", or "Play the preview" with no length stored. */
    public function listenTitle(): string
    {
        $seconds = $this->listen['seconds'] ?? null;

        return $seconds === null ? 'Play the preview' : 'Play the '.$seconds.'-second preview';
    }

    public function hasEntityLine(): bool
    {
        return $this->entityLine !== '';
    }

    /** Whether the row carries a Follow button: a film or a show (SPEC 5.5). */
    public function canFollow(): bool
    {
        return $this->followRoot !== null && $this->followId !== '';
    }

    /** The Reported chip's words: "Reported", or "Reported (N)" with more than one report. */
    public function reportedLabel(): string
    {
        return $this->reports > 1 ? 'Reported ('.$this->reports.')' : 'Reported';
    }

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
