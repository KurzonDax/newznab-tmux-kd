<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which post-processing path a pending release belongs to.
 *
 * {@see AudioCandidateQuery} and {@see AdditionalCandidateQuery} apply this with
 * opposite polarity, so the two are exact complements of one another over the
 * pending set: every release is claimable by exactly one of them, and no release
 * is claimable by both. Change the rule here, never in one of the two queries.
 *
 * The rule itself: a release is audio when its category sits under the Music
 * root, or when its group carries a forced Music root category. Categories 3xxx
 * are not a guarantee -- anime posted to alt.binaries.multimedia lands in
 * MUSIC_VIDEO -- so the audio worker still probes before committing, and hands
 * back anything that turns out to be video by writing the decline sentinel
 * described on {@see self::DECLINED_TOKEN}.
 */
final class AudioRouting
{
    /**
     * Written to `additional_pp_claim_token` (with `additional_pp_claimed_at`
     * cleared) when the audio worker probes a release and finds video, or no
     * audio at all.
     *
     * It is a routing marker, not a claim: the audio query treats a release
     * carrying it as belonging to the video path, and the video query treats it
     * as its own even though the category says otherwise. The claim window keys
     * off `additional_pp_claimed_at`, which is null here, so the marker never
     * makes a release look busy.
     */
    public const string DECLINED_TOKEN = 'aud:declined';

    /**
     * Restrict a releases query (aliased `r`) to the audio worker's half of the
     * pending set.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function applyAudioPath(Builder $query): Builder
    {
        return $query
            ->where(self::routedToAudio(...))
            ->where(function (Builder $tokenQuery): void {
                $tokenQuery
                    ->whereNull('r.'.ReleaseClaimant::CLAIM_TOKEN_COLUMN)
                    ->orWhere('r.'.ReleaseClaimant::CLAIM_TOKEN_COLUMN, '!=', self::DECLINED_TOKEN);
            });
    }

    /**
     * Restrict a releases query (aliased `r`) to the video worker's half: the
     * complement of {@see self::applyAudioPath()}.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function applyVideoPath(Builder $query): Builder
    {
        return $query->where(function (Builder $pathQuery): void {
            $pathQuery
                ->whereNot(self::routedToAudio(...))
                ->orWhere('r.'.ReleaseClaimant::CLAIM_TOKEN_COLUMN, self::DECLINED_TOKEN);
        });
    }

    /**
     * Restrict a releases query (aliased `r`) to releases the category/group
     * rule routes to audio, ignoring the decline marker.
     *
     * This is the selection an operator tool wants -- "every audio release",
     * including the ones the worker has handed to the video path -- as opposed
     * to {@see self::applyAudioPath()}, which is what the worker itself claims.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function applyRoutingPredicate(Builder $query): Builder
    {
        return $query->where(self::routedToAudio(...));
    }

    /**
     * Whether the audio worker has already probed this release and handed it to
     * the video path.
     */
    public static function isDeclined(?string $claimToken): bool
    {
        return $claimToken === self::DECLINED_TOKEN;
    }

    /**
     * The category/group half of the rule, without the decline marker.
     *
     * Expressed as a range rather than through {@see Category::rootCategoryFor()}
     * because it has to run in SQL; the two agree for the Music root, where every
     * id from 3000 to 3999 resolves to 3000.
     *
     * @param  Builder<Release>  $query
     */
    private static function routedToAudio(Builder $query): void
    {
        $query
            ->whereBetween('r.categories_id', [Category::MUSIC_ROOT, Category::MUSIC_ROOT + 999])
            ->orWhereExists(static function (QueryBuilder $groupQuery): void {
                $groupQuery
                    ->from('usenet_groups')
                    ->whereColumn('usenet_groups.id', 'r.groups_id')
                    ->where('usenet_groups.forced_root_categories_id', Category::MUSIC_ROOT);
            });
    }
}
