<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Support\Facades\DB;

/**
 * The TV section's shows-and-people search (the toolbar field on /tv and /tv/shows): shows
 * from one character, people from two; at most 6 shows ordered by where the text matches,
 * then title, and 5 people ordered by how many listed shows they are in. Only shows the user
 * may see count, the wall's rule.
 */
final class TvShowSearch
{
    private const int SHOWS = 6;

    private const int PEOPLE = 5;

    public function __construct(private readonly TvShowWall $wall) {}

    /**
     * @param  list<int>  $exclusions
     * @return array{shows: list<array{id: int, title: string, year: ?int, genres: list<string>, poster: ?string}>, people: list<array{id: int, name: string, shows: list<string>}>}
     */
    public function find(string $text, array $exclusions): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['shows' => [], 'people' => []];
        }
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';

        return ['shows' => $this->shows($text, $pattern, $exclusions), 'people' => mb_strlen($text) < 2 ? [] : $this->people($pattern, $exclusions)];
    }

    /**
     * @param  list<int>  $exclusions
     * @return list<array{id: int, title: string, year: ?int, genres: list<string>, poster: ?string}>
     */
    private function shows(string $text, string $pattern, array $exclusions): array
    {
        $shows = DB::table('videos as v')->leftJoin('tv_info as t', 't.videos_id', '=', 'v.id')
            ->where('v.type', 0)->whereRaw('v.title LIKE ? ESCAPE ?', [$pattern, '\\']);
        $rows = $this->wall->whereVisible($shows, $exclusions)
            ->orderByRaw('INSTR(LOWER(v.title), LOWER(?))', [$text])->orderBy('v.title')->limit(self::SHOWS)
            ->get(['v.id', 'v.title', 'v.started', 't.premiered']);
        $genres = $this->wall->genreTitles($rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());

        return $rows->map(static fn (object $show): array => [
            'id' => (int) $show->id,
            'title' => (string) $show->title,
            'year' => TvShowWall::year($show->premiered, $show->started),
            'genres' => array_slice($genres[(int) $show->id] ?? [], 0, 2),
            'poster' => getImageAssetUrl('tvshows', (string) $show->id),
        ])->values()->all();
    }

    /**
     * @param  list<int>  $exclusions
     * @return list<array{id: int, name: string, shows: list<string>}>
     */
    private function people(string $pattern, array $exclusions): array
    {
        // From people outward: the name filter first, then one visibility probe per credited show
        // (video_people holds TV shows only). Starting from videos probes every show instead.
        $people = $this->wall->whereVisible(DB::table('people as p')->join('video_people as vp', 'vp.people_id', '=', 'p.id')
            ->whereRaw('p.name LIKE ? ESCAPE ?', [$pattern, '\\']), $exclusions, 'vp.videos_id')
            ->groupBy('p.id', 'p.name')->select('p.id', 'p.name')->selectRaw('COUNT(*) AS shows')
            ->orderByDesc('shows')->orderBy('p.name')->limit(self::PEOPLE)->get();
        if ($people->isEmpty()) {
            return [];
        }
        $titles = [];
        $credits = DB::table('video_people as vp')->join('videos as v', 'v.id', '=', 'vp.videos_id')->whereIn('vp.people_id', $people->pluck('id')->all());
        foreach ($this->wall->whereVisible($credits, $exclusions)->orderBy('v.title')->get(['vp.people_id', 'v.title']) as $row) {
            $titles[(int) $row->people_id][] = (string) $row->title;
        }

        return $people->map(static fn (object $person): array => [
            'id' => (int) $person->id,
            'name' => (string) $person->name,
            'shows' => $titles[(int) $person->id] ?? [],
        ])->values()->all();
    }
}
