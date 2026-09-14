<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\ReleaseReport;
use App\Services\Releases\ReleaseSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class DetailsControllerTest extends TestCase
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
        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedInteger('predb_id')->nullable();
            $table->string('password')->nullable();
        });
        DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies']);
        DB::table('categories')->insert(['id' => 2030, 'title' => 'HD', 'root_categories_id' => 2000]);
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table', '2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });
        Schema::create('release_regexes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
        });
        Schema::create('dnzb_failures', function (Blueprint $table): void {
            $table->unsignedInteger('release_id');
            $table->unsignedInteger('failed');
        });
        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        DB::statement('CREATE TABLE release_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, releases_id INTEGER NOT NULL, text VARCHAR(2000), isvisible INTEGER DEFAULT 1, username VARCHAR(255), users_id INTEGER, created_at DATETIME, updated_at DATETIME, host VARCHAR(45))');
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('imdbid');
            $table->string('title');
            $table->string('year')->nullable();
            $table->string('genre')->nullable();
            $table->string('rating')->nullable();
            $table->text('trailer')->nullable();
        });
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
        $this->release('Raw.Release', ['guid' => 'details-http', 'display_name' => 'Readable release', 'size' => 41943040, 'nfostatus' => 0,
            'videos_id' => null, 'tv_episodes_id' => null, 'imdbid' => null, 'musicinfo_id' => null, 'gamesinfo_id' => null,
            'consoleinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null]);
        $response = $this->actingAs($this->browserUser())->get('/details/details-http')->assertOk();
        $response->assertSee('Readable release')->assertSee('40.00 MB')->assertSee('data-details-header', false)
            ->assertSee('No media info for this release.')->assertSee('No NFO for this release.')
            ->assertSee('href="#comments"', false)->assertSee('None.')->assertDontSee('Similar releases');
        $this->assertSame('Readable release', $response->viewData('release')->row_data->name);
    }

    public function test_related_releases_keep_only_other_permitted_releases_of_the_same_title(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '0111161', 'title' => 'A Matched Movie']);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'UHD', 'root_categories_id' => 2000]);
        $current = $this->detailRelease('Current.1080p', ['imdbid' => '0111161']);
        $this->detailRelease('Other.720p.WEB', ['imdbid' => '0111161', 'size' => 41943040, 'completion' => 93]);
        $this->detailRelease('Excluded.2160p', ['imdbid' => '0111161', 'categories_id' => 2040]);
        $this->detailRelease('Passworded.1080p', ['imdbid' => '0111161', 'passwordstatus' => 2]);
        $this->detailRelease('Different.Title', ['imdbid' => '7654321']);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => 2040]);
        $response = $this->actingAs($user)->get('/details/'.md5('Current.1080p'))->assertOk();
        $response->assertSee('A Matched Movie')->assertSee('40.00 MB')->assertSee('93%')
            ->assertSee('720p · WEB')->assertSee('/details/'.md5('Other.720p.WEB'), false)->assertDontSee('Excluded.2160p')
            ->assertDontSee('Passworded.1080p')->assertDontSee('Different.Title');
        $this->assertCount(1, $response->viewData('otherReleases'));
        $this->assertNotEquals($current, $response->viewData('otherReleases')->first()->id);
        for ($number = 1; $number <= 12; $number++) {
            $this->detailRelease('Additional.'.$number.'.1080p', ['imdbid' => '0111161']);
        }
        $more = $this->get('/details/'.md5('Current.1080p'))->assertOk()->assertSee('View all 13 other releases')
            ->assertSee('/title/movies/0111161', false);
        $this->assertCount(10, $more->viewData('otherReleases'));
        $this->assertSame(13, $more->viewData('otherReleases')->total());

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
            ->assertSee('Original.Scene.Release')->assertSee('Original report text')->assertSee('Public staff response');
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

    /** @param array<string, mixed> $attributes */
    private function detailRelease(string $name, array $attributes = []): int
    {
        return $this->release($name, ['videos_id' => null, 'tv_episodes_id' => null, 'imdbid' => null, 'musicinfo_id' => null,
            'gamesinfo_id' => null, 'consoleinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null, ...$attributes]);
    }
}
