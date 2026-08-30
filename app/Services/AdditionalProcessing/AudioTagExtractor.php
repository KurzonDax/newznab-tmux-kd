<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use Mhor\MediaInfo\Attribute\Duration;
use Mhor\MediaInfo\Attribute\Mode;
use Mhor\MediaInfo\Container\MediaInfoContainer;

/**
 * Turns the tag bag MediaInfo reports for an audio file into the attribute set
 * stored on `release_audio_tags`.
 *
 * MediaInfo puts tags on the **General** track, never on the Audio tracks, and
 * with the OLDXML output format php-mediainfo uses, the `<extra>` fields a
 * tagger embedded (MusicBrainz identifiers among them) arrive flattened into
 * the same bag. Everything that has no column of its own is kept verbatim in
 * `raw_tags` so a later lookup can still use it.
 */
final class AudioTagExtractor
{
    /**
     * Column widths enforced here so an over-long tag cannot fail the insert.
     */
    private const array COLUMN_WIDTHS = [
        'album' => 255,
        'album_performer' => 255,
        'performer' => 255,
        'track_name' => 255,
        'genre' => 100,
        'recorded_date' => 32,
        'source_file' => 255,
        'audio_format' => 50,
    ];

    /**
     * Attribute name prefixes describing where the sampled file happened to
     * live on disk. Matched as prefixes against the normalised key, because
     * MediaInfo emits a `_Last` sibling for each of them whenever the input was
     * a concatenated set, and spells them inconsistently between versions
     * (`Complete_name`, `CompleteName_Last`). They are temp paths, so they are
     * dropped rather than stored.
     */
    private const array PATH_ATTRIBUTE_PREFIXES = [
        'completename',
        'foldername',
        'filename',
        'fileextension',
        'filelastmodificationdate',
        'coverdata',
    ];

    /**
     * Column => tagger-specific keys that fill it, normalised to lowercase
     * alphanumerics. First match wins, so the most specific alias leads.
     *
     * @var array<string, list<string>>
     */
    private const array MUSICBRAINZ_ALIASES = [
        'musicbrainz_album_id' => ['musicbrainzalbumid', 'musicbrainzreleaseid'],
        'musicbrainz_artist_id' => ['musicbrainzartistid', 'musicbrainzalbumartistid'],
        'musicbrainz_track_id' => ['musicbrainzreleasetrackid'],
        'musicbrainz_recording_id' => ['musicbrainzrecordingid', 'musicbrainztrackid'],
        'musicbrainz_release_group_id' => ['musicbrainzreleasegroupid'],
    ];

    private const array PROJECTION_COLUMNS = [
        'album',
        'album_performer',
        'performer',
        'track_name',
        'track_position',
        'track_position_total',
        'genre',
        'recorded_date',
        'recorded_year',
        'source_file',
        'audio_format',
        'raw_tags',
        'musicbrainz_album_id',
        'musicbrainz_artist_id',
        'musicbrainz_track_id',
        'musicbrainz_release_group_id',
    ];

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param  string  $sourceFile  Name of the file the tags were read from.
     * @return array<string, mixed>|null Null when the file carries no album or
     *                                   performer tag, which is the signal that
     *                                   there is nothing worth recording.
     */
    public function extract(MediaInfoContainer $container, string $sourceFile): ?array
    {
        $evidence = $this->extractEvidence($container, $sourceFile);
        if ($evidence === null || ($evidence['album'] === null && $evidence['performer'] === null)) {
            return null;
        }

        $projection = array_intersect_key($evidence, array_flip(self::PROJECTION_COLUMNS));
        // release_audio_tags predates the evidence ledger and historically
        // stores MUSICBRAINZ_TRACKID (a recording MBID) in this column.
        $projection['musicbrainz_track_id'] ??= $evidence['musicbrainz_recording_id'];

        return $projection;
    }

