<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Release;
use App\Models\User;
use App\Services\MetadataProcessing\ConsoleGenres;
use App\Services\Releases\ReleaseSearchService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsNoRetiredAddress;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The Console release page of a release with a game, GET /details/{guid}
 * (docs/proposals/books-console-pc-redesign/SPEC.md 5B; DATA-CONTRACT.md 4.3 and 6; the game-page
 * checks of prototype/check.mjs): the release name as the heading, the game under it, the film
 * page's body, the release's tabs, every release of the game and Similar releases.
 */
final class ConsoleGameReleasePageTest extends TestCase
{
    use AssertsNoRetiredAddress;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const NDS = 1010;

    private const PS3 = 1080;

    private const PS4 = 1180;

    private const OTHER = 1999;

    /** A sub-category an admin added under Console, which the Console list's Category order does not list. */
    private const CUSTOM = 1190;

    private const GAME = 5;

    private const GB = 1073741824;

    private ?User $user = null;

    private int $nextRelease = 0;

    private string $covers = '';

    /** @var list<int> the ids of the rows searchSimilar answers with */
    private array $similarIds = [];

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
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id',
            'videos_id', 'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'predb_id', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages', 'releases_groups', 'release_regexes', 'release_comments', 'release_nfos', 'video_data', 'audio_data',
            'release_subtitles', 'media_infos', 'media_info_probes', 'media_info_tracks', 'predb', 'release_tv_episodes', 'tv_episodes', 'tv_info', 'networks',
            'video_genres', 'video_people', 'genres', 'consoleinfo', 'console_genres', 'companies', 'console_companies', 'game_modes', 'console_game_modes',
            'player_perspectives', 'console_player_perspectives'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert(['id' => 1000, 'title' => 'Console', 'status' => 1]);
        DB::table('categories')->insert([
            ['id' => self::NDS, 'title' => 'NDS', 'root_categories_id' => 1000, 'status' => 1],
            ['id' => self::PS3, 'title' => 'PS3', 'root_categories_id' => 1000, 'status' => 1],
            ['id' => self::PS4, 'title' => 'PS4', 'root_categories_id' => 1000, 'status' => 1],
            ['id' => self::CUSTOM, 'title' => 'Handhelds', 'root_categories_id' => 1000, 'status' => 1],
            ['id' => self::OTHER, 'title' => 'Other', 'root_categories_id' => 1000, 'status' => 1],
        ]);
        DB::table('usenet_groups')->insert(['id' => 99, 'name' => 'alt.binaries.console.ps3']);
        $this->covers = $this->makeTempDirectory('console-game-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
        $search = Mockery::mock(ReleaseSearchService::class)->makePartial();
        $search->shouldReceive('searchSimilar')->andReturnUsing(fn (): array => Release::query()->whereIn('id', $this->similarIds)->get()->all());
        $this->app->instance(ReleaseSearchService::class, $search);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_console_release_with_a_stored_game_gets_the_game_page_with_the_release_name_on_top_and_the_game_under_it(): void
    {
        $this->game();
        mkdir($this->covers.'/console', 0777, true);
        file_put_contents($this->covers.'/console/'.self::GAME.'.jpg', 'cover');
        $id = $this->console('Halo.Reach.PS3-GRP');

        $response = $this->details($id)->assertOk()->assertViewIs('details.console.index');

        $crumbs = $this->between($response, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>');
        $this->assertSame('<a href="'.route('console.releases').'">Console releases</a><span aria-hidden="true">›</span><span>PS3</span>', (string) preg_replace('/>\s+</', '><', $crumbs));
        $head = $this->between($response, '<div class="tv-show-head">', '<div class="tv-details-columns is-release-only">');
        $this->assertStringContainsString('<img src="'.url('/covers/console/'.self::GAME.'.jpg').'" alt="Halo: Reach cover">', $head);
        $this->assertStringNotContainsString('<a ', $this->between($response, '<div class="tv-show-art" data-part="game cover">', '</div>'));
        $this->assertStringContainsString('<h1 class="is-release-name" data-part="details heading">Halo.Reach.PS3-GRP</h1>', $head);
        $line = $this->between($response, '<div class="tv-show-meta tv-film-meta tv-game-line" data-part="game line">', '</div>');
        $this->assertSame('<b>Halo: Reach</b><span aria-hidden="true">·</span><span>2010</span><span aria-hidden="true">·</span><span>PS3</span>', $line);
        $this->assertSame(1, substr_count((string) $response->getContent(), '<h1'));
        $response->assertSee('x-data="movieReleaseDetails"', false)->assertSee('data-nzb-link-base="'.url('/api/v1/api').'"', false)
            ->assertSee('<div class="tv-chips tv-details-chips">', false)->assertSee('<div class="tv-chips tv-details-origin">', false)
            ->assertDontSee('Console Game Information');
    }

    public function test_the_game_release_page_renders_no_retired_address(): void
    {
        $this->game();
        $id = $this->console('Halo.Reach.PS3-GRP');

        $response = $this->details($id)->assertOk()->assertViewIs('details.console.index');

        $this->assertNoRetiredAddress((string) $response->getContent(), 'Console game release page');
    }

    public function test_a_game_with_no_cover_shows_the_film_page_s_title_card_never_no_cover(): void
    {
        $this->game();
        $withoutFile = $this->console('Halo.Reach.PS3-GRP');
        $this->assertTitleCard($this->details($withoutFile), '<span class="tv-tile-card-title">Halo: Reach</span><small>2010</small>');

        DB::table('consoleinfo')->where('id', self::GAME)->update(['cover' => 0]);
        mkdir($this->covers.'/console', 0777, true);
        file_put_contents($this->covers.'/console/'.self::GAME.'.jpg', 'cover');
        $this->assertTitleCard($this->details($withoutFile), '<span class="tv-tile-card-title">Halo: Reach</span><small>2010</small>');

        $this->game(6, ['title' => 'Undated Game', 'asin' => '99', 'releasedate' => null, 'cover' => 0]);
        $undated = $this->console('Undated.Game.PS3-GRP', ['consoleinfo_id' => 6]);
        $this->assertTitleCard($this->details($undated), '<span class="tv-tile-card-title">Undated Game</span></div>');
        $line = $this->between($this->details($undated), 'data-part="game line">', '</div>');
        $this->assertSame('<b>Undated Game</b><span aria-hidden="true">·</span><span>PS3</span>', $line);
    }

    public function test_nothing_announces_the_tabs_and_the_overview_has_no_genre_fact(): void
    {
        $this->game();
        $id = $this->console('Halo.Reach.PS3-GRP');

        $response = $this->details($id)->assertOk();
        $html = (string) $response->getContent();
        $between = $this->between($response, '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-tabs"');
        $this->assertDoesNotMatchRegularExpression('/<h[1-6]/', $between);
        foreach (['This release', 'About the game', 'tv-about', 'tv-details-art', 'tv-details-aside', 'tv-details-summary'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertSame(['Category', 'Size', 'Files', 'Completion', 'Posted', 'Added', 'Grabs', 'Group', 'Poster', 'Password status'], array_keys($this->facts($html)));
        $this->assertSame('Console &gt; PS3', $this->facts($html)['Category']);
    }

    public function test_the_summary_and_storyline_paragraphs_show_from_the_stored_game(): void
    {
        $this->game();
        $id = $this->console('Halo.Reach.PS3-GRP');
        $head = $this->between($this->details($id), '<div class="tv-show-head">', '<div class="tv-details-actions tv-show-actions">');
        $this->assertStringContainsString('<p>The prequel to Halo.</p>', $head);
        $this->assertStringContainsString('<p class="tv-storyline"><b>Storyline</b> Noble Team defends Reach.</p>', $head);
        $this->assertLessThan(strpos($head, '<p class="tv-storyline">'), strpos($head, '<p>The prequel'));

        DB::table('consoleinfo')->where('id', self::GAME)->update(['storyline' => null, 'review' => '']);
        $head = $this->between($this->details($id), '<div class="tv-show-head">', '<div class="tv-details-actions tv-show-actions">');
        $this->assertStringNotContainsString('tv-storyline', $head);
        $this->assertStringNotContainsString('<p', $head);
    }

    public function test_genre_tags_link_to_the_console_list_with_that_genre_alone_then_the_outlined_tags(): void
    {
        $this->game();
        $id = $this->console('Halo.Reach.PS3-GRP');

        $tags = $this->between($this->details($id), '<div class="tv-show-tags">', '</div>');
        preg_match_all('/<a class="tv-tag" href="([^"]+)">([^<]+)<\/a>/', $tags, $genres);
        $this->assertSame(['Shooter', 'Adventure'], $genres[2]);
        $this->assertSame([e(route('console.releases', ['genre' => [12]])), e(route('console.releases', ['genre' => [11]]))], $genres[1]);
        $this->assertStringNotContainsString('Unknown', $tags);
        $this->assertSame(['Critic score 91', 'User score 84', 'ESRB M'], $this->plainTags($tags));

        DB::table('consoleinfo')->where('id', self::GAME)->update(['esrb' => 'PEGI 12', 'critic_score' => null]);
        $this->assertSame(['User score 84', 'PEGI 12'], $this->plainTags($this->between($this->details($id), '<div class="tv-show-tags">', '</div>')));

        DB::table('consoleinfo')->where('id', self::GAME)->update(['esrb' => null, 'user_score' => null]);
        $tags = $this->between($this->details($id), '<div class="tv-show-tags">', '</div>');
        $this->assertSame([], $this->plainTags($tags));
        $this->assertStringNotContainsString('tv-tag-plain', $tags);

        DB::table('console_genres')->delete();
        $this->details($id)->assertOk()->assertDontSee('tv-show-tags', false);
    }

    public function test_the_info_lines_are_plain_text_in_order_each_left_out_when_missing(): void
    {
        $this->game();
        $this->user()->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();
        $id = $this->console('Halo.Reach.PS3-GRP');

        $html = (string) $this->details($id)->getContent();
        $this->assertSame([
            'Developed by' => 'Bungie, Saber Interactive',
            'Published by' => 'Microsoft',
            'Released' => 'Sep 14, 2010',
            'Game modes' => 'Single player, Multiplayer',
            'Perspective' => 'First person',
        ], $this->infoLines($html));
        preg_match_all('/<div class="tv-starring">(.*?)<\/div>/s', $html, $lines);
        foreach ($lines[1] as $line) {
            $this->assertStringNotContainsString('<a', $line);
        }

        DB::table('console_companies')->where('role', 1)->delete();
        DB::table('console_player_perspectives')->delete();
        DB::table('consoleinfo')->where('id', self::GAME)->update(['releasedate' => null]);
        $this->assertSame(['Developed by', 'Game modes'], array_keys($this->infoLines((string) $this->details($id)->getContent())));
    }

    public function test_the_button_row_ends_with_igdb_and_website_opening_a_new_tab(): void
    {
        $this->game();
        $id = $this->console('Halo.Reach.PS3-GRP');

        $actions = $this->between($this->details($id), '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-columns');
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart', 'IGDB', 'Website'], $this->buttons($actions));
        foreach (['https://www.igdb.com/games/halo-reach', 'https://www.halowaypoint.com'] as $url) {
            $this->assertMatchesRegularExpression('/<a class="tv-details-button is-secondary" href="'.preg_quote($url, '/').'" target="_blank" rel="noopener noreferrer">[A-Za-z]+<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"><\/i><span class="sr-only"> \(opens in a new tab\)<\/span><\/a>/', $actions);
        }
        $this->assertStringNotContainsString('data-watch', $actions);

        DB::table('consoleinfo')->where('id', self::GAME)->update(['website' => null]);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart', 'IGDB'], $this->buttons($this->between($this->details($id), '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-columns')));

        DB::table('consoleinfo')->where('id', self::GAME)->update(['url' => '']);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($this->between($this->details($id), '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-columns')));

        // Only a web address becomes a button.
        DB::table('consoleinfo')->where('id', self::GAME)->update(['url' => 'https://www.igdb.com/games/halo-reach', 'website' => 'javascript:alert(1)']);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart', 'IGDB'], $this->buttons($this->between($this->details($id), '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-columns')));
        DB::table('consoleinfo')->where('id', self::GAME)->update(['url' => 'javascript:alert(1)', 'website' => null]);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($this->between($this->details($id), '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-columns')));

        // A row left by the old Amazon lookup keeps an Amazon ASIN and URL: no IGDB button.
        DB::table('consoleinfo')->where('id', self::GAME)->update(['asin' => 'B000TEST01', 'url' => 'https://www.amazon.com/dp/B000TEST01']);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($this->between($this->details($id), '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-columns')));
    }

    public function test_the_game_s_genres_companies_modes_and_perspectives_are_read_in_one_query(): void
    {
        $this->game();
        $id = $this->console('Halo.Reach.PS3-GRP');
        $queries = [];
        // The release rows read each game's genre titles in their own subquery (ConsoleReleaseRows); every other read of the four tables counts.
        $rowGenres = ConsoleGenres::titlesSql('c.id', ', ');
        DB::listen(static function (QueryExecuted $query) use (&$queries, $rowGenres): void {
            if (preg_match('/console_genres|console_companies|console_game_modes|console_player_perspectives/', str_replace($rowGenres, '', $query->sql)) === 1) {
                $queries[] = $query->sql;
            }
        });

        $this->details($id)->assertOk();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('console_genres', $queries[0]);
        $this->assertStringContainsString('console_game_modes', $queries[0]);
        $this->assertStringContainsString('console_player_perspectives', $queries[0]);
        $this->assertStringContainsString('union all', strtolower($queries[0]));
    }

    public function test_all_releases_of_the_game_list_the_visible_ones_with_this_release_marked_and_each_row_s_game_line(): void
    {
        $this->game();
        $current = $this->console('Halo.Reach.PS3-GRP', ['postdate' => '2026-09-20 10:00:00']);
        $other = $this->console('Halo.Reach.PS4-GRP', ['categories_id' => self::PS4, 'postdate' => '2026-09-22 10:00:00']);
        $this->console('Halo.Reach.Hidden.NDS-GRP', ['categories_id' => self::NDS, 'postdate' => '2026-09-23 10:00:00']);
        $this->console('Halo.Reach.Passworded.PS3-GRP', ['passwordstatus' => 1, 'postdate' => '2026-09-24 10:00:00']);
        $this->console('Other.Game.PS3-GRP', ['consoleinfo_id' => null, 'postdate' => '2026-09-24 10:00:00']);
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user()->id, 'categories_id' => self::NDS]);

