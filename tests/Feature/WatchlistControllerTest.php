<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BrowseRoot;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use Database\Factories\VideoFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class WatchlistControllerTest extends TestCase
{
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
        foreach (['2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        $this->createGenresTable();
        DB::table('genres')->insert(['id' => 1, 'title' => 'Adventure']);
        foreach (self::entityRoots() as [$root, $table, $key, $category]) {
            DB::table('root_categories')->insert(['id' => $category - 30, 'title' => ucfirst($root)]);
            DB::table('categories')->insert(['id' => $category, 'title' => 'HD', 'root_categories_id' => $category - 30]);
            ProductionTables::fromAuthority()->create($table);
        }
        Schema::create('tv_info', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id')->primary();
            $table->string('publisher')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('image')->default(false);
        });
        ProductionTables::fromAuthority()->create('tv_episodes', ['id', 'videos_id', 'series', 'episode', 'firstaired']);
        foreach (['user_movies', 'user_series'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamps());
        }
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('title-artwork')]);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function entityRoots(): iterable
    {
        yield 'movies' => ['movies', 'movieinfo', 'imdbid', 2030];
        yield 'tv' => ['tv', 'videos', 'videos_id', 5030];
    }

    public function test_picker_add_edit_and_remove_share_categories_without_changing_another_users_list(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '0137523', 'title' => 'A Movie']);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'UHD', 'root_categories_id' => 2000]);
        $user = $this->browserUser();
        DB::table('user_movies')->insert(['users_id' => 999, 'imdbid' => '0137523', 'categories' => '2030']);
        $this->actingAs($user)->getJson('/watchlist/movies/0137523')->assertOk()
            ->assertJsonPath('watched', false)->assertJsonPath('selected', [2030, 2040])->assertJsonPath('title', 'A Movie');
        $this->postJson('/watchlist/movies/0137523', ['categories' => [2040]])->assertOk()->assertJsonPath('watched', true)->assertJsonPath('selected', [2040]);
        $this->postJson('/watchlist/movies/0137523', ['categories' => [2030]])->assertOk()->assertJsonPath('selected', [2030]);
        $this->assertDatabaseCount('user_movies', 2);
        $this->assertDatabaseHas('user_movies', ['users_id' => $user->id, 'imdbid' => '0137523', 'categories' => '2030']);
        $this->deleteJson('/watchlist/movies/0137523')->assertOk()->assertJsonPath('watched', false)->assertJsonPath('removedCategories', [2030]);
        $this->assertDatabaseCount('user_movies', 1);
        $this->postJson('/watchlist/movies/0137523', ['categories' => [2030]])->assertOk();
        $this->assertDatabaseCount('user_movies', 2);
    }

    public function test_page_finds_unfollowed_titles_without_releases_and_shows_latest_allowed_release(): void
    {
        DB::table('movieinfo')->insert([
            ['imdbid' => '0137523', 'title' => 'Followed Movie', 'year' => '2024'],
            ['imdbid' => '0000001', 'title' => 'Quiet Movie', 'year' => '2025'],
        ]);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'UHD', 'root_categories_id' => 2000]);
        $user = $this->browserUser();
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '0137523', 'categories' => '2030']);
        $this->release('Allowed latest', ['imdbid' => '0137523']);
        $this->release('Unwanted UHD', ['imdbid' => '0137523', 'categories_id' => 2040, 'adddate' => now()]);
        $this->actingAs($user)->get('/watchlist?tab=movies&q=Movie')->assertOk()
            ->assertSee('Quiet Movie')->assertSee('Followed Movie')->assertSee('Allowed latest')->assertDontSee('Unwanted UHD')
            ->assertSee('/title/movies/0137523', false)->assertSee('/rss/mymovies', false)->assertSee('data-watch-picker', false);
        $this->get('/watchlist?tab=tv')->assertOk()->assertSee('Nothing followed yet.')->assertSee('/rss/myshows', false);
    }

    public function test_legacy_unrestricted_and_root_subscriptions_include_releases_in_watching_browse(): void
    {
        $user = $this->browserUser();
        DB::table('movieinfo')->insert(['imdbid' => '0137523', 'title' => 'A Movie']);
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '0137523', 'categories' => 'NULL']);
        $this->release('Legacy all categories', ['imdbid' => '0137523']);
        $this->actingAs($user)->get('/browse/movies?watching=1&view=table')->assertOk()->assertSee('Legacy all categories')->assertSee('Movies you follow')->assertSee('Watching')->assertSee('aria-label="Clear filters"', false);
        DB::table('user_movies')->update(['categories' => '2000|9999']);
        $this->get('/browse/movies?watching=1&view=table')->assertOk()->assertSee('Legacy all categories')->assertSee('Movies you follow')->assertSee('Watching')->assertSee('aria-label="Clear filters"', false);
    }

    public function test_picker_rejects_invalid_categories_and_keeps_last_choice_after_removal(): void
    {
        DB::table('movieinfo')->insert([['imdbid' => '0137523', 'title' => 'First'], ['imdbid' => '0000001', 'title' => 'Next']]);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'UHD', 'root_categories_id' => 2000]);
        $this->actingAs($this->browserUser());
        foreach ([[], [5030], [9999], [2030, 2030], ['x'], [[2030]]] as $categories) {
            $this->postJson('/watchlist/movies/0137523', compact('categories'))->assertUnprocessable();
        }
        $this->assertDatabaseCount('user_movies', 0);
        $this->postJson('/watchlist/movies/0137523', ['categories' => [2040]])->assertOk();
        $this->deleteJson('/watchlist/movies/0137523')->assertOk();
        $this->getJson('/watchlist/movies/0000001')->assertOk()->assertJsonPath('selected', [2040]);
        $this->getJson('/watchlist/audio/1')->assertNotFound();
    }

    public function test_legacy_pages_redirect_to_tabs_or_the_title_picker(): void
    {
        $this->actingAs($this->browserUser());
        $this->get('/browse/movies')->assertOk()->assertSee('Only titles I follow');
        $this->get('/browse/tv')->assertOk()->assertSee('Only titles I follow');
        $this->get('/mymovies')->assertRedirect('/watchlist?tab=movies');
        $this->get('/myshows')->assertRedirect('/watchlist?tab=tv');
        $this->get('/mymovies?id=add&imdb=0137523')->assertRedirect('/title/movies/0137523?watch=1');
        $this->get('/myshows?action=add&id=12')->assertRedirect('/title/tv/12?watch=1');
        $this->get('/mymovies/browse')->assertRedirect('/browse/movies?watching=1');
        $this->get('/mymovies?id=browse')->assertRedirect('/browse/movies?watching=1');
        $this->get('/myshows/browse')->assertRedirect('/browse/tv?watching=1');
    }

    public function test_picker_enforces_root_permissions_and_title_identity(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '0137523', 'title' => 'Movie']);
        $user = $this->browserUser();
        $user->revokePermissionTo('view movies');
        $this->actingAs($user)->getJson('/watchlist/movies/0137523')->assertForbidden();
        $this->postJson('/watchlist/movies/0137523', ['categories' => [2030]])->assertForbidden();
        $this->deleteJson('/watchlist/movies/0137523')->assertForbidden();
        $this->get('/watchlist')->assertOk()->assertViewHas('root', BrowseRoot::Tv);
        $this->getJson('/watchlist/tv/not-an-id')->assertNotFound();
        $this->getJson('/watchlist/tv/999')->assertNotFound();
    }

    public function test_undo_preserves_exact_legacy_categories_and_is_bound_to_the_current_user_and_title(): void
    {
        DB::table('movieinfo')->insert([['imdbid' => '0137523', 'title' => 'First'], ['imdbid' => '0000001', 'title' => 'Next']]);
        $user = $this->browserUser();
        $this->actingAs($user);
        foreach ([null, 'NULL', '2000', '2030|9999', '9999'] as $categories) {
            DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '0137523', 'categories' => $categories]);
            $removed = $this->deleteJson('/watchlist/movies/0137523')->assertOk();
            $token = $removed->json('undoToken');
            $this->assertIsString($token);
            $this->postJson('/watchlist/movies/0000001', ['undo_token' => $token])->assertUnprocessable();
            $this->postJson('/watchlist/movies/0137523', ['undo_token' => $token])->assertOk()->assertJsonPath('watched', true);
            $this->assertDatabaseHas('user_movies', ['users_id' => $user->id, 'imdbid' => '0137523', 'categories' => $categories]);
            DB::table('user_movies')->where('users_id', $user->id)->delete();
        }
        $this->flushSession();
        $this->actingAs($this->browserUser())->postJson('/watchlist/movies/0137523', ['undo_token' => $token])->assertUnprocessable();
        $this->flushSession();
        $this->actingAs($user);
        $this->travel(6)->minutes();
        $this->postJson('/watchlist/movies/0137523', ['undo_token' => $token])->assertUnprocessable();
    }

    public function test_last_quality_choice_carries_between_movies_and_shows(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '0137523', 'title' => 'Movie']);
        $this->video(['id' => 12, 'title' => 'Show', 'started' => '2024-01-01']);
        DB::table('categories')->insert([
            ['id' => 2040, 'title' => 'UHD', 'root_categories_id' => 2000],
            ['id' => 5040, 'title' => 'UHD', 'root_categories_id' => 5000],
        ]);
        $this->actingAs($this->browserUser());
        $this->postJson('/watchlist/movies/0137523', ['categories' => [2040]])->assertOk();
        $this->getJson('/watchlist/tv/12')->assertOk()->assertJsonPath('selected', [5040]);
        $this->postJson('/watchlist/tv/12', ['categories' => [5040]])->assertOk();
        $this->get('/watchlist?tab=tv')->assertOk()->assertSee('Show')->assertSee('UHD');
    }

    public function test_header_and_action_counts_use_the_same_accessible_roots(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view movies');
        DB::table('movieinfo')->insert(['imdbid' => '0137523', 'title' => 'Movie']);
        $this->video(['id' => 12, 'title' => 'Show']);
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '0137523']);
        DB::table('user_series')->insert(['users_id' => $user->id, 'videos_id' => 12, 'categories' => '5030']);
        $page = $this->actingAs($user)->get('/watchlist?tab=tv')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        foreach ((new \DOMXPath($dom))->query('//*[@data-watchlist-count]') as $count) {
            $this->assertSame('1', trim($count->textContent));
        }
        $this->postJson('/watchlist/tv/12', ['categories' => [5030]])->assertOk()->assertJsonPath('counts', ['movies' => 0, 'tv' => 1]);
        $this->get('/watchlist?tab=tv&_fragment=lists')->assertOk()->assertSee('data-watchlist-fragment', false)->assertDontSee('<!DOCTYPE', false);
    }

    /** @param array<string, mixed> $attributes */
    private function video(array $attributes): void
    {
        DB::table('videos')->insert(array_intersect_key(
            VideoFactory::new()->raw(['started' => null, ...$attributes]),
            array_flip(Schema::getColumnListing('videos')),
        ));
    }
}
