<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/** One release row of the TV screens (the releases list and the show page's release tables), ready to render. */
final readonly class TvReleaseRow
{
    use ReleaseRowParts;

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

    /**
     * The no-poster placeholder's name card (SPEC appendix A, the prototype's `showName()`): the title the
     * release name states and its episode or air date, or null for the "No poster" tile. Nothing is
     * stripped from the title and there is no letter rule.
     *
     * @return array{title: string, episode: string}|null
     */
    public function nameCard(): ?array
    {
        if (preg_match('/\.rar|\.part\d|^["\'(\[]/i', $this->name) === 1
            || preg_match('/^(.+?)[._ \-]+(?:(S\d{1,4})[ ._-]?(E\d{1,4})(?:-?(E\d{1,4}))?|(\d{4})[._ \-](\d{2})[._ \-](\d{2})|(E\d{2,4}))(?=[._ \-]|$)/iu', $this->name, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        $title = trim((string) preg_replace(['/[._]+/u', '/[\s\-]+$/u'], [' ', ''], (string) $match[1]));
        if ($title === '') {
            return null;
        }

        return ['title' => $title, 'episode' => match (true) {
            $match[2] !== null => strtoupper($match[2].$match[3]).($match[4] !== null ? '–'.strtoupper($match[4]) : ''),
            $match[5] !== null => $match[5].'-'.$match[6].'-'.$match[7],
            default => strtoupper((string) $match[8]),
        }];
    }

    /** `Show · S01E02 · Episode title`, or just the show when the release declares nothing. */
    public function showLine(): string
    {
        return implode(' · ', array_filter([$this->showTitle, $this->episodeLabel], static fn (string $part): bool => $part !== ''));
    }
}
