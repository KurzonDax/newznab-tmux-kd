<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use App\Services\DecisionModel\DecisionVerdict;

/**
 * What the model's answer means for one matcher decision. Nothing acts on it:
 * the check runs in shadow, and the log is read later to decide whether it
 * ever should.
 */
final readonly class MusicAlbumVerdict implements DecisionVerdict
{
    /**
     * The probability at or above which a pick would be linked, were the answer
     * ever applied: the spike's threshold, fixed because nothing is applied.
     */
    public const float LINK_THRESHOLD = 0.8;

    /**
     * @param  string  $choice  The picked candidate key, or "none".
     * @param  float|null  $probability  The model's probability for its choice, when it gave one.
     * @param  int|null  $pickedRank  The picked candidate's matcher rank; null for "none".
     * @param  string|null  $pickedReleaseGroupId  The picked candidate's MusicBrainz release group; null for "none" or a candidate without one.
     * @param  bool  $wouldLink  The choice is a candidate at or above LINK_THRESHOLD.
     * @param  bool|null  $agreesWithAccepted  For an accepted album: whether a link would be the matcher's album (or one with the same label); null when the decision accepts no album or the accepted album was not offered.
     */
    public function __construct(
        public string $choice,
        public ?float $probability,
        public ?int $pickedRank,
        public ?string $pickedReleaseGroupId,
        public bool $wouldLink,
        public ?bool $agreesWithAccepted,
    ) {}

    public function toArray(): array
    {
        return [
            'choice' => $this->choice,
            'probability' => $this->probability,
            'picked_rank' => $this->pickedRank,
            'picked_release_group_id' => $this->pickedReleaseGroupId,
            'would_link' => $this->wouldLink,
            'link_threshold' => self::LINK_THRESHOLD,
            'agrees_with_accepted' => $this->agreesWithAccepted,
        ];
    }
}
