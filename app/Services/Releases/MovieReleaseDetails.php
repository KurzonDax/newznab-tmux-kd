<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieFilmHeader;
use App\Data\MovieFilmPageFilters;
use App\Data\MovieReleaseRow;
use App\Models\Release;
use App\Models\ReleaseVideoClip;

/**
 * The Movies release details page (docs/proposals/movies-redesign/SPEC.md 5C): the release as a
 * Movies row, its film's header parts and "About the film", the facts grid, the PreDB block,
 * "All N releases of this film" (DATA-CONTRACT 4.4) and Similar releases without the film's own
 * releases (4.5). A release with no matched film (movieinfo_id empty) gets none of the film's parts.
 */
final class MovieReleaseDetails
{
    /** "Starring" in "About the film" names at most this many people (SPEC 5C.3). */
    public const STARRING_LIMIT = 8;

    public function __construct(
        private readonly MovieReleaseRows $rows,
        private readonly MovieFilmPage $films,
        private readonly ReleaseSearchService $search,
    ) {}

    /**
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, string $category, array $exclusions, MovieFilmPageFilters $table, bool $pageNamed): array
    {
        $row = $this->row($release);
        $film = $this->film($row, $exclusions);

        return [
            'row' => $row,
            'film' => $film,
            'category' => $category,
            'starring' => $film === null ? [] : array_slice($film->cast, 0, self::STARRING_LIMIT, true),
            'clip' => $this->clip($release),
            'facts' => ReleaseDetailsFacts::grid($release, $row, $category),
            'predb' => ReleaseDetailsFacts::predb((int) $release->predb_id),
            'similar' => $this->similar($release, $exclusions, $row->filmId),
            ...$this->table($row, $film, $exclusions, $table, $pageNamed),
        ];
    }

    /**
     * "All N releases of this film" alone: the fragment a sort change or another page loads.
     *
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function releasesTable(Release $release, array $exclusions, MovieFilmPageFilters $table, bool $pageNamed): array
    {
        $row = $this->row($release);
        $film = $this->film($row, $exclusions);

        return ['row' => $row, 'film' => $film, ...$this->table($row, $film, $exclusions, $table, $pageNamed)];
    }

    private function row(Release $release): MovieReleaseRow
    {
        $row = $this->rows->load([(int) $release->id], false)[0] ?? null;
        abort_if($row === null, 404);

        return $row;
    }

    /** @param list<int> $exclusions */
    private function film(MovieReleaseRow $row, array $exclusions): ?MovieFilmHeader
    {
        return $row->filmId === null ? null : $this->films->header($row->filmId, $exclusions);
    }

    /**
     * The table opens on the page holding this release unless the URL names a page; a release the
     * viewer may not see (an excluded category, a hidden password status) opens on page 1.
     *
     * @param  list<int>  $exclusions
     * @return array{table: MovieFilmPageFilters|null, tableRows: list<MovieReleaseRow>, tableTotal: int, tableLastPage: int}
     */
    private function table(MovieReleaseRow $row, ?MovieFilmHeader $film, array $exclusions, MovieFilmPageFilters $table, bool $pageNamed): array
    {
        if ($film === null || $film->releases === 0) {
            return ['table' => null, 'tableRows' => [], 'tableTotal' => 0, 'tableLastPage' => 1];
        }
        $lastPage = max(1, (int) ceil($film->releases / MovieFilmPageFilters::PER_PAGE));
        $page = $pageNamed ? $table->page : ($this->films->pageHolding($film->id, $row->id, $table, $exclusions) ?? 1);
        $table = new MovieFilmPageFilters(sort: $table->sort, ascending: $table->ascending, page: min($page, $lastPage));

        return [
            'table' => $table,
            'tableRows' => $this->rows->load($this->films->pageIds($film->id, $table, $exclusions), false),
            'tableTotal' => $film->releases,
            'tableLastPage' => $lastPage,
        ];
    }

    /**
     * Similar releases without the film's own releases (ReleaseSearchService::searchSimilarMovies()).
     *
     * @param  list<int>  $exclusions
     * @return list<MovieReleaseRow>
     */
    private function similar(Release $release, array $exclusions, ?int $filmId): array
    {
        $ids = $this->search->searchSimilarMovies((int) $release->id, (string) $release->searchname, $exclusions, $filmId);

        return $ids === [] ? [] : $this->rows->load($ids, false);
    }

    /**
     * The Clip chip's player (the video preview, as today's preview modal plays it), or null.
     *
     * @return array{url: string, type: string}|null
     */
    private function clip(Release $release): ?array
    {
        if ((int) $release->videostatus !== 1) {
            return null;
        }
        $clip = ReleaseVideoClip::query()->where('releases_id', $release->id)->first(['releases_id', 'extension', 'mime']);

        return ['url' => route('preview.video', $release->guid), 'type' => $clip?->clipMimeType() ?? ReleaseVideoClip::VIDEO_MIME_TYPES['ogv']];
    }
}
