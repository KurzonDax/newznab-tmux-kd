<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use App\Models\ReleaseMusicCandidateAttempt;
use App\Models\ReleaseMusicIdentification;

/**
 * One music matcher decision the model is asked to second-guess: the release's
 * name and file names it judges from, and the matcher's stored candidates as
 * the options it may pick from.
 *
 * Candidates are the identification's stored attempts in rank order, with the
 * untitled ones (no title or no artist credit in the display snapshot) left
 * out, and then one row per MusicBrainz release group: the lowest rank wins.
 * A row with no release group is kept apart under its lower-cased title and
 * artist credit. The survivors are keyed "1", "2", … in that order.
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
     * @param  int  $untitledCandidates  How many stored rows were left out for want of a title or artist credit.
     */
    public function __construct(
        public ReleaseMusicIdentification $identification,
        public string $releaseName,
        public array $fileNames,
        public array $candidates,
        public int $untitledCandidates = 0,
    ) {}

    /**
     * @param  list<string>  $fileNames  `release_files.name` values, ordered by name; the first FILE_LIMIT are kept.
     * @param  iterable<ReleaseMusicCandidateAttempt>  $attempts  The identification's stored candidates in rank order.
     */
    public static function build(ReleaseMusicIdentification $identification, string $releaseName, array $fileNames, iterable $attempts): self
    {
        $candidates = [];
        $untitled = 0;
        $seen = [];

        foreach ($attempts as $attempt) {
            $label = self::label($attempt->display_snapshot);
            if ($label === null) {
                $untitled++;

                continue;
            }

            $releaseGroupId = self::id($attempt->musicbrainz_release_group_id);
            $dedupeKey = $releaseGroupId === null
                ? 'untracked|'.mb_strtolower((string) self::text($attempt->display_snapshot['title'] ?? null)).'|'.mb_strtolower((string) self::text($attempt->display_snapshot['artistCredit'] ?? null))
                : 'group|'.$releaseGroupId;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $candidates[] = [
                'key' => (string) (count($candidates) + 1),
                'rank' => (int) $attempt->rank,
                'release_group_id' => $releaseGroupId,
                'label' => $label,
            ];
        }

        $names = array_map(self::baseName(...), array_slice(array_values($fileNames), 0, self::FILE_LIMIT));

        return new self($identification, $releaseName, $names, $candidates, $untitled);
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
        if (! $this->identification->state->acceptsAlbum()) {
            return null;
        }

        $accepted = self::id($this->identification->musicbrainz_release_group_id);
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
            'state' => $this->identification->state->value,
            'band' => $this->identification->band->value,
            'score' => (int) $this->identification->score,
            'accepted_release_group_id' => self::id($this->identification->musicbrainz_release_group_id),
            'candidate_count' => count($this->candidates),
            'untitled_candidates' => $this->untitledCandidates,
            'options' => $options,
            'matcher_renamed' => $matcherRenamed,
            'releases_id' => (int) $this->identification->releases_id,
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
