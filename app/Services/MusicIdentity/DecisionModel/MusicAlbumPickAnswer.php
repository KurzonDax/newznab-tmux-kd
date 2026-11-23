<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use App\Services\DecisionModel\DecisionRun;

/**
 * The decision model's answer to resolution step 9 (issue #1275), carried on the
 * decision it was asked about until that decision is stored and the answer can be
 * logged against it.
 */
final readonly class MusicAlbumPickAnswer
{
    /**
     * @param  array<string, mixed>  $pipelineAnswer  The matcher's decision before step 9, as the decision log stores it.
     * @param  bool  $applied  Whether the model's pick replaced the matcher's decision.
     */
    public function __construct(
        public DecisionRun $run,
        public array $pipelineAnswer,
        public bool $applied = false,
    ) {}

    /** The verdict, or null when the model could not be asked or its answer was unusable. */
    public function verdict(): ?MusicAlbumVerdict
    {
        return $this->run->verdict instanceof MusicAlbumVerdict ? $this->run->verdict : null;
    }

    /** The same answer, as the one that replaced the matcher's decision. */
    public function applied(): self
    {
        return new self($this->run, $this->pipelineAnswer, true);
    }
}
