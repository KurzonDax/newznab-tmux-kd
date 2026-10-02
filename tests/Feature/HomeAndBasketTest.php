<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\GetNzbController;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Content;
use Illuminate\Http\Request;
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
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_home_shows_eight_eligible_latest_releases_and_survives_empty_content(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view audio');
        foreach (range(1, 10) as $index) {
            $this->release('Eligible '.$index, ['isrenamed' => 1, 'nfostatus' => 1]);
        }
        $this->release('Pending rename', ['isrenamed' => 0]);
        $this->release('Excluded audio', ['isrenamed' => 1, 'categories_id' => 3030, 'nfostatus' => 1]);
        $response = $this->actingAs($user)->get('/')->assertOk()->assertSee('Latest releases')->assertSee('<h2>Following</h2>', false)->assertDontSee('Trending');
        $this->assertNoWatchWording((string) $response->getContent(), 'Home');
        $this->assertCount(8, $response->viewData('latest'));
        $response->assertDontSee('Pending rename')->assertDontSee('Excluded audio')->assertDontSee('No Content Available');
        Content::query()->create(['title' => 'Site announcement', 'body' => 'Welcome to the site', 'contenttype' => Content::TYPE_INDEX, 'status' => 1, 'role' => 0]);
        $this->get('/')->assertOk()->assertSee('Site announcement')->assertSee('Latest releases');
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
        foreach (['console' => [1030, 'consoleinfo_id'], 'pc' => [4030, 'gamesinfo_id'], 'book' => [7030, 'bookinfo_id'], 'audio' => [3030, 'musicinfo_id']] as $kind => [$category, $foreignKey]) {
            $id = $this->release('Chip.'.$kind.'.release', ['categories_id' => $category, $foreignKey => 12, 'fromname' => 'Shared poster', 'isrenamed' => 1, 'nfostatus' => 1]);
            DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => $id]);
        }
        $this->actingAs($user);

        foreach (['/' => 'Home', '/basket' => 'Basket'] as $path => $page) {
            $html = (string) $this->get($path)->assertOk()->assertSee('Chip.book.release')->assertSee('Chip.pc.release')->getContent();
            $this->assertShelfTitleChips($html, $page, md5('Chip.console.release'));
            $this->assertNoRetiredAddress($html, $page);
        }
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

    public function test_home_limits_watched_titles_to_their_newest_releases_and_links_each_film_page(): void
    {
        $user = $this->browserUser();
        foreach (range(1, 7) as $index) {
            $imdb = str_pad((string) $index, 7, '0', STR_PAD_LEFT);
            DB::table('movieinfo')->insert(['id' => 40 + $index, 'imdbid' => $imdb, 'title' => 'Followed movie '.$index, 'year' => '2026']);
            DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => $imdb, 'categories' => '2030']);
            $this->release('Older '.$index, ['imdbid' => $imdb, 'movieinfo_id' => 40 + $index, 'adddate' => '2026-09-11 12:00:00']);
            $this->release('Newest '.$index, ['imdbid' => $imdb, 'movieinfo_id' => 40 + $index, 'adddate' => '2026-09-13 12:00:00']);
        }
        $this->release('Disallowed watched category', ['imdbid' => '0000007', 'categories_id' => 3030, 'adddate' => '2026-09-14 12:00:00']);
        $response = $this->actingAs($user)->get('/')->assertOk();
        $watched = $response->viewData('homeWatched');
        $this->assertCount(5, $watched);
        $this->assertCount(5, $watched->pluck('imdbid')->unique());
        foreach ($watched as $release) {
            $this->assertStringStartsWith('Newest', $release->searchname);
            $response->assertSee('href="'.route('movies.film', ['movieinfoId' => $release->movieinfo_id]).'"', false);
        }
        $response->assertDontSee('/title/movies/', false);
    }
}
