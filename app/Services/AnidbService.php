<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnidbTitle;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Service class for AniDB data fetching and processing.
 */
class AnidbService
{
    /**
     * Which stored title the admin edit form shows, and therefore the one it writes back.
     */
    private const TITLE_PREFERENCE = "CASE WHEN lang = 'en' THEN 1 WHEN lang = 'x-jat' THEN 2 ELSE 3 END";

    /**
     * Updates stored AniList entries in the database.
     * Note: AniList doesn't support episodes, so episode-related parameters are ignored.
     */
    public function updateTitle(
        int $anidbID,
        string $title,
        string $type,
        ?string $startdate = null,
        ?string $enddate = null,
        ?string $related = null,
        ?string $similar = null,
        ?string $creators = null,
        ?string $description = null,
        ?string $rating = null,
        ?string $categories = null,
        ?string $characters = null
    ): void {
        // The edit form shows one title out of several: anidb_titles holds a row per language
        // (romaji, English, native), and getAnimeInfo() picks one by the preference below. Only
        // that row's title may be rewritten -- an update filtered on anidbid alone would collapse
        // every language onto the English title, or collide on the primary key.
        //
        // `type` on the form is the media type, which lives on anidb_info. anidb_titles.type is
        // a different thing entirely (main/official/synonym) and is left alone.
        DB::transaction(function () use (
            $anidbID,
            $title,
            $type,
            $startdate,
            $enddate,
            $related,
            $similar,
            $creators,
            $description,
            $rating,
            $categories,
            $characters
        ): void {
            $displayed = DB::table('anidb_titles')
                ->where('anidbid', $anidbID)
                ->orderByRaw(self::TITLE_PREFERENCE)
                ->first();

            if ($displayed === null || ! DB::table('anidb_info')->where('anidbid', $anidbID)->exists()) {
                return;
            }

            DB::table('anidb_titles')
                ->where('anidbid', $anidbID)
                ->where('type', $displayed->type)
                ->where('lang', $displayed->lang)
                ->where('title', $displayed->title)
                ->update(['title' => $title]);

            // startdate and enddate are DATE columns: an empty string is not a valid date and a
            // strict-mode server rejects it, so a blank field is stored as NULL.
            $nullable = static fn (?string $value): ?string => $value === null || $value === ''
                ? null
                : $value;

            DB::table('anidb_info')
                ->where('anidbid', $anidbID)
                ->update([
                    'type' => $type,
                    'startdate' => $nullable($startdate),
                    'enddate' => $nullable($enddate),
                    'related' => $nullable($related),
                    'similar' => $nullable($similar),
                    'creators' => $nullable($creators),
                    'description' => $nullable($description),
                    'rating' => $nullable($rating),
                    'categories' => $nullable($categories),
                    'characters' => $nullable($characters),
                ]);
        });
    }

    /**
     * Deletes an AniDB title and associated info.
     *
     * @throws \Throwable
     */
    public function deleteTitle(int $anidbID): void
    {
        DB::transaction(function () use ($anidbID) {
            DB::delete(
                sprintf(
                    '
				DELETE at, ai
				FROM anidb_titles AS at
				LEFT OUTER JOIN anidb_info ai USING (anidbid)
				WHERE anidbid = %d',
                    $anidbID
                )
            );
        }, 3);
    }

    /**
     * Retrieves a list of Anime titles, optionally filtered by starting character and title.
     *
     * @return array<string, mixed>
     */
    public function getAnimeList(string $letter = '', string $animeTitle = ''): array
    {
        $rsql = $tsql = '';

        if ($letter !== '') {
            if ($letter === '0-9') {
                $letter = '[0-9]';
            }
            $rsql .= sprintf('AND at.title REGEXP %s', escapeString('^'.$letter));
        }

        if ($animeTitle !== '') {
            $tsql .= sprintf('AND at.title LIKE %s', escapeString('%'.$animeTitle.'%'));
        }

        return DB::select(
            sprintf(
                '
				SELECT at.anidbid, at.title,
					ai.type, ai.categories, ai.rating, ai.startdate, ai.enddate
				FROM anidb_titles at
				LEFT JOIN anidb_info ai USING (anidbid)
				STRAIGHT_JOIN releases r ON at.anidbid = r.anidbid
				WHERE at.anidbid > 0 %s %s
				AND r.categories_id = %d
				GROUP BY at.anidbid
				ORDER BY at.title ASC',
                $rsql,
                $tsql,
                Category::TV_ANIME
            )
        );
    }

