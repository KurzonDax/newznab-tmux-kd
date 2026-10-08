<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationStatus;

/**
 * The MusicBrainz text an accepted decision makes searchable (issue #308), stored with the decision.
 * An accepted recording keeps only its title and artist credit. An accepted album keeps the release
 * group's canonical title apart from its search aliases (aliases never enter a rename), the release
 * artist credit, the aligned release's track titles and track artist credits, and the release group's
 * original first-release date; only an accepted edition keeps its own title and date. A component
 * MusicBrainz does not supply stays absent.
 */
final readonly class AcceptedMusicText
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $trackTitles
     * @param  list<string>  $trackArtistCredits  distinct, in track order
     */
    public function __construct(
        public AcceptedIdentityScope $scope,
        public ?string $title,
        public ?string $editionTitle = null,
        public array $aliases = [],
        public ?string $artistCredit = null,
        public array $trackTitles = [],
        public array $trackArtistCredits = [],
        public ?string $originalReleaseDate = null,
        public ?string $editionReleaseDate = null,
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

        $trackTitles = [];
        $trackArtistCredits = [];
        foreach ($release['media'] ?? [] as $medium) {
            foreach ($medium['releaseTracks'] as $track) {
                if (($title = self::text($track['title'])) !== null) {
                    $trackTitles[] = $title;
                }
                if (($credit = self::text($track['artistCredit'])) !== null && ! in_array($credit, $trackArtistCredits, true)) {
                    $trackArtistCredits[] = $credit;
                }
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

        return new self(
            scope: $scope,
            title: $title,
            editionTitle: $editionTitle,
            aliases: $aliases,
            artistCredit: self::text($release['artistCredit'] ?? null) ?? self::text($group['artistCredit'] ?? null),
            trackTitles: $trackTitles,
            trackArtistCredits: $trackArtistCredits,
            originalReleaseDate: self::date($group['firstReleaseDate'] ?? null),
            editionReleaseDate: $isEdition ? self::date($release['date'] ?? null) : null,
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
