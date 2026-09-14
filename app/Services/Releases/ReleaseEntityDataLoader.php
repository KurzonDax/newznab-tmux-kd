<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseEntityData;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ReleaseEntityDataLoader
{
    /** @param Collection<int|string, \stdClass> $releases
     * @return array<int, ReleaseEntityData>
     */
    public function load(Collection $releases): array
    {
        $entities = [];
        $sources = [
            Category::MOVIE_ROOT => ['movies', 'movieinfo', 'imdbid', 'imdbid', 'year', 'movies'],
            Category::TV_ROOT => ['tv', 'videos', 'videos_id', 'id', 'started', 'tvshows'],
            Category::MUSIC_ROOT => ['audio', 'musicinfo', 'musicinfo_id', 'id', 'year', 'music'],
            Category::GAME_ROOT => ['console', 'consoleinfo', 'consoleinfo_id', 'id', 'releasedate', 'console'],
            Category::PC_ROOT => ['games', 'gamesinfo', 'gamesinfo_id', 'id', 'releasedate', 'games'],
            Category::BOOKS_ROOT => ['books', 'bookinfo', 'bookinfo_id', 'id', 'publishdate', 'book'],
        ];
        foreach ($sources as $categoryRoot => [$root, $table, $foreignKey, $key, $yearField, $artType]) {
            $matched = $releases->filter(static fn (object $release): bool => Category::rootCategoryFor((int) ($release->categories_id ?? 0)) === $categoryRoot
                && (int) ($release->{$foreignKey} ?? 0) > 0);
            if ($matched->isEmpty()) {
                continue;
            }
            $records = DB::table($table)->whereIn($key, $matched->pluck($foreignKey))->get()->keyBy($key);
            $episodeIds = $root === 'tv' ? $matched->pluck('tv_episodes_id')->filter()->unique() : collect();
            $episodes = $episodeIds->isEmpty() ? collect() : DB::table('tv_episodes')->whereIn('id', $episodeIds)->get()->keyBy('id');
            foreach ($matched as $release) {
                $id = (string) $release->{$foreignKey};
                $record = $records->get($id);
                if ($record === null) {
                    continue;
                }
                $year = (string) ($record->{$yearField} ?? $record->year ?? '');
                $entities[(int) $release->id] = new ReleaseEntityData(
                    root: $root,
                    id: $id,
                    title: (string) ($record->title ?? ''),
                    year: $year === '' ? null : substr($year, 0, 4),
                    artwork: getImageAssetUrl($artType, $root === 'movies' ? $id.'-cover' : $id, null),
                    season: isset($episodes[$release->tv_episodes_id ?? 0]) ? (int) $episodes[$release->tv_episodes_id]->series : null,
                    episode: isset($episodes[$release->tv_episodes_id ?? 0]) ? (int) $episodes[$release->tv_episodes_id]->episode : null,
                );
            }
        }

        $anime = $releases->filter(static fn (object $release): bool => Category::rootCategoryFor((int) ($release->categories_id ?? 0)) === Category::TV_ROOT
            && (int) ($release->anidbid ?? 0) > 0 && ! isset($entities[(int) $release->id]));
        if ($anime->isNotEmpty()) {
            $records = DB::table('anidb_info as info')->join('anidb_titles as titles', 'titles.anidbid', '=', 'info.anidbid')
                ->whereIn('info.anidbid', $anime->pluck('anidbid'))
                ->orderByRaw("CASE WHEN titles.lang = 'en' THEN 0 WHEN titles.lang = 'x-jat' THEN 1 ELSE 2 END")
                ->orderByRaw("CASE WHEN titles.type = 'main' THEN 0 WHEN titles.type = 'official' THEN 1 ELSE 2 END")
                ->orderBy('titles.title')
                ->get(['info.anidbid', 'info.startdate', 'titles.title'])
                ->groupBy('anidbid')->map(static fn (Collection $titles): object => $titles->first());
            foreach ($anime as $release) {
                $record = $records->get($release->anidbid);
                if ($record === null) {
                    continue;
                }
                $id = (string) $release->anidbid;
                $entities[(int) $release->id] = new ReleaseEntityData(
                    root: 'anime', id: $id, title: (string) $record->title,
                    year: $record->startdate === null ? null : substr($record->startdate, 0, 4),
                    artwork: getImageAssetUrl('anime', $id.'-cover', null, [$id]),
                );
            }
        }

        return $entities;
    }
}
