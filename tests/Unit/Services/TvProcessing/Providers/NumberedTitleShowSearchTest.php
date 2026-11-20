<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TvProcessing\Providers;

use App\Services\FanartTvService;
use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Services\TvProcessing\Pipes\AbstractTvProviderPipe;
use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Services\TvProcessing\Pipes\TraktPipe;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Services\TvProcessing\TvEpisodeRevisitService;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvReleaseContext;
use CanIHaveSomeCoffee\TheTVDbAPI\Route\SearchRoute;
use CanIHaveSomeCoffee\TheTVDbAPI\TheTVDbAPI;
use DariusIII\TVMaze\TVMaze as TvMazeClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A release whose show title carries a year ("Sealab 2021") is searched once more under that numbered
 * title when the provider's search for its clean name ("Sealab (2021)") finds no show.
 */
final class NumberedTitleShowSearchTest extends TestCase
{
    private const SEASON_PACK = 'Sealab.2021.S04.iNTERNAL.DVDRip.XviD-VCDVaULT';

    private const NO_NUMBERED_TITLE = 'The.Mentalist.S01E01.2008.1080p.Amazon.WEB-DL.AVC.DDP.5.1-4X4RYA-DBTV';

    public const ADDED_VIDEO_ID = 77;

    private const RELEASE_ID = 99;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nntmux_api.fanarttv_api_key' => '']);
        // The provider gateway paces TVMaze requests; these searches are faked.
        Sleep::fake();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providers(): iterable
    {
        yield 'TVDB' => ['tvdb'];
        yield 'TVMaze' => ['tvmaze'];
        yield 'TMDB' => ['tmdb'];
        yield 'Trakt' => ['trakt'];
    }

    #[DataProvider('providers')]
    #[Test]
    public function the_live_provider_binds_a_show_found_under_the_numbered_title(string $source): void
    {
        $provider = $this->provider($source, [['Sealab 2021', '2000-12-21']]);

        $this->processSite($provider, self::SEASON_PACK);

        $this->assertSame([['Sealab (2021)', null], ['Sealab 2021', null]], $provider->searches);
        $this->assertSame(['Sealab 2021'], $provider->addedTitles);
        $this->assertSame([[self::ADDED_VIDEO_ID, self::RELEASE_ID, 0]], $provider->bound);
    }

    #[DataProvider('providers')]
    #[Test]
    public function the_live_provider_rejects_a_show_titled_without_the_number(string $source): void
    {
        $provider = $this->provider($source, [['Sealab', '2000-12-21']]);

        $this->processSite($provider, self::SEASON_PACK);

        $this->assertSame([['Sealab (2021)', null], ['Sealab 2021', null]], $provider->searches);
        $this->assertSame([], $provider->addedTitles);
        $this->assertSame([], $provider->bound);
    }

    #[DataProvider('providers')]
    #[Test]
    public function the_live_provider_does_not_search_again_when_the_first_search_found_a_show(string $source): void
    {
        $provider = $this->provider($source, [['Sealab', '2021-01-10']]);

        $this->processSite($provider, self::SEASON_PACK);

        $this->assertSame([['Sealab (2021)', null]], $provider->searches);
        $this->assertSame(['Sealab'], $provider->addedTitles);
    }

    #[DataProvider('providers')]
    #[Test]
    public function the_live_provider_does_not_search_again_without_a_numbered_title(string $source): void
    {
        $provider = $this->provider($source, []);

        $this->processSite($provider, self::NO_NUMBERED_TITLE);

        $this->assertSame([['The Mentalist (2008)', null]], $provider->searches);
    }

    #[DataProvider('providers')]
    #[Test]
    public function the_live_provider_does_not_search_the_numbered_title_for_a_known_show_revisit(string $source): void
    {
        // The final provider settles a revisit's miss through this service; keep that off the database.
        $this->app->instance(TvEpisodeRevisitService::class, new class
        {
            /**
             * @param  array<string, mixed>|null  $parsedInfo
             */
            public function settleFinalFailure(int $releaseId, bool $ambiguous = false, ?array $parsedInfo = null): void {}
        });
        $provider = $this->provider($source, [['Sealab 2021', '2000-12-21']]);

        $this->processSite($provider, self::SEASON_PACK, knownVideoId: 10);

        $this->assertNotContains('Sealab 2021', array_column($provider->searches, 0));
        $this->assertSame([], $provider->addedTitles);
    }

