<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/** One release row of the Movie releases screen (docs/proposals/movies-redesign/SPEC.md 5.4-5.7), ready to render. */
final readonly class MovieReleaseRow
{
    use ReleaseRowParts;

    /**
     * @param  int|null  $filmId  the release's film (releases.movieinfo_id), null when it has none
     * @param  string  $imdbId  the film's IMDb id, the Follow key (`movies:<imdbid>`)
     * @param  array{percent: int, band: string, repairing: bool}|null  $completion  null at 100% or when never measured
     * @param  array{thumb: ?string, full: ?string}|null  $preview
     * @param  array{thumb: ?string, full: ?string}|null  $sample
     * @param  bool  $watched  whether the user follows the film
     */
    public function __construct(
        public int $id,
        public string $guid,
        public string $name,
        public ?int $filmId,
        public string $filmTitle,
        public string $filmYear,
        public string $imdbId,
        public ?string $poster,
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

    public function hasFilm(): bool
    {
        return $this->filmId !== null;
    }

    /** The film page (`/movies/film/{movieinfo.id}`). */
    public function filmUrl(): string
    {
        return url('/movies/film/'.$this->filmId);
    }

    /** The grey film line under the name: `Title · Year`, or the title alone without a year. */
    public function filmLine(): string
    {
        return implode(' · ', array_filter([$this->filmTitle, $this->filmYear], static fn (string $part): bool => $part !== ''));
    }

    /**
     * The no-poster placeholder's name card (SPEC 5.6): a matched film's title and year, or the
     * title and year a film-less release name states before a quality word (the prototype's
     * `nameTitle()`); null for the "No poster" tile. Nothing is stripped from the title and
     * there is no letter rule; episodes, archive parts and a leading quote or bracket get no card.
     *
     * @return array{title: string, year: string}|null
     */
    public function nameCard(): ?array
    {
        if ($this->hasFilm()) {
            return ['title' => $this->filmTitle, 'year' => $this->filmYear];
        }
        if (preg_match('/S\d+E\d+|\.rar|\.part\d|^["\'(\[]/i', $this->name) === 1
            || preg_match('/^(.+?)[._ \-(\[]+((?:19|20)\d{2})[._ \-)\]]+(?:[^A-Za-z0-9]*)(?:2160p|1080[pi]|720p|576p|480p|UHD|BluRay|Blu-Ray|BDRip|BRRip|WEB|WEBRip|HDTV|DVD\w*|REMUX|MULTi|VFF|VFI|VOSTFR|FRENCH|GERMAN|iTALiAN|SPANiSH|HUN|DUAL|x26[45]|HEVC|AVC)\b/i', $this->name, $match) !== 1) {
            return null;
        }
        $title = trim((string) preg_replace(['/[._]+/', '/[\s\-]+$/'], [' ', ''], $match[1]));

        return $title === '' ? null : ['title' => $title, 'year' => $match[2]];
    }
}
