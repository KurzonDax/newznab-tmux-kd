<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use App\Services\DecisionModel\DecisionUseCase;
use App\Services\DecisionModel\Exceptions\MalformedDecisionAnswer;
use App\Services\DecisionModel\Questions\ChoiceQuestion;
use InvalidArgumentException;

/**
 * Asks the decision model which of the music matcher's stored candidate
 * albums a release is, or none of them, from the release name and up to ten
 * of its file names (issue #1140).
 *
 * One request, one choice question. The state is the release name with its
 * file names, or the bare name when the release has no stored files. The
 * options are the subject's de-duplicated candidates plus "none".
 *
 * An album answer that is missing, failed or names neither a candidate nor
 * "none" is malformed.
 */
final class MusicAlbumCheckUseCase implements DecisionUseCase
{
    public const string NAME = 'music_album_check';

    /** The id the album question is asked under. */
    public const string ALBUM = 'album';

    public function __construct(private ?MusicAlbumCriteria $criteria = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function criteriaVersion(): string
    {
        return $this->criteria()->version;
    }

    /**
     * @return string|array{release_name: string, files: list<string>}
     */
    public function state(object $subject): string|array
    {
        $subject = $this->subject($subject);

        if ($subject->fileNames === []) {
            return $subject->releaseName;
        }

        return ['release_name' => $subject->releaseName, 'files' => $subject->fileNames];
    }

    public function questions(object $subject, array $responses): array
    {
        if ($responses !== []) {
            return [];
        }

        $options = [];
        foreach ($this->subject($subject)->candidates as $candidate) {
            $options[$candidate['key']] = $candidate['label'];
        }
        $options[MusicAlbumCheckSubject::NONE] = null;

        return [self::ALBUM => new ChoiceQuestion($this->criteria()->instructions, $options)];
    }

    public function interpret(object $subject, array $responses): MusicAlbumVerdict
    {
        $subject = $this->subject($subject);

        $answer = ($responses[0] ?? null)?->usableAnswer(self::ALBUM);
        $choice = $answer?->choice;
        if ($answer === null || $choice === null) {
            throw new MalformedDecisionAnswer('album_answer_unusable');
        }

        $picked = $subject->candidate($choice);
        if ($picked === null && $choice !== MusicAlbumCheckSubject::NONE) {
            throw new MalformedDecisionAnswer('album_answer_unusable');
        }

        $probability = $answer->probabilities[$choice] ?? null;
        $wouldLink = $picked !== null && $probability !== null && $probability >= MusicAlbumVerdict::LINK_THRESHOLD;

        $agreesWithAccepted = null;
        $accepted = $subject->acceptedCandidate();
        if ($accepted !== null) {
            $agreesWithAccepted = $wouldLink && $picked !== null
                && ($picked['release_group_id'] === $accepted['release_group_id'] || $picked['label'] === $accepted['label']);
        }

        return new MusicAlbumVerdict(
            choice: $choice,
            probability: $probability,
            pickedRank: $picked['rank'] ?? null,
            pickedReleaseGroupId: $picked['release_group_id'] ?? null,
            wouldLink: $wouldLink,
            agreesWithAccepted: $agreesWithAccepted,
        );
    }

    private function subject(object $subject): MusicAlbumCheckSubject
    {
        return $subject instanceof MusicAlbumCheckSubject
            ? $subject
            : throw new InvalidArgumentException('The music album check decides about a '.MusicAlbumCheckSubject::class.'.');
    }

    /**
     * Read on first use, so a process that never asks the model never opens the file.
     */
    private function criteria(): MusicAlbumCriteria
    {
        return $this->criteria ??= MusicAlbumCriteria::fromFile(
            rtrim((string) config('decision-model.criteria_path'), '/').'/'.self::NAME.'.json',
        );
    }
}
