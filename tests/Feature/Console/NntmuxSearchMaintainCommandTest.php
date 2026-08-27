<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Support\ReleaseSearchIndexDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;

final class NntmuxSearchMaintainCommandTest extends SearchConsoleCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProjectionSchema();

        Cache::forget('search:release-index-reconcile:cursor');
    }

    public function test_bounded_slice_repairs_stale_and_missing_documents_and_deletes_orphans(): void
    {
        // ReleaseFactory requires the full production schema and fires search observers;
        // this fixture intentionally exercises the projection against a minimal schema.
        DB::table('releases')->insert([
            [
                'id' => 1,
                'guid' => 'release-one',
                'name' => 'Release.One',
                'searchname' => 'Release One',
                'fromname' => 'poster',
                'categories_id' => 0,
                'groups_id' => 0,
                'size' => 100,
                'postdate' => '2026-01-01 00:00:00',
                'adddate' => '2026-01-01 00:01:00',
                'totalpart' => 10,
                'grabs' => 0,
                'comments' => 0,
                'passwordstatus' => -1,
                'nzbstatus' => 1,
                'nfostatus' => -1,
                'haspreview' => -1,
                'jpgstatus' => 0,
                'videos_id' => 0,
                'tv_episodes_id' => 0,
                'movieinfo_id' => 0,
                'imdbid' => '',
                'anidbid' => 0,
            ],
            [
                'id' => 2,
                'guid' => 'release-two',
                'name' => 'Release.Two',
                'searchname' => 'Release Two',
                'fromname' => 'poster',
                'categories_id' => 0,
                'groups_id' => 0,
                'size' => 200,
                'postdate' => '2026-01-02 00:00:00',
                'adddate' => '2026-01-02 00:01:00',
                'totalpart' => 20,
                'grabs' => 0,
                'comments' => 0,
                'passwordstatus' => 0,
                'nzbstatus' => 1,
                'nfostatus' => 1,
                'haspreview' => 1,
                'jpgstatus' => 0,
                'videos_id' => 0,
                'tv_episodes_id' => 0,
                'movieinfo_id' => 0,
                'imdbid' => '',
                'anidbid' => 0,
            ],
        ]);

        $storedOne = ReleaseIndexProjection::forId(1);
        $this->assertNotNull($storedOne);
        $staleOne = $storedOne;
        $staleOne['searchname'] = 'Stale title';

        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('releaseDocumentsAfterId')
            ->once()
            ->with(0, 3)
            ->andReturn([
                1 => $staleOne,
                3 => ReleaseSearchIndexDocument::normalizeForReconciliation([
                    'id' => 3,
                    'searchname' => 'Orphan',
                ]),
            ]);
        $this->app->instance(SearchDriverInterface::class, $driver);

        Search::shouldReceive('updateRelease')->once()->with(1);
        Search::shouldReceive('updateRelease')->once()->with(2);
        Search::shouldReceive('deleteReleases')->once()->with([3]);

        config(['search.reconciliation.batch_size' => 3]);

        $this->artisan('nntmux:search-maintain')->assertSuccessful();

        $this->assertSame(3, Cache::get('search:release-index-reconcile:cursor'));
    }

    public function test_cursor_wraps_after_both_database_and_index_reach_the_end(): void
    {
        Cache::forever('search:release-index-reconcile:cursor', 50);

        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('releaseDocumentsAfterId')
            ->once()
            ->with(50, 5)
            ->andReturn([]);
        $this->app->instance(SearchDriverInterface::class, $driver);

        Search::spy();
        config(['search.reconciliation.batch_size' => 5]);

        $this->artisan('nntmux:search-maintain')->assertSuccessful();

        $this->assertSame(0, Cache::get('search:release-index-reconcile:cursor'));
        Search::shouldNotHaveReceived('updateRelease');
        Search::shouldNotHaveReceived('deleteReleases');
    }

    public function test_driver_failure_keeps_the_cursor_in_place_for_the_next_run(): void
    {
        Cache::forever('search:release-index-reconcile:cursor', 9);

        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('releaseDocumentsAfterId')
            ->once()
            ->with(9, 5)
            ->andThrow(new \RuntimeException('index unavailable'));
        $this->app->instance(SearchDriverInterface::class, $driver);

        config(['search.reconciliation.batch_size' => 5]);

        $this->artisan('nntmux:search-maintain')->assertFailed();

        $this->assertSame(9, Cache::get('search:release-index-reconcile:cursor'));
    }

    private function createProjectionSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('guid');
            $table->string('name');
            $table->string('searchname');
            $table->float('completion')->default(0);
            $table->string('repair_outcome')->nullable();
            $table->string('rescan_outcome')->nullable();
            $table->string('fromname');
            $table->unsignedBigInteger('categories_id');
            $table->unsignedBigInteger('groups_id');
            $table->unsignedBigInteger('size');
            $table->dateTime('postdate');
            $table->dateTime('adddate');
            $table->integer('totalpart');
            $table->integer('grabs');
            $table->integer('comments');
            $table->integer('passwordstatus');
            $table->integer('nzbstatus');
            $table->integer('nfostatus');
            $table->integer('haspreview');
            $table->integer('jpgstatus');
            $table->unsignedBigInteger('videos_id');
            $table->unsignedBigInteger('tv_episodes_id');
            $table->unsignedBigInteger('movieinfo_id');
            $table->string('imdbid');
            $table->unsignedBigInteger('anidbid');
        });

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('root_categories_id');
            $table->string('title');
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tmdbid');
            $table->unsignedBigInteger('traktid');
        });
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tvdb');
            $table->unsignedBigInteger('tvmaze');
            $table->unsignedBigInteger('tvrage');
            $table->unsignedBigInteger('trakt');
            $table->string('imdb');
            $table->unsignedBigInteger('tmdb');
        });
        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->integer('series');
            $table->integer('episode');
            $table->dateTime('firstaired')->nullable();
        });
        Schema::create('release_nfos', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
        });
        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
            $table->string('containerformat')->nullable();
            $table->string('videoformat')->nullable();
            $table->string('videocodec')->nullable();
            $table->integer('videowidth')->nullable();
            $table->integer('videoheight')->nullable();
        });
        Schema::create('media_infos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->string('movie_name')->nullable();
            $table->string('file_name')->nullable();
            $table->string('unique_id')->nullable();
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
            $table->string('name');
        });
        Schema::create('audio_data', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
            $table->string('audioformat')->nullable();
            $table->string('audiochannels')->nullable();
            $table->string('audiolanguage')->nullable();
        });
        Schema::create('release_subtitles', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
            $table->string('subslanguage')->nullable();
        });
    }
}
