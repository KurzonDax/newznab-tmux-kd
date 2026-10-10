<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\GetNzbController;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsNoRetiredAddress;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class HomeAndBasketTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsNoRetiredAddress;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        foreach ([2000 => 'Movies', 5000 => 'TV', 3000 => 'Audio'] as $id => $title) {
            DB::table('root_categories')->insert(['id' => $id, 'title' => $title]);
            DB::table('categories')->insert(['id' => $id + 30, 'title' => 'HD', 'root_categories_id' => $id]);
        }
        foreach (['movieinfo', 'videos'] as $name) {
            ProductionTables::fromAuthority()->create($name);
        }
        ProductionTables::fromAuthority()->create('tv_info', ['videos_id', 'publisher', 'image']);
        foreach (['2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_home_shows_the_users_shelves_without_the_finished_only_rule_and_survives_empty_content(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view audio');
        DB::table('movieinfo')->insert(['id' => 40, 'imdbid' => '0000040', 'title' => 'Shelf film', 'year' => '2026']);
        // the cards' finished-only rule went with the cards (SPEC 8): a release still waiting for its rename counts
        $this->release('Renamed film', ['movieinfo_id' => 40, 'imdbid' => '0000040', 'isrenamed' => 1, 'nfostatus' => 1, 'adddate' => now()->subHours(2), 'postdate' => now()->subHours(3)]);
        $this->release('Pending rename', ['movieinfo_id' => 40, 'imdbid' => '0000040', 'isrenamed' => 0, 'adddate' => now()->subHours(2), 'postdate' => now()->subHours(3)]);
        $this->release('Excluded audio', ['isrenamed' => 1, 'categories_id' => 3030, 'nfostatus' => 1]);
        $response = $this->actingAs($user)->get('/')->assertOk()->assertDontSee('Latest releases')->assertDontSee('Trending')
            ->assertSee('<h1 data-part="page title">Home</h1>', false)->assertSee('2 today · 1 film this week')->assertSee('2 releases · 3 hr ago');
        $this->assertSame(['Following', 'TV', 'Movies', 'Books'], $this->shelves((string) $response->getContent()));
        $this->assertNoWatchWording((string) $response->getContent(), 'Home');
        $response->assertSee('title="Shelf film (2026)"', false)->assertDontSee('Excluded audio')->assertDontSee('No Content Available');
        Content::query()->create(['title' => 'Site announcement', 'body' => 'Welcome to the site', 'contenttype' => Content::TYPE_INDEX, 'status' => 1, 'role' => 0]);
        $this->get('/')->assertOk()->assertSeeInOrder(['data-shelf="Movies"', 'Site announcement'], false);
    }

    public function test_home_shelves_show_what_their_section_lists_show_and_a_tile_opens_the_row_with_its_actions(): void
    {
        $cases = [
            'Found NFO' => [1, 0, null], 'No NFO' => [0, 0, null],
            'Failed NFO' => [-9, 0, null], 'Skipped NFO' => [-10, 0, null],
            'First retry' => [-1, 0, null], 'Last retry' => [-8, 0, null],
            'Password unchecked' => [1, -1, null], 'Empty claim' => [1, 0, ''],
        ];
        foreach ($cases as $name => [$nfo, $password, $claim]) {
            $this->release($name, ['categories_id' => 3030, 'isrenamed' => 1, 'nfostatus' => $nfo, 'passwordstatus' => $password, 'additional_pp_claim_token' => $claim]);
        }
        $this->release('Original name', ['categories_id' => 3030, 'isrenamed' => 0, 'nfostatus' => 1, 'passwordstatus' => 0]);
        $this->release('Claimed release', ['categories_id' => 3030, 'isrenamed' => 1, 'nfostatus' => 1, 'passwordstatus' => 0, 'additional_pp_claim_token' => 'active-claim']);
        $this->release('Passworded release', ['categories_id' => 3030, 'isrenamed' => 1, 'nfostatus' => 1, 'passwordstatus' => 1]);

        $response = $this->actingAs($this->browserUser())->get('/')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML((string) $response->getContent());
        $xpath = new \DOMXPath($document);
        $tiles = $xpath->query('//section[@data-shelf="Audio"]//button[@data-tile]');
        // the Audio list's rule: every release the password setting lets through, finished or not
        $this->assertEqualsCanonicalizing([...array_keys($cases), 'Original name', 'Claimed release'], array_map(static fn (\DOMElement $tile): string => $tile->getAttribute('title'), iterator_to_array($tiles)));
        foreach ($tiles as $tile) {
            $this->assertSame(['album', 'false'], [$tile->getAttribute('data-kind'), $tile->getAttribute('aria-expanded')]);
            $this->assertSame(1, $xpath->query('.//i[contains(@class, "fa-compact-disc")]', $tile)->length);
            $this->assertSame(0, $xpath->query('.//img', $tile)->length);
        }
        // the page holds no rows: a tile's releases arrive with its panel
        $this->assertSame(0, $xpath->query('//*[@data-release-row] | //*[@data-select] | //*[@data-release-select] | //*[@data-release-cards]')->length);

        $id = DB::table('releases')->where('name', 'Found NFO')->value('id');
        $panel = (string) $this->get('/?_fragment=panel&shelf=Audio&kind=album&id='.$id)->assertOk()->assertSee('Found NFO')->getContent();
        $this->assertSame(1, substr_count($panel, 'data-release-row'));
        foreach (['download-nzb', 'data-copy-nzb="'.md5('Found NFO').'"', 'data-cart="'.md5('Found NFO').'"', 'tv-action-slot'] as $action) {
            $this->assertSame(1, substr_count($panel, $action), $action);
        }
        $this->assertStringNotContainsString('data-select', $panel);
    }

    public function test_home_and_basket_show_no_title_chip_for_books_or_pc_and_link_console_to_details(): void
    {
        foreach ([1000 => 'Console', 4000 => 'PC', 7000 => 'Books'] as $id => $title) {
            DB::table('root_categories')->insert(['id' => $id, 'title' => $title]);
            DB::table('categories')->insert(['id' => $id + 30, 'title' => 'HD', 'root_categories_id' => $id]);
        }
        foreach (['genres', 'musicinfo', 'consoleinfo', 'bookinfo', 'gamesinfo'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('consoleinfo')->insert(['id' => 12, 'title' => 'Console Game Title', 'platform' => 'PS5', 'releasedate' => '2024-01-01']);
        DB::table('gamesinfo')->insert(['id' => 12, 'title' => 'Computer Game Title', 'releasedate' => '2023-01-01']);
        DB::table('bookinfo')->insert(['id' => 12, 'title' => 'Printed Book Title', 'author' => 'An author', 'genre' => 'Mystery', 'publishdate' => '2022-01-01']);
        DB::table('musicinfo')->insert(['id' => 12, 'title' => 'Album Title', 'year' => '2021']);
        $user = $this->browserUser();
        DB::table('users')->where('id', $user->id)->update(['view_prefs' => json_encode(['home' => ['ticked' => ['Audio', 'Books', 'Console', 'PC']]])]);
        foreach (['console' => [1030, 'consoleinfo_id'], 'pc' => [4030, 'gamesinfo_id'], 'book' => [7030, 'bookinfo_id'], 'audio' => [3030, 'musicinfo_id']] as $kind => [$category, $foreignKey]) {
            $id = $this->release('Chip.'.$kind.'.release', ['categories_id' => $category, $foreignKey => 12, 'fromname' => 'Shared poster', 'isrenamed' => 1, 'nfostatus' => 1]);
            DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => $id]);
        }
        $this->actingAs($user);

        // Home: each release is a tile on its section's shelf, named by its release name; no old match is its title
        $html = (string) $this->get('/')->assertOk()->assertSee('Chip.book.release')->assertSee('Chip.pc.release')->assertSee('Chip.console.release')->assertSee('Chip.audio.release')
            ->assertDontSee('Printed Book Title')->assertDontSee('Computer Game Title')->assertDontSee('Album Title')->assertDontSee('data-chip-variant="entity"', false)->getContent();
        $this->assertSame(['Audio', 'Books', 'Console', 'PC'], $this->shelves($html));
        $this->assertNoRetiredAddress($html, 'Home');
        // the console tile's panel: the name opens the details page and the game is a plain line
        $console = (string) $this->get('/?_fragment=panel&shelf=Console&kind=rel&id='.DB::table('releases')->where('name', 'Chip.console.release')->value('id'))->assertOk()
            ->assertSee('<a class="tv-release-name" href="'.route('details', md5('Chip.console.release')).'"', false)
            ->assertSee('<span class="tv-game-line" data-entity="game">Console Game Title · 2024</span>', false)->getContent();
        $this->assertNoRetiredAddress($console, 'A home panel');
        foreach (['book', 'pc'] as $kind) {
            $panel = (string) $this->get('/?_fragment=panel&shelf='.($kind === 'book' ? 'Books' : 'PC').'&kind=rel&id='.DB::table('releases')->where('name', 'Chip.'.$kind.'.release')->value('id'))
                ->assertOk()->assertSee('Chip.'.$kind.'.release')->assertDontSee('Printed Book Title')->assertDontSee('Computer Game Title')->getContent();
            $this->assertStringNotContainsString('data-entity=', $panel);
            $this->assertNoRetiredAddress($panel, 'A home panel');
        }

        $html = (string) $this->get('/basket')->assertOk()->assertSee('Chip.book.release')->assertSee('Chip.pc.release')->getContent();
        $this->assertShelfTitleChips($html, 'Basket', md5('Chip.console.release'));
        $this->assertNoRetiredAddress($html, 'Basket');
    }

    public function test_basket_ignores_listing_filters_and_footer_actions_cover_all_pages_only_for_its_owner(): void
    {
        $user = $this->browserUser();
        foreach (range(1, 26) as $index) {
            $id = $this->release('Basket '.$index);
            DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => $id]);
        }
        $id = $this->release('Someone else');
        DB::table('users_releases')->insert(['users_id' => 999, 'releases_id' => $id]);
        $response = $this->actingAs($user)->get('/basket?per=24&q=nothing&watching=1')->assertOk()->assertSee('26 in basket')->assertSee('Download 26 NZBs')->assertSee('Empty basket')->assertDontSee('Someone else');
        $this->assertSame(26, $response->viewData('results')->total());
        $response->assertDontSee('data-preference="view"', false);
        $this->get('/cart/index')->assertRedirect('/basket');
        $expectedGuids = DB::table('releases')->where('id', '!=', $id)->pluck('guid')->all();
        $this->mock(GetNzbController::class, function ($mock) use ($expectedGuids, $user): void {
            $mock->shouldReceive('getNzb')->once()->withArgs(function (Request $request) use ($expectedGuids, $user): bool {
                $this->assertEqualsCanonicalizing($expectedGuids, explode(',', $request->input('id')));
                $this->assertSame('1', $request->input('zip'));
                $this->assertSame($user->id, $request->attributes->get(GetNzbController::REQUEST_USER_ATTRIBUTE)->id);

                return true;
            })->andReturn(response('All basket NZBs'));
        });
        $this->post('/basket/download?per=24&page=2&q=nothing', ['id' => md5('Someone else')])->assertOk()->assertSee('All basket NZBs');
        $this->post('/basket/empty')->assertRedirect('/basket');
        $this->assertDatabaseCount('users_releases', 1);
        $this->get('/basket')->assertOk()->assertSee('Your basket is empty.')->assertDontSee('Empty basket');
    }

    public function test_home_lists_every_followed_film_once_by_its_newest_followed_release_and_links_each_film_page(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        $user = $this->browserUser();
        foreach (range(1, 7) as $index) {
            $imdb = str_pad((string) $index, 7, '0', STR_PAD_LEFT);
            DB::table('movieinfo')->insert(['id' => 40 + $index, 'imdbid' => $imdb, 'title' => 'Followed movie '.$index, 'year' => '2026']);
            DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => $imdb, 'categories' => '2030']);
            $this->release('Older '.$index, ['imdbid' => $imdb, 'movieinfo_id' => 40 + $index, 'adddate' => '2026-09-11 12:00:00']);
            $this->release('Newest '.$index, ['imdbid' => $imdb, 'movieinfo_id' => 40 + $index, 'adddate' => '2026-09-13 12:00:00']);
        }
        // outside the follow's category list: it never moves the film forward
        $this->release('Disallowed watched category', ['imdbid' => '0000007', 'categories_id' => 3030, 'adddate' => '2026-09-14 11:00:00']);
        $response = $this->actingAs($user)->get('/')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML((string) $response->getContent());
        $xpath = new \DOMXPath($document);
        $tiles = iterator_to_array($xpath->query('//section[@data-shelf="Following"]//button[@data-tile]'));
        // all seven, each once (the old rows stopped at five)
        $this->assertEqualsCanonicalizing(array_map(static fn (int $index): string => 'Followed movie '.$index, range(1, 7)), array_map(static fn (\DOMElement $tile): string => $tile->getAttribute('title'), $tiles));
        foreach ($tiles as $tile) {
            $this->assertSame('film', $tile->getAttribute('data-kind'));
            $this->assertSame('1 day ago', trim((string) $xpath->query('.//*[contains(@class, "tv-tile-what")]', $tile)->item(0)?->textContent));
            $id = (int) $tile->getAttribute('data-id');
            $this->get('/?_fragment=panel&shelf=Following&kind=film&id='.$id)->assertOk()
                ->assertSeeInOrder(['Newest '.($id - 40), 'Older '.($id - 40)])->assertDontSee('Disallowed watched category')
                ->assertSee('href="'.route('movies.film', ['movieinfoId' => $id]).'"', false)->assertDontSee('/title/movies/', false);
        }
        $response->assertDontSee('/title/movies/', false);
    }

    /** @return list<string> the shelves on the page, in order */
    private function shelves(string $html): array
    {
        preg_match_all('/<section class="home-shelf" data-shelf="([^"]+)"/', $html, $matches);

        return $matches[1];
    }
}
