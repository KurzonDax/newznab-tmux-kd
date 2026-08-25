<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Release;
use Illuminate\Support\Carbon;

final class TvEpisodeRevisitService
{
    public const int NO_MATCH_FOUND = -6;

    /**
     * Finalize known-show rows whose postdate window has elapsed.
     */
    public function finalizeExpired(
        string $groupId = '',
        string $guidChar = '',
        int|string|null $processTv = null,
    ): int {
        return TvProcessingCandidateQuery::query($groupId, $guidChar, $processTv)
            ->where('videos_id', '>', 0)
            ->where('postdate', '<', TvProcessingCandidateQuery::revisitWindowCutoff())
            ->update(['tv_episodes_id' => self::NO_MATCH_FOUND]);
    }

    /**
     * Settle the final provider's failure according to the release's partial match.
     */
    public function settleFinalFailure(int $releaseId): void
    {
        $release = Release::query()->find($releaseId, ['id', 'videos_id', 'postdate']);
        if ($release === null) {
            return;
        }

        $postdate = Carbon::parse((string) $release->postdate);

        if ((int) $release->videos_id <= 0 || $postdate->lt(TvProcessingCandidateQuery::revisitWindowCutoff())) {
            $release->newQuery()->whereKey($releaseId)->update([
                'tv_episodes_id' => self::NO_MATCH_FOUND,
            ]);

            return;
        }

        $release->newQuery()->whereKey($releaseId)->update([
            'tv_episodes_id' => TvProcessingCandidateQuery::FIRST_PROVIDER_STATUS,
            'tv_episode_lookup_attempted_at' => now(),
        ]);
    }
}
