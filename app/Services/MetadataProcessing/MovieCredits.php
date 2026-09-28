<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Category;
use App\Models\Genre;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a film's `movie_genres` and `movie_people` rows in step with its genre names, its
 * directors and its cast. Genres are `genres` rows of the Movies type and people are the
 * `people` rows TV shares, so a person in films and shows is one row. Called when a film is
 * fetched, when an admin edits it, and by the migration that moved the film text into rows.
 */
final class MovieCredits
{
    public const int ROLE_DIRECTOR = 0;

    public const int ROLE_CAST = 1;

    /** As TV's cast; the film page shows twelve. */
    public const int CAST_LIMIT = 12;

    /** `genres` has no unique key and movie workers run in parallel, so genre creation is serialised. */
    private const string GENRE_LOCK = 'movie_credits:genre_create';

    private const int GENRE_LOCK_SECONDS = 10;

    private const int GENRE_TITLE_LENGTH = 255;

    public function __construct(private readonly PeopleRows $people) {}

    /**
     * Replaces the film's genre and people rows, in one transaction and only when they
     * differ. Directors are all kept; the cast is the first twelve distinct people, counted
     * after the names resolve to rows (two spellings the collation treats as one name are one
     * person, a TMDB person with an empty name and no row is left out, and the next name
     * takes the place). A people list that resolves to nobody is replaced by its fallback.
     *
     * @param  list<string>  $genres
     * @param  list<array{name: string, tmdb_id: ?int}>  $directors
     * @param  list<array{name: string, tmdb_id: ?int}>  $cast
     * @param  list<array{name: string, tmdb_id: ?int}>  $directorsFallback
     * @param  list<array{name: string, tmdb_id: ?int}>  $castFallback
     * @return bool Whether any row changed.
     */
    public function sync(int $movieinfoId, array $genres, array $directors, array $cast, array $directorsFallback = [], array $castFallback = []): bool
    {
        // Found or created before the transaction opens, so no transaction holds a new
        // genre another worker cannot see yet.
        $genreRows = [];
        foreach ($this->distinct(array_map($this->genreId(...), $genres), PHP_INT_MAX) as $position => $genreId) {
            $genreRows[] = ['genres_id' => $genreId, 'position' => $position];
        }

        $peopleRows = [];
        $lists = [
            self::ROLE_DIRECTOR => [$directors, $directorsFallback, PHP_INT_MAX],
            self::ROLE_CAST => [$cast, $castFallback, self::CAST_LIMIT],
        ];
        foreach ($lists as $role => [$people, $fallback, $limit]) {
            $ids = $this->personIds($people, $limit);
            foreach ($ids !== [] ? $ids : $this->personIds($fallback, $limit) as $position => $personId) {
                $peopleRows[] = ['people_id' => $personId, 'role' => $role, 'position' => $position];
            }
        }

        if ($this->storedGenres($movieinfoId) === $genreRows && $this->storedPeople($movieinfoId) === $peopleRows) {
            return false;
        }

        DB::transaction(static function () use ($movieinfoId, $genreRows, $peopleRows): void {
            DB::table('movie_genres')->where('movieinfo_id', $movieinfoId)->delete();
            DB::table('movie_genres')->insert(array_map(static fn (array $row): array => ['movieinfo_id' => $movieinfoId] + $row, $genreRows));
            DB::table('movie_people')->where('movieinfo_id', $movieinfoId)->delete();
            DB::table('movie_people')->insert(array_map(static fn (array $row): array => ['movieinfo_id' => $movieinfoId] + $row, $peopleRows));
        });

        return true;
    }

    /**
     * Replaces the film's rows from its saved `genre`, `director` and `actors` text.
     */
    public function syncFromText(int $movieinfoId, string $genre, string $director, string $actors): bool
    {
        return $this->sync(
            $movieinfoId,
            MovieCreditsText::names($genre),
            MovieCreditsText::people($director),
            MovieCreditsText::people($actors),
        );
    }

    /**
     * The people's rows in order, each once, up to the limit. Names resolve one at a time
     * and stop at the limit, so no row is added for a person past it.
     *
     * @param  list<array{name: string, tmdb_id: ?int}>  $people
     * @return list<int>
     */
    private function personIds(array $people, int $limit): array
    {
        $kept = [];
        foreach ($people as $person) {
            if (count($kept) === $limit) {
                break;
            }
            $id = $this->people->findOrAdd($person['name'], $person['tmdb_id']);
            if ($id !== null && ! in_array($id, $kept, true)) {
                $kept[] = $id;
            }
        }

        return $kept;
    }

    private function genreId(string $title): ?int
    {
        $title = mb_substr(trim($title), 0, self::GENRE_TITLE_LENGTH);
        if ($title === '') {
            return null;
        }

        $find = static fn (): mixed => Genre::query()->where('type', Category::MOVIE_ROOT)->where('title', $title)->orderBy('id')->value('id');
        $id = $find();
        if ($id !== null) {
            return (int) $id;
        }

        return (int) Cache::lock(self::GENRE_LOCK, self::GENRE_LOCK_SECONDS)->block(
            self::GENRE_LOCK_SECONDS,
            static fn (): mixed => $find() ?? Genre::query()->insertGetId(['title' => $title, 'type' => Category::MOVIE_ROOT, 'disabled' => 0]),
        );
    }

    /**
     * The ids in order, each once, up to the limit.
     *
     * @param  list<?int>  $ids
     * @return list<int>
     */
    private function distinct(array $ids, int $limit): array
    {
        $kept = [];
        foreach ($ids as $id) {
            if ($id === null || in_array($id, $kept, true)) {
                continue;
            }
            $kept[] = $id;
            if (count($kept) === $limit) {
                break;
            }
        }

        return $kept;
    }

    /**
     * @return list<array{genres_id: int, position: int}>
     */
    private function storedGenres(int $movieinfoId): array
    {
        return DB::table('movie_genres')->where('movieinfo_id', $movieinfoId)->orderBy('position')->get(['genres_id', 'position'])
            ->map(static fn (object $row): array => ['genres_id' => (int) $row->genres_id, 'position' => (int) $row->position])
            ->values()->all();
    }

    /**
     * @return list<array{people_id: int, role: int, position: int}>
     */
    private function storedPeople(int $movieinfoId): array
    {
        return DB::table('movie_people')->where('movieinfo_id', $movieinfoId)->orderBy('role')->orderBy('position')->get(['people_id', 'role', 'position'])
            ->map(static fn (object $row): array => ['people_id' => (int) $row->people_id, 'role' => (int) $row->role, 'position' => (int) $row->position])
            ->values()->all();
    }
}