        $response = $this->details($current)->assertOk();
        $section = $this->between($response, '<section class="tv-siblings tv-list-end" id="releases" aria-labelledby="game-releases-heading" x-ref="releases" data-film-releases>', '</section>');
        $this->assertStringContainsString('<h2 id="game-releases-heading">All 2 releases of this game</h2>', $section);
        $this->assertSame([$other, $current], $this->rowIds($section));
        $this->assertMatchesRegularExpression('/<tr class="is-current"\s+aria-current="true"\s+data-release-row[^>]*>\s*<td class="tv-what">\s*<span class="tv-release-name" title="Halo.Reach.PS3-GRP">Halo.Reach.PS3-GRP<\/span>\s*<div class="tv-this-release">The release on this page<\/div>/', $section);
        $this->assertStringNotContainsString('href="'.route('details', $this->guid($current)).'"', $section);
        $this->assertSame(2, substr_count($section, '<span class="tv-game-line">Halo: Reach · 2010</span>'));
        $this->assertSame(['Release', 'Category', 'Size', 'Files', 'Posted', 'Actions'], $this->headings($section));
        preg_match_all('/<button type="button" data-sort="([a-z]+)"/', $section, $sortable);
        $this->assertSame(['category', 'size', 'posted'], $sortable[1]);
        $this->assertStringNotContainsString('data-similar-sort', $section);
        $this->assertStringNotContainsString('pager-line', $section);

