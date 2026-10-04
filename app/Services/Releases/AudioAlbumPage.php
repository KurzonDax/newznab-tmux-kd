<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ConsoleGamePageFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "All N releases of this album" on the Audio album page (docs/proposals/audio-redesign/SPEC.md 5B.6,
 * DATA-CONTRACT.md 4.5), as ConsoleGamePage is for a game: the band-3000 releases whose tags name
 * the same album and the same artist (the album artist, else the performer; an empty or blank
 * value counts as none), without regard to case, under the password setting and without the viewer's excluded categories. A release whose
 * tags name no artist matches the same album's releases that name none either. On MariaDB the read
 * starts from the tags' album index and joins `releases` in that order (STRAIGHT_JOIN), where the
 * column collation ignores case; on other drivers it is a plain join whose tests add COLLATE NOCASE.
 */
final class AudioAlbumPage
{
    /** The tags' album index the MariaDB read starts from. */
    public const string ALBUM_INDEX = 'release_audio_tags_album_index';

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * The album's releases the viewer may see.
     *
     * @param  list<int>  $exclusions
     */
    public function count(string $album, ?string $artist, array $exclusions): int
    {
        return $this->visible($album, $artist, $exclusions)->count();
    }

    /**
     * The ids of the requested page in the table's order.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(string $album, ?string $artist, ConsoleGamePageFilters $filters, array $exclusions): array
    {
        return $this->ordered($album, $artist, $filters, $exclusions)->offset(($filters->page - 1) * ConsoleGamePageFilters::PER_PAGE)
            ->limit(ConsoleGamePageFilters::PER_PAGE)->pluck('releases.id')->map(static fn (mixed $release): int => (int) $release)->all();
    }

    /**
     * The page of the table, in its order, that holds a release: where "All N releases of this
     * album" opens; null when the viewer may not see it.
     *
     * @param  list<int>  $exclusions
     */
    public function pageHolding(string $album, ?string $artist, int $releaseId, ConsoleGamePageFilters $filters, array $exclusions): ?int
    {
        $rank = $this->ordered($album, $artist, $filters, $exclusions)->pluck('releases.id')->search(static fn (mixed $release): bool => (int) $release === $releaseId);

        return $rank === false ? null : intdiv((int) $rank, ConsoleGamePageFilters::PER_PAGE) + 1;
    }

    /**
     * Every release of the album the viewer may see: Similar releases leaves them out.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function ids(string $album, ?string $artist, array $exclusions): array
    {
        return $this->visible($album, $artist, $exclusions)->pluck('releases.id')->map(static fn (mixed $release): int => (int) $release)->all();
    }

    /**
     * The table's order: the sorted column, then newest posted first, then the higher id. Category
     * orders by the sub-category's place in the Audio list's Category menu; one the order does not
     * list (an admin's own) comes after the last listed one.
     *
     * @param  list<int>  $exclusions
     */
    private function ordered(string $album, ?string $artist, ConsoleGamePageFilters $filters, array $exclusions): Builder
    {
        $direction = $filters->ascending ? 'asc' : 'desc';
        $query = $this->visible($album, $artist, $exclusions);
        match ($filters->sort) {
            'category' => $query->orderByRaw(self::categoryOrder().' '.$direction)->orderByDesc('releases.postdate'),
            'size' => $query->orderBy('releases.size', $direction)->orderByDesc('releases.postdate'),
            default => $query->orderBy('releases.postdate', $direction),
        };

        return $query->orderByDesc('releases.id');
    }

    /** `CASE releases.categories_id WHEN <id> THEN <position> … ELSE <the order's length> END` over AudioReleaseList::CATEGORY_ORDER. */
    private static function categoryOrder(): string
    {
        $order = AudioReleaseList::CATEGORY_ORDER;
        $when = implode(' ', array_map(static fn (int $category, int $position): string => 'WHEN '.$category.' THEN '.$position, $order, array_keys($order)));

        return 'CASE releases.categories_id '.$when.' ELSE '.count($order).' END';
    }

    /** @param list<int> $exclusions */
    private function visible(string $album, ?string $artist, array $exclusions): Builder
    {
        $mariaDb = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        // MariaDB's column collation (utf8mb4_unicode_ci) ignores case; SQLite's `=` does not. Never LOWER(): it would bypass the album index.
        $collate = $mariaDb ? '' : ' COLLATE NOCASE';
        $query = $mariaDb
            ? DB::query()->from(DB::raw('release_audio_tags FORCE INDEX ('.self::ALBUM_INDEX.') STRAIGHT_JOIN releases ON releases.id = release_audio_tags.releases_id'))
            : DB::table('release_audio_tags')->join('releases', 'releases.id', '=', 'release_audio_tags.releases_id');
        $query->whereRaw('release_audio_tags.album = ?'.$collate, [$album]);
        // The artist as the page reads it: the album artist, else the performer, an empty or blank value counting as none.
        $artistSql = "COALESCE(NULLIF(TRIM(release_audio_tags.album_performer), ''), NULLIF(TRIM(release_audio_tags.performer), ''))";
        if ($artist === null) {
            $query->whereRaw($artistSql.' IS NULL');
        } else {
            $query->whereRaw($artistSql.' = ?'.$collate, [trim($artist)]);
        }
        $query->whereBetween('releases.categories_id', AudioReleaseList::BAND_CATEGORIES)
            ->whereRaw('releases.passwordstatus '.$this->releases->showPasswords());
        if ($exclusions !== []) {
            $query->whereNotIn('releases.categories_id', $exclusions);
        }

        return $query;
    }
}
