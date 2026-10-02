<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ConsoleGame;
use App\Data\ConsoleGamePageFilters;
use App\Data\ConsoleReleaseRow;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\Release;

/**
 * The Console release page of a release with a game (docs/proposals/books-console-pc-redesign/SPEC.md
 * 5B): the release as a Console list row, its sub-category for the breadcrumb and the game line,
 * the game (ConsoleGamePage), the facts grid without a Genre fact (the genres are the header's
 * tags), the PreDB block, "All N releases of this game" and Similar releases without the game's
 * own releases (DATA-CONTRACT 4.3), as MovieReleaseDetails is for a film.
 */
final class ConsoleGameReleaseDetails
{
    public function __construct(
        private readonly ConsoleReleaseRows $rows,
        private readonly ConsoleGamePage $games,
        private readonly ReleaseSearchService $search,
    ) {}

    /**
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, string $category, array $exclusions, ConsoleGamePageFilters $table, bool $pageNamed): array
    {
        $row = $this->row($release);
        $game = $this->game($row, $exclusions);
        abort_if($game === null, 404);
        // The breadcrumb and the game line name the sub-category alone ("PS3"); the facts read the root by its label ("Console > PS3").
        $subCategory = (string) (Category::query()->whereKey((int) $release->categories_id)->value('title') ?? '');
        $category = $subCategory === '' ? $category : BrowseRoot::Console->label().' > '.$subCategory;

        return [
            'row' => $row,
            'game' => $game,
            'category' => $category,
            'subCategory' => $subCategory,
            'facts' => ReleaseDetailsFacts::grid($release, $row, $category),
            'predb' => ReleaseDetailsFacts::predb((int) $release->predb_id),
            'similar' => $this->similar($release, $row->gameId, $exclusions),
            ...$this->table($row, $game, $exclusions, $table, $pageNamed),
        ];
    }

    /**
     * "All N releases of this game" alone: the fragment a sort change or another page loads.
     *
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function releasesTable(Release $release, array $exclusions, ConsoleGamePageFilters $table, bool $pageNamed): array
    {
        $row = $this->row($release);
        $game = $this->game($row, $exclusions);

        return ['row' => $row, 'game' => $game, ...$this->table($row, $game, $exclusions, $table, $pageNamed)];
    }

    private function row(Release $release): ConsoleReleaseRow
    {
        $row = $this->rows->load([(int) $release->id], false)[0] ?? null;
        abort_if($row === null, 404);

        return $row;
    }

    /** @param list<int> $exclusions */
    private function game(ConsoleReleaseRow $row, array $exclusions): ?ConsoleGame
    {
        return $row->gameId === null ? null : $this->games->game($row->gameId, $exclusions);
    }

    /**
     * The table opens on the page holding this release unless the URL names a page; a release the
     * viewer may not see (a hidden password status) opens on page 1.
     *
     * @param  list<int>  $exclusions
     * @return array{table: ConsoleGamePageFilters|null, tableRows: list<ConsoleReleaseRow>, tableTotal: int, tableLastPage: int}
     */
    private function table(ConsoleReleaseRow $row, ?ConsoleGame $game, array $exclusions, ConsoleGamePageFilters $table, bool $pageNamed): array
    {
        if ($game === null || $game->releases === 0) {
            return ['table' => null, 'tableRows' => [], 'tableTotal' => 0, 'tableLastPage' => 1];
        }
        $lastPage = max(1, (int) ceil($game->releases / ConsoleGamePageFilters::PER_PAGE));
        $page = $pageNamed ? $table->page : ($this->games->pageHolding($game->id, $row->id, $table, $exclusions) ?? 1);
        $table = new ConsoleGamePageFilters(sort: $table->sort, ascending: $table->ascending, page: min($page, $lastPage));

        return [
            'table' => $table,
            'tableRows' => $this->rows->load($this->games->pageIds($game->id, $table, $exclusions), false),
            'tableTotal' => $game->releases,
            'tableLastPage' => $lastPage,
        ];
    }

    /**
     * Similar releases: today's search (ReleaseSearchService::searchSimilar(), which leaves out this
     * release) without the releases of the same game (the table above lists them), newest posted first
     * as the table's Posted heading says (ties: the newer id first).
     *
     * @param  list<int>  $exclusions
     * @return list<ConsoleReleaseRow>
     */
    private function similar(Release $release, ?int $gameId, array $exclusions): array
    {
        $found = $this->search->searchSimilar((int) $release->id, (string) $release->searchname, $exclusions);
        if (! is_array($found)) {
            return [];
        }
        $ids = array_map(static fn (mixed $match): int => (int) $match['id'], array_values($found));
        if ($ids === []) {
            return [];
        }
        $rows = array_values(array_filter($this->rows->load($ids, false), static fn (ConsoleReleaseRow $row): bool => $gameId === null || $row->gameId !== $gameId));
        usort($rows, static fn (ConsoleReleaseRow $a, ConsoleReleaseRow $b): int => [$b->postedAt, $b->id] <=> [$a->postedAt, $a->id]);

        return $rows;
    }
}
