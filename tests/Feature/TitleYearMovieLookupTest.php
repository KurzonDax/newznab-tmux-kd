<?php

declare(strict_types=1);

namespace Tests\Feature;

use aharen\OMDbAPI;
use App\Facades\Search;
use App\Models\Category;
use App\Services\ImdbScraper;
use App\Services\MetadataProcessing\MovieProcessingCandidateQuery;
use App\Services\MovieService;
use App\Services\TmdbClient;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Pipes\ParseInfoPipe;
use App\Services\TvProcessing\TvProcessingPipeline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\ImdbScraperTestCase;

class TitleYearMovieLookupTest extends ImdbScraperTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Search::spy();
        config(['nntmux.echocli' => false, 'nntmux_api.omdb_api_key' => '', 'nntmux_api.trakttv_api_key' => '']);
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname');
            $table->string('name')->default('');
            $table->string('guid')->default('a');
            $table->unsignedInteger('groups_id')->default(1);
            $table->integer('categories_id')->default(Category::MOVIE_HD);
            $table->string('imdbid')->nullable();
            $table->integer('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->timestamp('tv_episode_lookup_attempted_at')->nullable();
            $table->timestamp('postdate')->nullable();
            $table->integer('iscategorized')->default(0);
            $table->integer('haspreview')->default(0);
        });
        (require base_path('database/migrations/2026_09_10_105328_add_imdb_lookup_retry_state_to_releases_table.php'))->up();
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('year');
            $table->string('imdbid');
        });
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->dateTime('started');
            $table->integer('type')->default(0);
            $table->integer('source')->default(0);
        });
        Schema::create('videos_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('videos_id');
            $table->string('title');
        });
        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('videoduration')->nullable();
            $table->string('videoformat')->default('AVC');
            $table->string('videocodec')->default('AVC');
            $table->string('containerformat')->default('Matroska');
            $table->integer('videowidth')->default(1920);
            $table->integer('videoheight')->default(1080);
        });
        Schema::create('audio_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->integer('audioid');
            $table->string('audioformat');
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->integer('forced_root_categories_id')->nullable();
        });
        DB::table('usenet_groups')->insert(['id' => 1]);
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->id();
            $table->integer('generate_previews')->default(1);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('root_categories_id');
        });
        $this->app->instance(TvProcessingPipeline::class, new TvProcessingPipeline([new ParseInfoPipe, new LocalDbPipe], false));
        $this->mock(TmdbClient::class)->shouldReceive('isConfigured')->andReturnFalse();
    }

    #[Test]
    public function series_handoff_binds_a_local_show_and_refines_tv_hd(): void
    {
        $this->seriesResponse();
        DB::table('videos')->insert(['id' => 10, 'title' => 'Sterling Point', 'started' => '2026-01-01']);
        $this->release('Sterling Point (2026)');
        DB::table('video_data')->insert(['releases_id' => 1, 'videoduration' => '00h:45m:00s']);
        (new MovieService)->processMovieReleases();
        $release = DB::table('releases')->find(1);
        $this->assertSame(10, $release->videos_id);
        $this->assertSame(-6, $release->tv_episodes_id);
        $this->assertSame(Category::TV_HD, $release->categories_id);
        $this->assertNull($release->imdbid);
    }

    #[Test]
    public function series_without_a_show_still_moves_to_tv_other(): void
    {
        $this->seriesResponse();
        $this->release('Sterling Point (2026)');
        (new MovieService)->processMovieReleases();
        $this->assertSame(Category::TV_OTHER, DB::table('releases')->find(1)->categories_id);
        $this->assertSame(0, DB::table('releases')->find(1)->videos_id);
        $this->assertNull(DB::table('releases')->find(1)->imdbid);
    }

    #[Test]
    public function series_handoff_respects_a_forced_movie_root(): void
    {
        $this->seriesResponse();
        $this->release('Sterling Point (2026)');
        DB::table('usenet_groups')->where('id', 1)->update(['forced_root_categories_id' => Category::MOVIE_ROOT]);
        (new MovieService)->processMovieReleases();
        $this->assertSame(Category::MOVIE_HD, DB::table('releases')->find(1)->categories_id);
        $this->assertSame('', DB::table('releases')->find(1)->imdbid);
        $this->assertFalse(MovieProcessingCandidateQuery::query(lookupMode: 1)->exists());
    }

    #[Test]
    public function unavailable_lookups_wait_six_hours_and_stop_on_the_fourth_attempt(): void
    {
        $this->mock(ImdbScraper::class)->shouldReceive('search')->andThrow(new \RuntimeException('offline'));
        $tmdb = $this->mock(TmdbClient::class);
        $tmdb->shouldReceive('isConfigured')->andReturnTrue();
        $tmdb->shouldReceive('searchMovies')->andThrow(new \RuntimeException('offline'));
        $omdb = \Mockery::mock(OMDbAPI::class);
        $omdb->shouldReceive('search')->andThrow(new \RuntimeException('offline'));
        $this->release('Con.Air.1997.1080p.BluRay.x264-GRP');
        $service = new MovieService;
        $service->omdbapikey = 'test';
        $service->omdbApi = $omdb;
        $service->processMovieReleases();
        $release = DB::table('releases')->find(1);
        $this->assertNull($release->imdbid);
        $this->assertSame(1, $release->imdb_lookup_attempts);
        $this->assertNotNull($release->imdb_lookup_attempted_at);
        $this->assertFalse(MovieProcessingCandidateQuery::query(lookupMode: 1)->exists());
        for ($attempt = 2; $attempt <= 4; $attempt++) {
            $this->travel(6)->hours();
            $this->assertTrue(MovieProcessingCandidateQuery::query(lookupMode: 1)->exists());
            $service->processMovieReleases();
        }
        $this->assertSame('', DB::table('releases')->find(1)->imdbid);
        $this->assertSame(4, DB::table('releases')->find(1)->imdb_lookup_attempts);
        $this->assertFalse(MovieProcessingCandidateQuery::query(lookupMode: 1)->exists());
        $this->travelBack();
    }

    #[Test]
    public function no_film_outside_the_scope_never_calls_tv(): void
    {
        $scraper = $this->mock(ImdbScraper::class);
        $scraper->shouldReceive('search')->andReturn([]);
        $scraper->shouldReceive('wasSearchUnavailable')->andReturnFalse();
        $this->mock(TvProcessingPipeline::class)->shouldNotReceive('processRelease');
        $this->release('Con.Air.1997.1080p.BluRay.x264-GRP');
        $this->release('Barcelona - Atletico Madrid 03.12.2023', 2);
        (new MovieService)->processMovieReleases();
        foreach (DB::table('releases')->get() as $release) {
            $this->assertSame('', $release->imdbid);
            $this->assertSame(Category::MOVIE_HD, $release->categories_id);
        }
    }

    #[Test]
    public function an_empty_omdb_response_is_a_terminal_no_film_result(): void
    {
        $this->mock(ImdbScraper::class)->shouldReceive('search')->andThrow(new \RuntimeException('offline'));
        $this->release('Con.Air.1997.1080p.BluRay.x264-GRP');
        $client = \Mockery::mock(OMDbAPI::class);
        $client->shouldReceive('search')->with('Con Air', 'movie', '1997')->once()->andReturn((object) [
            'message' => 'OK', 'data' => (object) ['Response' => 'False', 'Error' => 'Movie not found!'],
        ]);
        $client->shouldReceive('search')->with('Con Air', 'movie')->once()->andReturn((object) [
            'message' => 'OK', 'data' => (object) ['Response' => 'False', 'Error' => 'Movie not found!'],
        ]);
        $service = new MovieService;
        $service->omdbapikey = 'test';
        $service->omdbApi = $client;
        $service->processMovieReleases();
        $this->assertSame('', DB::table('releases')->find(1)->imdbid);
        $this->assertNull(DB::table('releases')->find(1)->imdb_lookup_attempts);
    }

    #[Test]
    public function no_film_handoff_binds_a_show_but_preserves_an_unmatched_release(): void
    {
        $scraper = $this->mock(ImdbScraper::class);
        $scraper->shouldReceive('search')->andReturn([]);
        $scraper->shouldReceive('wasSearchUnavailable')->andReturnFalse();
        $this->release('Sterling Point (2026)');
        $this->release('Missing Show (2026)', 2);
        DB::table('videos')->insert(['id' => 10, 'title' => 'Sterling Point', 'started' => '2026-01-01']);
        (new MovieService)->processMovieReleases();
        $this->assertSame(10, DB::table('releases')->find(1)->videos_id);
        $this->assertSame(Category::TV_OTHER, DB::table('releases')->find(1)->categories_id);
        $this->assertSame('', DB::table('releases')->find(1)->imdbid);
        $missing = DB::table('releases')->find(2);
        $this->assertSame('', $missing->imdbid);
        $this->assertSame(Category::MOVIE_HD, $missing->categories_id);
        $this->assertSame(0, $missing->videos_id);
        $this->assertSame(0, $missing->tv_episodes_id);
    }

    private function release(string $name, int $id = 1): void
    {
        DB::table('releases')->insert(['id' => $id, 'searchname' => $name, 'postdate' => now()]);
    }

    private function seriesResponse(): void
    {
        $scraper = $this->mock(ImdbScraper::class);
        $scraper->shouldReceive('search')->andReturn([['imdbid' => '1234567', 'title' => 'Sterling Point', 'year' => '2026', 'type' => 'tvSeries']]);
        $scraper->shouldReceive('wasSearchUnavailable')->andReturnFalse();
    }
}
