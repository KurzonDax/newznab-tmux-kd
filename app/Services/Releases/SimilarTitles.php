<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The rule the film page's "Similar films" and the show page's "Similar shows" share
 * (docs/proposals/movies-redesign/SPEC.md 6.6, DATA-CONTRACT.md 4.5): candidates share a genre
 * or a person with the title; score = 2 × shared genres + 3 × shared people − |year gap| / 10,
 * with no year term when either year is unknown; the best six, ties to the lower id. Each
 * section supplies its link tables, its candidates' visibility probe and its year.
 */
final class SimilarTitles
{
    public const LIMIT = 6;

    /**
     * Every other title sharing a genre (of the section's genre type) or a person with the
     * title, one row each: its id under `$key`, and how many genres and people it shares.
     *
     * @param  string  $genreLinks  title ↔ genre table (`video_genres`, `movie_genres`)
     * @param  string  $peopleLinks  title ↔ person table (`video_people`, `movie_people`)
     * @param  string  $key  the title column both tables share (`videos_id`, `movieinfo_id`)
     */
    public static function shared(string $genreLinks, string $peopleLinks, string $key, int $genreType, int $id): Builder
    {
        $genres = DB::table($genreLinks.' as mine')->join('genres as g', static function (JoinClause $join) use ($genreType): void {
            $join->on('g.id', '=', 'mine.genres_id')->where('g.type', '=', $genreType);
        })->join($genreLinks.' as other', 'other.genres_id', '=', 'mine.genres_id')
            ->where('mine.'.$key, $id)->where('other.'.$key, '<>', $id)
            ->selectRaw('other.'.$key.' AS '.$key.', 1 AS genres, 0 AS people');
        $people = DB::table($peopleLinks.' as mine')->join($peopleLinks.' as other', 'other.people_id', '=', 'mine.people_id')
            ->where('mine.'.$key, $id)->where('other.'.$key, '<>', $id)
            ->selectRaw('other.'.$key.' AS '.$key.', 0 AS genres, 1 AS people');

        return DB::query()->fromSub($genres->unionAll($people), 'shared')
            ->groupBy('shared.'.$key)->selectRaw('shared.'.$key.', SUM(shared.genres) AS genres, SUM(shared.people) AS people');
    }

    /**
     * The best LIMIT ids of the candidates, which join shared() as `s` and select `$id`.
     *
     * @param  string  $otherYear  the candidate's year as an SQL expression, NULL when unknown
     * @param  ?int  $year  the title's own year, null when unknown
     * @return list<int>
     */
    public static function best(Builder $candidates, string $id, string $otherYear, ?int $year): array
    {
        $gap = $year === null ? '0' : 'COALESCE(ABS('.$otherYear.' - ?) / 10.0, 0)';

        return $candidates->orderByRaw('2 * s.genres + 3 * s.people - '.$gap.' DESC', $year === null ? [] : [$year])->orderBy($id)
            ->limit(self::LIMIT)->pluck($id)->map(static fn (mixed $value): int => (int) $value)->all();
    }
}
