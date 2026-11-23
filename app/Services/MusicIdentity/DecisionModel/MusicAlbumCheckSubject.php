<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use App\Models\ReleaseMusicCandidateAttempt;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;

/**
 * One music matcher decision the model is asked to second-guess: the release's
 * name and file names it judges from, and the matcher's candidates as the
 * options it may pick from.
 *
 * The candidates are a stored identification's attempts in rank order, or the
 * summaries a decision not yet stored would store as its attempts (resolution
 * step 9, #1275), with the untitled ones (no title or no artist credit in the
 * display snapshot) left out, and then one row per MusicBrainz release group:
 * the lowest rank wins. A row with no release group is kept apart under its
 * lower-cased title and artist credit. The survivors are keyed "1", "2", … in
 * that order.
 */
final readonly class MusicAlbumCheckSubject
{
    /** The option that means "none of the candidates". */
    public const string NONE = 'none';

    /** At most this many file names are sent. */
    public const int FILE_LIMIT = 10;

    /**
     * @param  list<string>  $fileNames  Base names, cut at both path separators, at most FILE_LIMIT.
     * @param  list<array{key: string, rank: int, release_group_id: ?string, label: string}>  $candidates  De-duplicated, in rank order.
     * @param  int  $untitledCandidates  How many candidate rows were left out for want of a title or artist credit.
     * @param  IdentificationStatus  $state  The matcher's decision.
     * @param  ?string  $acceptedReleaseGroupId  The release group the matcher accepted; null when it accepted none.
     * @param  int  $releasesId  The release the decision is about.
     */
    public function __construct(
        public string $releaseName,
        public array $fileNames,
        public array $candidates,
        public int $untitledCandidates,
        public IdentificationStatus $state,
        public IdentificationBand $band,
        public int $score,
        public ?string $acceptedReleaseGroupId,
        public int $releasesId,
    ) {}

    /**
     * @param  list<string>  $fileNames  `release_files.name` values, ordered by name; the first FILE_LIMIT are kept.
     * @param  iterable<ReleaseMusicCandidateAttempt>  $attempts  The identification's stored candidates in rank order.
     */
    public static function build(ReleaseMusicIdentification $identification, string $releaseName, array $fileNames, iterable $attempts): self
    {
        $rows = [];
        foreach ($attempts as $attempt) {
            $rows[] = [(int) $attempt->rank, self::id($attempt->musicbrainz_release_group_id), $attempt->display_snapshot];
        }

        return self::fromRows(
            $rows,
            $releaseName,
            $fileNames,
            $identification->state,
            $identification->band,
            (int) $identification->score,
            self::id($identification->musicbrainz_release_group_id),
            (int) $identification->releases_id,
        );
    }

    /**
     * A decision the matcher has reached but not stored: its first $candidateLimit
     * candidate summaries, the rows IdentificationDecisionStore would store, ranked
     * from 1 in the decision's order. It accepts no album the check would compare.
     *
     * @param  list<string>  $fileNames  `release_files.name` values, ordered by name; the first FILE_LIMIT are kept.
     */
    public static function fromDecision(IdentificationDecision $decision, int $candidateLimit, int $releasesId, string $releaseName, array $fileNames): self
    {
        $rows = [];
        foreach (array_slice($decision->candidates, 0, max(0, $candidateLimit)) as $index => $summary) {
            $rows[] = [$index + 1, self::id($summary->identity->releaseGroupId), $summary->displaySnapshot];
        }

        return self::fromRows($rows, $releaseName, $fileNames, $decision->status, $decision->band, $decision->score, null, $releasesId);
    }

    /**
     * @param  list<array{0: int, 1: ?string, 2: array<string, mixed>}>  $rows  rank, release group, display snapshot, in rank order
     * @param  list<string>  $fileNames
     */
    private static function fromRows(
        array $rows,
        string $releaseName,
        array $fileNames,
        IdentificationStatus $state,
        IdentificationBand $band,
        int $score,
        ?string $acceptedReleaseGroupId,
        int $releasesId,
    ): self {
        $candidates = [];
        $untitled = 0;
        $seen = [];

        foreach ($rows as [$rank, $releaseGroupId, $snapshot]) {
            $label = self::label($snapshot);
            if ($label === null) {
                $untitled++;

                continue;
            }

            $dedupeKey = $releaseGroupId === null
                ? 'untracked|'.mb_strtolower((string) self::text($snapshot['title'] ?? null)).'|'.mb_strtolower((string) self::text($snapshot['artistCredit'] ?? null))
                : 'group|'.$releaseGroupId;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $candidates[] = [
                'key' => (string) (count($candidates) + 1),
                'rank' => $rank,
                'release_group_id' => $releaseGroupId,
                'label' => $label,
            ];
        }

        $names = array_map(self::baseName(...), array_slice(array_values($fileNames), 0, self::FILE_LIMIT));

        return new self($releaseName, $names, $candidates, $untitled, $state, $band, $score, $acceptedReleaseGroupId, $releasesId);
    }

    /**
     * The option text of a stored candidate: "<artistCredit> - <title>", then
     * " (<year>)" when the snapshot has a date; null when it has no title or no
     * artist credit, which makes the candidate impossible to label.
     *
     * @param  array<string, mixed>  $snapshot  The candidate's display snapshot.
     */
    public static function label(array $snapshot): ?string
    {
        $title = self::text($snapshot['title'] ?? null);
        $artistCredit = self::text($snapshot['artistCredit'] ?? null);
        if ($title === null || $artistCredit === null) {
            return null;
        }

        $label = $artistCredit.' - '.$title;
        $date = self::text($snapshot['date'] ?? null);
        if ($date !== null) {
            $label .= ' ('.mb_substr($date, 0, 4).')';
        }

        return $label;
    }

    /**
     * The text after the last `/` or `\`, whichever comes later: stored file
     * names carry either separator.
     */
    public static function baseName(string $name): string
    {
        return (string) preg_replace('~^.*[/\\\\]~s', '', $name);
    }

    /**
     * @return array{key: string, rank: int, release_group_id: ?string, label: string}|null
     */
    public function candidate(string $key): ?array
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate['key'] === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The offered candidate carrying the album the matcher accepted, or null
     * when the decision accepts no album or its row was untitled and left out.
     *
     * @return array{key: string, rank: int, release_group_id: ?string, label: string}|null
     */
    public function acceptedCandidate(): ?array
    {
        if (! $this->state->acceptsAlbum()) {
            return null;
        }

        $accepted = $this->acceptedReleaseGroupId;
        if ($accepted === null) {
            return null;
        }

        foreach ($this->candidates as $candidate) {
            if ($candidate['release_group_id'] === $accepted) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What the matcher had decided, as the decision log stores it beside the
     * model's answer. The options are kept here because the log does not store
     * the questions and the candidate rows go with the release.
     *
     * @param  bool  $matcherRenamed  Whether the release's search name was written from the matcher's own answer.
     * @return array<string, mixed>
     */
    public function pipelineAnswer(bool $matcherRenamed): array
    {
        $options = [];
        foreach ($this->candidates as $candidate) {
            $options[$candidate['key']] = [
                'rank' => $candidate['rank'],
                'release_group_id' => $candidate['release_group_id'],
                'label' => $candidate['label'],
            ];
        }

        return [
            'state' => $this->state->value,
            'band' => $this->band->value,
            'score' => $this->score,
            'accepted_release_group_id' => $this->acceptedReleaseGroupId,
            'candidate_count' => count($this->candidates),
            'untitled_candidates' => $this->untitledCandidates,
            'options' => $options,
            'matcher_renamed' => $matcherRenamed,
            'releases_id' => $this->releasesId,
        ];
    }

    /** A snapshot value as stored: a non-empty string, else null (JSON null, a missing key, an empty string). */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A MusicBrainz id column, blank-trimmed the way CurrentMusicIdentity reads it. */
    private static function id(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
