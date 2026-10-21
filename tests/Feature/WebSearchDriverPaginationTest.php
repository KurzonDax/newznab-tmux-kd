<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\DTO\ReleaseSearchQuery;
use Illuminate\Support\Facades\Cache;
use Manticoresearch\Client as ManticoreClient;
use Manticoresearch\Response as ManticoreResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

final class WebSearchDriverPaginationTest extends TestCase
{
    /** @var list<array{ReflectionProperty, mixed}> */
    private array $originals = [];

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->originals) as [$property, $value]) {
            $property->setValue(null, $value);
        }
        Cache::flush();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'Manticore' => ['manticore'];
    }

    #[DataProvider('drivers')]
    public function test_web_release_pages_use_bounded_offsets_entity_filters_and_stable_sorting(string $name): void
    {
        $driver = $this->driver($name);
        foreach ([false, true] as $fuzzy) {
            $page = $driver->searchReleasesFiltered([
                'phrases' => ['searchname' => 'Dune'], 'try_fuzzy' => false,
                'web_search' => true, 'entity_filters' => ['imdbid' => ['0123456']], 'web_force_fuzzy' => $fuzzy, 'sort_field' => 'sort_name', 'sort_dir' => 'asc',
            ], 24, 1344);
            $this->assertSame([501], $page['ids']);
            $this->assertSame($fuzzy, $page['fuzzy']);
        }
        $this->assertCount(2, $this->requests);
        foreach ($this->requests as $body) {
            $this->assertSame([['sort_name' => 'asc'], ['id' => 'desc']], $body['sort']);
            $this->assertStringContainsString('0123456', json_encode($body['query'], JSON_THROW_ON_ERROR));
            $this->assertSame(1344, $body['offset']);
        }
        $this->assertStringNotContainsString('fuzziness', json_encode($this->requests[0], JSON_THROW_ON_ERROR));
        $this->assertTrue($this->requests[1]['options']['fuzzy']);
    }

    #[DataProvider('drivers')]
    public function test_all_web_sorts_keep_exact_totals_and_descending_id_ties(string $name): void
    {
        $driver = $this->driver($name, 1000000);
        foreach ([['postdate_ts', 'desc'], ['postdate_ts', 'asc'], ['adddate_ts', 'desc'], ['adddate_ts', 'asc'], ['sort_name', 'asc'], ['grabs', 'desc']] as [$field, $direction]) {
            $page = $driver->searchReleasesFiltered(['web_search' => true, 'phrases' => null, 'sort_field' => $field, 'sort_dir' => $direction], 24, 1344);
            $this->assertSame(1000000, $page['total']);
            $this->assertCount(1, $page['ids']);
            $body = $this->requests[array_key_last($this->requests)];
            $this->assertSame([[$field => $direction], ['id' => 'desc']], $body['sort']);
        }
        $this->assertCount(6, $this->requests);
    }

    #[DataProvider('drivers')]
    public function test_free_text_searches_every_linked_title_and_keeps_access_filters(string $name): void
    {
        $driver = $this->driver($name);
        $page = $driver->searchReleasesFiltered([
            'web_search' => true, 'phrases' => ['searchname' => '"Some Name" -(cam | ts)'],
            'category_ids' => [2030, 5030], 'excluded_category_ids' => [3030],
            'password_allow_rar' => false, 'min_completion' => 95, 'poster' => 'Exact@Poster',
        ], 24);
        $this->assertSame([501], $page['ids']);
        $query = json_encode($this->requests[0]['query'], JSON_THROW_ON_ERROR);
        foreach (['searchname', 'plainsearchname', 'name', 'filename', 'fromname', 'movie_title', 'show_title', 'album_title', 'artist', 'music_tracks', 'console_title', 'game_title', 'book_title', 'anime_titles'] as $field) {
            $this->assertStringContainsString($field, $query);
        }
        foreach (['2030', '5030', '3030', 'passwordstatus', 'completion', '95', hash('sha256', 'Exact@Poster')] as $filter) {
            $this->assertStringContainsString($filter, $query);
        }
        $this->assertCount(1, $this->requests);
    }

    /** Imported only on a search (issue #1050): one equality filter on the document's imported flag, absent without the choice. */
    #[DataProvider('drivers')]
    public function test_imported_only_filters_on_the_documents_imported_flag(string $name): void
    {
        $driver = $this->driver($name);
        $driver->searchReleasesFiltered(['web_search' => true, 'phrases' => ['searchname' => 'Dune'], 'imported_only' => true], 50);
        $driver->searchReleasesFiltered(['web_search' => true, 'phrases' => ['searchname' => 'Dune'], 'imported_only' => false], 50);
        $driver->searchReleasesFiltered(['web_search' => true, 'phrases' => ['searchname' => 'Dune']], 50);
        $this->assertCount(3, $this->requests);
        $filtered = json_encode($this->requests[0]['query'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('{"equals":{"is_imported":1}}', $filtered);
        foreach ([1, 2] as $request) {
            $this->assertStringNotContainsString('is_imported', json_encode($this->requests[$request], JSON_THROW_ON_ERROR));
        }
    }

    /** Similar releases on a Movies details page leave the film out in the search itself, fuzzy retry included (SPEC 5C.5). */
    #[DataProvider('drivers')]
    public function test_a_film_left_out_is_filtered_in_the_exact_search_and_its_fuzzy_retry(string $name): void
    {
        $driver = $this->driver($name, 0);
        $query = ReleaseSearchQuery::fromCriteria(['phrases' => ['searchname' => 'Heat 1995'], 'category_ids' => [2030, 2040], 'excluded_movieinfo_id' => 42], 50);
        $this->assertSame(42, $query->excludedMovieinfoId);
        $driver->searchReleasePage($query);

        $this->assertCount(2, $this->requests);
        $leftOut = '{"equals":{"movieinfo_id":42}}';
        foreach ($this->requests as $body) {
            $this->assertStringContainsString($leftOut, json_encode($body['query'], JSON_THROW_ON_ERROR));
        }
        $this->assertStringNotContainsString('fuzziness', json_encode($this->requests[0], JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('"must_not":[{"equals":{"movieinfo_id":42}}]', json_encode($this->requests[1]['query'], JSON_THROW_ON_ERROR));
        $this->assertTrue($this->requests[1]['options']['fuzzy']);

        $this->requests = [];
        $driver->searchReleasePage(ReleaseSearchQuery::fromCriteria(['phrases' => ['searchname' => 'Heat 1995']], 50));
        $this->assertNotEmpty($this->requests);
        foreach ($this->requests as $body) {
            $this->assertStringNotContainsString('movieinfo_id', json_encode($body['query'], JSON_THROW_ON_ERROR));
        }
    }

    /** API general search matches the music text fields besides the release name, exact and fuzzy alike (#308). */
    #[DataProvider('drivers')]
    public function test_api_general_search_matches_music_text_fields_exactly_and_fuzzily(string $name): void
    {
        $driver = $this->driver($name, 0);
        $driver->searchReleasePage(ReleaseSearchQuery::fromCriteria(['phrases' => 'Recorded Track', 'music_text' => true, 'include_documents' => true], 100));

        $this->assertCount(2, $this->requests, 'an exact search, then its fuzzy retry');
        $exact = json_encode($this->requests[0]['query'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('@(searchname,album_title,artist,music_tracks) (Recorded Track)', $exact);
        $this->assertTrue($this->requests[1]['options']['fuzzy']);
        $this->assertStringNotContainsString('@searchname', json_encode($this->requests[1]['query'], JSON_THROW_ON_ERROR), 'the fuzzy retry reads every text field');

        $this->requests = [];
        $driver->searchReleasePage(ReleaseSearchQuery::fromCriteria(['phrases' => 'Recorded Track'], 100));
        $exact = json_encode($this->requests[0]['query'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('music_tracks', $exact, 'other release-name searches keep their fields');
    }

    /** API music search matches only the music text fields, never the release name, and keeps its filters (#307). */
    #[DataProvider('drivers')]
    public function test_api_music_search_matches_only_the_music_text_fields(string $name): void
    {
        $driver = $this->driver($name);
        $driver->searchReleasePage(ReleaseSearchQuery::fromCriteria([
            'phrases' => 'Recorded Track', 'music_text_only' => true, 'try_fuzzy' => false,
            'category_ids' => [3040], 'min_size' => 1024, 'sort_field' => 'size', 'sort_dir' => 'asc',
        ], 2000));

        $this->assertCount(1, $this->requests, 'an exact search only, like the legacy music lookup');
        $body = $this->requests[0];
        $query = json_encode($body['query'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('@(album_title,artist,music_tracks) (Recorded Track)', $query);
        $this->assertStringNotContainsString('searchname', $query);
        $this->assertSame([['size' => 'asc'], ['id' => 'asc']], $body['sort']);
        foreach (['3040', '1024'] as $filter) {
            $this->assertStringContainsString($filter, $query);
        }
        $this->assertFalse(ReleaseSearchQuery::fromCriteria(['phrases' => 'Recorded Track'], 10)->criteria()['music_text_only']);
    }

    /** Release names keep their punctuation-separated fallback when the music fields join the API search. */
    public function test_api_general_search_keeps_the_normalized_release_name_alternative(): void
    {
        $driver = $this->driver('manticore');
        $driver->searchReleasePage(ReleaseSearchQuery::fromCriteria(['phrases' => 'Example.Artist-Album.2020', 'music_text' => true], 100));

        $query = json_encode($this->requests[0]['query'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('@(searchname,album_title,artist,music_tracks) (Example Artist Album 2020)', $query);
    }

    #[DataProvider('drivers')]
    public function test_entity_fields_preserve_phrases_exclusions_groups_and_return_link_keys(string $name): void
    {
        $driver = $this->driver($name);
        $result = $driver->searchEntityFields('movies', ['actors' => '("Hugh Jackman" | "Patrick Stewart")', 'director' => '(scorsese | nolan) -spielberg'], 'imdbid');
        $this->assertSame(['0123456'], $result['keys']);
        $this->assertSame([501], $result['ids']);
        $query = json_encode($this->requests[0]['query'], JSON_THROW_ON_ERROR);
        foreach (['Hugh Jackman', 'Patrick Stewart', '*scorsese*', '*nolan*', '-*spielberg*'] as $term) {
            $this->assertStringContainsString($term, $query);
        }
        $this->assertCount(1, $this->requests);
    }

    private function driver(string $name, int $total = 1): SearchDriverInterface
    {
        Cache::flush();
        $this->replaceStatic(ManticoreSearchDriver::class, 'availabilityCache', true);
        $this->replaceStatic(ManticoreSearchDriver::class, 'availabilityCacheTime', time());
        $hit = ['_id' => '501', '_source' => ['id' => 501, 'imdbid' => '0123456']];
        $client = $this->createStub(ManticoreClient::class);
        $client->method('search')->willReturnCallback(function (array $parameters) use ($hit, $total): ManticoreResponse {
            $this->requests[] = $parameters['body'];

            return new ManticoreResponse(['hits' => ['total' => $total, 'hits' => [$hit]]]);
        });
        $driver = new ManticoreSearchDriver(['host' => 'localhost', 'port' => 9308, 'fuzzy' => ['enabled' => true], 'indexes' => ['movies' => 'movies_rt', 'releases' => 'releases_rt']]);
        $driver->manticoreSearch = $client;

        return $driver;
    }

    private function replaceStatic(string $class, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($class, $name);
        $this->originals[] = [$property, $property->getValue()];
        $property->setValue(null, $value);
    }
}
