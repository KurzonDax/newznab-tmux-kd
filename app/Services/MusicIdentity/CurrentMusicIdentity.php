<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Services\MusicIdentity\CoverArt\CoverArtKind;
use App\Services\MusicIdentity\Enums\IdentificationStatus;

/**
 * A release's effective current music identity decision (CurrentMusicIdentityReader): always a
 * completed decision. Only an accepted album (release group or edition) gives the release a cover
 * and the MusicBrainz link; any other completed state withdraws both.
 */
final readonly class CurrentMusicIdentity
{
    public function __construct(
        public int $identificationId,
        public int $releaseId,
        public IdentificationStatus $state,
        public ?string $musicBrainzReleaseId,
        public ?string $musicBrainzReleaseGroupId,
    ) {}

    /** From a `release_music_identifications` row read with id, releases_id, state and the two MusicBrainz ids. */
    public static function fromRow(object $row): self
    {
        return new self(
            identificationId: (int) $row->id,
            releaseId: (int) $row->releases_id,
            state: $row->state instanceof IdentificationStatus ? $row->state : IdentificationStatus::from((string) $row->state),
            musicBrainzReleaseId: self::id($row->musicbrainz_release_id),
            musicBrainzReleaseGroupId: self::id($row->musicbrainz_release_group_id),
        );
    }

    public function acceptsAlbum(): bool
    {
        return $this->state === IdentificationStatus::AcceptedEdition || $this->state === IdentificationStatus::AcceptedReleaseGroup;
    }

    /** The accepted album's MusicBrainz release group page; '' without an accepted album. */
    public function releaseGroupUrl(): string
    {
        return $this->acceptsAlbum() && $this->musicBrainzReleaseGroupId !== null
            ? 'https://musicbrainz.org/release-group/'.$this->musicBrainzReleaseGroupId
            : '';
    }

    /**
     * The Cover Art Archive lookup that holds the album's cover: an edition's own release (which
     * falls back to its release group), else the release group; null without an accepted album.
     *
     * @return array{0: CoverArtKind, 1: string}|null
     */
    public function coverLookup(): ?array
    {
        if (! $this->acceptsAlbum()) {
            return null;
        }
        if ($this->state === IdentificationStatus::AcceptedEdition && $this->musicBrainzReleaseId !== null) {
            return [CoverArtKind::Release, $this->musicBrainzReleaseId];
        }

        return $this->musicBrainzReleaseGroupId === null ? null : [CoverArtKind::ReleaseGroup, $this->musicBrainzReleaseGroupId];
    }

    private static function id(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
