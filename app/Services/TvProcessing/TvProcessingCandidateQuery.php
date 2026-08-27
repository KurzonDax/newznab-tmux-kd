<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Category;
use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for TV post-processing admission.
 */
final class TvProcessingCandidateQuery
{
    public const int MINIMUM_SIZE_BYTES = 1_048_576;

    public const int FIRST_PROVIDER_STATUS = 0;

    public const int FINAL_PROVIDER_STATUS = -3;

    public static function revisitWindowCutoff(): Carbon
    {
        return now()->subDays(max(1, (int) config('nntmux.tv_episode_revisit_window_days', 14)));
    }

    public static function pacingCutoff(): Carbon
    {
        return now()->subHours(max(1, (int) config('nntmux.tv_episode_revisit_interval_hours', 6)));
    }

    /**
     * Build the worker/monitor query for all immediately actionable TV rows.
     *
     * @return Builder<Release>
     */
    public static function query(
        string $groupId = '',
        string $guidChar = '',
        int|string|null $processTv = null,
        bool $renamedOnly = false,
    ): Builder {
        $query = self::baseQuery($groupId, $guidChar, $processTv, $renamedOnly);

        return $query->where(function (Builder $candidate): void {
            $candidate->where(function (Builder $unmatched): void {
                $unmatched->where('videos_id', 0)
                    ->whereBetween('tv_episodes_id', [self::FINAL_PROVIDER_STATUS, self::FIRST_PROVIDER_STATUS])
                    ->where(function (Builder $actionable): void {
                        $actionable->where('tv_episodes_id', '<', self::FIRST_PROVIDER_STATUS)
                            ->orWhere(function (Builder $firstProvider): void {
                                $firstProvider->where('tv_episodes_id', self::FIRST_PROVIDER_STATUS);
                                self::constrainFirstProviderDue($firstProvider, includeExpired: true);
                            });
                    });
            })->orWhere(function (Builder $episodeMissing): void {
                $episodeMissing->where('videos_id', '>', 0)
                    ->whereBetween('tv_episodes_id', [self::FINAL_PROVIDER_STATUS, self::FIRST_PROVIDER_STATUS])
                    ->where(function (Builder $actionable): void {
                        $actionable->where('postdate', '<', self::revisitWindowCutoff())
                            ->orWhere('tv_episodes_id', '<', self::FIRST_PROVIDER_STATUS)
                            ->orWhere(function (Builder $due): void {
                                $due->where('tv_episodes_id', self::FIRST_PROVIDER_STATUS)
                                    ->where('postdate', '>=', self::revisitWindowCutoff());
                                self::constrainFirstProviderDue($due, includeExpired: false);
                            });
                    });
            });
        });
    }

    /**
     * Build one legacy-provider stage query while retaining known-show rows.
     *
     * @return Builder<Release>
     */
    public static function providerStage(
        int $status,
        string $groupId = '',
        string $guidChar = '',
        int|string|null $processTv = null,
    ): Builder {
        $query = self::baseQuery($groupId, $guidChar, $processTv, false);

        return $query->where('tv_episodes_id', $status)
            ->where(function (Builder $stage) use ($status): void {
                $stage->where(function (Builder $unmatched) use ($status): void {
                    $unmatched->where('videos_id', 0);

                    if ($status === self::FIRST_PROVIDER_STATUS) {
                        self::constrainFirstProviderDue($unmatched, includeExpired: true);
                    }
                })
                    ->orWhere(function (Builder $episodeMissing) use ($status): void {
                        $episodeMissing->where('videos_id', '>', 0);

                        if ($status === self::FIRST_PROVIDER_STATUS) {
                            self::constrainFirstProviderDue($episodeMissing, includeExpired: true);
                        }
                    });
            });
    }

    /** @param Builder<Release> $query */
    private static function constrainFirstProviderDue(Builder $query, bool $includeExpired): void
    {
        $query->where(function (Builder $due) use ($includeExpired): void {
            $due->whereNull('tv_episode_lookup_attempted_at')
                ->orWhere('tv_episode_lookup_attempted_at', '<=', self::pacingCutoff());

            if ($includeExpired) {
                $due->orWhere('postdate', '<', self::revisitWindowCutoff());
            }
        });
    }

    public static function count(bool $renamedOnly = false, ?int $lookupMode = null): int
    {
        return self::query(processTv: $lookupMode, renamedOnly: $renamedOnly)->count();
    }

    /**
     * @return list<object{id: string}>
     */
    public static function buckets(bool $renamedOnly = false, ?int $lookupMode = null): array
    {
        $bucketExpression = DB::getDriverName() === 'sqlite'
            ? 'substr(leftguid, 1, 1)'
            : 'LEFT(leftguid, 1)';

        return self::query(processTv: $lookupMode, renamedOnly: $renamedOnly)
            ->selectRaw($bucketExpression.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->map(static fn (\stdClass $row): object => (object) ['id' => (string) $row->id])
            ->values()
            ->all();
    }

    /**
     * @return Builder<Release>
     */
    private static function baseQuery(
        string $groupId,
        string $guidChar,
        int|string|null $processTv,
        bool $renamedOnly,
    ): Builder {
        $resolvedProcessTv = (int) (is_numeric($processTv) ? $processTv : Settings::settingValue('lookuptv'));
        $query = Release::query()
            ->where('size', '>', self::MINIMUM_SIZE_BYTES)
            ->whereBetween('categories_id', [Category::TV_ROOT, Category::TV_OTHER])
            ->where('categories_id', '<>', Category::TV_ANIME);

        if ($resolvedProcessTv <= 0) {
            return $query->whereRaw('0 = 1');
        }

        if ($groupId !== '') {
            $query->where('groups_id', $groupId);
        }

        if ($guidChar !== '') {
            $query->where('leftguid', $guidChar);
        }

        if ($resolvedProcessTv === 2 || $renamedOnly) {
            $query->where('isrenamed', 1);
        }

        return $query;
    }
}
