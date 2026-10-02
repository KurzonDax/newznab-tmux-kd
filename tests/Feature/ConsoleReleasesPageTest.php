<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ConsoleReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Enums\ReleaseSort;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\MetadataProcessing\ConsoleGenres;
use App\Services\Releases\ConsoleReleaseList;
use App\Services\Releases\ConsoleReleaseRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The Console releases list, GET /console (issue #933; docs/proposals/books-console-pc-redesign/SPEC.md
 * 1, 4 and 5, DATA-CONTRACT.md 4.2 and 4.5 and the list checks of prototype/check.mjs on console.html).
 */
final class ConsoleReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const NDS = 1010;

    private const PSP = 1020;

    private const PS3 = 1080;

    private const PS4 = 1180;

    private const CONSOLE_OTHER = 1999;

    private const ACTION = 101;

    private const FIGHTING = 102;

    private const RPG = 103;

    private const UNKNOWN_GENRE = 104;

    private const FOUR_X = 105;

    private const PUZZLE = 106;

    private const DRAMA = 201;

    private ?User $user = null;

    private string $covers = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        Carbon::setTestNow('2026-09-25 12:00:00');
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'name', 'searchname', 'guid', 'display_name', 'categories_id', 'category_band', 'size', 'totalpart',
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'repair_outcome', 'rescan_outcome', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages', 'genres', 'consoleinfo', 'console_genres'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 1000, 'title' => 'Console', 'status' => 1], ['id' => 7000, 'title' => 'Books', 'status' => 1]]);
        foreach ([self::CONSOLE_OTHER => 'Other', self::PS4 => 'PS4', self::PS3 => 'PS3', self::PSP => 'PSP', self::NDS => 'NDS'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 1000, 'status' => 1]);
        }
        DB::table('categories')->insert(['id' => 7020, 'title' => 'Ebook', 'root_categories_id' => 7000, 'status' => 1]);
        $this->covers = $this->makeTempDirectory('console-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_list_shows_the_console_band_only_newest_posted_first_with_its_header(): void
    {
        $this->console('Console.Newer', ['postdate' => '2026-09-25 10:00:00']);
        $this->console('Console.Older', ['postdate' => '2026-09-20 10:00:00', 'categories_id' => self::NDS]);
        $this->console('Console.Other.Upload', ['postdate' => '2026-09-24 10:00:00', 'categories_id' => self::CONSOLE_OTHER]);
        $this->release('A.Book', ['categories_id' => 7020, 'postdate' => '2026-09-25 11:00:00']);

        $response = $this->page('/console')->assertOk()
            ->assertSee('<h1 data-part="page title">Console releases</h1>', false)
            ->assertSee('<title>Console releases', false)
            ->assertSee('data-preference-root="console"', false)
            ->assertSeeInOrder(['Console.Newer', 'Console.Other.Upload', 'Console.Older'])
            ->assertDontSee('A.Book')
            ->assertSee('Showing 1–3 of 3 releases')->assertSee('2 hr ago')->assertSee('Sep 20, 2026')
            ->assertDontSee('style="', false);
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<h1 data-part="page title">Console releases<\/h1>.*?<div class="tv-search tv-name-search">\s*<label>\s*<i class="fas fa-magnifying-glass" aria-hidden="true"><\/i>\s*'
            .'<input type="text" x-ref="nameSearch" value="" placeholder="Search releases or games" autocomplete="off" aria-label="Search release and game names"/s', $html);
        $this->assertNoWatchWording($html, 'The Console releases list');
        $this->assertSame('/console', route('console.releases', [], false));
    }

    public function test_category_exclude_other_completion_the_sort_and_paging_behave_as_on_books(): void
    {
        $expected = [];
        foreach (range(1, 120) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            $expected[] = [$postdate, $this->console('Paged '.$index, ['postdate' => $postdate, 'categories_id' => [self::PS3, self::NDS, self::CONSOLE_OTHER][$index % 3],
                'completion' => [100, 96, 80][$index % 3]])];
        }
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);
        foreach ([1 => [1, 50], 2 => [51, 100], 3 => [101, 120]] as $page => [$from, $to]) {
            $response = $this->page('/console'.($page > 1 ? '?page='.$page : ''))->assertOk()->assertSee('Showing '.$from.'–'.$to.' of 120 releases');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $this->page('/console?page=9')->assertRedirect(route('console.releases', ['page' => 3]));

        $this->assertSame([self::NDS => 'NDS', self::PS3 => 'PS3', self::CONSOLE_OTHER => 'Other'], $this->page('/console')->viewData('categoryMenu'));
        $this->page('/console?category[]='.self::NDS)->assertSee('Showing 1–40 of 40 releases');
        $this->page('/console?category=exclude-other')->assertSee('title="Category: Exclude Other"', false)->assertSee('Showing 1–50 of 80 releases');
        $this->page('/console?completion=95')->assertSee('Showing 1–50 of 80 releases');
        $this->page('/console?completion=100&category[]='.self::NDS)->assertSee('Showing 0 releases')->assertSee('No releases match NDS · 100% complete.');
        $this->page('/console?category=exclude-other&completion=100')->assertSee('Showing 1–40 of 40 releases');
        $this->postJson('/profile/update-view', ['root' => 'console', 'sort' => 'posted_oldest'])->assertOk();
        $this->page('/console?clear=1')->assertRedirect(route('console.releases'));
        $oldest = $this->page('/console', User::query()->findOrFail($this->user->id))->assertOk();
        $this->assertSame(array_slice(array_reverse($order), 0, 50), $this->listedIds($oldest));
        preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', (string) $oldest->getContent(), $cells);
        $this->assertSame(['category', 'completion', 'genre', 'year'], $cells[1]);
        $oldest->assertSeeInOrder(['<div class="filter-row tv-bar-list is-shelf">', '<div class="filter-bar is-release" role="group" aria-label="The release">',
            'data-name="category"', 'data-name="completion"', '<div class="filter-bar is-game" role="group" aria-label="The game">', 'data-name="genre"',
            'data-name="year"', 'x-ref="list" class="tv-list-end"'], false);
        $this->assertSame(['Category: any', 'Completion: any', 'Genre: any', 'Year: any'], array_map(fn (string $name): string => $this->cellText($oldest, $name),
            ['category', 'completion', 'genre', 'year']));
    }

    public function test_a_genre_newer_than_the_cached_menu_still_filters_and_an_id_of_no_console_genre_is_ignored(): void
    {
        $this->genre(self::ACTION, 'Action');
        $this->genre(self::FIGHTING, 'Fighting');
        $this->genre(self::DRAMA, 'Drama', 2000);
        $this->game(1, 'Brawler', '2010-01-01', [self::ACTION]);
        $this->game(2, 'Puncher', '2011-01-01', [self::ACTION]);
        $this->console('Brawler.PS3', ['consoleinfo_id' => 1]);
        $this->console('Puncher.PS3', ['consoleinfo_id' => 2]);
        $this->page('/console')->assertOk();
        $this->assertSame([self::ACTION => 'Action'], Cache::get('console_releases_genre_menu'));

        app(ConsoleGenres::class)->replace(2, [self::FIGHTING]);
        $set = $this->page('/console?genre[]='.self::FIGHTING)->assertOk()->assertSee('Showing 1–1 of 1 release');
        $this->assertSame(['Puncher.PS3'], $this->listedNames($set));
        $this->assertSame([self::ACTION => 'Action'], Cache::get('console_releases_genre_menu'), 'the cached menu does not list it yet');
        $this->assertSame([self::FIGHTING], $set->viewData('filters')->genres);
        $this->assertSame('Genre: Fighting', $this->cellText($set, 'genre'), 'the cell names the genre that filters the list');
        $this->assertSame(['genre' => [self::FIGHTING]], $this->remembered($this->user, 'console'));

        foreach (['/console?genre[]='.self::DRAMA, '/console?genre[]=999', '/console?genre[]=abc', '/console?genre[]=unknown'] as $uri) {
            $ignored = $this->page($uri)->assertOk()->assertSee('Showing 1–2 of 2 releases');
            $this->assertSame([], $ignored->viewData('filters')->genres, $uri);
        }
    }

    public function test_the_game_menus_and_every_filter_return_exactly_what_a_direct_predicate_returns_in_every_sort_and_on_every_page(): void
    {
        [$releases, $games] = $this->catalogue();
        $list = app(ConsoleReleaseList::class);
        $genreSets = [[self::ACTION], [self::ACTION, self::FIGHTING], [ConsoleReleaseFilters::GENRE_UNKNOWN], [self::FIGHTING, ConsoleReleaseFilters::GENRE_UNKNOWN], []];
        $others = [[], ['categories' => [self::PS3]], ['completion' => 95], ['decades' => [2000]], ['decades' => [1990, 2010]], ['yearFrom' => 2001, 'yearTo' => 2004],
            ['yearFrom' => 1998], ['search' => 'Blades'], ['categories' => [self::NDS, self::CONSOLE_OTHER], 'completion' => 95, 'decades' => [2000, 2010], 'search' => 'o']];
        $checked = 0;
        foreach ($genreSets as $genres) {
            foreach ($others as $other) {
                foreach (array_keys(ReleaseListFilters::SORTS) as $sortKey) {
                    $sort = ReleaseSort::from($sortKey);
                    $filters = new ConsoleReleaseFilters(...[...$other, 'sort' => $sort, 'genres' => $genres]);
                    if (! $filters->anyGame()) {
                        continue;
                    }
                    $expected = $this->expected($releases, $games, $filters);
                    $label = json_encode([$genres, $other, $sort->value], JSON_THROW_ON_ERROR);
                    $total = $list->count($filters, []);
                    $this->assertSame(count($expected), $total, $label);
                    foreach (range(1, max(1, (int) ceil($total / 50))) as $page) {
                        $this->assertSame(array_slice($expected, ($page - 1) * 50, 50), $list->pageIds($filters->withPage($page), [], $total), $label.' page '.$page);
                    }
                    $checked++;
                }
            }
        }
        $this->assertSame((4 * 9 + 5) * 4, $checked, 'every game menu set, alone and with each other filter, in the four sorts');
        $unknown = $this->expected($releases, $games, new ConsoleReleaseFilters(genres: [ConsoleReleaseFilters::GENRE_UNKNOWN]));
        $this->assertGreaterThan(100, count($unknown), 'the Unknown list runs over three pages');

        // over HTTP, the same lists
        $response = $this->page('/console?genre[]='.self::FIGHTING.'&genre[]=unknown&completion=95&category[]='.self::PS3.'&page=2')->assertOk();
        $expected = $this->expected($releases, $games, new ConsoleReleaseFilters(categories: [self::PS3], completion: 95, genres: [self::FIGHTING, ConsoleReleaseFilters::GENRE_UNKNOWN]));
        $this->assertSame(array_slice($expected, 50, 50), $this->listedIds($response));
        $response->assertSee('Showing 51–'.min(100, count($expected)).' of '.count($expected).' releases');
    }

    public function test_unknown_without_a_year_is_the_releases_with_no_game_and_the_games_with_no_genre_merged_with_the_parts_counts_added(): void
    {
        $this->genre(self::ACTION, 'Action');
        $this->genre(self::UNKNOWN_GENRE, ConsoleGenres::UNKNOWN);
        $this->game(1, 'With Action', '2010-01-01', [self::ACTION]);
        $this->game(2, 'Only Unknown', '2010-01-01', [self::UNKNOWN_GENRE]);
        $this->game(3, 'No Genre Row', '2010-01-01', []);
        $this->console('Not.Looked.Up', ['postdate' => '2026-09-20 00:00:00']);
        $this->console('Looked.Up.Not.Found', ['consoleinfo_id' => -2, 'postdate' => '2026-09-19 00:00:00']);
        $this->console('Game.Of.Unknown', ['consoleinfo_id' => 2, 'postdate' => '2026-09-21 00:00:00']);
        $this->console('Game.Without.Genre', ['consoleinfo_id' => 3, 'postdate' => '2026-09-18 00:00:00']);
        $this->console('Game.With.Action', ['consoleinfo_id' => 1, 'postdate' => '2026-09-22 00:00:00']);

        $response = $this->page('/console?genre[]=unknown')->assertOk()->assertSee('Showing 1–4 of 4 releases')
            ->assertSeeInOrder(['Game.Of.Unknown', 'Not.Looked.Up', 'Looked.Up.Not.Found', 'Game.Without.Genre']);
        $this->assertNotContains('Game.With.Action', $this->listedNames($response));
        $this->assertSame('Genre: Unknown', $this->cellText($response, 'genre'));
        $this->page('/console?genre[]='.self::ACTION.'&genre[]=unknown')->assertSee('Showing 1–5 of 5 releases');
        $this->page('/console?genre[]=unknown&decade[]=2010')->assertSee('Showing 1–2 of 2 releases')->assertDontSee('Not.Looked.Up');
        // the Unknown genre's own id (a genre link from a game page) reads as the one Unknown option
        $byId = $this->page('/console?genre[]='.self::UNKNOWN_GENRE)->assertOk()->assertSee('Showing 1–4 of 4 releases');
        $this->assertSame([ConsoleReleaseFilters::GENRE_UNKNOWN], $byId->viewData('filters')->genres);
        $this->assertSame([self::ACTION => 'Action', ConsoleReleaseFilters::GENRE_UNKNOWN => 'Unknown'], $byId->viewData('genreMenu'));

        $list = app(ConsoleReleaseList::class);
        $filters = new ConsoleReleaseFilters(genres: [ConsoleReleaseFilters::GENRE_UNKNOWN]);
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(4, $list->count($filters, []));
        $counts = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_starts_with($query['query'], 'select count(*)'))->count();
        DB::disableQueryLog();
        $this->assertSame(2, $counts, 'the count is the two parts counted and added');
    }

    public function test_year_reads_decades_a_range_and_from_alone_as_one_year_and_drops_releases_with_no_game(): void
    {
        foreach (['1987-06-01' => 'Year1987', '1998-12-31 23:59:59' => 'Year1998', '1999-01-01' => 'Year1999', '2001-03-01' => 'Year2001', '2004-12-31' => 'Year2004',
            '2005-01-01' => 'Year2005', '2015-05-05' => 'Year2015', '2024-02-02' => 'Year2024'] as $date => $name) {
            $id = (int) substr($name, 4);
            $this->game($id, 'Game '.$name, $date, []);
            $this->console($name, ['consoleinfo_id' => $id]);
        }
        $this->game(1, 'Undated', null, []);
        $this->console('Undated.Game', ['consoleinfo_id' => 1]);
        $this->console('No.Game');

        $this->assertListed('/console?decade[]=1990', ['Year1998', 'Year1999']);
        $this->assertListed('/console?decade[]=2000&decade[]=2020', ['Year2001', 'Year2004', 'Year2005', 'Year2024']);
        $this->assertListed('/console?year_from=2001&year_to=2004', ['Year2001', 'Year2004']);
        $this->assertListed('/console?year_from=1998', ['Year1998']);
        $this->assertListed('/console?year_from=1998&decade[]=2010', ['Year1998'], 'a range replaces ticked decades');
        foreach (['/console?decade[]=1980', '/console?year_from=1899&year_to=1999', '/console?year_from=1998&year_to=2030', '/console?year_from=2004&year_to=2001', '/console?year_from=98'] as $uri) {
            $ignored = $this->page($uri)->assertOk()->assertSee('Showing 1–10 of 10 releases');
            $this->assertFalse($ignored->viewData('filters')->anyYear(), $uri);
        }
        $this->assertSame('Year: 1998', $this->cellText($this->page('/console?year_from=1998&decade[]=2010'), 'year'));
        $this->page('/console?decade[]=1990&decade[]=2000&q=zzz')->assertSee('No releases match 2000s or 1990s · release or game names containing “zzz”.', false);
        $this->page('/console?year_from=1998&year_to=2001&q=zzz')->assertSee('No releases match 1998–2001 · release or game names containing “zzz”.', false);
        $this->page('/console?year_from=2024&q=zzz')->assertSee('No releases match 2024 · release or game names containing “zzz”.', false);
    }

    public function test_the_genre_menu_lists_the_console_genres_with_a_game_a_to_z_then_unknown_when_something_has_no_genre(): void
    {
        $this->genre(self::RPG, 'Role-playing (RPG)');
        $this->genre(self::ACTION, 'Action');
        $this->genre(self::FIGHTING, 'Fighting');
        $this->genre(self::UNKNOWN_GENRE, ConsoleGenres::UNKNOWN);
        $this->genre(self::PUZZLE, 'Puzzle');
        $this->genre(self::DRAMA, 'Drama', 2000);
        $this->game(1, 'Fighter', '2010-01-01', [self::FIGHTING, self::ACTION]);
        $this->game(2, 'Quest', '2010-01-01', [self::RPG]);
        $this->game(3, 'Unknown Game', '2010-01-01', [self::UNKNOWN_GENRE]);
        DB::table('consoleinfo')->where('id', 3)->update(['genres_id' => self::ACTION]);
        DB::table('console_genres')->insert(['consoleinfo_id' => 2, 'genres_id' => self::DRAMA, 'position' => 1]);
        $this->console('Fighter.PS3', ['consoleinfo_id' => 1]);
        $list = app(ConsoleReleaseList::class);

        $genres = [self::ACTION => 'Action', self::FIGHTING => 'Fighting', self::RPG => 'Role-playing (RPG)'];
        $this->assertSame($genres, $list->genreMenu(), 'no release without a game and no game without a genre: no Unknown');
        $this->assertSame($genres, Cache::get('console_releases_genre_menu'));
        foreach ([
            'a band release not looked up' => fn () => $this->console('Not.Looked.Up'),
            'a band release whose game was not found' => fn () => $this->console('Not.Found', ['consoleinfo_id' => -2]),
            'a game whose first genre is empty' => fn () => $this->game(4, 'No Genre', '2010-01-01', []),
            'a game of the Unknown genre' => fn () => DB::table('consoleinfo')->where('id', 3)->update(['genres_id' => self::UNKNOWN_GENRE]),
        ] as $case => $make) {
            DB::table('releases')->whereIn('name', ['Not.Looked.Up', 'Not.Found'])->delete();
            DB::table('consoleinfo')->where('id', 4)->delete();
            DB::table('consoleinfo')->where('id', 3)->update(['genres_id' => self::ACTION]);
            Cache::flush();
            $this->assertArrayNotHasKey(ConsoleReleaseFilters::GENRE_UNKNOWN, $list->genreMenu(), $case.': reset');
            $make();
            Cache::flush();
            $this->assertSame($genres + [ConsoleReleaseFilters::GENRE_UNKNOWN => 'Unknown'], $list->genreMenu(), $case);
        }
        $this->release('A.Book.Without.Game', ['categories_id' => 7020]);
        DB::table('consoleinfo')->where('id', 3)->update(['genres_id' => self::ACTION]);
        Cache::flush();
        $this->assertArrayNotHasKey(ConsoleReleaseFilters::GENRE_UNKNOWN, $list->genreMenu(), 'a release outside the band does not count');

        $this->console('Not.Looked.Up');
        Cache::flush();
        $first = $this->page('/console')->assertOk();
        $this->assertSame($genres + [ConsoleReleaseFilters::GENRE_UNKNOWN => 'Unknown'], $first->viewData('genreMenu'));
        $this->assertSame($first->viewData('genreMenu'), Cache::get('console_releases_genre_menu'), 'one cached menu, no user in its key');
        $first->assertSeeInOrder(['data-name="genre"', 'Any genre', 'Action', 'Fighting', 'Role-playing (RPG)', 'Unknown', 'data-name="year"'], false);
        $html = (string) $first->getContent();
        $this->assertMatchesRegularExpression('/<div class="checkbox-menu is-cell[^"]*" x-data="checkboxMenu" data-name="genre" data-label="Genre"/', $html);
        $this->assertStringContainsString('data-first="1900" data-last="2026"', $html);
        preg_match('/<div class="year-menu-decades".*?<\/div>\s*<\/div>/s', $html, $decades);
        preg_match_all('/data-value="(\d+)"[^>]*>.*?(\d{4}s)</s', $decades[0] ?? $html, $offered);
        $this->assertSame(['2020s', '2010s', '2000s', '1990s'], $offered[2]);
        $this->assertSame(['2020', '2010', '2000', '1990'], $offered[1]);
    }

    public function test_the_genre_menu_searches_inside_itself_over_ten_genres(): void
    {
        foreach (range(1, 11) as $index) {
            $this->genre(300 + $index, 'Genre '.chr(64 + $index));
        }
        $this->game(1, 'Everything', '2010-01-01', range(301, 311));
        $this->console('Everything.PS3', ['consoleinfo_id' => 1]);

        $this->page('/console')->assertOk()->assertSee('placeholder="Search genres"', false);
    }

    public function test_the_game_menus_read_the_per_game_index_and_the_release_filters_the_release_indexes(): void
    {
        foreach ([self::PS3, self::PS3, self::PS3, self::NDS] as $index => $category) {
            $this->console('Release '.$index, ['categories_id' => $category]);
        }
        $list = app(ConsoleReleaseList::class);
        $index = static fn (array $filters): string => $list->readIndex(new ConsoleReleaseFilters(...$filters), []);
        $count = fn (array $filters): string => (fn (ConsoleReleaseFilters $filters): string => $this->countIndex($filters))->call($list, new ConsoleReleaseFilters(...$filters));
        $added = ['sort' => ReleaseSort::AddedNewest];

        $this->assertSame('ix_releases_band_posted', $index([]));
        $this->assertSame('ix_releases_band_added', $index($added));
        $this->assertSame('ix_releases_band_cat_posted', $index(['categories' => [self::NDS]]));
        $this->assertSame('ix_releases_band_posted', $index(['search' => 'x', 'completion' => 95]));
        $this->assertSame('ix_releases_band_count', $count([]));
        $this->assertSame('ix_releases_band_cat_posted', $count(['categories' => [self::NDS], 'search' => 'x']));
        foreach ([['genres' => [self::ACTION]], ['genres' => [ConsoleReleaseFilters::GENRE_UNKNOWN]], ['decades' => [1990]], ['yearFrom' => 2024]] as $game) {
            $label = json_encode($game, JSON_THROW_ON_ERROR);
            $this->assertSame('ix_releases_consoleinfo_cat', $index($game), $label);
            $this->assertSame('ix_releases_consoleinfo_cat', $index(['categories' => [self::NDS], 'completion' => 95, 'search' => 'x', ...$added, ...$game]), $label);
            $this->assertSame('ix_releases_consoleinfo_cat', $count($game), $label);
            $this->assertSame('ix_releases_consoleinfo_cat', $count(['categories' => [self::NDS], 'completion' => 95, 'search' => 'x', ...$added, ...$game]), $label);
        }
    }

    public function test_the_name_search_matches_the_release_name_or_the_game_name_with_wildcards_literal(): void
    {
        $this->genre(self::ACTION, 'Action');
        $this->game(1, 'Blades of Varn', '2010-01-01', [self::ACTION]);
        $this->game(2, '100%_Real!Deal', '2012-01-01', [self::ACTION]);
        $this->game(3, '100 Real Deal', '2012-01-01', []);
        $this->console('xq7.scrambled', ['consoleinfo_id' => 1]);
        $this->console('Blades.Fan.Patch', ['display_name' => 'Blades Fan Patch']);
        $this->console('raw.one', ['display_name' => 'Shown Words', 'searchname' => 'hidden words']);
        $this->console('raw.two', ['consoleinfo_id' => 2]);
        $this->console('raw.three', ['consoleinfo_id' => 3, 'categories_id' => self::NDS]);
        $this->release('Blades.Of.Varn.Ebook', ['categories_id' => 7020]);

        $this->assertListed('/console?q=blades', ['Blades.Fan.Patch', 'xq7.scrambled']);
        $this->assertListed('/console?q=hidden', []);
        $this->assertListed('/console?q=shown', ['raw.one']);
        $this->assertListed('/console?q=%25_Real', ['raw.two']);
        $this->assertListed('/console?q=l!D', ['raw.two']);
        $this->assertListed('/console?q=100', ['raw.three', 'raw.two']);
        $this->assertListed('/console?q=100&category[]='.self::NDS, ['raw.three']);
        $this->assertListed('/console?q=100&genre[]='.self::ACTION, ['raw.two']);
        $this->assertListed('/console?q=blades&decade[]=2010', ['xq7.scrambled']);
        $this->page('/console?_fragment=list&q=100')->assertSee('Showing 1–2 of 2 releases');
        $this->page('/console?q=zzqqxx&category[]='.self::PS3)->assertSee('No releases match PS3 · release or game names containing “zzqqxx”.', false)
            ->assertDontSee('<table', false);
    }

    public function test_the_empty_result_names_every_set_filter_in_the_prototypes_order(): void
    {
        $this->page('/console')->assertOk()->assertSee('There are no console releases yet.')->assertDontSee('<table', false);
        $this->genre(self::ACTION, 'Action');
        $this->genre(self::FIGHTING, 'Fighting');
        $this->game(1, 'Brawler', '2010-01-01', [self::ACTION, self::FIGHTING]);
        $this->console('Brawler.NDS', ['consoleinfo_id' => 1, 'categories_id' => self::NDS, 'completion' => 90]);
        $this->console('Not.Looked.Up', ['categories_id' => self::PS3]);
        Cache::flush();

        $this->page('/console?category[]='.self::PS3.'&category[]='.self::NDS.'&genre[]='.self::FIGHTING.'&genre[]=unknown&genre[]='.self::ACTION.'&decade[]=1990&decade[]=2000&completion=100&q=%20Lost%20')
            ->assertSee('No releases match NDS or PS3 · Action or Fighting or Unknown · 2000s or 1990s · 100% complete · release or game names containing “Lost”.', false);
        $this->page('/console?category=exclude-other&genre[]='.self::ACTION.'&year_from=1998&year_to=2001')->assertSee('No releases match Action · 1998–2001.', false);
    }

    public function test_each_row_shows_its_cover_game_line_and_genres_or_the_no_game_tiles(): void
    {
        $this->genre(self::ACTION, 'Action');
        $this->genre(self::FOUR_X, ConsoleGenres::FOUR_X);
        $this->genre(self::FIGHTING, 'Fighting');
        $this->genre(self::UNKNOWN_GENRE, ConsoleGenres::UNKNOWN);
        $this->game(1, 'Covered Game', '1998-07-01', [self::FOUR_X, self::ACTION, self::FIGHTING], cover: 1);
        $this->game(2, 'Flagged No File', '2004-01-01', [self::ACTION], cover: 1);
        $this->game(3, 'No Cover Flag', null, [self::UNKNOWN_GENRE]);
        $this->game(4, 'No Genre Row', '2010-01-01', []);
        File::ensureDirectoryExists($this->covers.'/console');
        File::put($this->covers.'/console/1.jpg', 'jpg');
        File::put($this->covers.'/console/3.jpg', 'jpg');
        $this->console('Covered.PS3', ['consoleinfo_id' => 1, 'categories_id' => self::PS3, 'size' => 2.5 * 1073741824]);
        $this->console('Flagged.PS3', ['consoleinfo_id' => 2]);
        $this->console('Unflagged.PS3', ['consoleinfo_id' => 3]);
        $this->console('No.Genre.PS3', ['consoleinfo_id' => 4]);
        $this->console('No.Game.PS3');
        $this->console('Not.Found.PS3', ['consoleinfo_id' => -2]);

        $response = $this->page('/console')->assertOk()
            ->assertSee('<table class="tv-feed is-shelf"', false)
            ->assertSee('<colgroup><col class="tv-col-select"><col class="tv-col-art"><col><col class="tv-col-category"><col class="tv-col-genre"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>', false)
            ->assertSeeInOrder(['<th colspan="2">Release</th>', '<th class="tv-category">Category</th>', '<th class="tv-genre">Genre</th>', '<th class="tv-num">Size</th>', '<th class="tv-num">Posted</th>'], false);
        $this->assertSame(7, substr_count(strstr((string) $response->getContent(), '</thead>', true), '<th') - 1, 'seven headers over eight columns');

        $covered = $this->rowOf($response, 'Covered.PS3');
        $this->assertSame(8, substr_count($covered, '<td'));
        $details = e(route('details', md5('Covered.PS3')));
        $this->assertStringContainsString('<td class="tv-art">', $covered);
        $this->assertStringContainsString('<a href="'.$details.'" tabindex="-1" aria-hidden="true"><img src="'.url('/covers/console/1.jpg').'" alt="" loading="eager"></a>', $covered);
        $this->assertMatchesRegularExpression('/<a class="tv-release-name" href="'.preg_quote($details, '/').'" title="Covered.PS3" data-part="release name">Covered.PS3<\/a>\s*<span class="tv-game-line">Covered Game · 1998<\/span>/', $covered);
        $this->assertStringContainsString('<td class="tv-genre" title="'.e(ConsoleGenres::FOUR_X.', Action, Fighting').'"><span>'.e(ConsoleGenres::FOUR_X.', Action, Fighting').'</span></td>', $covered);
        $this->assertStringContainsString('<td class="tv-category" title="Console &gt; PS3">PS3</td>', $covered);
        $this->assertStringContainsString('<td class="tv-num tv-size">2.50 GB</td>', $covered);
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($covered));

        $noCover = '/<a class="tv-placeholder" href="[^"]+" tabindex="-1" aria-hidden="true">\s*<i class="fas fa-image" aria-hidden="true"><\/i>\s*<span class="tv-placeholder-label">No cover<\/span>/';
        $flagged = $this->rowOf($response, 'Flagged.PS3');
        $this->assertMatchesRegularExpression($noCover, $flagged, 'the cover file is missing');
        $this->assertStringContainsString('<span class="tv-game-line">Flagged No File · 2004</span>', $flagged);
        $unflagged = $this->rowOf($response, 'Unflagged.PS3');
        $this->assertMatchesRegularExpression($noCover, $unflagged, 'cover is 0 although a file exists');
        $this->assertStringContainsString('<span class="tv-game-line">No Cover Flag</span>', $unflagged, 'no year: the game alone');
        $this->assertStringContainsString('<td class="tv-genre" title="Unknown"><span>Unknown</span></td>', $unflagged);
        $this->assertStringContainsString('<td class="tv-genre"><span>—</span></td>', $this->rowOf($response, 'No.Genre.PS3'));
        foreach (['No.Game.PS3', 'Not.Found.PS3'] as $name) {
            $row = $this->rowOf($response, $name);
            $this->assertMatchesRegularExpression($noCover, $row, $name);
            $this->assertStringNotContainsString('tv-game-line', $row, $name);
            $this->assertStringContainsString('<td class="tv-genre"><span>—</span></td>', $row, $name);
        }
        // what the release details issue reads from a row: the file count, the category path, no picture
        [$withCount, $withoutCount] = app(ConsoleReleaseRows::class)->load([$this->console('Counted.PS3', ['totalpart' => 37, 'consoleinfo_id' => 1]), $this->console('Uncounted.PS3', ['totalpart' => 0])], false);
        $this->assertSame(['37', '—', 'Console > PS3', null, null, 1, null], [$withCount->filesShown(), $withoutCount->filesShown(), $withCount->categoryPath,
            $withCount->preview, $withCount->sample, $withCount->gameId, $withoutCount->gameId]);
        $table = strstr((string) $response->getContent(), '<tbody>');
        $this->assertStringNotContainsString('loading="lazy"', (string) $table);
        $this->assertStringNotContainsString('tv-show-line', (string) $table);
    }

    public function test_a_page_of_fifty_releases_with_games_runs_as_many_queries_as_a_page_of_one(): void
    {
        $this->genre(self::ACTION, 'Action');
        $this->genre(self::FIGHTING, 'Fighting');
        $this->game(1, 'Game 1', '2010-01-01', [self::ACTION, self::FIGHTING], cover: 1);
        $this->console('Release 1', ['consoleinfo_id' => 1]);
        $one = $this->queriesOf('/console');
        foreach (range(2, 60) as $index) {
            $this->game($index, 'Game '.$index, '2010-01-01', [$index % 2 === 0 ? self::ACTION : self::FIGHTING], cover: 1);
            $this->console('Release '.$index, ['consoleinfo_id' => $index]);
        }
        Cache::flush();
        $fifty = $this->queriesOf('/console');

        $this->assertSame(50, count($this->listedIds($this->page('/console'))));
        $this->assertSame($one, $fifty);
    }

    public function test_the_game_menus_category_and_sort_are_remembered_under_console_and_the_name_search_never_is(): void
    {
        $this->genre(self::ACTION, 'Action');
        $this->game(1, 'Game', '2010-01-01', [self::ACTION]);
        $this->console('Posted early added late', ['consoleinfo_id' => 1, 'postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']);
        $this->console('Posted late added early', ['consoleinfo_id' => 1, 'postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $user = $this->user = $this->browserUser();

        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'console', 'sort' => 'newest'])->assertOk();
        $this->assertSame('newest', User::query()->findOrFail($user->id)->releaseViewPreferences('console')['sort']);
        $this->page('/console', User::query()->findOrFail($user->id))->assertSeeInOrder(['Posted early added late', 'Posted late added early'])
            ->assertSee('<th class="tv-num">Added</th>', false);
        $this->postJson('/profile/update-view', ['root' => 'console', 'sort' => 'grabs'])->assertUnprocessable();

        $this->page('/console?_fragment=list&category[]='.self::PS3.'&genre[]='.self::ACTION.'&decade[]=2010&q=Posted', User::query()->findOrFail($user->id))->assertOk();
        $this->assertSame(['category' => [self::PS3], 'genre' => [self::ACTION], 'decade' => [2010]], $this->remembered($user, 'console'));
        $this->page('/console', User::query()->findOrFail($user->id))->assertRedirect(route('console.releases', ['category' => [self::PS3], 'genre' => [self::ACTION], 'decade' => [2010]]));
        $this->page('/console?q=late', User::query()->findOrFail($user->id))
            ->assertRedirect(route('console.releases', ['category' => [self::PS3], 'genre' => [self::ACTION], 'decade' => [2010], 'q' => 'late']));
        $this->page('/console?_fragment=list&year_from=2009&year_to=2011', User::query()->findOrFail($user->id))->assertOk();
        $this->assertSame(['year_from' => 2009, 'year_to' => 2011], $this->remembered($user, 'console'));
        $this->page('/console?clear=1', User::query()->findOrFail($user->id))->assertRedirect(route('console.releases'));
        $this->assertSame([], $this->remembered($user, 'console') ?? []);
    }

    public function test_the_list_needs_the_console_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view console');
        $this->page('/console', $user)->assertForbidden()->assertSee('Console is hidden in your account preferences.');
    }

    public function test_the_capitalised_console_address_is_gone_and_the_list_still_answers(): void
    {
        $this->page('/Console')->assertNotFound();
        $this->assertSame('console.releases', app('router')->getRoutes()->match(request()->create('/console'))->getName());
    }

    public function test_the_header_sends_console_to_the_new_list_and_marks_it_current(): void
    {
        $this->console('A game release');
        $response = $this->page('/console')->assertOk()
            ->assertSee('href="'.route('console.releases').'" class="public-menu-root">All Console</a>', false)
            ->assertDontSee('href="'.url('/browse/console').'"', false);
        foreach ([self::NDS, self::PSP, self::PS3, self::PS4, self::CONSOLE_OTHER] as $category) {
            $response->assertSee('href="'.e(route('console.releases', ['category' => [$category]])).'"', false);
        }
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-console"\s+aria-current="true"/', (string) $response->getContent());
        $this->assertSame(1, substr_count((string) $response->getContent(), 'aria-current="true"'));
    }

    /**
     * A catalogue of 300 console releases over eight games, release with no game in both forms,
     * three categories and three completions; dates collide so the id breaks ties.
     *
     * @return array{array<int, array{name: string, category: int, completion: int, posted: string, added: string, game: ?int}>, array<int, array{title: string, genres: list<int>, year: ?int}>}
     */
    private function catalogue(): array
    {
        $this->genre(self::ACTION, 'Action');
        $this->genre(self::FIGHTING, 'Fighting');
        $this->genre(self::RPG, 'Role-playing (RPG)');
        $this->genre(self::UNKNOWN_GENRE, ConsoleGenres::UNKNOWN);
        $this->genre(self::FOUR_X, ConsoleGenres::FOUR_X);
        $games = [
            1 => ['title' => 'Blades of Varn', 'genres' => [self::ACTION], 'year' => 1998],
            2 => ['title' => 'Duel Kings', 'genres' => [self::ACTION, self::FIGHTING], 'year' => 2004],
            3 => ['title' => 'Iron Fist Arena', 'genres' => [self::FIGHTING], 'year' => 2015],
            4 => ['title' => 'Elder Quest', 'genres' => [self::RPG], 'year' => 1987],
            5 => ['title' => 'Mystery Box', 'genres' => [self::UNKNOWN_GENRE], 'year' => 2010],
            6 => ['title' => 'Nameless', 'genres' => [], 'year' => 2001],
            7 => ['title' => 'Timeless Blades', 'genres' => [self::ACTION], 'year' => null],
            8 => ['title' => 'Star Empire', 'genres' => [self::FOUR_X, self::FIGHTING], 'year' => 2024],
        ];
        foreach ($games as $id => $game) {
            $this->game($id, $game['title'], $game['year'] === null ? null : $game['year'].'-06-15 00:00:00', $game['genres']);
        }
        $releases = [];
        $links = [null, -2, 1, 2, 3, 4, 5, 6, 7, 8, null, -2];
        foreach (range(1, 300) as $index) {
            $release = [
                'name' => 'Console Release '.$index.($index % 17 === 0 ? ' Blades' : ''),
                'category' => [self::PS3, self::NDS, self::CONSOLE_OTHER][$index % 3],
                'completion' => [100, 96, 80, 99][$index % 4],
                'posted' => Carbon::parse('2026-01-01')->addHours(intdiv($index, 2))->toDateTimeString(),
                'added' => Carbon::parse('2026-03-01')->subHours(intdiv($index, 3))->toDateTimeString(),
                'game' => $links[$index % 12],
            ];
            $id = $this->console($release['name'], ['categories_id' => $release['category'], 'completion' => $release['completion'], 'postdate' => $release['posted'],
                'adddate' => $release['added'], 'consoleinfo_id' => $release['game']]);
            $releases[$id] = $release;
        }

        return [$releases, $games];
    }

    /**
     * The ids the filters keep, in the sort, read straight from the catalogue.
     *
     * @param  array<int, array{name: string, category: int, completion: int, posted: string, added: string, game: ?int}>  $releases
     * @param  array<int, array{title: string, genres: list<int>, year: ?int}>  $games
     * @return list<int>
     */
    private function expected(array $releases, array $games, ConsoleReleaseFilters $filters): array
    {
        $kept = array_filter($releases, static function (array $release) use ($games, $filters): bool {
            $game = $release['game'] !== null && $release['game'] > 0 ? $games[$release['game']] : null;
            if (($filters->categories !== [] && ! in_array($release['category'], $filters->categories, true))
                || ($filters->completion !== null && $release['completion'] < $filters->completion)
                || ($filters->search !== '' && stripos($release['name'], $filters->search) === false && ($game === null || stripos($game['title'], $filters->search) === false))) {
                return false;
            }
            if ($filters->genres !== []) {
                $unknownGame = $game !== null && ($game['genres'] === [] || $game['genres'][0] === self::UNKNOWN_GENRE);
                $matches = $game === null ? $filters->genreUnknown()
                    : array_intersect($game['genres'], $filters->genreIds()) !== [] || ($filters->genreUnknown() && $unknownGame);
                if (! $matches) {
                    return false;
                }
            }
            if ($filters->anyYear()) {
                $year = $game['year'] ?? null;
                if ($year === null) {
                    return false;
                }
                $bounds = $filters->yearBounds();

                return $bounds !== null ? $year >= $bounds[0] && $year <= $bounds[1]
                    : array_filter($filters->decades, static fn (int $decade): bool => $year >= $decade && $year < $decade + 10) !== [];
            }

            return true;
        });
        $date = $filters->sortsByAdded() ? 'added' : 'posted';
        $ids = array_keys($kept);
        usort($ids, static fn (int $a, int $b): int => $filters->ascending() ? [$kept[$a][$date], $a] <=> [$kept[$b][$date], $b] : [$kept[$b][$date], $b] <=> [$kept[$a][$date], $a]);

        return $ids;
    }

    /** The queries of the second open of a page, its menus and counts already cached. */
    private function queriesOf(string $uri): int
    {
        $this->page($uri)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function remembered(?User $user, string $root): mixed
    {
        return User::query()->findOrFail($user?->id)->releaseViewPreferences($root)['filters'] ?? null;
    }

    /** @param list<string> $names */
    private function assertListed(string $uri, array $names, string $message = ''): void
    {
        $this->assertSame($names, $this->listedNames($this->page($uri)->assertOk()), $uri.' '.$message);
    }

    /** @return list<string> the listed releases' names, A to Z */
    private function listedNames(TestResponse $response): array
    {
        return DB::table('releases')->whereIn('id', $this->listedIds($response))->orderBy('name')->pluck('name')->all();
    }

    /** @return list<int> */
    private function listedIds(TestResponse $response): array
    {
        preg_match_all('/data-select value="([0-9a-f]{32})"/', (string) $response->getContent(), $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    private function rowOf(TestResponse $response, string $name): string
    {
        foreach (explode('<tr data-release-row', (string) $response->getContent()) as $row) {
            if (str_contains($row, 'title="'.e($name).'"')) {
                return strstr($row, '</tr>', true) ?: $row;
            }
        }
        $this->fail('No row for '.$name);
    }

    /** @return list<string> */
    private function actions(string $row): array
    {
        preg_match_all('/class="tv-action(?: tv-action-download download-nzb)?" (?:data-copy-nzb|data-cart|data-watch-picker|title="Download)/', $row, $matches);

        return array_map(static fn (string $match): string => match (true) {
            str_contains($match, 'Download') => 'download', str_contains($match, 'copy') => 'copy',
            str_contains($match, 'cart') => 'cart', default => 'watch',
        }, $matches[0]);
    }

    /** A filter cell's text as check.mjs reads it (textContent): "Genre: 2 chosen". */
    private function cellText(TestResponse $response, string $name): string
    {
        $this->assertMatchesRegularExpression('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent());
        preg_match('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent(), $match);

        return html_entity_decode(trim((string) preg_replace('/\s+/', ' ', strip_tags($match[1]))), ENT_QUOTES);
    }

    private function genre(int $id, string $title, int $type = 1000): void
    {
        DB::table('genres')->insert(['id' => $id, 'title' => $title, 'type' => $type, 'disabled' => 0]);
    }

    /**
     * A game with its genre rows in order and `genres_id` the first, as ConsoleGenres::replace() keeps them.
     *
     * @param  list<int>  $genres
     */
    private function game(int $id, string $title, ?string $released, array $genres, int $cover = 0): void
    {
        DB::table('consoleinfo')->insert(['id' => $id, 'title' => $title, 'releasedate' => $released, 'cover' => $cover, 'genres_id' => $genres[0] ?? null]);
        foreach ($genres as $position => $genre) {
            DB::table('console_genres')->insert(['consoleinfo_id' => $id, 'genres_id' => $genre, 'position' => $position]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function console(string $name, array $attributes = []): int
    {
        return $this->release($name, ['categories_id' => self::PS3, 'consoleinfo_id' => null, 'passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0,
            ...$attributes]);
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }
}
