<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use App\Models\Settings;
use App\Services\DecisionModel\DecisionMode;
use App\Services\DecisionModel\DecisionModelClient;
use App\Services\DecisionModel\DecisionRunner;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The question of resolution step 9 (issue #1275): in Apply mode, a decision the
 * matcher left unresolved or for review is put to the decision model while the
 * release is matched, exactly as the shadow check puts a stored decision
 * ({@see MusicAlbumCheck}): the release's search name, up to ten file names, and
 * the candidates the decision would store, in the matcher's order, plus "none".
 *
 * Nothing is asked of a release under {@see DecisionRunner::MIN_RELEASE_COMPLETION}%
 * complete or of a decision with no titled candidate. When the service is down,
 * busy or refusing the key, there is no answer and nothing to log; the shadow
 * catch-up asks about the stored decision later.
 */
final readonly class MusicAlbumPick
{
    public function __construct(
        private DecisionModelClient $client,
        private DecisionRunner $runner,
    ) {}

    /** The model's answer about the decision, or null when it was not asked or could not be reached. */
    public function ask(AudioEvidenceSet $evidence, IdentificationDecision $decision): ?MusicAlbumPickAnswer
    {
        if (! in_array($decision->status, [IdentificationStatus::Unresolved, IdentificationStatus::NeedsReview], true)
            || DecisionMode::fromSetting(Settings::settingValue(MusicAlbumCheck::SETTING)) !== DecisionMode::Apply
            || ! $this->client->isUsable()) {
            return null;
        }

        $release = DB::table('release_audio_evidence as e')
            ->join('releases as r', 'r.id', '=', 'e.releases_id')
            ->where('e.id', $evidence->evidenceId)
            ->first(['r.id', 'r.searchname', 'r.completion']);
        if ($release === null || (float) $release->completion < DecisionRunner::MIN_RELEASE_COMPLETION) {
            return null;
        }

        $releaseId = (int) $release->id;
        $subject = MusicAlbumCheckSubject::fromDecision(
            $decision,
            (int) config('music-identity.candidate_attempt_limit', 5),
            $releaseId,
            (string) $release->searchname,
            MusicAlbumCheck::fileNamesOf($releaseId),
        );
        if ($subject->candidates === []) {
            return null;
        }

        $run = $this->runner->run(MusicAlbumCheckUseCase::NAME, $subject);
        if ($run->failed() && MusicAlbumCheck::serviceUnavailable((string) $run->failureReason)) {
            Log::warning('music_identity.album_pick_unavailable', [
                'release_id' => $releaseId,
                'reason' => $run->failureReason,
            ]);

            return null;
        }

        return new MusicAlbumPickAnswer($run, $subject->pipelineAnswer(false));
    }
}
