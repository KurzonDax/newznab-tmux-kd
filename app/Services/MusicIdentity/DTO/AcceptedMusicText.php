<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationStatus;

/**
 * The MusicBrainz text an accepted decision makes searchable (issue #308), stored with the decision.
 * An accepted recording keeps only its title and artist credit. An accepted album keeps the release
 * group's canonical title apart from its search aliases (aliases never enter a rename), the release
 * artist credit, and the release group's original first-release date; only an accepted edition keeps
 * its own title and date. An accepted album also names the aligned release it took its text from and
 * carries that release's track list, and the artists of the credit it took (the release's, else the
 * release group's) with their canonical names and searched aliases (issue #313); both are stored
 * once per MusicBrainz release or artist rather than with the decision. A component MusicBrainz
 * does not supply stays absent.
 *
 * @phpstan-import-type MusicCreditedArtist from CandidateMetadata
 *
 * @phpstan-type AcceptedTrack array{mediumPosition: int, trackPosition: int, title: string, lengthMs: int|null, artistCredit: string|null}
 */
final readonly class AcceptedMusicText
{
    /**
     * @param  list<string>  $aliases
     * @param  string|null  $releaseId  the aligned MusicBrainz release an accepted album's text came from
     * @param  list<AcceptedTrack>  $tracks  that release's tracks in MusicBrainz medium and track order
     * @param  list<MusicCreditedArtist>  $artists  the album artists, in credit order
     */
    public function __construct(
        public AcceptedIdentityScope $scope,
        public ?string $title,
        public ?string $editionTitle = null,
        public array $aliases = [],
        public ?string $artistCredit = null,
        public ?string $originalReleaseDate = null,
        public ?string $editionReleaseDate = null,
        public ?string $releaseId = null,
        public array $tracks = [],
        public array $artists = [],
    ) {}

    /** The text of the evaluation an accepted status was decided from; null for any other status. */
    public static function fromAcceptance(IdentificationStatus $status, CandidateEvaluation $evaluation): ?self
    {
        $metadata = $evaluation->candidate->metadata;

        return match ($status) {
            IdentificationStatus::AcceptedRecording => self::recording($metadata, $evaluation->candidate->uniqueRecordingId()),
            IdentificationStatus::AcceptedReleaseGroup => self::album(AcceptedIdentityScope::ReleaseGroup, $metadata, $evaluation->alignedIdentity),
            IdentificationStatus::AcceptedEdition => self::album(AcceptedIdentityScope::Edition, $metadata, $evaluation->alignedIdentity),
            default => null,
        };
    }

    private static function recording(CandidateMetadata $metadata, ?string $recordingId): ?self
    {
        if ($recordingId === null) {
            return null;
        }
        $recordings = $metadata->recordings;
        foreach ($metadata->releases as $release) {
            foreach ($release['media'] as $medium) {
                foreach ($medium['releaseTracks'] as $track) {
                    if ($track['recording'] !== null) {
                        $recordings[] = $track['recording'];
                    }
                }
            }
        }
        foreach ($recordings as $recording) {
            if ($recording['recordingId'] === $recordingId && self::text($recording['title']) !== null) {
                return new self(AcceptedIdentityScope::Recording, self::text($recording['title']), artistCredit: self::text($recording['artistCredit']));
            }
        }

        return null;
    }

    private static function album(AcceptedIdentityScope $scope, CandidateMetadata $metadata, CandidateIdentity $aligned): self
    {
        $release = null;
        foreach ($metadata->releases as $candidate) {
            if ($aligned->releaseId !== null && $candidate['releaseId'] === $aligned->releaseId) {
                $release = $candidate;
                break;
            }
        }
        $group = null;
        foreach ($metadata->releaseGroups as $candidate) {
            if ($aligned->releaseGroupId !== null && $candidate['releaseGroupId'] === $aligned->releaseGroupId) {
                $group = $candidate;
                break;
            }
        }
        $isEdition = $scope === AcceptedIdentityScope::Edition;

        // MusicBrainz's order; a missing position is the medium's place in the release, or the
        // track's place on its medium. Titles and credits are kept exactly as MusicBrainz has them.
        $tracks = [];
        foreach (array_values($release['media'] ?? []) as $mediumIndex => $medium) {
            foreach (array_values($medium['releaseTracks']) as $trackIndex => $track) {
                $tracks[] = [
                    'mediumPosition' => $medium['position'] ?? $mediumIndex + 1,
                    'trackPosition' => $track['position'] ?? $trackIndex + 1,
                    'title' => $track['title'],
                    'lengthMs' => $track['lengthMs'],
                    'artistCredit' => $track['artistCredit'],
                ];
            }
        }

        $title = self::text($group['title'] ?? null);
        $editionTitle = $isEdition ? self::text($release['title'] ?? null) : null;
        $aliases = [];
        foreach ([...($group['aliases'] ?? []), ...($isEdition ? $release['aliases'] ?? [] : [])] as $alias) {
            $alias = self::text($alias);
            if ($alias !== null && $alias !== $title && $alias !== $editionTitle && ! in_array($alias, $aliases, true)) {
                $aliases[] = $alias;
            }
        }

        $releaseCredit = self::text($release['artistCredit'] ?? null);

        return new self(
            scope: $scope,
            title: $title,
            editionTitle: $editionTitle,
            aliases: $aliases,
            artistCredit: $releaseCredit ?? self::text($group['artistCredit'] ?? null),
            originalReleaseDate: self::date($group['firstReleaseDate'] ?? null),
            editionReleaseDate: $isEdition ? self::date($release['date'] ?? null) : null,
            releaseId: $release['releaseId'] ?? null,
            tracks: $tracks,
            artists: $releaseCredit !== null ? $release['artists'] ?? [] : $group['artists'] ?? [],
        );
    }

    /** A MusicBrainz date (YYYY, YYYY-MM or YYYY-MM-DD); anything else is absent. */
    private static function date(?string $value): ?string
    {
        $value = self::text($value);

        return $value !== null && preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $value) === 1 ? $value : null;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