        DB::table('releases')->where('id', $other)->delete();
        $this->assertStringContainsString('<h2 id="game-releases-heading">The only release of this game</h2>', (string) $this->details($current)->getContent());
    }

    public function test_the_table_pages_at_50_opens_on_the_page_holding_this_release_and_sorts_on_the_server(): void
    {
        $this->game();
        $ids = [];
        foreach (range(1, 51) as $day) {
            // posted newest first puts day 1 last (page 2); size ascends with the day
            $ids[$day] = $this->console('Halo.Reach.Day'.$day.'.PS3-GRP', ['postdate' => Carbon::parse('2026-07-01 10:00:00')->addDays($day)->toDateTimeString(), 'size' => $day * self::GB]);
        }
        $current = $ids[1];

        $opening = $this->between($this->details($current)->assertOk(), 'data-film-releases>', '</section>');
        $this->assertStringContainsString('All 51 releases of this game', $opening);
        $this->assertStringContainsString('Page 2 of 2', $opening);
        $this->assertSame([$current], $this->rowIds($opening));

        $first = $this->between($this->page($current, '?page=1'), 'data-film-releases>', '</section>');
        $this->assertCount(50, $this->rowIds($first));
        $this->assertStringNotContainsString('aria-current="true"', $first);
        $second = $this->between($this->page($current, '?page=2'), 'data-film-releases>', '</section>');
        $this->assertSame([$current], $this->rowIds($second));

        $smallest = $this->page($current, '?sort=size_asc&_fragment=releases')->assertOk()->assertViewIs('details.console.releases');
        $fragment = (string) $smallest->getContent();
        $this->assertStringStartsWith('<h2 id="game-releases-heading">', trim($fragment));
        $this->assertStringNotContainsString('tv-show-head', $fragment);
        $this->assertSame(array_slice(array_values($ids), 0, 50), $this->rowIds($fragment));
        $this->assertSame(['size' => 'ascending'], $this->sortedHeadings($fragment));
        $this->assertStringContainsString('href="'.e(route('details', ['guid' => $this->guid($current), 'sort' => 'size_asc', 'page' => 2]).'#releases').'"', $fragment);
        $this->assertStringContainsString('href="'.e(route('details', ['guid' => $this->guid($current), 'page' => 1]).'#releases').'"', $opening);
        $this->assertStringContainsString('aria-current="true"', $fragment);
    }

    public function test_category_sorts_by_the_console_list_s_category_order_with_an_admin_s_own_sub_category_last(): void
    {
        $this->game();
        $current = $this->console('Halo.Reach.PS3-GRP', ['postdate' => '2026-09-20 10:00:00']);
        $nds = $this->console('Halo.Reach.NDS-GRP', ['categories_id' => self::NDS, 'postdate' => '2026-09-19 10:00:00']);
        $other = $this->console('Halo.Reach.Other-GRP', ['categories_id' => self::OTHER, 'postdate' => '2026-09-21 10:00:00']);
        $custom = $this->console('Halo.Reach.Handheld-GRP', ['categories_id' => self::CUSTOM, 'postdate' => '2026-09-22 10:00:00']);
        $ps4 = $this->console('Halo.Reach.PS4-GRP', ['categories_id' => self::PS4, 'postdate' => '2026-09-18 10:00:00']);

        $descending = $this->between($this->page($current, '?sort=category'), 'data-film-releases>', '</section>');
        $this->assertSame([$custom, $other, $ps4, $current, $nds], $this->rowIds($descending));
        $this->assertSame(['category' => 'descending'], $this->sortedHeadings($descending));
        $ascending = $this->between($this->page($current, '?sort=category_asc'), 'data-film-releases>', '</section>');
        $this->assertSame([$nds, $current, $ps4, $other, $custom], $this->rowIds($ascending));
        $this->assertSame(['category' => 'ascending'], $this->sortedHeadings($ascending));
    }

    public function test_similar_releases_leave_out_the_releases_of_the_same_game_and_this_release(): void
    {
        $this->game();
        $this->game(6, ['title' => 'Halo 3', 'asin' => '7', 'releasedate' => '2007-09-25']);
        $current = $this->console('Halo.Reach.PS3-GRP');
        $sameGame = $this->console('Halo.Reach.PS4-GRP', ['categories_id' => self::PS4, 'postdate' => '2026-09-22 10:00:00']);
        $otherGame = $this->console('Halo.3.PS3-GRP', ['consoleinfo_id' => 6, 'postdate' => '2026-09-21 10:00:00']);
        $noGame = $this->console('Halo.Something.PS3-GRP', ['consoleinfo_id' => null, 'postdate' => '2026-09-23 10:00:00']);
        $this->similarIds = [$otherGame, $current, $sameGame, $noGame];

        $response = $this->details($current)->assertOk();
        $similar = $this->between($response, '<section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>', '</section>');
        $this->assertSame([$noGame, $otherGame], $this->rowIds($similar));
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($similar, 'data-similar-sort'));
        preg_match_all('/<button type="button" data-similar-sort="([a-z]+)"/', $similar, $sortable);
        $this->assertSame(['category', 'size', 'posted'], $sortable[1]);
        $response->assertSee('<section class="tv-siblings" id="releases"', false);

        $this->similarIds = [$sameGame];
        $this->details($current)->assertOk()->assertDontSee('data-similar-releases', false)->assertSee('<section class="tv-siblings tv-list-end" id="releases"', false);
    }

    public function test_a_console_release_with_no_game_keeps_the_release_only_page(): void
    {
        $id = $this->console('No.Game.PS3-GRP', ['consoleinfo_id' => -2]);

        $this->details($id)->assertOk()->assertViewIs('details.shelf.index');
    }

    public function test_a_hidden_category_is_refused_and_a_comment_still_posts_to_the_details_url(): void
    {
        $this->game();
        $hidden = $this->console('Halo.Reach.NDS-GRP', ['categories_id' => self::NDS]);
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user()->id, 'categories_id' => self::NDS]);
        $this->details($hidden)->assertForbidden()->assertViewIs('errors.category-disabled')->assertDontSee('Halo.Reach.NDS');

        $id = $this->console('Halo.Reach.PS3-GRP');
        $url = '/details/'.$this->guid($id);
        $this->actingAs($this->user())->post($url, ['txtAddComment' => 'Works well.'])->assertRedirect($url.'#comments');
        $response = $this->details($id)->assertOk()->assertSee('Works well.');
        preg_match_all('/<button type="button" role="tab"[^>]*>([^<]+)<\/button>/', (string) $response->getContent(), $tabs);
        $this->assertContains('Comments (1)', array_map('trim', $tabs[1]));
    }

    /**
     * A stored game; the default one has every value, two genres (and the Unknown genre, never a
     * tag), two developers, a publisher, two game modes and a perspective.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function game(int $id = self::GAME, array $attributes = []): void
    {
        DB::table('consoleinfo')->insert([
            'id' => $id, 'title' => 'Halo: Reach', 'asin' => '1234', 'url' => 'https://www.igdb.com/games/halo-reach', 'publisher' => 'Microsoft',
            'esrb' => 'M', 'releasedate' => '2010-09-14 00:00:00', 'review' => 'The prequel to Halo.', 'cover' => 1,
            'storyline' => 'Noble Team defends Reach.', 'critic_score' => 91, 'user_score' => 84, 'website' => 'https://www.halowaypoint.com', ...$attributes,
        ]);
        if ($id !== self::GAME) {
            return;
        }
        DB::table('genres')->insert([['id' => 10, 'title' => 'Unknown', 'type' => 1000], ['id' => 11, 'title' => 'Adventure', 'type' => 1000], ['id' => 12, 'title' => 'Shooter', 'type' => 1000]]);
        DB::table('console_genres')->insert([['consoleinfo_id' => $id, 'genres_id' => 11, 'position' => 1], ['consoleinfo_id' => $id, 'genres_id' => 12, 'position' => 0],
            ['consoleinfo_id' => $id, 'genres_id' => 10, 'position' => 2]]);
        DB::table('companies')->insert([['id' => 1, 'name' => 'Saber Interactive'], ['id' => 2, 'name' => 'Bungie'], ['id' => 3, 'name' => 'Microsoft']]);
        DB::table('console_companies')->insert([['consoleinfo_id' => $id, 'companies_id' => 1, 'role' => 0, 'position' => 1], ['consoleinfo_id' => $id, 'companies_id' => 2, 'role' => 0, 'position' => 0],
            ['consoleinfo_id' => $id, 'companies_id' => 3, 'role' => 1, 'position' => 0]]);
        DB::table('game_modes')->insert([['id' => 1, 'name' => 'Multiplayer'], ['id' => 2, 'name' => 'Single player']]);
        DB::table('console_game_modes')->insert([['consoleinfo_id' => $id, 'game_modes_id' => 1, 'position' => 1], ['consoleinfo_id' => $id, 'game_modes_id' => 2, 'position' => 0]]);
        DB::table('player_perspectives')->insert(['id' => 1, 'name' => 'First person']);
        DB::table('console_player_perspectives')->insert(['consoleinfo_id' => $id, 'player_perspectives_id' => 1, 'position' => 0]);
    }

    /** @param array<string, mixed> $attributes */
    private function console(string $name, array $attributes = []): int
    {
        $number = ++$this->nextRelease;
        $posted = (string) ($attributes['postdate'] ?? '2026-09-20 10:00:00');

        return $this->release($name, ['categories_id' => self::PS3, 'passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'consoleinfo_id' => self::GAME, 'gamesinfo_id' => null, 'bookinfo_id' => null,
            'musicinfo_id' => null, 'anidbid' => null, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0,
            'size' => self::GB, 'totalpart' => 1, 'groups_id' => 99, 'guid' => md5('console game release '.$number),
            'adddate' => Carbon::parse($posted)->addHour()->toDateTimeString(), ...$attributes, 'postdate' => $posted]);
    }

    private function assertTitleCard(TestResponse $response, string $card): void
    {
        $response->assertOk()->assertDontSee('No cover');
        $art = $this->between($response, '<div class="tv-show-art" data-part="game cover">', '<h1');
        $this->assertStringContainsString('<div class="tv-show-card is-film">'.$card, $art);
        $this->assertStringNotContainsString('<img', $art);
    }

    private function user(): User
    {
        return $this->user ??= $this->browserUser();
    }

    private function guid(int $id): string
    {
        return (string) DB::table('releases')->where('id', $id)->value('guid');
    }

    private function details(int $id): TestResponse
    {
        return $this->page($id, '');
    }

    private function page(int $id, string $query): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($this->user())->get('/details/'.$this->guid($id).$query);
    }

    /** @return list<string> */
    private function plainTags(string $tags): array
    {
        preg_match_all('/<span class="tv-tag tv-tag-plain">([^<]+)<\/span>/', $tags, $plain);

        return $plain[1];
    }

    /** @return array<string, string> label => value */
    private function infoLines(string $html): array
    {
        preg_match_all('/<div class="tv-starring">([^<]+) <span class="tv-starring-value">([^<]+)<\/span><\/div>/', $html, $lines);

        return array_combine($lines[1], $lines[2]);
    }

    /** @return list<string> */
    private function buttons(string $html): array
    {
        preg_match_all('/<\/i>(?:<span[^>]*>)*([^<]+)|<a class="tv-details-button is-secondary" href="[^"]*" target="_blank" rel="noopener noreferrer">([^<]+)/', $html, $labels);

        return array_values(array_filter(array_map(static fn (string $a, string $b): string => trim($a.$b), $labels[1], $labels[2]), static fn (string $label): bool => $label !== '' && $label !== '(opens in a new tab)'));
    }

    /** @return array<string, string> the first facts grid's labels and values, in order */
    private function facts(string $html): array
    {
        preg_match('/<dl class="tv-details-facts">(.*?)<\/dl>/s', $html, $match);
        preg_match_all('/<dt[^>]*>([^<]+)<\/dt><dd[^>]*>([^<]*)<\/dd>/', $match[1] ?? '', $facts);

        return array_combine($facts[1], $facts[2]);
    }

    /** @return list<int> release ids in table order */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-copy-nzb="([0-9a-f]{32})"/', $html, $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    /** @return list<string> */
    private function headings(string $html): array
    {
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $cells);

        return array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);
    }

    /** @return array<string, string> the sorted heading's key and direction */
    private function sortedHeadings(string $html, string $attribute = 'data-sort'): array
    {
        preg_match_all('/<th[^>]*aria-sort="([a-z]+)"[^>]*><button type="button" '.$attribute.'="([a-z]+)"/', $html, $sorted);

        return array_combine($sorted[2], $sorted[1]);
    }

    private function between(TestResponse $response, string $from, string $to): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);

        return trim(substr($html, $start, strpos($html, $to, $start) - $start));
    }
}
