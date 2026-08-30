<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\Exceptions\InvalidMusicBrainzResponse;

/**
 * Turns accepted MusicBrainz responses into the module's stable schema.
 * Provider field spelling and optional wrappers never cross this boundary.
 *
 * @phpstan-import-type MusicRecording from CandidateMetadata
 * @phpstan-import-type MusicRelease from CandidateMetadata
 * @phpstan-import-type MusicReleaseGroup from CandidateMetadata
 */
final class MusicBrainzNormalizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @return MusicRecording
     */
    public function recording(
        array $raw,
        string $source,
        ?string $releaseId = null,
        ?string $releaseGroupId = null,
    ): array {
        $releaseIds = $releaseId === null ? [] : [$releaseId];
        $releaseGroupIds = $releaseGroupId === null ? [] : [$releaseGroupId];

        foreach ($this->list($raw, 'releases') as $release) {
            $release = $this->object($release, 'recording release');
            $releaseIds[] = $this->requiredString($release, 'id');
            $group = $release['release-group'] ?? null;
            if ($group !== null) {
                $group = $this->object($group, 'recording release group');
                $releaseGroupIds[] = $this->requiredString($group, 'id');
            }
        }

        return [
            'recordingId' => $this->requiredString($raw, 'id'),
            'title' => $this->requiredString($raw, 'title'),
            'artistCredit' => $this->artistCredit($raw['artist-credit'] ?? []),
            'lengthMs' => $this->nullableInteger($raw['length'] ?? null, 'recording length'),
            'video' => $this->nullableBoolean($raw['video'] ?? null, 'recording video'),
            'isrcs' => $this->stringList($raw['isrcs'] ?? [], 'recording ISRCs'),
            'releaseIds' => array_values(array_unique($releaseIds)),
            'releaseGroupIds' => array_values(array_unique($releaseGroupIds)),
            'providerScore' => $this->nullableInteger($raw['score'] ?? null, 'recording score'),
            'sources' => [$source],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return MusicRelease
     */
    public function release(array $raw): array
    {
        $releaseId = $this->requiredString($raw, 'id');
        $group = $raw['release-group'] ?? null;
        $group = $group === null ? null : $this->object($group, 'release group');
        $releaseGroupId = $group === null ? null : $this->nullableString($group['id'] ?? null, 'release group id');

        $labels = [];
        foreach ($this->list($raw, 'label-info') as $labelInfo) {
            $labelInfo = $this->object($labelInfo, 'release label info');
            $label = $labelInfo['label'] ?? null;
            $label = $label === null ? null : $this->object($label, 'release label');
            $labels[] = [
                'catalogNumber' => $this->nullableString($labelInfo['catalog-number'] ?? null, 'catalog number'),
                'labelId' => $label === null ? null : $this->nullableString($label['id'] ?? null, 'label id'),
                'labelName' => $label === null ? null : $this->nullableString($label['name'] ?? null, 'label name'),
            ];
        }

        $media = [];
        foreach ($this->list($raw, 'media') as $medium) {
            $medium = $this->object($medium, 'release medium');
            $tracks = [];
            foreach ($this->list($medium, 'tracks') as $track) {
                $track = $this->object($track, 'release track');
                $recording = $track['recording'] ?? null;
                $recording = $recording === null ? null : $this->object($recording, 'release track recording');
                $tracks[] = [
                    'trackId' => $this->requiredString($track, 'id'),
                    'title' => $this->requiredString($track, 'title'),
                    'position' => $this->nullableInteger($track['position'] ?? null, 'track position'),
                    'number' => $this->nullableString($track['number'] ?? null, 'track number'),
                    'lengthMs' => $this->nullableInteger($track['length'] ?? null, 'track length'),
                    'artistCredit' => $this->artistCredit($track['artist-credit'] ?? []),
                    'recording' => $recording === null ? null : $this->recording(
                        $recording,
                        'release_hydration',
                        $releaseId,
                        $releaseGroupId,
                    ),
                ];
            }

            $discIds = [];
            foreach ($this->list($medium, 'discs') as $disc) {
                $disc = $this->object($disc, 'medium disc');
                $discIds[] = $this->requiredString($disc, 'id');
            }

            $media[] = [
                'position' => $this->nullableInteger($medium['position'] ?? null, 'medium position'),
                'title' => $this->nullableString($medium['title'] ?? null, 'medium title'),
                'format' => $this->nullableString($medium['format'] ?? null, 'medium format'),
                'trackCount' => $this->nullableInteger($medium['track-count'] ?? null, 'medium track count'),
                'discIds' => $discIds,
                'tracks' => $tracks,
            ];
        }

        return [
            'releaseId' => $releaseId,
            'title' => $this->requiredString($raw, 'title'),
            'artistCredit' => $this->artistCredit($raw['artist-credit'] ?? []),
            'releaseGroupId' => $releaseGroupId,
            'status' => $this->nullableString($raw['status'] ?? null, 'release status'),
            'date' => $this->nullableString($raw['date'] ?? null, 'release date'),
            'country' => $this->nullableString($raw['country'] ?? null, 'release country'),
            'barcode' => $this->nullableString($raw['barcode'] ?? null, 'release barcode'),
            'labels' => $labels,
            'media' => $media,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return MusicReleaseGroup
     */
    public function releaseGroup(array $raw): array
    {
        return [
            'releaseGroupId' => $this->requiredString($raw, 'id'),
            'title' => $this->requiredString($raw, 'title'),
            'artistCredit' => $this->artistCredit($raw['artist-credit'] ?? []),
            'primaryType' => $this->nullableString($raw['primary-type'] ?? null, 'release group primary type'),
            'secondaryTypes' => $this->stringList($raw['secondary-types'] ?? [], 'release group secondary types'),
            'firstReleaseDate' => $this->nullableString($raw['first-release-date'] ?? null, 'release group first release date'),
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     * @return list<MusicRecording>
     */
    public function recordingsFromRelease(array $release, string $source): array
    {
        $releaseId = $this->requiredString($release, 'id');
        $group = $release['release-group'] ?? null;
        $group = $group === null ? null : $this->object($group, 'release group');
        $releaseGroupId = $group === null ? null : $this->nullableString($group['id'] ?? null, 'release group id');
        $recordings = [];

        foreach ($this->list($release, 'media') as $medium) {
            $medium = $this->object($medium, 'release medium');
            foreach ($this->list($medium, 'tracks') as $track) {
                $track = $this->object($track, 'release track');
                $recording = $this->object($track['recording'] ?? null, 'release track recording');
                $recordings[] = $this->recording($recording, $source, $releaseId, $releaseGroupId);
            }
        }

        return $recordings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function objects(array $payload, string $key): array
    {
        $objects = [];
        foreach ($this->requiredList($payload, $key) as $value) {
            $objects[] = $this->object($value, $key.' item');
        }

        return $objects;
    }

    /** @param array<string, mixed> $payload */
    public function requiredCount(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz response field "%s" must be an integer.', $key));
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $raw */
    private function requiredString(array $raw, string $key): string
    {
        $value = $this->nullableString($raw[$key] ?? null, $key);
        if ($value === null) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz response field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    private function nullableString(mixed $value, string $description): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz %s must be a string or null.', $description));
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableInteger(mixed $value, string $description): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz %s must be an integer or null.', $description));
        }

        return (int) $value;
    }

    private function nullableBoolean(mixed $value, string $description): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (! is_bool($value)) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz %s must be a boolean or null.', $description));
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $description): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz %s must be a list.', $description));
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz %s must contain only non-empty strings.', $description));
            }
            $strings[] = $item;
        }

        return array_values(array_unique($strings));
    }

    private function artistCredit(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidMusicBrainzResponse('MusicBrainz artist credit must be a list.');
        }

        $credit = '';
        foreach ($value as $item) {
            $item = $this->object($item, 'artist credit');
            $artist = $item['artist'] ?? null;
            $artist = $artist === null ? null : $this->object($artist, 'artist credit artist');
            $name = $this->nullableString($item['name'] ?? null, 'artist credited name')
                ?? ($artist === null ? null : $this->nullableString($artist['name'] ?? null, 'artist name'));
            if ($name === null) {
                throw new InvalidMusicBrainzResponse('MusicBrainz artist credit has no usable name.');
            }
            $credit .= $name.($this->nullableString($item['joinphrase'] ?? null, 'artist join phrase') ?? '');
        }

        return $credit;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<mixed>
     */
    private function list(array $raw, string $key): array
    {
        if (! array_key_exists($key, $raw)) {
            return [];
        }

        return $this->requiredList($raw, $key);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<mixed>
     */
    private function requiredList(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz response field "%s" must be a list.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $description): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidMusicBrainzResponse(sprintf('MusicBrainz %s must be an object.', $description));
        }

        return $value;
    }
}
