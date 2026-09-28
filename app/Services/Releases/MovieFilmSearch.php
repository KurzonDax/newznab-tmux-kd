<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieFilmFilters;
use Illuminate\Support\Facades\DB;

/**
 * "Search films or actors" (docs/proposals/movies-redesign/SPEC.md 5.9, DATA-CONTRACT.md 4.3), the
 * toolbar field on /movies: films from one character, people from two. At most 6 films whose
 * title holds the text, ordered by where it matches, then title; then the 50 people with the
 * most films whose name holds it, of whom the 5 with the most films the user may see, each
 * with that count and their first three such films A to Z. Only films with a release the user
 * may see count: a per-film scalar probe on the per-film index (fact 7).
 */
final class MovieFilmSearch
{
    private const int FILMS = 6;

    private const int PEOPLE = 5;

    /** The people with the most films whose visible films are counted. */
    private const int PEOPLE_PROBED = 50;

    private const int PERSON_FILMS = 3;

    public function __construct(private readonly MovieFilmWall $wall) {}

    /**
     * @param  list<int>  $exclusions
     * @return array{films: list<array{id: int, title: string, year: ?int, genres: list<string>, poster: ?string}>, people: list<array{id: int, name: string, count: int, films: list<string>}>}
     */
    public function find(string $text, array $exclusions): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['films' => [], 'people' => []];
        }
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';

        return ['films' => $this->films($text, $pattern, $exclusions), 'people' => mb_strlen($text) < 2 ? [] : $this->people($pattern, $exclusions)];
    }

    /**
     * @param  list<int>  $exclusions
     * @return list<array{id: int, title: string, year: ?int, genres: list<string>, poster: ?string}>
     */
    private function films(string $text, string $pattern, array $exclusions): array
    {
        $rows = $this->wall->whereVisible(DB::table('movieinfo as m')->whereRaw('m.title LIKE ? ESCAPE ?', [$pattern, '\\']), $exclusions)
            ->orderByRaw('INSTR(LOWER(m.title), LOWER(?))', [$text])->orderBy('m.title')->limit(self::FILMS)
            ->get(['m.id', 'm.imdbid', 'm.title', 'm.year']);
        $genres = [];
        if ($rows->isNotEmpty()) {
            foreach (DB::table('movie_genres as mg')->join('genres as g', 'g.id', '=', 'mg.genres_id')->whereIn('mg.movieinfo_id', $rows->pluck('id')->all())
                ->orderBy('mg.movieinfo_id')->orderBy('mg.position')->get(['mg.movieinfo_id', 'g.title']) as $genre) {
                $genres[(int) $genre->movieinfo_id][] = (string) $genre->title;
            }
        }

        return $rows->map(static fn (object $film): array => [
            'id' => (int) $film->id,
            'title' => (string) $film->title,
            'year' => (int) $film->year >= MovieFilmFilters::FIRST_YEAR ? (int) $film->year : null,
            'genres' => array_slice($genres[(int) $film->id] ?? [], 0, 2),
            'poster' => (string) $film->imdbid === '' ? null : getImageAssetUrl('movies', $film->imdbid.'-cover'),
        ])->values()->all();
    }

    /**
     * @param  list<int>  $exclusions
     * @return list<array{id: int, name: string, count: int, films: list<string>}>
     */
    private function people(string $pattern, array $exclusions): array
    {
        // From people outward: the name filter and the 50 with the most films, then one
        // visibility probe per credited film. A person credited twice on a film (director and
        // cast) counts it once.
        $candidates = DB::table('people as p')->join('movie_people as mp', 'mp.people_id', '=', 'p.id')
            ->whereRaw('p.name LIKE ? ESCAPE ?', [$pattern, '\\'])->groupBy('p.id', 'p.name')
            ->select('p.id', 'p.name')->selectRaw('COUNT(DISTINCT mp.movieinfo_id) AS films')
            ->orderByDesc('films')->orderBy('p.name')->limit(self::PEOPLE_PROBED);
        $people = $this->wall->whereVisible(DB::query()->fromSub($candidates, 'c')->join('movie_people as vp', 'vp.people_id', '=', 'c.id'), $exclusions, 'vp.movieinfo_id')
            ->groupBy('c.id', 'c.name')->select('c.id', 'c.name')->selectRaw('COUNT(DISTINCT vp.movieinfo_id) AS films')
            ->orderByDesc('films')->orderBy('c.name')->limit(self::PEOPLE)->get();
        if ($people->isEmpty()) {
            return [];
        }
        $titles = [];
        $credits = DB::table('movie_people as mp')->join('movieinfo as m', 'm.id', '=', 'mp.movieinfo_id')->whereIn('mp.people_id', $people->pluck('id')->all());
        foreach ($this->wall->whereVisible($credits, $exclusions)->distinct()->orderBy('mp.people_id')->orderBy('m.title')->orderBy('m.id')->get(['mp.people_id', 'm.id', 'm.title']) as $row) {
            $person = (int) $row->people_id;
            if (count($titles[$person] ?? []) < self::PERSON_FILMS) {
                $titles[$person][] = (string) $row->title;
            }
        }

        return $people->map(static fn (object $person): array => [
            'id' => (int) $person->id,
            'name' => (string) $person->name,
            'count' => (int) $person->films,
            'films' => $titles[(int) $person->id] ?? [],
        ])->values()->all();
    }
}
