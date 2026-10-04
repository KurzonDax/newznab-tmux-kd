<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AudioReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Audio releases list's queries (ShelfReleaseList, docs/proposals/audio-redesign/DATA-CONTRACT.md
 * 4.1-4.4): band 3000. With no Genre and no Year, the shelf list's release index. Unknown alone
 * and genres with Unknown, without a Year, stay release-led on that index: an anti-join on the
 * release's position-0 genre row (`u`), with an EXISTS for the genres. A Year, or genres without
 * Unknown, make the read tag-led: a derived table `g` of the matching release ids (the genres'
 * link rows, or the tags' Year index) joined in that order (STRAIGHT_JOIN) to `releases` on its
 * primary key, the band as `categories_id BETWEEN 3000 AND 3999`, the release filters and the
 * name search as conditions; the same cost on every page, so never mirrored. The name search
 * also reads the tags' album, album artist and performer.
 *
 * @extends ShelfReleaseList<AudioReleaseFilters>
 */
final class AudioReleaseList extends ShelfReleaseList
{
    /** The index a tag-led read joins `releases` on, so BandReleaseList::pageIds() never mirrors it. */
    public const string TAG_LED = 'PRIMARY';

    /** The tags' Year index a year-first read starts from. */
    public const string YEAR_INDEX = 'ix_release_audio_tags_recorded_year';

    /** The band as the tag-led reads test it on `releases`: the Audio categories_id range. */
    public const array BAND_CATEGORIES = [Category::MUSIC_ROOT, Category::MUSIC_ROOT + 999];

    /** The Category menu's order (SPEC 5.2): the site's order, Other last. */
    public const CATEGORY_ORDER = [Category::MUSIC_MP3, Category::MUSIC_VIDEO, Category::MUSIC_AUDIOBOOK, Category::MUSIC_LOSSLESS, Category::MUSIC_PODCAST,
        Category::MUSIC_FOREIGN, Category::MUSIC_OTHER];

    protected function band(): int
    {
        return Category::MUSIC_ROOT;
    }

    protected function cachePrefix(): string
    {
        return 'audio_releases';
    }

    /** @param AudioReleaseFilters $filters */
    protected function countIndex(ReleaseListFilters $filters): string
    {
        return $this->tagLed($filters) ? self::TAG_LED : parent::countIndex($filters);
    }

    /**
     * @param  AudioReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    public function readIndex(ReleaseListFilters $filters, array $exclusions): string
    {
        return $this->tagLed($filters) ? self::TAG_LED : parent::readIndex($filters, $exclusions);
    }

    /**
     * The Genre menu (SPEC 5.8), the same for every user and kept for an hour: the `audio_genres`
     * rows a band-3000 release has, one probe per genre, A to Z ignoring case (ties by id); then
     * Unknown when a band release has no genre row.
     *
     * @return array<int|string, string> URL value (audio_genres.id, or AudioReleaseFilters::GENRE_UNKNOWN) => name
     */
    public function genreMenu(): array
    {
        return Cache::remember($this->cachePrefix().'_genre_menu', self::MENU_SECONDS, function (): array {
            $probe = DB::table('release_audio_genres as rag')->join('releases as r', 'r.id', '=', 'rag.releases_id')->select('rag.releases_id')
                ->whereColumn('rag.audio_genres_id', 'ag.id')->whereBetween('r.categories_id', self::BAND_CATEGORIES)->limit(1);
            $menu = DB::table('audio_genres as ag')->whereRaw('('.$probe->toSql().') IS NOT NULL', $probe->getBindings())->get(['ag.id', 'ag.name'])
                ->mapWithKeys(static fn (object $genre): array => [(int) $genre->id => (string) $genre->name])->all();
            uksort($menu, static fn (int $a, int $b): int => [mb_strtolower($menu[$a]), $a] <=> [mb_strtolower($menu[$b]), $b]);
            $unknown = DB::table('releases as r');
            if ($this->isMariaDb()) {
                $unknown->forceIndex('ix_releases_band_posted');
            }
            $unknown->leftJoin('release_audio_genres as u', static fn (JoinClause $join): JoinClause => $join->on('u.releases_id', '=', 'r.id')->where('u.position', '=', 0))
                ->where('r.category_band', $this->band())->whereNull('u.releases_id');
            if ($unknown->limit(1)->value('r.id') !== null) {
                $menu[AudioReleaseFilters::GENRE_UNKNOWN] = 'Unknown';
            }

            return $menu;
        });
    }

    /**
     * The Genre menu with the ticked genres it does not list yet (it lags new genres by up to an
     * hour), in A to Z order before Unknown, so the cell names what filters the list.
     *
     * @param  array<int|string, string>  $menu  genreMenu()
     * @param  list<int|string>  $ticked  AudioReleaseFilters::$genres
     * @return array<int|string, string>
     */
    public function genreMenuWith(array $menu, array $ticked): array
    {
        $missing = array_values(array_diff(array_filter($ticked, 'is_int'), array_keys($menu)));
        if ($missing === []) {
            return $menu;
        }
        $unknown = array_key_exists(AudioReleaseFilters::GENRE_UNKNOWN, $menu) ? [AudioReleaseFilters::GENRE_UNKNOWN => $menu[AudioReleaseFilters::GENRE_UNKNOWN]] : [];
        unset($menu[AudioReleaseFilters::GENRE_UNKNOWN]);
        $menu += DB::table('audio_genres')->whereIn('id', $missing)->pluck('name', 'id')->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();
        uksort($menu, static fn (int|string $a, int|string $b): int => [mb_strtolower($menu[$a]), $a] <=> [mb_strtolower($menu[$b]), $b]);

        return $menu + $unknown;
    }

    /**
     * @param  AudioReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function visible(ReleaseListFilters $filters, array $exclusions, string $index): Builder
    {
        if ($this->tagLed($filters)) {
            return $this->tagReleases($filters, $exclusions);
        }
        $query = parent::visible($filters, $exclusions, $index);
        if ($filters->genreUnknown()) {
            // Unknown alone, or genres with Unknown, without a Year: the anti-join on the release index.
            $this->joinFirstGenre($query, 'releases.id');
            $query->where(fn (Builder $either) => $this->whereNoGenreOr($either, 'releases.id', $filters->genreIds()));
        }

        return $query;
    }

    /**
     * The name search (SPEC 5.6) on a release-led read: the release name, or the tags' album,
     * album artist or performer, contains the text; the tags as an IN list (DATA-CONTRACT 4.2: a
     * LEFT JOIN with OR reads twice as long).
     */
    protected function whereSearch(Builder $query, string $search): void
    {
        $like = self::likeContaining($search);
        $query->where(static fn (Builder $either) => $either->whereRaw(self::RELEASE_NAME." LIKE ? ESCAPE '!'", [$like])
            ->orWhereIn('releases.id', static fn (Builder $tags) => $tags->select('t.releases_id')->from('release_audio_tags as t')
                ->whereRaw('('.self::tagsContain('t').')', [$like, $like, $like])));
    }

    /** The tags' album, album artist or performer LIKE three bound patterns, on the tag columns of $tags. */
    private static function tagsContain(string $tags): string
    {
        return "{$tags}.album LIKE ? ESCAPE '!' OR {$tags}.album_performer LIKE ? ESCAPE '!' OR {$tags}.performer LIKE ? ESCAPE '!'";
    }

    /** A Year, or genres without Unknown: the read starts from the tags or the genre links (reads 4-7). */
    private function tagLed(AudioReleaseFilters $filters): bool
    {
        return $filters->anyYear() || ($filters->genreIds() !== [] && ! $filters->genreUnknown());
    }

    /**
     * The matching release ids (`g`) joined in that order to their releases on the primary key,
     * with the band, the release filters and the name search on the release and its tags. Genres
     * without Unknown start from the genre links, with the tag row (`t`) joined after them while a
     * Year or the name search reads it; otherwise the tags' Year index starts, `g` carrying the
     * tag columns the name search reads.
     *
     * @param  list<int>  $exclusions
     */
    private function tagReleases(AudioReleaseFilters $filters, array $exclusions): Builder
    {
        $search = $filters->search;
        $genreFirst = ! $filters->genreUnknown() && $filters->genreIds() !== [];
        $tagColumns = $genreFirst ? 't' : 'g';
        if ($genreFirst) {
            $ids = DB::table('release_audio_genres as rag')->distinct()->select('rag.releases_id as id')->whereIn('rag.audio_genres_id', $filters->genreIds());
            $withTags = $filters->anyYear() || $search !== '';
        } else {
            $ids = $this->yearIds($filters);
            $withTags = false;
        }
        if ($this->isMariaDb()) {
            $from = '('.$ids->toSql().') AS g'.($withTags ? ' STRAIGHT_JOIN release_audio_tags t ON t.releases_id = g.id' : '').' STRAIGHT_JOIN releases ON releases.id = g.id';
            $query = DB::query()->fromRaw($from, $ids->getBindings());
        } else {
            $query = DB::query()->fromSub($ids, 'g');
            if ($withTags) {
                $query->join('release_audio_tags as t', 't.releases_id', '=', 'g.id');
            }
            $query->join('releases', 'releases.id', '=', 'g.id');
        }
        if ($genreFirst && $filters->anyYear()) {
            $this->whereYear($query, $filters, 't');
        }
        $query->whereBetween('releases.categories_id', self::BAND_CATEGORIES);
        $this->whereRelease($query, $filters, $exclusions);
        if ($search !== '') {
            $like = self::likeContaining($search);
            $query->whereRaw('('.self::RELEASE_NAME." LIKE ? ESCAPE '!' OR ".self::tagsContain($tagColumns).')', [$like, $like, $like, $like]);
        }

        return $query;
    }

    /**
     * Year first (reads 5-7): the tag rows of the Year on its index, with Unknown the anti-join on
     * the position-0 genre row (or a ticked genre's link), and the tag columns the name search
     * reads while it is set.
     */
    private function yearIds(AudioReleaseFilters $filters): Builder
    {
        $ids = DB::table('release_audio_tags as t');
        if ($this->isMariaDb()) {
            $ids->forceIndex(self::YEAR_INDEX);
        }
        $ids->select($filters->search === '' ? ['t.releases_id as id'] : ['t.releases_id as id', 't.album', 't.album_performer', 't.performer']);
        if ($filters->genreUnknown()) {
            $this->joinFirstGenre($ids, 't.releases_id');
        }
        $this->whereYear($ids, $filters, 't');
        if ($filters->genreUnknown()) {
            $ids->where(fn (Builder $either) => $this->whereNoGenreOr($either, 't.releases_id', $filters->genreIds()));
        }

        return $ids;
    }

    /**
     * The tags' recorded year in a ticked decade or the typed range (From alone is that year); a
     * release with no tag row or no recorded year never matches.
     */
    private function whereYear(Builder $query, AudioReleaseFilters $filters, string $tags): void
    {
        $query->where(static function (Builder $any) use ($filters, $tags): void {
            if (($bounds = $filters->yearBounds()) !== null) {
                $any->whereBetween($tags.'.recorded_year', $bounds);

                return;
            }
            foreach ($filters->decades as $decade) {
                $any->orWhereBetween($tags.'.recorded_year', [$decade, $decade + 9]);
            }
        });
    }

    /** LEFT JOIN the release's position-0 genre row as `u`; every release with a genre has exactly one. */
    private function joinFirstGenre(Builder $query, string $releaseId): void
    {
        $query->leftJoin('release_audio_genres as u', static fn (JoinClause $join): JoinClause => $join->on('u.releases_id', '=', $releaseId)->where('u.position', '=', 0));
    }

    /**
     * No genre row, or (with ticked genres) a link to one of them.
     *
     * @param  list<int>  $genreIds
     */
    private function whereNoGenreOr(Builder $either, string $releaseId, array $genreIds): void
    {
        $either->whereNull('u.releases_id');
        if ($genreIds !== []) {
            $either->orWhereExists(static fn (Builder $links) => $links->selectRaw('1')->from('release_audio_genres as rag')
                ->whereColumn('rag.releases_id', $releaseId)->whereIn('rag.audio_genres_id', $genreIds));
        }
    }
}
