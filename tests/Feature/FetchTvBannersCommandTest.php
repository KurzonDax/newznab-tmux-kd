<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ReleaseImageService;
use App\Services\TvBannerService;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvReleaseContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

final class FetchTvBannersCommandTest extends TestCase
{
    private string $coversRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux_api.fanarttv_api_key' => 'test-api-key',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('tvdb')->default(0);
        });
        Schema::create('tv_info', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id')->primary();
            $table->boolean('image')->default(false);
            $table->boolean('banner')->default(false);
        });

        $this->coversRoot = $this->makeTempDirectory('tv-banner-command-covers');
        config(['nntmux_settings.covers_path' => $this->coversRoot]);
        $this->app->instance(
            ReleaseImageService::class,
            new ReleaseImageService(static fn (string $host): array => ['93.184.216.34']),
        );
    }

    public function test_fetches_only_missing_banners_and_respects_limit(): void
    {
        $firstMissingId = $this->createShow('First Missing', 1001);
        $secondMissingId = $this->createShow('Second Missing', 1002);
        $existingId = $this->createShow('Existing Banner', 1003, true);
        $this->fakeFanartAndImageResponses();

        $this->artisan('tv:fetch-banners', ['--limit' => 1, '--delay' => 0])
            ->assertSuccessful();

        $this->assertSame(1, (int) DB::table('tv_info')->where('videos_id', $firstMissingId)->value('banner'));
        $this->assertSame(0, (int) DB::table('tv_info')->where('videos_id', $secondMissingId)->value('banner'));
        $this->assertSame(1, (int) DB::table('tv_info')->where('videos_id', $existingId)->value('banner'));
        $this->assertNotNull(resolveImageAssetFilename('tvshows', $firstMissingId.'-banner'));
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/tv/1001'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/tv/1002') || str_contains($request->url(), '/tv/1003'));
    }

    public function test_dry_run_does_not_fetch_or_change_banner_flags(): void
    {
        $videoId = $this->createShow('Dry Run Show', 2001);
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('tv:fetch-banners', ['--dry-run' => true, '--limit' => 1])
            ->expectsOutputToContain('Dry run: 1 show(s)')
            ->assertSuccessful();

        $this->assertSame(0, (int) DB::table('tv_info')->where('videos_id', $videoId)->value('banner'));
        Http::assertNothingSent();
    }

    public function test_successful_fetch_is_a_no_op_when_the_command_is_rerun(): void
    {
        $videoId = $this->createShow('Idempotent Show', 3001);
        $this->fakeFanartAndImageResponses();

        $this->artisan('tv:fetch-banners', ['--delay' => 0])->assertSuccessful();
        $this->assertSame(1, (int) DB::table('tv_info')->where('videos_id', $videoId)->value('banner'));

        $this->fakeFanartAndImageResponses();
        $this->artisan('tv:fetch-banners', ['--delay' => 0])
            ->expectsOutputToContain('Inspected 0 show(s)')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_new_tvdb_pipeline_match_fetches_and_marks_the_banner(): void
    {
        $videoId = $this->createShow('New Match', 4001);
        $this->fakeFanartAndImageResponses();

        $provider = Mockery::mock(TvdbProvider::class);
        $provider->shouldReceive('getByTitle')->once()->with('New Match', 0)->andReturn(0);
        $provider->shouldReceive('getShowInfo')->once()->with('New Match')->andReturn([
            'tvdb' => 4001,
            'poster' => 'https://assets.example/poster.png',
        ]);
        $provider->shouldReceive('add')->once()->andReturn($videoId);
        $provider->shouldReceive('getPoster')->once()->with($videoId)->andReturn(1);
        $provider->shouldReceive('getBanner')->once()->with($videoId, 4001)->andReturnUsing(
            fn (): bool => $this->app->make(TvBannerService::class)->fetch($videoId, 4001),
        );
        $provider->shouldReceive('setVideoIdFound')->once()->with($videoId, 99, 0);

        $pipe = (new TvdbPipe)->setEchoOutput(false);
        $providerProperty = new ReflectionProperty(TvdbPipe::class, 'tvdb');
        $providerProperty->setValue($pipe, $provider);

        $passable = new TvProcessingPassable(new TvReleaseContext(99, 'New.Match.S01-GROUP', 1, 5000));
        $passable->setParsedInfo([
            'name' => 'New Match',
            'cleanname' => 'New Match',
            'season' => 'S01',
            'episode' => 'all',
        ]);

        $result = $pipe->handle($passable, static fn (TvProcessingPassable $handled): TvProcessingPassable => $handled);

        $this->assertTrue($result->result->isMatched());
        $this->assertSame(1, (int) DB::table('tv_info')->where('videos_id', $videoId)->value('banner'));
        $this->assertNotNull(resolveImageAssetFilename('tvshows', $videoId.'-banner'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/tv/4001'));
    }

    private function createShow(string $title, int $tvdbId, bool $hasBanner = false): int
    {
        $videoId = DB::table('videos')->insertGetId([
            'title' => $title,
            'tvdb' => $tvdbId,
        ]);

        DB::table('tv_info')->insert([
            'videos_id' => $videoId,
            'image' => false,
            'banner' => $hasBanner,
        ]);

        return (int) $videoId;
    }

    private function fakeFanartAndImageResponses(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);

        Http::preventStrayRequests();
        Http::fake(static function (Request $request) use ($png) {
            if (str_contains($request->url(), 'webservice.fanart.tv/v3/tv/')) {
                $tvdbId = basename((string) parse_url($request->url(), PHP_URL_PATH));

                return Http::response([
                    'name' => 'Fixture Show',
                    'tvbanner' => [[
                        'url' => 'https://assets.example/'.$tvdbId.'-banner.png',
                        'likes' => '5',
                    ]],
                ]);
            }

            if (str_contains($request->url(), 'assets.example/')) {
                return Http::response($png, 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) strlen($png),
                ]);
            }

            return Http::response([], 404);
        });
    }
}
