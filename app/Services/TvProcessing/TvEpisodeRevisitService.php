<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;
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
            ->where('postdate', '<', TvProcessingCandidateQuery::revisitWindowCutoff())
            ->where(function (Builder $finalizable): void {
                $finalizable->where('videos_id', '>', 0)
                    ->orWhere(function (Builder $ambiguous): void {
                        $ambiguous->where('videos_id', 0)
                            ->whereNotNull('tv_episode_lookup_attempted_at');
                    });
            })
            ->update(['tv_episodes_id' => self::NO_MATCH_FOUND]);
    }

    /**
     * Settle the final provider's failure according to the release's partial match.
     */
    public function settleFinalFailure(int $releaseId, bool $ambiguous = false): void
    {
        $release = Release::query()->find($releaseId, ['id', 'videos_id', 'postdate']);
        if ($release === null) {
            return;
        }

        $postdate = Carbon::parse((string) $release->postdate);

        if (((int) $release->videos_id <= 0 && ! $ambiguous)
            || $postdate->lt(TvProcessingCandidateQuery::revisitWindowCutoff())) {
            $updates = [
                'tv_episodes_id' => self::NO_MATCH_FOUND,
            ];
            if ($ambiguous) {
                $updates['videos_id'] = 0;
            }

            $release->newQuery()->whereKey($releaseId)->update($updates);

            return;
        }

        $updates = [
            'tv_episodes_id' => TvProcessingCandidateQuery::FIRST_PROVIDER_STATUS,
            'tv_episode_lookup_attempted_at' => now(),
        ];
        if ($ambiguous) {
            $updates['videos_id'] = 0;
        }

        $release->newQuery()->whereKey($releaseId)->update($updates);
    }
}