    /**
     * Retrieves a range of Anime titles for site display.
     */
    public function getAnimeRange(string $animeTitle = ''): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        $titleAggregate = DB::connection()->getDriverName() === 'sqlite'
            ? "GROUP_CONCAT(at.title, ', ') AS title"
            : "GROUP_CONCAT(at.title SEPARATOR ', ') AS title";

        $query = AnidbTitle::query()
            ->where('at.lang', '=', 'en');
        if ($animeTitle !== '') {
            $query->where('at.title', 'like', '%'.$animeTitle.'%');
        }
        $query->select([
            'at.anidbid',
            DB::raw($titleAggregate),
            'ai.description',
            'ai.type',
            'ai.startdate',
            'ai.enddate',
            'ai.rating',
        ])
            ->from('anidb_titles as at')
            ->leftJoin('anidb_info as ai', 'ai.anidbid', '=', 'at.anidbid')
            ->groupBy('at.anidbid')
            ->orderByDesc('at.anidbid');

        return $query->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Retrieves all info for a specific AniDB ID.
     * Note: AniList doesn't support episodes, so episode data is not included.
     */
    public function getAnimeInfo(?int $anidbID): mixed
    {
        if ($anidbID === null || $anidbID <= 0) {
            return null;
        }

        // Get main info with primary title (prefer English, fallback to any)
        $animeInfo = DB::select(
            sprintf(
                '
				SELECT at.anidbid, at.lang, at.title,
					ai.anilist_id, ai.mal_id, ai.country, ai.media_type, ai.episodes, ai.duration, ai.status, ai.source, ai.hashtag,
					ai.startdate, ai.enddate, ai.updated, ai.related, ai.creators, ai.description,
					ai.rating, ai.picture, ai.categories, ai.characters, ai.type, ai.similar
				FROM anidb_titles AS at
				LEFT JOIN anidb_info AS ai USING (anidbid)
				WHERE at.anidbid = %d
				ORDER BY CASE
					WHEN at.lang = "en" THEN 1
					WHEN at.lang = "x-jat" THEN 2
					ELSE 3
				END
				LIMIT 1',
                $anidbID
            )
        );

        $result = $animeInfo[0] ?? false;

        if ($result) {
            // Get English title separately
            $englishTitle = DB::selectOne(
                sprintf(
                    '
					SELECT title
					FROM anidb_titles
					WHERE anidbid = %d AND lang = "en"
					LIMIT 1',
                    $anidbID
                )
            );

            // Get native title (prefer ja, then x-jat, then any non-English title)
            $nativeTitle = DB::selectOne(
                sprintf(
                    '
					SELECT title, lang
					FROM anidb_titles
					WHERE anidbid = %d AND lang NOT IN ("en")
					ORDER BY CASE
						WHEN lang = "ja" THEN 1
						WHEN lang = "x-jat" THEN 2
						ELSE 3
					END
					LIMIT 1',
                    $anidbID
                )
            );

            // Get romaji title separately
            $romajiTitle = DB::selectOne(
                sprintf(
                    '
					SELECT title, lang
					FROM anidb_titles
					WHERE anidbid = %d AND lang = "x-jat"
					LIMIT 1',
                    $anidbID
                )
            );

            // If we found a native title, use it; otherwise use romaji as original
            $originalTitle = $nativeTitle;
            if (! $originalTitle && $romajiTitle) {
                $originalTitle = $romajiTitle;
            }

            // Add titles to result
            if ($englishTitle && isset($englishTitle->title)) {
                $result->english_title = $englishTitle->title;
            } else {
                $result->english_title = null;
            }

            if ($originalTitle && isset($originalTitle->title)) {
                $result->original_title = $originalTitle->title;
                $result->original_lang = $originalTitle->lang;
            } else {
                $result->original_title = null;
                $result->original_lang = null;
            }

            // Add romaji title separately (even if it's also the original)
            if ($romajiTitle && isset($romajiTitle->title)) {
                $result->romaji_title = $romajiTitle->title;
            } else {
                $result->romaji_title = null;
            }
        }

        return $result;
    }
}
