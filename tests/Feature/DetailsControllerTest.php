<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Release;
use App\Models\ReleaseReport;
use App\Services\Releases\ReleaseSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Today's details page, which releases outside TV and Movies keep (TV and Movies have their own
 * pages: TvReleaseDetailsPageTest, MovieReleaseDetailsPageTest). Its releases are PC > Games.
 */
final class DetailsControllerTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const PC_GAMES = 4050;

    private const BOOKS_EBOOK = 7020;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedInteger('predb_id')->nullable();
        });
        DB::table('root_categories')->insert(['id' => 4000, 'title' => 'PC']);
        DB::table('categories')->insert(['id' => self::PC_GAMES, 'title' => 'Games', 'root_categories_id' => 4000]);
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table', '2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        ProductionTables::fromAuthority()->create('releases_groups');
        ProductionTables::fromAuthority()->create('release_regexes');
        Schema::create('dnzb_failures', function (Blueprint $table): void {
            $table->unsignedInteger('release_id');
            $table->unsignedInteger('failed');
        });
        ProductionTables::fromAuthority()->create('predb', ['id', 'title']);
        DB::statement('CREATE TABLE release_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, releases_id INTEGER NOT NULL, text VARCHAR(2000), isvisible INTEGER DEFAULT 1, username VARCHAR(255), users_id INTEGER, created_at DATETIME, updated_at DATETIME, host VARCHAR(45))');
        ProductionTables::fromAuthority()->create('movieinfo', ['id', 'imdbid', 'title', 'year', 'genre', 'director', 'actors', 'rating', 'trailer']);
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('details-artwork')]);
        $this->mock(ReleaseSearchService::class)->shouldReceive('searchSimilar')->andReturn([]);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_details_header_uses_the_shared_release_data_and_renders_each_tab(): void
    {
        $this->release('Raw.Release', ['categories_id' => self::PC_GAMES, 'guid' => 'details-http', 'display_name' => 'Readable release', 'size' => 41943040, 'nfostatus' => 0,
            'videos_id' => null, 'tv_episodes_id' => null, 'imdbid' => null, 'musicinfo_id' => null, 'gamesinfo_id' => null,
            'consoleinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null]);
        $response = $this->actingAs($this->browserUser())->get('/details/details-http')->assertOk()->assertViewIs('details.index');
        $response->assertSee('Readable release')->assertSee('40.00 MB')->assertSee('data-details-header', false)
            ->assertSee('No media info for this release.')->assertSee('No NFO for this release.')
            ->assertSee('href="#comments"', false)->assertSee('None.')->assertDontSee('Similar releases');
        $this->assertSame('Readable release', $response->viewData('release')->row_data->name);
    }

    public function test_comment_posts_return_to_the_comments_tab_and_blank_posts_do_not_change_the_count(): void
    {
        $this->detailRelease('Comment.Release');
        $url = '/details/'.md5('Comment.Release');
        $this->actingAs($this->browserUser())->post($url, ['txtAddComment' => 'Works well.'])
            ->assertRedirect($url.'#comments')->assertSessionHas('success');
        $this->get($url)->assertOk()->assertSee('Works well.')->assertSee('Comments (1)');
        $this->from($url.'#comments')->post($url, ['txtAddComment' => '   '])->assertSessionHasErrors('txtAddComment');
        $this->get($url)->assertOk()->assertSee('Comments (1)');
    }

    public function test_movie_trailers_use_the_shared_player_and_predb_and_public_reports_remain_visible(): void
    {
        DB::table('settings')->updateOrInsert(['name' => 'trailers_display'], ['value' => '1']);
        DB::table('movieinfo')->insert(['imdbid' => '0111161', 'title' => 'Trailer Movie', 'trailer' => 'https://youtu.be/Way9Dexny3w']);
        DB::table('predb')->insert(['id' => 5, 'title' => 'Original.Scene.Release']);
        $id = $this->detailRelease('Trailer.Release', ['imdbid' => '0111161', 'predb_id' => 5]);
        $user = $this->browserUser();
        ReleaseReport::factory()->create(['releases_id' => $id, 'users_id' => $user->id, 'description' => 'Original report text',
            'response' => 'Public staff response', 'response_is_public' => true, 'responded_by' => $user->id]);
        $response = $this->actingAs($user)->get('/details/'.md5('Trailer.Release'))->assertOk();
        $response->assertSee('data-trailer-url="https://www.youtube-nocookie.com/embed/Way9Dexny3w"', false)
            ->assertDontSee('<iframe', false)->assertSee('x-data="trailerModal"', false)
            ->assertSee('Original.Scene.Release')->assertSee('Original report text')->assertSee('Public staff response')
            ->assertDontSee('data-watch-picker', false);
        $this->assertNoWatchWording((string) $response->getContent(), 'Today\'s details page with a film trailer');
    }

    public function test_movie_genre_director_and_cast_are_escaped_plain_text_limited_to_eight_names(): void
    {
        $markup = '"><script>alert(1)</script>';
        DB::table('movieinfo')->insert(['imdbid' => '0111162', 'title' => 'Plain Movie', 'genre' => 'Drama, '.$markup,
            'director' => 'Tom & Jerry', 'actors' => implode(', ', ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10'])]);
        $this->detailRelease('Plain.Movie.Release', ['imdbid' => '0111162']);
        $response = $this->actingAs($this->browserUser())->get('/details/'.md5('Plain.Movie.Release'))->assertOk();
        $content = (string) $response->getContent();
        $response->assertSee('Drama, '.e($markup), false)->assertSee('Tom &amp; Jerry', false)
            ->assertSee('A1, A2, A3, A4, A5, A6, A7, A8<', false)->assertDontSee('A9', false);
        $this->assertStringNotContainsString('<script>alert(1)', $content);
        $this->assertStringNotContainsString('?genre=', $content);
        $this->assertStringNotContainsString('?actors=', $content);
        $this->assertStringNotContainsString('?director=', $content);
    }

    public function test_completion_and_repair_status_stay_in_the_header_above_the_tabs(): void
    {
        $id = $this->detailRelease('Incomplete.Release', ['completion' => 93]);
        $url = '/details/'.md5('Incomplete.Release');
        $response = $this->actingAs($this->browserUser())->get($url)->assertOk();
        $response->assertSeeInOrder(['data-details-header', '93%', 'Repair Attempt(s) Pending', 'class="details-tabs"'], false);
        DB::table('releases')->where('id', $id)->update(['completion' => 0]);
        $this->get($url)->assertOk()->assertSee('Completion not measured')->assertDontSee('Repair Attempt(s) Pending');
    }

    public function test_similar_releases_lists_the_same_root_matches_without_the_release_itself(): void
    {
        $current = $this->detailRelease('Some.Game.v1.0-GRP');
        $same = $this->detailRelease('Some.Game.v1.1-GRP', ['display_name' => 'Some Game update']);
        $sibling = $this->detailRelease('Some.Game.Soundtrack.ISO', ['categories_id' => 4030, 'display_name' => 'Some Game disc image']);
        $book = $this->detailRelease('Some.Game.Strategy.Guide', ['categories_id' => self::BOOKS_EBOOK, 'display_name' => 'Some Game strategy guide']);
        $user = $this->browserUser();
        DB::table('categories')->insert(['id' => 4010, 'title' => '0day', 'root_categories_id' => 4000]);
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => 4010]);
        $searches = [];
        // search() runs MariaDB-only SQL; its rows carry id and categories_id and no categoryparentid.
        // categories_id comes back as a string here so the root comparison must not depend on its type.
        $this->partialMock(ReleaseSearchService::class, function (MockInterface $mock) use (&$searches, $current, $same, $sibling, $book): void {
            $mock->shouldReceive('search')->andReturnUsing(function (mixed ...$arguments) use (&$searches, $current, $same, $sibling, $book) {
                $searches[] = $arguments;

                return Release::query()->whereIn('id', [$current, $same, $sibling, $book])->orderBy('id')->get()
                    ->each(static function (Release $row): void {
                        $row->setRawAttributes(['categories_id' => (string) $row->categories_id] + $row->getAttributes());
                    });
            });
        });

        $response = $this->actingAs($user)->get('/details/'.md5('Some.Game.v1.0-GRP'))->assertOk();

        $this->assertSame([$same, $sibling], array_map(static fn (Release $row): int => (int) $row->id, $response->viewData('similars')));
        $response->assertSee('Similar releases')->assertSee('Some Game update')->assertSee('Some Game disc image')->assertDontSee('Some Game strategy guide');
        $this->assertCount(1, $searches);
        [$phrases, $limit, $excludedCategories, $categories] = [$searches[0][0], $searches[0][7], $searches[0][10], $searches[0][12]];
        $this->assertSame(['searchname' => getSimilarName('Some.Game.v1.0-GRP')], $phrases);
        $this->assertSame((int) config('nntmux.items_per_page'), $limit);
        $this->assertContains(4010, array_map('intval', $excludedCategories));
        $this->assertSame([4000], $categories);
    }

    /** @return array<string, array{string, string}> */
    public static function hidingModes(): array
    {
        return [
            'the whole root switched off' => ['root', 'PC'],
            'only the sub-category unticked' => ['sub', 'PC - Games'],
        ];
    }

    #[DataProvider('hidingModes')]
    public function test_a_release_in_a_hidden_category_is_refused_and_takes_no_comment(string $mode, string $name): void
    {
        DB::table('root_categories')->insert(['id' => 7000, 'title' => 'Books']);
        DB::table('categories')->insert(['id' => self::BOOKS_EBOOK, 'title' => 'Ebook', 'root_categories_id' => 7000]);
        $this->detailRelease('Hidden.Game-GRP');
        $this->detailRelease('Visible.Book-GRP', ['categories_id' => self::BOOKS_EBOOK]);
        $user = $this->browserUser();
        if ($mode === 'root') {
            $user->revokePermissionTo('view pc');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user = $user->fresh();
        } else {
            DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::PC_GAMES]);
        }
        $hidden = '/details/'.md5('Hidden.Game-GRP');

        foreach ([$this->actingAs($user)->get($hidden), $this->post($hidden, ['txtAddComment' => 'Should not land.'])] as $response) {
            $response->assertForbidden()->assertViewIs('errors.category-disabled')->assertViewHas('category', $name)
                ->assertSee($name.' is hidden in your account preferences.')->assertDontSee('Hidden.Game-GRP');
        }
        $this->assertSame(0, DB::table('release_comments')->count());

        $visible = '/details/'.md5('Visible.Book-GRP');
        $this->get($visible)->assertOk()->assertViewIs('details.index')->assertSee('Visible.Book-GRP');
        $this->post($visible, ['txtAddComment' => 'Lands.'])->assertRedirect($visible.'#comments');
        $this->assertSame(1, DB::table('release_comments')->count());
    }

    /** @param array<string, mixed> $attributes */
    private function detailRelease(string $name, array $attributes = []): int
    {
        return $this->release($name, ['categories_id' => self::PC_GAMES, 'videos_id' => null, 'tv_episodes_id' => null, 'imdbid' => null, 'musicinfo_id' => null,
            'gamesinfo_id' => null, 'consoleinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null, ...$attributes]);
    }
}