    #[DataProvider('providers')]
    #[Test]
    public function a_pipe_searches_the_numbered_title_after_the_year_stripped_retry(string $source): void
    {
        $provider = $this->provider($source, [['Sealab 2021', '2000-12-21']]);

        $result = $this->processPipe($source, $provider, self::SEASON_PACK);

        $this->assertSame([['Sealab (2021)', null], ['Sealab', 2021], ['Sealab 2021', null]], $provider->searches);
        $this->assertSame(['Sealab 2021'], $provider->addedTitles);
        $this->assertSame([[self::ADDED_VIDEO_ID, self::RELEASE_ID, 0]], $provider->bound);
        $this->assertTrue($result->isMatched());
    }

    #[DataProvider('providers')]
    #[Test]
    public function a_pipe_rejects_a_show_titled_without_the_number(string $source): void
    {
        $provider = $this->provider($source, [['Sealab', '2000-12-21']]);

        $result = $this->processPipe($source, $provider, self::SEASON_PACK);

        $this->assertSame([['Sealab (2021)', null], ['Sealab', 2021], ['Sealab 2021', null]], $provider->searches);
        $this->assertSame([], $provider->addedTitles);
        $this->assertFalse($result->isMatched());
    }

    #[DataProvider('providers')]
    #[Test]
    public function a_pipe_does_not_search_again_when_the_first_search_found_a_show(string $source): void
    {
        $provider = $this->provider($source, [['Sealab', '2021-01-10']]);

        $this->processPipe($source, $provider, self::SEASON_PACK);

        $this->assertSame([['Sealab (2021)', null]], $provider->searches);
        $this->assertSame(['Sealab'], $provider->addedTitles);
    }

    #[DataProvider('providers')]
    #[Test]
    public function a_pipe_does_not_search_again_without_a_numbered_title(string $source): void
    {
        $provider = $this->provider($source, []);

        $this->processPipe($source, $provider, self::NO_NUMBERED_TITLE);

        $this->assertSame([['The Mentalist (2008)', null], ['The Mentalist', 2008]], $provider->searches);
    }

    #[DataProvider('providers')]
    #[Test]
    public function a_pipe_does_not_search_the_numbered_title_for_a_known_show_revisit(string $source): void
    {
        $provider = $this->provider($source, [['Sealab 2021', '2000-12-21']]);

        $this->processPipe($source, $provider, self::SEASON_PACK, knownVideoId: 10);

        $this->assertNotContains('Sealab 2021', array_column($provider->searches, 0));
        $this->assertSame([], $provider->addedTitles);
    }

    private function processSite(AbstractTvProvider $provider, string $searchname, int $knownVideoId = 0): void
    {
        /** @var NumberedTitleTvdbProvider|NumberedTitleTvMazeProvider|NumberedTitleTmdbProvider|NumberedTitleTraktProvider $provider */
        $provider->releases = [['id' => self::RELEASE_ID, 'searchname' => $searchname, 'videos_id' => $knownVideoId]];
        $provider->processSite('', '', 1);
    }

    private function processPipe(string $source, AbstractTvProvider $provider, string $searchname, int $knownVideoId = 0): TvProcessingResult
    {
        $pipeClass = match ($source) {
            'tvdb' => TvdbPipe::class,
            'tvmaze' => TvMazePipe::class,
            'tmdb' => TmdbPipe::class,
            'trakt' => TraktPipe::class,
        };
        /** @var AbstractTvProviderPipe $pipe */
        $pipe = (new $pipeClass)->setEchoOutput(false);
        (new \ReflectionProperty($pipeClass, $source))->setValue($pipe, $provider);

        $passable = new TvProcessingPassable(new TvReleaseContext(self::RELEASE_ID, $searchname, 1, 5000, $knownVideoId));
        $passable->setParsedInfo((new LocalDbProvider)->parseInfo($searchname));

        return $pipe->handle($passable, static fn (TvProcessingPassable $handled): TvProcessingPassable => $handled)->result;
    }

