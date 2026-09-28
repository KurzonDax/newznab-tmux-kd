<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieReleaseRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Loads a page of Movies release ids into display rows: ReleaseRowFacts supplies the facts every
 * release list shows; this adds the film, read by `releases.movieinfo_id` (DATA-CONTRACT fact 1),
 * its poster, and whether the user follows it (`user_movies` by the film's IMDb id, fact 11).
 */
final class MovieReleaseRows
{
    public function __construct(private readonly ReleaseRowFacts $facts) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<MovieReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        $ordered = $this->facts->load($ids, ['movieinfo_id']);
        if ($ordered === []) {
            return [];
        }
        $filmIds = array_values(array_unique(array_filter(array_map(static fn (object $release): int => (int) $release->movieinfo_id, $ordered))));
        $films = $filmIds === [] ? collect() : DB::table('movieinfo')->whereIn('id', $filmIds)->get(['id', 'imdbid', 'title', 'year'])->keyBy('id');
        $imdbIds = $films->pluck('imdbid')->map(static fn (mixed $id): string => (string) $id)->filter()->values()->all();
        $followed = $imdbIds === [] || Auth::id() === null ? [] : DB::table('user_movies')->where('users_id', Auth::id())->whereIn('imdbid', $imdbIds)
            ->pluck('imdbid')->map(static fn (mixed $id): string => (string) $id)->all();
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

        return array_map(function (object $release) use ($films, $followed, $byAdded, $now): MovieReleaseRow {
            $film = $films->get((int) $release->movieinfo_id);
            $imdbId = $film === null ? '' : (string) $film->imdbid;

            // The film's own follow state replaces the shared loader's, which reads releases.imdbid.
            return new MovieReleaseRow(...[
                'filmId' => $film === null ? null : (int) $film->id,
                'filmTitle' => $film === null ? '' : (string) $film->title,
                'filmYear' => $film === null ? '' : (string) $film->year,
                'imdbId' => $imdbId,
                'poster' => $imdbId === '' ? null : getImageAssetUrl('movies', $imdbId.'-cover'),
                'watched' => $imdbId !== '' && in_array($imdbId, $followed, true),
            ] + $this->facts->facts($release, $byAdded, $now));
        }, $ordered);
    }
}
