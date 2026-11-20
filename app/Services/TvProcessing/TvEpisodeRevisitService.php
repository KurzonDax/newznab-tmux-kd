<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Release;
use App\Models\TvEpisode;
use App\Models\TvInfo;
use App\Models\Video;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\ProviderGateway\ProviderGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TvEpisodeRevisitService
{
    public const int NO_MATCH_FOUND = -6;

    private const int FINALIZE_CHUNK_SIZE = 100;

    /** A parsed season of at least this is a year, compared only with year-numbered seasons. */
    public const int YEAR_SEASON_MINIMUM = 1900;

    /**
     * Finalize known-show rows whose postdate window has elapsed.
     */
    public function finalizeExpired(
        string $groupId = '',
        string $guidChar = '',
        int|string|null $processTv = null,
    ): int {
        $finalized = 0;

        try {
            $query = TvProcessingCandidateQuery::query($groupId, $guidChar, $processTv)
                ->where('postdate', '<', TvProcessingCandidateQuery::revisitWindowCutoff())
                ->where(function (Builder $finalizable): void {
                    $finalizable->where('videos_id', '>', 0)
                        ->orWhere(function (Builder $ambiguous): void {
                            $ambiguous->where('videos_id', 0)
                                ->whereNotNull('tv_episode_lookup_attempted_at');
                        });
                });

            $candidateIds = (clone $query)->pluck('id')->all();
            if ($candidateIds === []) {
                return 0;
            }

            foreach (array_chunk($candidateIds, self::FINALIZE_CHUNK_SIZE) as $candidateIdChunk) {
                $finalized += DB::transaction(
                    fn (): int => (clone $query)
                        ->whereKey($candidateIdChunk)
                        ->update(['tv_episodes_id' => self::NO_MATCH_FOUND]),
                    3,
                );
            }

            return $finalized;
        } catch (Throwable $exception) {
            Log::warning('TV revisit finalization failed; processing will continue.', [
                'group_id' => $groupId,
                'guid_char' => $guidChar,
                'process_tv' => $processTv,
                'finalized' => $finalized,
                'exception' => $exception,
            ]);

            return $finalized;
        }
    }

    /**
     * Settle the final provider's failure according to the release's partial match.
     *
     * A known show's miss is retried later only while its episode could still be added to the
     * show's catalog, or when a lookup provider could not be asked during this pass.
     *
     * @param  array<string, mixed>|null  $parsedInfo  The release's parseInfo() result.
     */
    public function settleFinalFailure(int $releaseId, bool $ambiguous = false, ?array $parsedInfo = null): void
    {
        $release = Release::query()->whereRaw(RecoveryIdentityPolicy::singleItemSql())->whereRaw(BundleIdentity::singleItemSql())->find($releaseId, ['id', 'videos_id', 'postdate']);
        if ($release === null) {
            return;
        }

        $postdate = Carbon::parse((string) $release->postdate);

        if (((int) $release->videos_id <= 0 && ! $ambiguous)
            || $postdate->lt(TvProcessingCandidateQuery::revisitWindowCutoff())
            || (! $ambiguous && ! $this->episodeCouldStillBeListed((int) $release->videos_id, $parsedInfo))) {
            $updates = [
                'tv_episodes_id' => self::NO_MATCH_FOUND,
            ];
            if ($ambiguous) {
                $updates['videos_id'] = 0;
            }

            $release->newQuery()->whereKey($releaseId)->whereRaw(RecoveryIdentityPolicy::singleItemSql())->whereRaw(BundleIdentity::singleItemSql())->update($updates);

            return;
        }

        $updates = [
            'tv_episodes_id' => TvProcessingCandidateQuery::FIRST_PROVIDER_STATUS,
            'tv_episode_lookup_attempted_at' => now(),
        ];
        if ($ambiguous) {
            $updates['videos_id'] = 0;
        }

        $release->newQuery()->whereKey($releaseId)->whereRaw(RecoveryIdentityPolicy::singleItemSql())->whereRaw(BundleIdentity::singleItemSql())->update($updates);
    }

    /**
     * Whether a later pass could still find the episode of a known show's missed release.
     *
     * @param  array<string, mixed>|null  $parsedInfo
     */
    private function episodeCouldStillBeListed(int $videoId, ?array $parsedInfo): bool
    {
        // A provider that could not be asked has not said the episode does not exist.
        if (app(ProviderGateway::class)->unavailableCount(...TvProcessingPipeline::LOOKUP_PROVIDERS) > 0) {
            return true;
        }

        $season = self::positiveNumber($parsedInfo['season'] ?? null, 'S');
        $episode = self::positiveNumber($parsedInfo['episode'] ?? null, 'E');
        $airdate = $parsedInfo['airdate'] ?? null;
        $airdate = \is_string($airdate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $airdate) === 1 ? $airdate : null;
        $cutoff = TvProcessingCandidateQuery::revisitWindowCutoff();

        $hasNumber = $season !== null && $episode !== null;
        $episodes = TvEpisode::query()->where('videos_id', $videoId);

        // The episode was stored during this pass; the next pass binds it from the local DB.
        if (($hasNumber || $airdate !== null) && (clone $episodes)
            ->where(function (Builder $match) use ($hasNumber, $season, $episode, $airdate): void {
                if ($hasNumber) {
                    $match->orWhere(fn (Builder $numbered) => $numbered->where('series', $season)->where('episode', $episode));
                }
                if ($airdate !== null) {
                    $match->orWhereDate('firstaired', $airdate);
                }
            })->exists()) {
            return true;
        }

        if (self::isFinished($videoId)) {
            return false;
        }

        $sameScheme = clone $episodes;
        if ($season === null) {
            $sameScheme->where('series', '>', 0);
        } elseif ($season >= self::YEAR_SEASON_MINIMUM) {
            $sameScheme->where('series', '>=', self::YEAR_SEASON_MINIMUM);
        } else {
            $sameScheme->whereBetween('series', [1, self::YEAR_SEASON_MINIMUM - 1]);
        }

        $newest = $sameScheme->orderByDesc('series')->orderByDesc('episode')->first(['series', 'episode']);
        if ($newest === null) {
            // Only a show that has just started may still get its episode list filled.
            $started = Video::query()->whereKey($videoId)->value('started');

            return $started === null || Carbon::parse((string) $started)->gte($cutoff);
        }

        $newestSeries = (int) $newest->series;
        if ($hasNumber && ($season > $newestSeries || ($season === $newestSeries && $episode > (int) $newest->episode))) {
            return true;
        }

        if ($airdate !== null) {
            $latestAired = (clone $episodes)->whereNotNull('firstaired')->max('firstaired');
            if ($latestAired !== null && $airdate >= substr((string) $latestAired, 0, 10)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a show is finished: TMDB reports it ended and none of its stored episodes aired
     * inside the revisit window or later. A show without a tv_info row is not finished.
     */
    public static function isFinished(int $videoId): bool
    {
        return (int) TvInfo::query()->where('videos_id', $videoId)->value('status') === TvShowDetails::STATUS_ENDED
            && ! TvEpisode::query()
                ->where('videos_id', $videoId)
                ->where('firstaired', '>=', TvProcessingCandidateQuery::revisitWindowCutoff()->toDateString())
                ->exists();
    }

    /** A parsed season or episode number as a positive integer, or null when it is none. */
    public static function positiveNumber(mixed $value, string $prefix): ?int
    {
        if (! \is_int($value) && ! \is_string($value)) {
            return null;
        }

        $number = preg_replace('/^'.$prefix.'0*/i', '', (string) $value);

        return \is_string($number) && ctype_digit($number) && (int) $number > 0 ? (int) $number : null;
    }
}
