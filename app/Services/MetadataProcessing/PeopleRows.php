<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Person;
use Illuminate\Cache\Lock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Finds or adds the shared `people` row for a film or show credit, so a person in films and
 * shows is one row. Films write through MovieCredits, shows through TvShowDetails. A caller
 * inside a transaction resolves its people before the transaction opens: the lock is
 * released at the insert, and a row still uncommitted then is one another writer's lookup
 * under the lock cannot see.
 */
final class PeopleRows
{
    /**
     * `people.name` has no unique key and the collation makes two spellings one name, so a
     * lock keyed on the name could not cover them: every insert of a person no lookup found
     * is serialised, text and TMDB alike (a text row and a TMDB row can be one person), and
     * the lookups repeat under the lock. The key keeps the name it had when only films used
     * it, so workers on either side of a deploy share one lock.
     */
    public const string LOCK = 'movie_credits:person_create';

    /** How long a held lock lives if its worker dies without releasing it. */
    private const int LOCK_SECONDS = 10;

    /**
     * A holder keeps the lock for a lookup and an insert, so waiters retry often, and wait
     * past the lifetime of a lock a dead worker left, rather than abort the film or show.
     * A wait that still runs out throws, for films and shows alike.
     */
    private const int LOCK_WAIT_SECONDS = 15;

    private const int LOCK_RETRY_MILLISECONDS = 20;

    /**
     * The person's row, found or added. A TMDB person is found by TMDB id, else claims an
     * unclaimed row of the same name; a name from text is found among all people. Only a
     * person with a name is added: an empty name with no row gives null.
     */
    public function findOrAdd(string $name, ?int $tmdbId): ?int
    {
        $name = mb_substr(trim($name), 0, Person::NAME_LENGTH);
        $tmdbId = $tmdbId !== null && $tmdbId > 0 ? $tmdbId : null;

        $id = $this->findOrClaim($name, $tmdbId);
        if ($id !== null || $name === '') {
            return $id;
        }

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if ($lock instanceof Lock) {
            $lock->betweenBlockedAttemptsSleepFor(self::LOCK_RETRY_MILLISECONDS);
        }

        return (int) $lock->block(self::LOCK_WAIT_SECONDS, fn (): int => $this->findOrClaim($name, $tmdbId) ?? $this->insert($name, $tmdbId));
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

    /**
     * The person's existing row. A TMDB person found only by name claims that row (a write).
     */
    private function findOrClaim(string $name, ?int $tmdbId): ?int
    {
        if ($tmdbId !== null) {
            $id = Person::query()->where('tmdb_id', $tmdbId)->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }
        if ($name === '') {
            return null;
        }
        if ($tmdbId === null) {
            return $this->textPersonId($name);
        }

        $unclaimed = Person::query()->where('name', $name)->whereNull('tmdb_id')->orderBy('id')->value('id');

        return $unclaimed !== null ? $this->claim((int) $unclaimed, $tmdbId, $name) : null;
    }

    private function insert(string $name, ?int $tmdbId): int
    {
        return $tmdbId === null
            ? (int) Person::query()->insertGetId(['name' => $name, 'tmdb_id' => null])
            : $this->tmdbPersonId($tmdbId, $name);
    }

    /**
     * Ignore-then-read: a claim that lost its race may meet a row another worker added.
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
    private function textPersonId(string $name): ?int
    {
        $id = Person::query()->where('name', $name)->orderByRaw('tmdb_id IS NULL')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }
}