    /**
     * A provider whose client answers every search with the given shows, numbered from 1.
     *
     * @param  list<array{string, string}>  $shows  title and premiere date of each show
     */
    private function provider(string $source, array $shows): NumberedTitleTvdbProvider|NumberedTitleTvMazeProvider|NumberedTitleTmdbProvider|NumberedTitleTraktProvider
    {
        return match ($source) {
            'tvdb' => $this->tvdbProvider($shows),
            'tvmaze' => $this->tvMazeProvider($shows),
            'tmdb' => $this->tmdbProvider($shows),
            'trakt' => $this->traktProvider($shows),
        };
    }

    /**
     * @param  list<array{string, string}>  $shows
     */
    private function tvdbProvider(array $shows): NumberedTitleTvdbProvider
    {
        $results = [];
        foreach ($shows as $index => [$title, $started]) {
            $results[] = (object) [
                'tvdb_id' => (string) ($index + 1),
                'name' => $title,
                'overview' => 'Fixture',
                'first_air_time' => $started,
                'aliases' => [],
            ];
        }
        $search = Mockery::mock(SearchRoute::class);
        $search->shouldReceive('search')->andReturn($results);
        $client = Mockery::mock(TheTVDbAPI::class);
        $client->shouldReceive('search')->andReturn($search);

        return new NumberedTitleTvdbProvider($client);
    }

    /**
     * @param  list<array{string, string}>  $shows
     */
    private function tvMazeProvider(array $shows): NumberedTitleTvMazeProvider
    {
        $results = [];
        foreach ($shows as $index => [$title, $started]) {
            $results[] = (object) [
                'id' => $index + 1,
                'name' => $title,
                'summary' => 'Fixture',
                'premiered' => $started,
                'country' => 'US',
                'akas' => [],
            ];
        }
        $client = Mockery::mock(TvMazeClient::class);
        $client->shouldReceive('singleSearchAkas')->andReturn($results);
        $client->shouldReceive('search')->andReturn($results);
        $client->shouldReceive('getShowAKAs')->andReturn([]);

        return new NumberedTitleTvMazeProvider($client);
    }

    /**
     * @param  list<array{string, string}>  $shows
     */
    private function tmdbProvider(array $shows): NumberedTitleTmdbProvider
    {
        $results = [];
        foreach ($shows as $index => [$title, $started]) {
            $results[] = [
                'id' => $index + 1,
                'name' => $title,
                'overview' => 'Fixture',
                'first_air_date' => $started,
                'origin_country' => ['US'],
            ];
        }
        $client = Mockery::mock(TmdbClient::class);
        $client->shouldReceive('isConfigured')->andReturnTrue();
        $client->shouldReceive('searchTv')->andReturn(['results' => $results]);
        $client->shouldReceive('getTvAlternativeTitles')->andReturn(['results' => []]);
        $client->shouldReceive('getTvExternalIds')->andReturn([]);
        $this->app->instance(TmdbClient::class, $client);

        return new NumberedTitleTmdbProvider;
    }

    /**
     * @param  list<array{string, string}>  $shows
     */
    private function traktProvider(array $shows): NumberedTitleTraktProvider
    {
        $results = [];
        $summaries = [];
        foreach ($shows as $index => [$title, $started]) {
            $id = $index + 1;
            $results[] = ['show' => ['title' => $title, 'ids' => ['trakt' => $id]]];
            $summaries[$id] = ['title' => $title, 'ids' => ['trakt' => $id], 'first_aired' => $started.'T20:00:00.000-04:00'];
        }
        $client = Mockery::mock(TraktService::class);
        $client->shouldReceive('searchShows')->andReturn($results);
        $client->shouldReceive('getShowSummary')->andReturnUsing(static fn (int $id): array => $summaries[$id]);

        return new NumberedTitleTraktProvider($client);
    }
}

