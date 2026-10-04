<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The music of an Audio release's details page (docs/proposals/audio-redesign/SPEC.md 5A and 5B,
 * DATA-CONTRACT.md 4.5): its tag row and genres. The album page is shown when the tags name an
 * album; the music line, the tags and the Performed by line read the rest.
 */
final readonly class AudioReleaseMusic
{
    /**
     * @param  string  $album  the tags' album; '' when none is tagged
     * @param  string  $artist  the album artist, else the performer; '' when neither is tagged
     * @param  string  $performedBy  the performer when the tags hold both an album artist and a performer and the two differ as written; '' otherwise
     * @param  string  $year  the tags' recorded year; '' when none is tagged
     * @param  array<int, string>  $genres  audio_genres.id => name, in `position` order
     * @param  bool  $unknownGenre  no genre row, and the tag's genre value has a part reading "Unknown"
     * @param  string  $format  the tags' audio format, MediaInfo's "MPEG Audio" shown as "MP3"; '' when none is stored
     * @param  string  $trackTitle  the previewed track's title tag; '' when none is stored
     */
    public function __construct(
        public string $album,
        public string $artist,
        public string $performedBy,
        public string $year,
        public array $genres,
        public bool $unknownGenre,
        public string $format,
        public string $trackTitle,
    ) {}

    /** Whether the tags name an album: the album page is shown. */
    public function hasAlbum(): bool
    {
        return $this->album !== '';
    }

    /** The release-only page's Genre fact: the genres joined with ", ", "Unknown" for a tag reading so, else "—". */
    public function genreFact(): string
    {
        return $this->genres !== [] ? implode(', ', $this->genres) : ($this->unknownGenre ? 'Unknown' : '—');
    }

    /**
     * The album page's outlined format tag (SPEC 5B.4, Appendix A): the format, only when it differs
     * from the sub-category's name and the media info chip's text does not start with it; '' otherwise.
     */
    public function formatTag(string $subCategory, ?string $mediaInfo): string
    {
        if ($this->format === '' || $this->format === $subCategory || str_starts_with((string) $mediaInfo, $this->format)) {
            return '';
        }

        return $this->format;
    }
}
