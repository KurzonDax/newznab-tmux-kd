<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Support\ChildRows;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keeps a release's `release_audio_genres` rows, one per genre in its audio tag's genre value,
 * in the value's order. The names are `audio_genres` rows, kept out of the shared `genres` table
 * so the frozen API capabilities genre list never lists them. Written with the tag row, and by
 * the migration that filled the rows for existing tag rows.
 *
 * Not final, so a test can hand the audio processor a subclass.
 */
class AudioGenres
{
    /** The tag value that names no genre; dropped in any case. */
    private const string UNKNOWN = 'unknown';

    /**
     * The genre names in a tag's genre value, in order and each once: split on `;` and on a
     * slash with a space on each side, trimmed, with empty parts and `Unknown` (any case) left
     * out. A comma or an unspaced slash (`Pop/Rock`) is part of a name. Of names that differ
     * only by case, the first spelling is kept. No other cleaning: names are taken as written.
     *
     * @return list<string>
     */
    public static function split(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $names = [];
        $seen = [];
        foreach (preg_split('~;| / ~', $value) ?: [] as $part) {
            $name = trim($part);
            $key = mb_strtolower($name);
            if ($name === '' || $key === self::UNKNOWN || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * The `audio_genres` row of each name, in order and each once. A name resolves without
     * regard to case to the lowest-id row, created with the name as written when none exists.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public function ids(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $id = $this->genreId($name);
            // Two names the split keeps apart can share one row under the table's collation.
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Replaces the release's genre rows with the genres in order (position 0, 1, 2 ...), in one
     * transaction with the other writes; an empty list removes the stored rows.
     *
     * @param  list<int>  $audioGenreIds
     * @param  (Closure(): void)|null  $alsoWrite  Other writes for the same transaction, run before the rows are replaced.
     */
    public function replace(int $releasesId, array $audioGenreIds, ?Closure $alsoWrite = null): void
    {
        $rows = [];
        foreach (array_values($audioGenreIds) as $position => $audioGenreId) {
            $rows[] = ['audio_genres_id' => $audioGenreId, 'position' => $position];
        }

        ChildRows::replace('releases', $releasesId, 'releases_id', ['release_audio_genres' => $rows], $alsoWrite);
    }

    /**
     * The release's stored genre rows in order.
     *
     * @return list<int>
     */
    public function stored(int $releasesId): array
    {
        return DB::table('release_audio_genres')->where('releases_id', $releasesId)->orderBy('position')
            ->pluck('audio_genres_id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * Compared as LOWER() on both sides: the MariaDB collation already ignores case, SQLite's `=`
     * does not. The unique name key makes a racing insert of the same name a no-op, and the
     * re-read finds the row the other worker stored.
     */
    private function genreId(string $name): int
    {
        $find = static fn (): mixed => DB::table('audio_genres')->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->orderBy('id')->value('id');
        $id = $find();
        if ($id === null) {
            DB::table('audio_genres')->insertOrIgnore(['name' => $name]);
            $id = $find() ?? throw new RuntimeException("Could not store the audio genre name '{$name}'.");
        }

        return (int) $id;
    }
}
