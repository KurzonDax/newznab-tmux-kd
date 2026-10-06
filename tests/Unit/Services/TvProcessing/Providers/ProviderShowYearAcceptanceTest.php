<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TvProcessing\Providers;

use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Services\TvProcessing\Pipes\TraktPipe;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvReleaseContext;
use CanIHaveSomeCoffee\TheTVDbAPI\Route\SearchRoute;
use CanIHaveSomeCoffee\TheTVDbAPI\TheTVDbAPI;
use DariusIII\TVMaze\TVMaze as TvMazeClient;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProviderShowYearAcceptanceTest extends TestCase
{
    #[Test]
    public function every_external_pipe_binds_a_new_show_without_requesting_episodes(): void
    {
        foreach ([
            [TmdbPipe::class, TmdbProvider::class, 'tmdb'],
            [TvdbPipe::class, TvdbProvider::class, 'tvdb'],
            [TvMazePipe::class, TvMazeProvider::class, 'tvmaze'],
            [TraktPipe::class, TraktProvider::class, 'trakt'],
        ] as [$pipeClass, $providerClass, $property]) {
            $provider = Mockery::mock($providerClass);
            $provider->shouldReceive('getByRelease')->once()->andReturn(0);
            $provider->shouldReceive('getShowInfo')->once()->with('Sterling Point (2026)')->andReturn([
                $property => 200, 'poster' => 'poster.jpg',
            ]);
            $provider->shouldReceive('add')->once()->andReturn(77);
            $provider->shouldReceive('getPoster')->andReturn(1);
            $provider->shouldReceive('getBanner')->andReturnTrue();
            $provider->shouldNotReceive('getEpisodeInfo');
            $provider->shouldNotReceive('getBySeasonEp');
            $provider->shouldNotReceive('countEpsByVideoID');
            $provider->shouldReceive('setVideoIdFound')->once()->with(77, 99, 0);
            $provider->shouldReceive('setVideoNotFound')->once()->with(-6, 99);
            $pipe = (new $pipeClass)->setEchoOutput(false);
            (new \ReflectionProperty($pipeClass, $property))->setValue($pipe, $provider);
            $passable = new TvProcessingPassable(new TvReleaseContext(99, 'Sterling Point (2026)', 1, 5000));
            $passable->setParsedInfo((new LocalDbProvider)->parseInfo('Sterling Point (2026)'));
            $result = $pipe->handle($passable, fn ($value) => $value);
            $this->assertTrue($result->result->isMatched());
            $this->assertSame(0, $result->result->episodeId);
        }
    }

    #[Test]
    public function tvdb_prefers_the_same_titled_show_with_a_plausible_premiere_year(): void
    {
        $oldShow = $this->tvdbShow(100, '1990-09-20');
        $modernShow = $this->tvdbShow(200, '2014-10-07');

        $search = Mockery::mock(SearchRoute::class);
        $search->shouldReceive('search')
            ->once()
            ->with('The Flash', ['type' => 'series'])
            ->andReturn([$oldShow, $modernShow]);

        $client = Mockery::mock(TheTVDbAPI::class);
        $client->shouldReceive('search')->once()->andReturn($search);

        $provider = new TestableTvdbProvider($client);

        $this->assertSame(
            ['tvdb' => 200, 'started' => '2014-10-07'],
            $provider->getShowInfo('The Flash', 2014),
        );
    }

    #[Test]
    public function tvmaze_rejects_a_year_implausible_single_result_and_uses_the_full_search(): void
    {
        $oldShow = $this->tvMazeShow(100, '1990-09-20');
        $modernShow = $this->tvMazeShow(200, '2014-10-07');

        $client = Mockery::mock(TvMazeClient::class);
        $client->shouldReceive('singleSearchAkas')->once()->with('The Flash')->andReturn([$oldShow]);
        $client->shouldReceive('search')->once()->with('The Flash')->andReturn([$oldShow, $modernShow]);
        $client->shouldReceive('getShowAKAs')->once()->with(200)->andReturn([]);

        $provider = new TestableTvMazeProvider($client);

        $this->assertSame(
            ['tvmaze' => 200, 'started' => '2014-10-07', 'aliases' => []],
            $provider->getShowInfo('The Flash (2014)'),
        );
    }

    #[Test]
    public function tmdb_prefers_the_same_titled_show_with_a_plausible_premiere_year(): void
    {
        $oldShow = $this->tmdbShow(100, '1990-09-20');
        $modernShow = $this->tmdbShow(200, '2014-10-07');

        $client = Mockery::mock(TmdbClient::class);
        $client->shouldReceive('isConfigured')->once()->andReturnTrue();
        $client->shouldReceive('searchTv')->once()->with('The Flash')->andReturn([
            'results' => [$oldShow, $modernShow],
        ]);
        $client->shouldReceive('getTvAlternativeTitles')->once()->with(200)->andReturn(['results' => []]);
        $client->shouldReceive('getTvExternalIds')->once()->with(200)->andReturn([]);
        $this->app->instance(TmdbClient::class, $client);

        $provider = new TestableTmdbProvider;

        $this->assertSame(
            ['tmdb' => 200, 'started' => '2014-10-07'],
            $provider->getShowInfo('The Flash', 2014),
        );
    }

    #[Test]
    public function trakt_prefers_the_same_titled_show_with_a_plausible_premiere_year(): void
    {
        $searchResults = [
            $this->traktSearchResult(100),
            $this->traktSearchResult(200),
        ];
        $oldShow = $this->traktShow(100, '1990-09-20T20:00:00.000-04:00');
        $modernShow = $this->traktShow(200, '2014-10-07T20:00:00.000-04:00');

        $client = Mockery::mock(TraktService::class);
        $client->shouldReceive('searchShows')->once()->with('The Flash')->andReturn($searchResults);
        $client->shouldReceive('getShowSummary')->once()->with(100)->andReturn($oldShow);
        $client->shouldReceive('getShowSummary')->once()->with(200)->andReturn($modernShow);

        $provider = new TestableTraktProvider($client);

        $this->assertSame(
            ['trakt' => 200, 'started' => '2014-10-07 20:00:00'],
            $provider->getShowInfo('The Flash (2014)'),
        );
    }

    #[Test]
    public function a_year_implausible_show_is_rejected_for_year_carrying_and_stripped_queries(): void
    {
        $oldTvdbShow = $this->tvdbShow(100, '1990-09-20');
        $tvdbSearch = Mockery::mock(SearchRoute::class);
        $tvdbSearch->shouldReceive('search')->once()->andReturn([$oldTvdbShow]);
        $tvdbClient = Mockery::mock(TheTVDbAPI::class);
        $tvdbClient->shouldReceive('search')->once()->andReturn($tvdbSearch);

        $oldTvMazeShow = $this->tvMazeShow(100, '1990-09-20');
        $tvMazeClient = Mockery::mock(TvMazeClient::class);
        $tvMazeClient->shouldReceive('singleSearchAkas')->once()->with('The Flash')->andReturn([$oldTvMazeShow]);
        $tvMazeClient->shouldReceive('search')->once()->with('The Flash')->andReturn([$oldTvMazeShow]);

        $oldTmdbShow = $this->tmdbShow(100, '1990-09-20');
        $tmdbClient = Mockery::mock(TmdbClient::class);
        $tmdbClient->shouldReceive('isConfigured')->once()->andReturnTrue();
        $tmdbClient->shouldReceive('searchTv')->once()->with('The Flash')->andReturn([
            'results' => [$oldTmdbShow],
        ]);
        $this->app->instance(TmdbClient::class, $tmdbClient);

        $oldTraktShow = $this->traktShow(100, '1990-09-20T20:00:00.000-04:00');
        $traktClient = Mockery::mock(TraktService::class);
        $traktClient->shouldReceive('searchShows')->once()->with('The Flash')->andReturn([
            $this->traktSearchResult(100),
        ]);
        $traktClient->shouldReceive('getShowSummary')->once()->with(100)->andReturn($oldTraktShow);

        $this->assertFalse((new TestableTvdbProvider($tvdbClient))->getShowInfo('The Flash', 2014));
        $this->assertFalse((new TestableTvMazeProvider($tvMazeClient))->getShowInfo('The Flash (2014)'));
        $this->assertFalse((new TestableTmdbProvider)->getShowInfo('The Flash', 2014));
        $this->assertFalse((new TestableTraktProvider($traktClient))->getShowInfo('The Flash (2014)'));
    }

    /**
     * @return iterable<string, array{mixed, int|null, bool}>
     */
    public static function premiereYearBoundaries(): iterable
    {
        yield 'five years before' => ['2019-12-31', 2024, true];
        yield 'six years before' => ['2018-12-31', 2024, false];
        yield 'following January' => ['2025-01-31', 2024, true];
        yield 'following February' => ['2025-02-01', 2024, false];
        yield 'malformed date' => ['not-a-date', 2024, false];
        yield 'missing date' => [null, 2024, false];
        yield 'no release year' => [null, null, true];
    }

    #[DataProvider('premiereYearBoundaries')]
    #[Test]
    public function premiere_year_plausibility_has_explicit_boundaries(
        mixed $premiereDate,
        ?int $releaseYear,
        bool $expected,
    ): void {
        $provider = new TestableTmdbProvider;

        $this->assertSame($expected, $provider->premiereYearIsPlausible($premiereDate, $releaseYear));
    }

    #[Test]
    public function a_show_name_without_a_year_keeps_first_exact_match_precedence(): void
    {
        $oldShow = $this->traktShow(100, '1990-09-20T20:00:00.000-04:00');
        $client = Mockery::mock(TraktService::class);
        $client->shouldReceive('searchShows')->once()->with('The Flash')->andReturn([
            $this->traktSearchResult(100),
            $this->traktSearchResult(200),
        ]);
        $client->shouldReceive('getShowSummary')->once()->with(100)->andReturn($oldShow);

        $this->assertSame(
            ['trakt' => 100, 'started' => '1990-09-20 20:00:00'],
            (new TestableTraktProvider($client))->getShowInfo('The Flash'),
        );
    }

    #[Test]
    public function a_year_mismatched_local_show_is_skipped_before_the_modern_api_show_is_added_and_bound(): void
    {
        $provider = Mockery::mock(TvdbProvider::class);
        $provider->shouldReceive('getByRelease')->once()->withArgs(
            static fn (array $showInfo, int $type, int $source, int $fallbackVideoId): bool => $showInfo['cleanname'] === 'The Flash (2014)'
                && $type === 0
                && $source === 0
                && $fallbackVideoId === 0,
        )->andReturn(0);
        $provider->shouldReceive('getShowInfo')->once()->with('The Flash (2014)')->andReturnFalse();
        $provider->shouldReceive('getShowInfo')->once()->with('The Flash', 2014)->andReturn([
            'tvdb' => 200,
            'poster' => 'poster.jpg',
        ]);
        $provider->shouldReceive('add')->once()->andReturn(77);
        $provider->shouldReceive('getPoster')->once()->with(77)->andReturn(1);
        $provider->shouldReceive('getBanner')->once()->with(77, 200)->andReturnTrue();
        $provider->shouldReceive('countEpsByVideoID')->once()->with(77)->andReturnTrue();
        $provider->shouldReceive('getBySeasonEp')->once()->with(77, '1', '1', '')->andReturn(88);
        $provider->shouldReceive('setVideoIdFound')->once()->with(77, 99, 88);

        $pipe = (new TvdbPipe)->setEchoOutput(false);
        (new \ReflectionProperty(TvdbPipe::class, 'tvdb'))->setValue($pipe, $provider);

        $passable = new TvProcessingPassable(new TvReleaseContext(99, 'The.Flash.2014.S01E01', 1, 5000));
        $passable->setParsedInfo([
            'name' => 'The Flash',
            'cleanname' => 'The Flash (2014)',
            'season' => 'S01',
            'episode' => 'E01',
        ]);

        $result = $pipe->handle($passable, static fn (TvProcessingPassable $handled): TvProcessingPassable => $handled);

        $this->assertTrue($result->result->isMatched());
        $this->assertSame(77, $result->result->videoId);
        $this->assertSame(88, $result->result->episodeId);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function showTitleKeyPairs(): iterable
    {
        yield 'accents' => ['Pokémon', 'Pokemon', true];
        yield 'possessive and initials' => ["Marvel's Agents of S.H.I.E.L.D.", 'Marvels Agents of SHIELD', true];
        yield 'abbreviation dots' => ['House M.D.', 'House MD', true];
        yield 'ampersand' => ['Law & Order', 'Law and Order', true];
        yield 'typographic apostrophe' => ['Tyler Perry’s Bruh', 'Tyler Perrys Bruh', true];
        yield 'leading the' => ['The Office', 'Office', true];
        yield 'leading a' => ['A Man in a Veil', 'Man in a Veil', true];
        yield 'spacing' => ['Heart Beat', 'Heartbeat', true];
        yield 'near miss' => ['EXchange', 'Seachange', false];
        yield 'one letter apart' => ['Healer', 'Hvaler', false];
        yield 'extra leading words' => ['Nashville', '9-1-1: Nashville', false];
        yield 'words inside other words' => ['Ano fe', 'Another Life', false];
    }

    #[DataProvider('showTitleKeyPairs')]
    #[Test]
    public function show_title_keys_are_equal_only_for_the_same_name(string $left, string $right, bool $equal): void
    {
        $provider = new TestableTmdbProvider;

        $this->assertSame($equal, $provider->titleKey($left) === $provider->titleKey($right));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function externalProviders(): iterable
    {
        yield 'TVDB' => ['tvdb'];
        yield 'TVMaze' => ['tvmaze'];
        yield 'TMDB' => ['tmdb'];
        yield 'Trakt' => ['trakt'];
    }

    #[DataProvider('externalProviders')]
    #[Test]
    public function a_show_whose_name_only_resembles_the_query_is_not_chosen(string $provider): void
    {
        $this->assertFalse($this->chosenShowId($provider, 'EXchange', [['name' => 'Seachange']]));
    }

    #[DataProvider('externalProviders')]
    #[Test]
    public function a_later_identically_named_show_beats_an_earlier_near_miss(string $provider): void
    {
        $this->assertSame(
            2,
            $this->chosenShowId($provider, 'EXchange', [['name' => 'Seachange'], ['name' => 'EXchange']]),
        );
    }

    #[DataProvider('externalProviders')]
    #[Test]
    public function an_identically_named_show_beats_an_earlier_bracketed_sibling(string $provider): void
    {
        $this->assertSame(
            2,
            $this->chosenShowId($provider, 'Castle', [['name' => 'Castle (2009)'], ['name' => 'Castle']]),
        );
    }

    #[DataProvider('externalProviders')]
    #[Test]
    public function a_bracketed_sibling_is_chosen_when_no_show_is_identically_named(string $provider): void
    {
        $this->assertSame(1, $this->chosenShowId($provider, 'Castle', [['name' => 'Castle (2009)']]));
    }

    #[Test]
    public function tvdb_matches_a_show_by_its_english_translation(): void
    {
        $this->assertSame(1, $this->chosenShowId('tvdb', 'Attack on Titan', [
            ['name' => '進撃の巨人', 'translations' => ['eng' => 'Attack on Titan']],
        ]));
    }

    #[Test]
    public function tvmaze_matches_a_show_by_its_aka(): void
    {
        $this->assertSame(1, $this->chosenShowId('tvmaze', 'Attack on Titan', [
            ['name' => '進撃の巨人', 'akas' => [['name' => 'Attack on Titan']]],
        ]));
    }

    #[Test]
    public function tmdb_matches_a_show_by_its_original_name(): void
    {
        $this->assertSame(1, $this->chosenShowId('tmdb', 'Squid Game', [
            ['name' => '오징어 게임', 'original_name' => 'Squid Game'],
        ]));
    }

    #[Test]
    public function tvdb_show_names_combine_the_name_aliases_and_translations(): void
    {
        $provider = new TestableTvdbProvider(Mockery::mock(TheTVDbAPI::class));

        $this->assertSame(['Seachange', 'Sea Change', 'Mer Change'], $provider->showNames((object) [
            'name' => 'Seachange',
            'aliases' => ['Sea Change', 'Seachange', ''],
            'translations' => ['eng' => 'Seachange', 'fra' => 'Mer Change'],
        ]));
        $this->assertSame(['Seachange', 'Sea Change'], $provider->showNames((object) [
            'name' => 'Seachange',
            'aliases' => ['Sea Change'],
        ]));
    }

    /**
     * Run one provider's show search against fixture results numbered from 1 in the given order.
     *
     * @param  list<array<string, mixed>>  $results  each a name plus provider-specific extra fields
     */
    private function chosenShowId(string $provider, string $query, array $results): int|false
    {
        $started = '2010-01-01';
        $info = match ($provider) {
            'tvdb' => $this->tvdbChoice($query, $results, $started),
            'tvmaze' => $this->tvMazeChoice($query, $results, $started),
            'tmdb' => $this->tmdbChoice($query, $results, $started),
            'trakt' => $this->traktChoice($query, $results, $started),
        };

        return $info === false ? false : $info[$provider];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>|false
     */
    private function tvdbChoice(string $query, array $results, string $started): array|false
    {
        $shows = [];
        foreach ($results as $index => $result) {
            $shows[] = (object) array_merge((array) $this->tvdbShow($index + 1, $started), $result);
        }
        $search = Mockery::mock(SearchRoute::class);
        $search->shouldReceive('search')->once()->with($query, ['type' => 'series'])->andReturn($shows);
        $client = Mockery::mock(TheTVDbAPI::class);
        $client->shouldReceive('search')->once()->andReturn($search);

        return (new TestableTvdbProvider($client))->getShowInfo($query);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>|false
     */
    private function tvMazeChoice(string $query, array $results, string $started): array|false
    {
        $shows = [];
        foreach ($results as $index => $result) {
            $shows[] = (object) array_merge((array) $this->tvMazeShow($index + 1, $started), $result);
        }
        $client = Mockery::mock(TvMazeClient::class);
        $client->shouldReceive('singleSearchAkas')->with($query)->andReturn($shows);
        $client->shouldReceive('search')->with($query)->andReturn($shows);
        $client->shouldReceive('getShowAKAs')->andReturn([]);

        return (new TestableTvMazeProvider($client))->getShowInfo($query);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>|false
     */
    private function tmdbChoice(string $query, array $results, string $started): array|false
    {
        $shows = [];
        foreach ($results as $index => $result) {
            $shows[] = array_merge($this->tmdbShow($index + 1, $started), $result);
        }
        $client = Mockery::mock(TmdbClient::class);
        $client->shouldReceive('isConfigured')->andReturnTrue();
        $client->shouldReceive('searchTv')->once()->with($query)->andReturn(['results' => $shows]);
        $client->shouldReceive('getTvAlternativeTitles')->andReturn(['results' => []]);
        $client->shouldReceive('getTvExternalIds')->andReturn([]);
        $this->app->instance(TmdbClient::class, $client);

        return (new TestableTmdbProvider)->getShowInfo($query);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>|false
     */
    private function traktChoice(string $query, array $results, string $started): array|false
    {
        $searchResults = [];
        foreach ($results as $index => $result) {
            $searchResult = $this->traktSearchResult($index + 1);
            $searchResult['show']['title'] = $result['name'];
            $searchResults[] = $searchResult;
        }
        $client = Mockery::mock(TraktService::class);
        $client->shouldReceive('searchShows')->once()->with($query)->andReturn($searchResults);
        $client->shouldReceive('getShowSummary')->andReturnUsing(
            fn (int $id): array => $this->traktShow($id, $started.'T20:00:00.000-04:00'),
        );

        return (new TestableTraktProvider($client))->getShowInfo($query);
    }

    private function tvdbShow(int $id, string $started): object
    {
        return (object) [
            'tvdb_id' => (string) $id,
            'name' => 'The Flash',
            'overview' => 'Fixture',
            'first_air_time' => $started,
            'aliases' => [],
        ];
    }

    private function tvMazeShow(int $id, string $started): object
    {
        return (object) [
            'id' => $id,
            'name' => 'The Flash',
            'summary' => 'Fixture',
            'premiered' => $started,
            'country' => 'US',
            'akas' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tmdbShow(int $id, string $started): array
    {
        return [
            'id' => $id,
            'name' => 'The Flash',
            'overview' => 'Fixture',
            'first_air_date' => $started,
            'origin_country' => ['US'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function traktSearchResult(int $id): array
    {
        return ['show' => ['title' => 'The Flash', 'ids' => ['trakt' => $id]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function traktShow(int $id, string $started): array
    {
        return [
            'title' => 'The Flash',
            'ids' => ['trakt' => $id],
            'first_aired' => $started,
        ];
    }
}

final class TestableTvdbProvider extends TvdbProvider
{
    public function __construct(TheTVDbAPI $client)
    {
        $this->client = $client;
    }

    public function formatShowInfo(mixed $show): array
    {
        return ['tvdb' => (int) $show->tvdb_id, 'started' => $show->first_air_time];
    }

    /**
     * @return list<string>
     */
    public function showNames(object $show): array
    {
        return $this->tvdbShowNames($show);
    }
}

final class TestableTvMazeProvider extends TvMazeProvider
{
    public function __construct(TvMazeClient $client)
    {
        $this->client = $client;
    }

    public function formatShowInfo(mixed $show): array
    {
        return ['tvmaze' => (int) $show->id, 'started' => $show->premiered, 'aliases' => []];
    }
}

final class TestableTmdbProvider extends TmdbProvider
{
    public function premiereYearIsPlausible(mixed $premiereDate, ?int $releaseYear): bool
    {
        return $this->isPremiereYearPlausible($premiereDate, $releaseYear);
    }

    public function titleKey(string $title): string
    {
        return $this->showTitleKey($title);
    }

    public function formatShowInfo(mixed $show): array
    {
        return ['tmdb' => (int) $show['id'], 'started' => $show['first_air_date']];
    }
}

final class TestableTraktProvider extends TraktProvider
{
    public function __construct(TraktService $client)
    {
        $this->client = $client;
    }

    public function formatShowInfo(mixed $show): array
    {
        return [
            'trakt' => (int) $show['ids']['trakt'],
            'started' => substr((string) $show['first_aired'], 0, 10).' 20:00:00',
        ];
    }
}
