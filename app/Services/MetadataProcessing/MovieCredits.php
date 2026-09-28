<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Category;
use App\Models\Genre;
use App\Models\Person;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /**
     * Replaces the film's genre and people rows, in one transaction and only when they
     * differ. Directors are all kept; the cast is the first twelve distinct people, counted
     * after the names resolve to rows (two spellings the collation treats as one name are one
     * person, and the next name takes the place).
     *
     * @param  list<string>  $genres
     * @param  list<array{name: string, tmdb_id: ?int}>  $directors
     * @param  list<array{name: string, tmdb_id: ?int}>  $cast
     * @return bool Whether any row changed.
     */
    public function sync(int $movieinfoId, array $genres, array $directors, array $cast): bool
    {
        // Found or created before the transaction opens, so no transaction holds a new
        // genre another worker cannot see yet.
        $genreRows = [];
        foreach ($this->distinct(array_map($this->genreId(...), $genres), PHP_INT_MAX) as $position => $genreId) {
            $genreRows[] = ['genres_id' => $genreId, 'position' => $position];
        }

        $peopleRows = [];
        foreach ([self::ROLE_DIRECTOR => [$directors, PHP_INT_MAX], self::ROLE_CAST => [$cast, self::CAST_LIMIT]] as $role => [$people, $limit]) {
            $ids = array_map(fn (array $person): ?int => $this->personId($person['name'], $person['tmdb_id']), $people);
            foreach ($this->distinct($ids, $limit) as $position => $personId) {
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
     * Gives a person found by name, and still without a TMDB id, the TMDB id. A claim that
     * loses the race, or meets a TMDB id another row already holds, falls back to the row
     * that holds that TMDB id, inserting it when none does.
     */
    public function claim(int $personId, int $tmdbId, string $name): int
    {
        try {
            $claimed = Person::query()->whereKey($personId)->whereNull('tmdb_id')->update(['tmdb_id' => $tmdbId]);
        } catch (UniqueConstraintViolationException) {
            $claimed = 0;
        }

        return $claimed === 1 ? $personId : $this->tmdbPersonId($tmdbId, $name);
    }

    private function personId(string $name, ?int $tmdbId): ?int
    {
        $name = mb_substr(trim($name), 0, Person::NAME_LENGTH);
        if ($tmdbId === null || $tmdbId <= 0) {
            return $name === '' ? null : $this->textPersonId($name);
        }

        $id = Person::query()->where('tmdb_id', $tmdbId)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        $unclaimed = Person::query()->where('name', $name)->whereNull('tmdb_id')->orderBy('id')->value('id');
        if ($unclaimed !== null) {
            return $this->claim((int) $unclaimed, $tmdbId, $name);
        }

        return $this->tmdbPersonId($tmdbId, $name);
    }

    /**
     * Ignore-then-read, as TV does: workers may add the same TMDB person at once.
     */
    private function tmdbPersonId(int $tmdbId, string $name): int
    {
        Person::query()->insertOrIgnore(['tmdb_id' => $tmdbId, 'name' => $name]);

        return (int) Person::query()->where('tmdb_id', $tmdbId)->value('id');
    }

    /**
     * A name from text is looked up among all people, preferring one TMDB already
     * identified, then the lowest id, so text never re-creates a person TMDB has claimed.
     */
    private function textPersonId(string $name): int
    {
        $id = Person::query()->where('name', $name)->orderByRaw('tmdb_id IS NULL')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : (int) Person::query()->insertGetId(['name' => $name, 'tmdb_id' => null]);
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
