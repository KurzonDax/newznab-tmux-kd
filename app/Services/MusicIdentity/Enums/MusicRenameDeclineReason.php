<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

/** Why the rename gate left a release's name alone for an accepted album decision (issue #309). */
enum MusicRenameDeclineReason: string
{
    /** The decision stored no candidate evaluation to check. */
    case NoAcceptedEvaluation = 'no_accepted_evaluation';

    /** The accepted candidate contradicts the evidence. */
    case HardContradiction = 'hard_contradiction';

    /** The score is below `music-identity.scoring.minimum_album_score`. */
    case ScoreBelowMinimum = 'score_below_minimum';

    /** The runner-up margin is below `music-identity.scoring.minimum_runner_up_margin`. */
    case RunnerUpMarginBelowMinimum = 'runner_up_margin_below_minimum';

    /** A PreDB match names the release. */
    case PredbMatch = 'predb_match';

    /** A trusted source other than audio tags, or an admin, set the name. */
    case ProtectedName = 'protected_name';

    /** The decision stored no artist credit or title to build a name from. */
    case NoCanonicalName = 'no_canonical_name';

    /** The guarded naming write refused the release. */
    case RenameRefused = 'rename_refused';
}
