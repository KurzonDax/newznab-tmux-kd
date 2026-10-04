<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One track of an Audio release's Tracks tab (docs/proposals/audio-redesign/SPEC.md 5C.2): the
 * number the # column shows, the title, the length in whole seconds and the disc.
 */
final readonly class AudioTrack
{
    /**
     * @param  int  $number  the stored track number, else the track's place in the list
     * @param  int|null  $seconds  the whole length rounded to a second; null when none is stored
     * @param  int|null  $disc  the stored disc number
     */
    public function __construct(
        public int $number,
        public string $title,
        public ?int $seconds,
        public ?int $disc,
    ) {}

    /** The Length cell: `m:ss`, '' when no length is stored. */
    public function length(): string
    {
        return $this->seconds === null ? '' : intdiv($this->seconds, 60).':'.str_pad((string) ($this->seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The total length of a track list when every track has one: under an hour `N min`, from an
     * hour `H h M min`; '' when a length is missing.
     *
     * @param  list<self>  $tracks
     */
    public static function totalLength(array $tracks): string
    {
        if ($tracks === [] || array_any($tracks, static fn (self $track): bool => $track->seconds === null)) {
            return '';
        }
        // Rounded to the minute first, so 59:50 reads "1 h 0 min" and never "60 min" or "1 h 60 min".
        $minutes = (int) round(array_sum(array_map(static fn (self $track): int => (int) $track->seconds, $tracks)) / 60);

        return $minutes >= 60 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : $minutes.' min';
    }

    /**
     * Whether any track of a list has a stored length: the table shows its Length column.
     *
     * @param  list<self>  $tracks
     */
    public static function anyLength(array $tracks): bool
    {
        return array_any($tracks, static fn (self $track): bool => $track->seconds !== null);
    }

    /**
     * Whether a list's tracks name more than one disc: a disc row comes before each disc's tracks.
     *
     * @param  list<self>  $tracks
     */
    public static function manyDiscs(array $tracks): bool
    {
        return count(array_unique(array_filter(array_map(static fn (self $track): ?int => $track->disc, $tracks), static fn (?int $disc): bool => $disc !== null))) > 1;
    }
}