    /**
     * Return the complete local tag observation even when it is too sparse for
     * the one-row preview projection.
     *
     * @return array<string, mixed>|null
     */
    public function extractEvidence(MediaInfoContainer $container, string $sourceFile): ?array
    {
        $general = $container->getGeneral();
        if ($general === null) {
            return null;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $general->get();
        $audio = $container->getAudios()[0] ?? null;
        /** @var array<string, mixed> $audioAttributes */
        $audioAttributes = $audio?->get() ?? [];

        $album = $this->text($attributes['album'] ?? null);
        $performer = $this->text($attributes['performer'] ?? null);

        $recordedDate = $this->text($attributes['recorded_date'] ?? null);

        $tags = [
            'album' => $album,
            'album_performer' => $this->text($attributes['album_performer'] ?? null),
            'performer' => $performer,
            'track_name' => $this->text($attributes['track_name'] ?? null),
            'track_position' => $this->position($attributes, ['track_name_position', 'track_position', 'track']),
            'track_position_total' => $this->position($attributes, ['track_name_total', 'track_position_total']),
            'disc_position' => $this->position($attributes, ['part_position', 'disc_position', 'discnumber', 'disc']),
            'disc_position_total' => $this->position($attributes, ['part_position_total', 'disc_position_total', 'disctotal']),
            'genre' => $this->text($attributes['genre'] ?? null),
            'recorded_date' => $recordedDate,
            'recorded_year' => $this->year($recordedDate),
            'source_file' => $this->text($sourceFile),
            'audio_format' => $this->text($attributes['format'] ?? null),
            'container_format' => $this->text($attributes['format'] ?? null),
            'codec' => $this->text(
                $audioAttributes['format'] ?? $audioAttributes['codec_id'] ?? $audioAttributes['codec'] ?? null,
            ),
            'duration_seconds' => $this->durationSeconds($attributes['duration'] ?? null),
            'raw_tags' => $this->rawTags($attributes),
        ];

        return $this->truncate(array_merge(
            $tags,
            $this->musicBrainzIds($attributes),
            $this->evidenceIdentifiers($attributes),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    private function musicBrainzIds(array $attributes): array
    {
        $candidates = $this->candidates($attributes);

        $identifiers = [];
        foreach (self::MUSICBRAINZ_ALIASES as $column => $aliases) {
            $identifiers[$column] = null;
            foreach ($aliases as $alias) {
                $value = $candidates[$alias] ?? null;
                if ($value !== null && preg_match(self::UUID_PATTERN, $value) === 1) {
                    $identifiers[$column] = strtolower($value);
                    break;
                }
            }
        }

        return $identifiers;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{isrc: string|null, barcode: string|null, catalog_number: string|null, disc_id: string|null}
     */
    private function evidenceIdentifiers(array $attributes): array
    {
        $candidates = $this->candidates($attributes);

        return [
            'isrc' => $this->firstCandidate($candidates, ['isrc', 'isrccode']),
            'barcode' => $this->firstCandidate($candidates, ['barcode', 'upc', 'ean']),
            'catalog_number' => $this->firstCandidate($candidates, ['catalognumber', 'catalogue', 'catalog']),
            'disc_id' => $this->firstCandidate($candidates, ['musicbrainzdiscid', 'discid', 'cddbid']),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    private function candidates(array $attributes): array
    {
        $candidates = [];
        foreach ($attributes as $key => $value) {
            if (is_array($value) && $this->normalizeKey((string) $key) === 'extra') {
                foreach ($value as $extraKey => $extraValue) {
                    $candidates[$this->normalizeKey((string) $extraKey)] ??= $this->text($extraValue);
                }

                continue;
            }

            $candidates[$this->normalizeKey((string) $key)] ??= $this->text($value);
        }

        return $candidates;
    }

    /**
     * @param  array<string, string|null>  $candidates
     * @param  list<string>  $aliases
     */
    private function firstCandidate(array $candidates, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (($candidates[$alias] ?? null) !== null) {
                return $candidates[$alias];
            }
        }

        return null;
    }

    private function durationSeconds(mixed $value): ?float
    {
        if ($value instanceof Duration) {
            return round($value->getMilliseconds() / 1000, 3);
        }

        $text = $this->text($value);
        if ($text === null || preg_match('/\d+(?:\.\d+)?/', $text, $match) !== 1) {
            return null;
        }

        return round((float) $match[0], 3);
    }

    /**
     * Everything MediaInfo reported, scalarised, minus the on-disk location of
     * the sampled file.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function rawTags(array $attributes): array
    {
        $raw = [];
        foreach ($attributes as $key => $value) {
            if ($this->describesFileLocation((string) $key)) {
                continue;
            }

            $scalar = $this->scalarize($value);
            if ($scalar !== null) {
                $raw[$key] = $scalar;
            }
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $keys
     */
    private function position(array $attributes, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->text($attributes[$key] ?? null);
            // Taggers write "3" as often as "3/12"; only the leading number is
            // this column's business.
            if ($value !== null && preg_match('/\d+/', $value, $digits) === 1) {
                return min((int) $digits[0], 65535);
            }
        }

        return null;
    }

    /**
     * MediaInfo emits "2019", "2019-04-01" and "2019-04-01 00:00:00 UTC" for
     * the same tag, so the queryable year is pulled out of whichever shape
     * arrived.
     */
    private function year(?string $recordedDate): ?int
    {
        if ($recordedDate === null || preg_match('/(?:19|20)\d\d/', $recordedDate, $match) !== 1) {
            return null;
        }

        return (int) $match[0];
    }

    /**
     * Clip the width-limited columns in place, leaving every other value alone.
     *
     * @param  array<string, mixed>  $tags
     * @return array<string, mixed>
     */
    private function truncate(array $tags): array
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            if (is_string($tags[$column] ?? null)) {
                $tags[$column] = mb_substr($tags[$column], 0, $width);
            }
        }

        return $tags;
    }

    private function describesFileLocation(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        foreach (self::PATH_ATTRIBUTE_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    /**
     * Reduce one MediaInfo attribute to a trimmed, non-empty string. A repeated
     * tag arrives as an array; the first value is the one worth a column.
     */
    private function text(mixed $value): ?string
    {
        $scalar = $this->scalarize($value);

        while (is_array($scalar)) {
            $scalar = $scalar === [] ? null : reset($scalar);
        }

        return is_string($scalar) ? $scalar : null;
    }

    /**
     * php-mediainfo hands back plain strings for tags, attribute objects for
     * technical properties, and arrays whenever a tag was repeated.
     *
     * @return string|array<array-key, mixed>|null
     */
    private function scalarize(mixed $value): string|array|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Mode) {
            return $this->emptyToNull($value->getFullName());
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? $this->emptyToNull((string) $value) : null;
        }

        if (is_array($value)) {
            $nested = [];
            foreach ($value as $key => $item) {
                $scalar = $this->scalarize($item);
                if ($scalar !== null) {
                    $nested[$key] = $scalar;
                }
            }

            return $nested === [] ? null : $nested;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? $this->emptyToNull((string) $value) : null;
    }

    private function emptyToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
