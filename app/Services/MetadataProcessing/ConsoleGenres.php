<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Category;
use App\Models\Genre;
use App\Support\ChildRows;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a console game's `console_genres` rows, one per genre in the source's order, and its
 * `consoleinfo.genres_id` (the first genre) in step. Genres are `genres` rows of the Console
 * type. Called when a game is looked up, when an admin changes its genre, and by the migration
 * that split the combined genre titles into rows.
 */
final class ConsoleGenres
{
    /** The genre a game with no genre and no theme links to. */
    public const string UNKNOWN = 'Unknown';

    /** IGDB's only genre or theme name holding a comma or pipe; splitting keeps it whole. */
    public const string FOUR_X = '4X (explore, expand, exploit, and exterminate)';

    /** `genres` has no unique key and console workers run in parallel, so genre creation is serialised. */
    private const string GENRE_LOCK = 'console_genres:genre_create';

    private const int GENRE_LOCK_SECONDS = 10;

    private const int GENRE_TITLE_LENGTH = 255;

    /**
     * The genre rows for the names, in order and each once. A name resolves to the lowest-id
     * Console genre with that title, created when none exists; an empty name is left out.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public function ids(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $id = $this->genreId($name);
            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Replaces the game's genre rows with the genres in order and sets its `genres_id` to the
     * first (null for none), in one transaction.
     *
     * @param  list<int>  $genreIds
     * @param  (Closure(): void)|null  $alsoWrite  Other writes for the same transaction, run before `genres_id` is set.
     * @param  array<string, list<array<string, mixed>>>  $otherRows  Other child tables' new rows for the same transaction, keyed by table, without `consoleinfo_id`.
     */
    public function replace(int $consoleinfoId, array $genreIds, ?Closure $alsoWrite = null, array $otherRows = []): void
    {
        $rows = [];
        foreach (array_values($genreIds) as $position => $genreId) {
            $rows[] = ['genres_id' => $genreId, 'position' => $position];
        }

        ChildRows::replace('consoleinfo', $consoleinfoId, 'consoleinfo_id', ['console_genres' => $rows] + $otherRows,
            static function () use ($consoleinfoId, $genreIds, $alsoWrite): void {
                if ($alsoWrite !== null) {
                    $alsoWrite();
                }
                DB::table('consoleinfo')->where('id', $consoleinfoId)->update(['genres_id' => $genreIds[0] ?? null]);
            });
    }

    /**
     * The game's stored genre rows in order.
     *
     * @return list<int>
     */
    public function stored(int $consoleinfoId): array
    {
        return DB::table('console_genres')->where('consoleinfo_id', $consoleinfoId)->orderBy('position')
            ->pluck('genres_id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * A SQL expression for the game's genre titles in order, joined with `,` and no space, for the
     * game whose id is in the column; the Console releases list joins the titles with ', ' instead.
     *
     * @param  ',' | ', '  $separator
     */
    public static function titlesSql(string $consoleinfoIdColumn, string $separator = ','): string
    {
        if (! in_array($separator, [',', ', '], true)) {
            throw new \InvalidArgumentException('Unsupported genre title separator.');
        }
        $joined = DB::getDriverName() === 'sqlite'
            ? "GROUP_CONCAT(cgt.title, '{$separator}' ORDER BY cgl.position)"
            : "GROUP_CONCAT(cgt.title ORDER BY cgl.position SEPARATOR '{$separator}')";

        return '(SELECT '.$joined.' FROM console_genres cgl INNER JOIN genres cgt ON cgt.id = cgl.genres_id'
            .' WHERE cgl.consoleinfo_id = '.$consoleinfoIdColumn.')';
    }

    /**
     * A combined genre title's names in order: split on `,` and `|`, trimmed, empty parts left
     * out, with IGDB's 4X theme kept as one name.
     *
     * @return list<string>
     */
    public static function split(string $title): array
    {
        $parts = preg_split('/('.preg_quote(self::FOUR_X, '/').')|[,|]/', $title, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $name): bool => $name !== ''));
    }

    private function genreId(string $title): ?int
    {
        $title = mb_substr(trim($title), 0, self::GENRE_TITLE_LENGTH);
        if ($title === '') {
            return null;
        }

        $find = static fn (): mixed => Genre::query()->where('type', Category::GAME_ROOT)->where('title', $title)->orderBy('id')->value('id');
        $id = $find();
        if ($id !== null) {
            return (int) $id;
        }

        return (int) Cache::lock(self::GENRE_LOCK, self::GENRE_LOCK_SECONDS)->block(
            self::GENRE_LOCK_SECONDS,
            static fn (): mixed => $find() ?? Genre::query()->insertGetId(['title' => $title, 'type' => Category::GAME_ROOT, 'disabled' => 0]),
        );
    }
}
