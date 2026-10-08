<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/**
 * One release row of the Audio releases list (docs/proposals/audio-redesign/SPEC.md 5.4, 5.5, 5.7,
 * 5.8 and 5.10): the shelf row plus the release's tags, read by `release_audio_tags.releases_id`:
 * the music line under the name, the Genre cell and the Listen chip; plus the stored Cover Art
 * Archive cover of its current accepted album (issue #1015), else the "No cover" tile. Like
 * ShelfReleaseRow, the preview and sample are always null.
 */
final readonly class AudioReleaseRow
{
    use ReleaseRowParts {
        hasChips as private hasReleaseChips;
    }

    /**
     * @param  array{percent: int, band: string, repairing: bool}|null  $completion  null at 100% or when never measured
     * @param  array{thumb: ?string, full: ?string}|null  $preview  always null: the lists show no preview
     * @param  array{thumb: ?string, full: ?string}|null  $sample  always null: the lists show no sample
     * @param  string  $category  the release's sub-category title, the Category cell's text
     * @param  string  $categoryPath  the root and sub-category ("Audio > MP3"), the Category cell's title
     * @param  int  $categoryId  the release's sub-category id; the details page's Similar releases sorts by its place in the Category menu's order
     * @param  string  $artist  the tags' album artist, else performer; '' when neither is tagged
     * @param  string  $album  the tags' album; '' when none is tagged
     * @param  string  $year  the tags' recorded year; '' when none is tagged
     * @param  string  $genres  the release's genres in `position` order joined with ", " (a name may hold a comma); '' with none
     * @param  bool  $unknownGenre  the release has no genre row and its tag's genre value reads "Unknown"
     * @param  array{url: string, type: string, title: ?string, artist: ?string, seconds: ?int}|null  $listen  the playable preview: today's player route, its type, the track title and artist, its length
     * @param  ?string  $cover  the stored cover of the release's current accepted album (AlbumCoverImages); null shows the placeholder
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
        public string $artist = '',
        public string $album = '',
        public string $year = '',
        public string $genres = '',
        public bool $unknownGenre = false,
        public ?array $listen = null,
        public ?string $cover = null,
    ) {}

    public function hasChips(): bool
    {
        return $this->hasReleaseChips() || $this->listen !== null;
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

    /**
     * The music line under the name (SPEC 5.5): "Artist – Album · Year", the parts the tags lack
     * left out; '' when the tags name neither an album nor an artist.
     */
    public function musicLine(): string
    {
        $line = implode(' – ', array_filter([$this->artist, $this->album], static fn (string $part): bool => $part !== ''));
        if ($line === '') {
            return '';
        }

        return $this->year === '' ? $line : $line.' · '.$this->year;
    }

    /** The Genre cell's text (SPEC 5.8): the genres, "Unknown" for a tag reading so, '' for "—". */
    public function genreText(): string
    {
        return $this->genres !== '' ? $this->genres : ($this->unknownGenre ? 'Unknown' : '');
    }

    /** The Listen chip's title: "Play the 30-second preview", or "Play the preview" with no length stored. */
    public function listenTitle(): string
    {
        $seconds = $this->listen['seconds'] ?? null;

        return $seconds === null ? 'Play the preview' : 'Play the '.$seconds.'-second preview';
    }
}
