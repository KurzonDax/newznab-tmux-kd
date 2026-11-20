<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\TvEpisodeExistenceResolver;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvReleaseContext;
use App\Services\TvProcessing\TvShowDetails;
use App\Services\TvProcessing\TvShowResolution;
use CanIHaveSomeCoffee\TheTVDbAPI\Route\RouteFactory;
use Database\Factories\VideoFactory;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class EpisodeAwareVideoTitleLookupTest extends TestCase
{
    private int $tvdbRequests = 0;

    private int $tvdbDownloads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->tinyInteger('type')->default(0);
            $table->string('title');
            $table->dateTime('started');
            $table->tinyInteger('source')->default(0);
            $table->unsignedInteger('tvdb')->default(0);
            $table->unsignedInteger('tvmaze')->default(0);
            $table->unsignedInteger('tmdb')->default(0);
            $table->unsignedInteger('trakt')->default(0);
        });
        ProductionTables::fromAuthority()->create('videos_aliases', ['videos_id', 'title']);
        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->unsignedInteger('series');
            $table->unsignedInteger('episode');
            $table->string('se_complete')->default('');
            $table->string('title')->default('');
            $table->string('firstaired')->default('');
            $table->text('summary')->default('');
            $table->unique(['videos_id', 'series', 'episode', 'firstaired']);
        });
        ProductionTables::fromAuthority()->create('tv_info', ['videos_id', 'status']);

        // The TVDB library keeps its route objects, and with them the first client, in a static.
        (new \ReflectionProperty(RouteFactory::class, 'routeInstances'))->setValue(null, []);
        Cache::flush();
        Carbon::setTestNow('2026-11-20 12:00:00');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'nntmux.echocli' => false,
            'nntmux.tv_episode_revisit_window_days' => 14,
            'tvdb.api_key' => 'tvdb-key',
            'tvdb.user_pin' => 'pin',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_episode_title_self_heals_a_same_titled_show_binding(): void
    {
        $ukVideoId = $this->createVideo('The Office', '2001-07-09');
        $usVideoId = $this->createVideo('The Office (US)', '2005-03-24');
        $this->createEpisode($ukVideoId, 1, 1, 'Downsize');
        $this->createEpisode($ukVideoId, 2, 1, 'Merger');
        $usEpisodeId = $this->createEpisode($usVideoId, 5, 12, 'Prince Family Paper');

        $result = $this->processThroughLocalDb(
            'The.Office.S05E12.Prince.Family.Paper.720p.HDTV.x264',
            currentVideoId: $ukVideoId,
            expectedVideoId: $usVideoId,
            expectedEpisodeId: $usEpisodeId,
        );

        $this->assertSame($usVideoId, $result->result->videoId);
        $this->assertSame($usEpisodeId, $result->result->episodeId);
        $this->assertSame('Prince Family Paper', $result->parsedInfo['episode_title'] ?? null);
    }

    #[Test]
    public function unique_episode_existence_does_not_select_a_same_titled_show_without_an_episode_title(): void
    {
        $documentaryVideoId = $this->createVideo('Yellowstone', '2009-03-15');
        $dramaVideoId = $this->createVideo('Yellowstone (2018)', '2018-06-20');
        $this->createEpisode($documentaryVideoId, 1, 1, 'Winter');
        $this->createEpisode($dramaVideoId, 2, 8, 'Behind Us Only Grey');

        $result = $this->processThroughLocalDb(
            'Yellowstone.S02E08.1080p.WEB-DL.DD5.1.H264',
        );

        $this->assertNull($result->result->videoId);
        $this->assertTrue($result->isAmbiguous());
        $this->assertSame('', $result->parsedInfo['episode_title'] ?? null);
    }

    #[Test]
    public function shared_episode_existence_without_a_title_defers_instead_of_using_base_title_precedence(): void
    {
        $baseVideoId = $this->createVideo('Shared Show', '2001-01-01');
        $siblingVideoId = $this->createVideo('Shared Show (US)', '2020-01-01');
        $this->createEpisode($baseVideoId, 1, 1, 'Pilot');
        $this->createEpisode($siblingVideoId, 1, 1, 'Pilot');

        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('Shared.Show.S01E01.1080p.WEB-DL');

        $this->assertIsArray($showInfo);
        $this->assertSame(0, $provider->getByRelease($showInfo, 0));
    }

    #[Test]
    public function episode_title_similarity_below_the_threshold_defers_instead_of_using_current_precedence(): void
    {
        $baseVideoId = $this->createVideo('Castle', '2003-01-01');
        $modernVideoId = $this->createVideo('Castle (2009)', '2009-03-09');
        $this->createEpisode($baseVideoId, 8, 3, 'Completely Unrelated');
        $this->createEpisode($modernVideoId, 8, 3, 'Another Different Name');

        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('Castle.S08E03.PhDead.720p.HDTV.x264');

        $this->assertIsArray($showInfo);
        $this->assertSame('PhDead', $showInfo['episode_title'] ?? null);
        $this->assertSame(0, $provider->getByRelease($showInfo, 0));
    }

    #[Test]
    public function episode_evidence_compares_candidates_reached_through_aliases(): void
    {
        $ukVideoId = $this->createVideo('Office UK Official', '2001-07-09');
        $usVideoId = $this->createVideo('Office US Official', '2005-03-24');
        DB::table('videos_aliases')->insert([
            ['videos_id' => $ukVideoId, 'title' => 'The Office'],
            ['videos_id' => $usVideoId, 'title' => 'The Office (US)'],
        ]);
        $this->createEpisode($ukVideoId, 2, 1, 'Merger');
        $this->createEpisode($usVideoId, 5, 12, 'Prince Family Paper');

        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('The.Office.S05E12.Prince.Family.Paper.720p.HDTV.x264');

        $this->assertIsArray($showInfo);
        $this->assertSame($usVideoId, $provider->getByRelease($showInfo, 0));
    }

    #[Test]
    public function a_show_alone_with_the_episode_is_chosen_when_the_alias_show_list_ends_seasons_earlier(): void
    {
        [$friendsVideoId] = $this->createFriendsShows();
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 8));

        $resolution = $this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL.DDP5.1.DV.HDR.x265-PiA');

        $this->assertFalse($resolution->isAmbiguous());
        $this->assertSame($friendsVideoId, $resolution->videoId);
        $this->assertSame(1, $this->tvdbDownloads);
    }

    #[Test]
    public function the_season_after_the_alias_show_list_decides_only_when_the_alias_show_is_finished(): void
    {
        [$friendsVideoId, $aliasVideoId] = $this->createFriendsShows();
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 8));

        $this->assertTrue($this->resolveRelease('Friends.S02E05.1080p.WEB-DL')->isAmbiguous());

        DB::table('tv_info')->insert(['videos_id' => $aliasVideoId, 'status' => TvShowDetails::STATUS_ENDED]);
        $resolution = $this->resolveRelease('Friends.S02E05.1080p.WEB-DL');

        $this->assertSame($friendsVideoId, $resolution->videoId);
        $this->assertSame(1, $this->tvdbDownloads);
    }

    #[Test]
    public function a_listed_season_without_the_episode_number_stays_ambiguous(): void
    {
        $this->createFriendsShows();
        $this->fakeTvdbEpisodes([...$this->tvdbSeason(1, 8), ...$this->tvdbSeason(10, 12)]);

        $this->assertTrue($this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL')->isAmbiguous());
    }

    #[Test]
    public function an_episode_the_provider_lists_but_is_never_stored_still_counts_as_listed(): void
    {
        [, $aliasVideoId] = $this->createFriendsShows();
        $unstorable = $this->tvdbEpisode(10, 13);
        $unstorable['overview'] = null;
        $this->fakeTvdbEpisodes([...$this->tvdbSeason(1, 8), $unstorable]);

        $this->assertTrue($this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL')->isAmbiguous());
        $this->assertSame(8, DB::table('tv_episodes')->where('videos_id', $aliasVideoId)->count());
        $this->assertFalse(DB::table('tv_episodes')->where('videos_id', $aliasVideoId)->where('series', 10)->exists());
    }

    #[Test]
    public function a_season_below_the_newest_listed_one_stays_ambiguous_although_the_list_lacks_it(): void
    {
        [$friendsVideoId] = $this->createFriendsShows();
        $this->createEpisode($friendsVideoId, 5, 3, 'The One with the Thumb');
        $this->fakeTvdbEpisodes([...$this->tvdbSeason(18, 3), ...$this->tvdbSeason(19, 3), ...$this->tvdbSeason(20, 3)]);

        $this->assertTrue($this->resolveRelease('Friends.S05E03.1080p.WEB-DL')->isAmbiguous());
    }

    #[Test]
    public function a_list_without_an_episode_in_the_release_numbering_scheme_stays_ambiguous(): void
    {
        $this->createFriendsShows();
        $this->fakeTvdbEpisodes([$this->tvdbEpisode(2013, 1), $this->tvdbEpisode(2020, 4), $this->tvdbEpisode(2026, 19)]);

        $this->assertTrue($this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL')->isAmbiguous());
    }

    #[Test]
    public function an_unavailable_download_stays_ambiguous_is_not_cached_and_is_not_repeated_in_the_process(): void
    {
        $this->createFriendsShows();
        $this->fakeTvdbEpisodes(null);

        $this->assertTrue($this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL')->isAmbiguous());
        $this->assertSame(ProviderOutcome::Unavailable, app(ProviderGateway::class)->lastOutcome('tvdb'));
        $downloads = $this->tvdbDownloads;
        $this->assertGreaterThan(0, $downloads);

        $this->assertTrue($this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL', releaseId: 100)->isAmbiguous());
        $this->assertSame($downloads, $this->tvdbDownloads, 'the same process does not ask again');

        $this->startNewProcess();
        $this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL', releaseId: 101);
        $this->assertGreaterThan($downloads, $this->tvdbDownloads, 'nothing was cached for other processes');
    }

    #[Test]
    public function a_tmdb_list_with_an_unanswered_season_is_incomplete_and_not_cached(): void
    {
        config(['tmdb.api_key' => 'tmdb-key', 'tmdb.retry_times' => 1, 'tmdb.retry_delay' => 0]);
        $friendsVideoId = $this->createVideo('Friends', '1994-09-22');
        $this->createEpisode($friendsVideoId, 10, 13, 'The One Where Joey Speaks French');
        $aliasVideoId = $this->createVideo('프렌즈', '2021-01-01', source: 3, providerIds: ['tmdb' => 300]);
        DB::table('videos_aliases')->insert(['videos_id' => $aliasVideoId, 'title' => 'Friends']);
        Http::fake([
            '*tv/300/season/1*' => Http::response(['episodes' => array_map(
                static fn (int $number): array => [
                    'name' => 'Episode '.$number, 'season_number' => 1, 'episode_number' => $number,
                    'air_date' => '2021-01-0'.$number, 'overview' => 'Overview',
                ],
                range(1, 3),
            )]),
            '*tv/300/season/2*' => Http::response(['status_message' => 'not found'], 404),
            '*tv/300?*' => Http::response(['id' => 300, 'seasons' => [['season_number' => 1], ['season_number' => 2]]]),
        ]);

        $this->assertTrue($this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL')->isAmbiguous());
        $this->assertSame(3, DB::table('tv_episodes')->where('videos_id', $aliasVideoId)->where('series', 1)->count());
        $showRequests = static fn (): int => count(Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), '/tv/300?'),
        ));
        $this->assertSame(1, $showRequests());

        $this->startNewProcess();
        $this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL', releaseId: 100);
        $this->assertSame(2, $showRequests(), 'nothing was cached');
    }

    #[Test]
    public function an_alias_show_without_a_provider_source_is_never_fetched(): void
    {
        $this->createFriendsShows(aliasSource: 0);
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 8));

        $this->assertTrue($this->resolveRelease('Friends.S10E13.2160p.MAX.WEB-DL')->isAmbiguous());
        $this->assertSame(0, $this->tvdbRequests);
    }

    #[Test]
    public function the_episode_decides_nothing_unless_exactly_one_candidate_has_it_stored(): void
    {
        [$friendsVideoId, $aliasVideoId] = $this->createFriendsShows();
        $this->createEpisode($friendsVideoId, 2, 3, 'The One Where Heckles Dies');
        $this->createEpisode($aliasVideoId, 2, 3, 'Episode 3');
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 8));

        $this->assertTrue($this->resolveRelease('Friends.S02E03.1080p.WEB-DL')->isAmbiguous());
        $this->assertTrue($this->resolveRelease('Friends.S07E21.1080p.WEB-DL')->isAmbiguous());
        $this->assertSame(0, $this->tvdbRequests);
    }

    #[Test]
    public function a_finished_documentary_ending_a_season_earlier_leaves_the_drama_with_the_episode(): void
    {
        $documentaryVideoId = $this->createVideo('Yellowstone', '2009-03-15', source: 1, providerIds: ['tvdb' => 500]);
        DB::table('tv_info')->insert(['videos_id' => $documentaryVideoId, 'status' => TvShowDetails::STATUS_ENDED]);
        $dramaVideoId = $this->createVideo('Yellowstone (2018)', '2018-06-20');
        $this->createEpisode($dramaVideoId, 2, 8, 'Behind Us Only Grey');
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 6, year: 2009));

        $resolution = $this->resolveRelease('Yellowstone.S02E08.1080p.WEB-DL.DD5.1.H264');

        $this->assertSame($dramaVideoId, $resolution->videoId);
    }

    #[Test]
    public function a_complete_list_is_downloaded_once_for_every_release_that_needs_it_until_a_day_passes(): void
    {
        [$friendsVideoId] = $this->createFriendsShows();
        $this->createEpisode($friendsVideoId, 9, 1, 'The One Where No One Proposes');
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 8));

        $this->assertSame($friendsVideoId, $this->resolveRelease('Friends.S10E13.1080p.WEB-DL')->videoId);
        $this->startNewProcess();
        $this->assertSame($friendsVideoId, $this->resolveRelease('Friends.S09E01.1080p.WEB-DL', releaseId: 100)->videoId);
        $this->assertSame(1, $this->tvdbDownloads);

        Carbon::setTestNow(now()->addHours(24)->addSecond());
        $this->resolveRelease('Friends.S10E13.1080p.WEB-DL', releaseId: 101);
        $this->assertSame(2, $this->tvdbDownloads);
    }

    #[Test]
    public function a_complete_empty_list_is_cached_and_decides_nothing(): void
    {
        $this->createFriendsShows();
        $this->fakeTvdbEpisodes([]);

        $this->assertTrue($this->resolveRelease('Friends.S10E13.1080p.WEB-DL')->isAmbiguous());
        $this->startNewProcess();
        $this->assertTrue($this->resolveRelease('Friends.S02E05.1080p.WEB-DL', releaseId: 100)->isAmbiguous());
        $this->assertSame(1, $this->tvdbDownloads);
    }

    #[Test]
    public function a_lookup_without_a_release_id_never_uses_the_episode(): void
    {
        $this->createFriendsShows();
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 8));
        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('Friends.S10E13.2160p.MAX.WEB-DL');

        $this->assertIsArray($showInfo);
        $this->assertSame(0, $provider->getByRelease($showInfo, 0));
        $this->assertSame(0, $this->tvdbRequests);
    }

    #[Test]
    public function the_live_local_db_stage_binds_the_show_alone_with_the_episode(): void
    {
        Event::fake();
        ProductionTables::fromAuthority()->create('releases', [
            'id', 'searchname', 'videos_id', 'tv_episodes_id', 'tv_episode_lookup_attempted_at', 'postdate',
        ]);
        [$friendsVideoId] = $this->createFriendsShows();
        $episodeId = (int) DB::table('tv_episodes')->where('videos_id', $friendsVideoId)->where('series', 10)->value('id');
        DB::table('releases')->insert([
            'id' => 3908579, 'searchname' => 'Friends.S10E13.2160p.MAX.WEB-DL.DDP5.1.DV.HDR.x265-PiA',
            'videos_id' => 0, 'tv_episodes_id' => 0, 'postdate' => now()->subDay(),
        ]);
        $this->fakeTvdbEpisodes($this->tvdbSeason(1, 8));
        $provider = new class extends LocalDbProvider
        {
            public function __construct()
            {
                $this->echooutput = false;
            }

            public function getTvReleases(string $groupID = '', string $guidChar = '', int $lookupSetting = 1, int $status = 0): array|\Illuminate\Database\Eloquent\Collection|int|Collection
            {
                return DB::table('releases')->get(['id', 'searchname', 'videos_id']);
            }
        };

        $provider->processSite('', '', 1);

        $release = DB::table('releases')->find(3908579);
        $this->assertSame($friendsVideoId, (int) $release->videos_id);
        $this->assertSame($episodeId, (int) $release->tv_episodes_id);
        $this->assertNull($release->tv_episode_lookup_attempted_at, 'an ambiguous settlement would have paced it');
    }

    /**
     * Friends (1994) with S10E13 and S02E05 stored, and the alias show 프렌즈 known as "Friends".
     *
     * @return array{int, int}
     */
    private function createFriendsShows(int $aliasSource = 1): array
    {
        $friendsVideoId = $this->createVideo('Friends', '1994-09-22');
        $this->createEpisode($friendsVideoId, 10, 13, 'The One Where Joey Speaks French');
        $this->createEpisode($friendsVideoId, 2, 5, 'The One with Five Steaks and an Eggplant');
        $aliasVideoId = $this->createVideo('프렌즈', '2021-01-01', source: $aliasSource, providerIds: ['tvdb' => 396947]);
        DB::table('videos_aliases')->insert(['videos_id' => $aliasVideoId, 'title' => 'Friends']);

        return [$friendsVideoId, $aliasVideoId];
    }

    /**
     * Answers TVDB's login, and its episode list with these episodes or, when null, a 503.
     *
     * @param  list<array<string, mixed>>|null  $episodes
     */
    private function fakeTvdbEpisodes(?array $episodes): void
    {
        $this->tvdbRequests = 0;
        $this->tvdbDownloads = 0;
        app(ProviderGateway::class)->useHandler('tvdb', function (RequestInterface $request) use ($episodes): PromiseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/login')) {
                return Create::promiseFor(new Response(200, [], (string) json_encode(['data' => ['token' => 'token']])));
            }

            $this->tvdbRequests++;
            if (str_contains($request->getUri()->getPath(), '/episodes/')) {
                $this->tvdbDownloads++;
            }

            return Create::promiseFor($episodes === null
                ? new Response(503)
                : new Response(200, [], (string) json_encode(['data' => ['episodes' => $episodes]])));
        });
    }

    /** @return list<array<string, mixed>> */
    private function tvdbSeason(int $season, int $episodes, int $year = 2021): array
    {
        return array_map(fn (int $number): array => $this->tvdbEpisode($season, $number, $year), range(1, $episodes));
    }

    /** @return array<string, mixed> */
    private function tvdbEpisode(int $season, int $number, int $year = 2021): array
    {
        return [
            'id' => $season * 1000 + $number,
            'seasonNumber' => $season,
            'number' => $number,
            'name' => 'Episode '.$number,
            'aired' => sprintf('%d-01-%02d', $year, min($number, 28)),
            'overview' => 'Overview',
        ];
    }

    private function resolveRelease(string $searchName, int $releaseId = 99): TvShowResolution
    {
        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo($searchName);
        $this->assertIsArray($showInfo);

        return $provider->resolveByRelease($showInfo, 0, releaseId: $releaseId);
    }

    /** Drops what a process keeps in memory; the application cache stays. */
    private function startNewProcess(): void
    {
        app()->forgetInstance(TvEpisodeExistenceResolver::class);
    }

    private function processThroughLocalDb(
        string $searchName,
        int $currentVideoId = 0,
        ?int $expectedVideoId = null,
        int $expectedEpisodeId = 0,
    ): TvProcessingPassable {
        $provider = Mockery::mock(LocalDbProvider::class)->makePartial();
        if ($expectedVideoId === null) {
            $provider->shouldNotReceive('setVideoIdFound');
        } else {
            $provider->shouldReceive('setVideoIdFound')
                ->once()
                ->with($expectedVideoId, 99, $expectedEpisodeId);
        }

        $showInfo = $provider->parseInfo($searchName);
        $this->assertIsArray($showInfo);

        $pipe = (new LocalDbPipe)->setEchoOutput(false);
        (new \ReflectionProperty(LocalDbPipe::class, 'localDb'))->setValue($pipe, $provider);

        $passable = new TvProcessingPassable(
            new TvReleaseContext(99, $searchName, 1, 5000, videosId: $currentVideoId),
        );
        $passable->setParsedInfo($showInfo);

        return $pipe->handle(
            $passable,
            static fn (TvProcessingPassable $handled): TvProcessingPassable => $handled,
        );
    }

    /**
     * @param  array<string, int>  $providerIds
     */
    private function createVideo(string $title, string $started, int $source = 0, array $providerIds = []): int
    {
        $video = VideoFactory::new()->make([
            'title' => $title,
            'type' => 0,
            'started' => $started,
            'source' => $source,
            ...$providerIds,
        ]);
        $video->saveQuietly();

        return (int) $video->getKey();
    }

    private function createEpisode(int $videoId, int $series, int $episode, string $title): int
    {
        return (int) DB::table('tv_episodes')->insertGetId([
            'videos_id' => $videoId,
            'series' => $series,
            'episode' => $episode,
            'title' => $title,
            'firstaired' => '2020-01-01',
        ]);
    }
}