/**
 * Records the provider's show searches, additions and bindings, and stubs every database write.
 */
trait RecordsNumberedTitleSearches
{
    /** @var list<array{id: int, searchname: string, videos_id: int}> */
    public array $releases = [];

    /** @var list<array{string, int|null}> */
    public array $searches = [];

    /** @var list<string> */
    public array $addedTitles = [];

    /** @var list<array{int, int, int}> */
    public array $bound = [];

    public function getTvReleases(string $groupID = '', string $guidChar = '', int $lookupSetting = 1, int $status = 0): array|\Illuminate\Database\Eloquent\Collection|int|Collection // @phpstan-ignore missingType.generics
    {
        return collect($this->releases);
    }

    public function getShowInfo(string $name, ?int $releaseYear = null): bool|array
    {
        $this->searches[] = [$name, $releaseYear];

        return parent::getShowInfo($name, $releaseYear);
    }

    public function getByRelease(array $showInfo, int $type, int $source = 0, int $fallbackVideoId = 0): int
    {
        return 0;
    }

    public function add(array $show = []): int
    {
        $this->addedTitles[] = (string) $show['title'];

        return NumberedTitleShowSearchTest::ADDED_VIDEO_ID;
    }

    public function setVideoIdFound(int $videoId, int $releaseId, int $episodeId): void
    {
        $this->bound[] = [$videoId, $releaseId, $episodeId];
    }

    public function setVideoNotFound(mixed $status, mixed $Id): void {}

    public function getPoster(int $videoId): int
    {
        return 0;
    }

    public function getBanner(mixed $videoID, mixed $siteId): bool
    {
        return false;
    }

    protected function getVideoIDFromSiteID(string $siteColumn, int $siteID): bool|int
    {
        return false;
    }
}

final class NumberedTitleTvdbProvider extends TvdbProvider
{
    use RecordsNumberedTitleSearches;

    public function __construct(TheTVDbAPI $client)
    {
        $this->client = $client;
        $this->echooutput = false;
        $this->titleCache = [];
        (new \ReflectionProperty(TvdbProvider::class, 'local'))->setValue($this, false);
        (new \ReflectionProperty(TvdbProvider::class, 'fanart'))->setValue($this, new FanartTvService(''));
    }

    public function formatShowInfo(mixed $show): array
    {
        return ['tvdb' => (int) $show->tvdb_id, 'title' => $show->name, 'started' => $show->first_air_time];
    }
}

final class NumberedTitleTvMazeProvider extends TvMazeProvider
{
    use RecordsNumberedTitleSearches;

    public function __construct(TvMazeClient $client)
    {
        $this->client = $client;
        $this->echooutput = false;
        $this->titleCache = [];
    }

    public function formatShowInfo(mixed $show): array
    {
        return ['tvmaze' => (int) $show->id, 'tvdb' => 0, 'title' => $show->name, 'started' => $show->premiered, 'aliases' => []];
    }
}

final class NumberedTitleTmdbProvider extends TmdbProvider
{
    use RecordsNumberedTitleSearches;

    public function __construct()
    {
        $this->echooutput = false;
        $this->titleCache = [];
    }

    public function formatShowInfo(mixed $show): array
    {
        return ['tmdb' => (int) $show['id'], 'tvdb' => 0, 'title' => $show['name'], 'started' => $show['first_air_date']];
    }
}

final class NumberedTitleTraktProvider extends TraktProvider
{
    use RecordsNumberedTitleSearches;

    public function __construct(TraktService $client)
    {
        $this->client = $client;
        $this->echooutput = false;
        $this->titleCache = [];
    }

    public function formatShowInfo(mixed $show): array
    {
        return ['trakt' => (int) $show['ids']['trakt'], 'title' => $show['title'], 'started' => substr((string) $show['first_aired'], 0, 10)];
    }
}
