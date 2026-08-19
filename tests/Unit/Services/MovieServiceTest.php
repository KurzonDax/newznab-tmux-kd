<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Facades\Search;
use App\Models\Release;
use App\Services\ImdbScraper;
use App\Services\MovieService;
use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Services\TvProcessing\Providers\TraktProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Termwind\Termwind;
use Tests\Unit\ImdbScraperTestCase;

class MovieServiceTest extends ImdbScraperTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('movieinfo');
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->id();
            $table->string('imdbid')->unique();
            $table->unsignedInteger('tmdbid')->default(0);
            $table->unsignedInteger('traktid')->default(0);
            $table->string('title')->default('');
            $table->string('tagline')->default('');
            $table->string('rating', 4)->default('');
            $table->string('rtrating', 10)->default('');
            $table->string('plot')->default('');
            $table->string('year', 4)->default('');
            $table->string('genre', 64)->default('');
            $table->string('type', 32)->default('');
            $table->string('director', 64)->default('');
            $table->text('actors')->default('');
            $table->string('language', 64)->default('');
            $table->boolean('cover')->default(false);
            $table->boolean('backdrop')->default(false);
            $table->string('trailer')->default('');
            $table->timestamps();
        });

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname')->default('');
            $table->unsignedInteger('categories_id')->default(0);
            $table->string('imdbid')->nullable();
            $table->unsignedBigInteger('movieinfo_id')->nullable();
        });
    }

    #[Test]
    public function it_accepts_numeric_trakt_years_when_matching_movie_metadata(): void
    {
        Cache::flush();

        $service = $this->makeMovieServiceForTraktResponse([
            'title' => 'Example Movie',
            'year' => 2024,
            'ids' => ['trakt' => 12345],
            'overview' => 'Test overview',
            'tagline' => 'Test tagline',
            'genres' => ['Drama'],
            'rating' => 7.5,
            'votes' => 10,
            'language' => 'en',
            'runtime' => 100,
            'trailer' => '',
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Example Movie');
        $this->setMovieServiceProperty($service, 'currentYear', '2024');

        $movie = $service->fetchTraktTVProperties('8169446');

        $this->assertIsArray($movie);
        $this->assertSame('Example Movie', $movie['title']);
        $this->assertSame(2024, $movie['year']);
    }

    #[Test]
    public function it_still_rejects_mismatched_numeric_trakt_years(): void
    {
        Cache::flush();

        $service = $this->makeMovieServiceForTraktResponse([
            'title' => 'Example Movie',
            'year' => 2024,
            'ids' => ['trakt' => 12345],
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Example Movie');
        $this->setMovieServiceProperty($service, 'currentYear', '2023');

        $this->assertFalse($service->fetchTraktTVProperties('8169447'));
    }

    #[Test]
    public function it_finds_movie_info_for_imdb_ids_with_meaningful_leading_zeroes(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;

        $service->update([
            'imdbid' => '0137523',
            'title' => 'Example Movie',
            'year' => '2024',
        ]);

        $movie = $service->getMovieInfo('0137523');

        $this->assertNotNull($movie);
        $this->assertSame('0137523', $movie->imdbid);
        $this->assertSame('Example Movie', $movie->title);
    }

    #[Test]
    public function it_synchronizes_the_movie_index_after_the_upsert_path(): void
    {
        Search::shouldReceive('insertMovie')
            ->once()
            ->with([
                'id' => 1,
                'imdbid' => '0137523',
                'tmdbid' => 0,
                'traktid' => 0,
                'title' => 'Fight Club',
                'year' => '1999',
                'genre' => 'Drama',
                'actors' => 'Edward Norton, Brad Pitt',
                'director' => 'David Fincher',
                'rating' => '8.8',
                'plot' => 'An insomniac meets a soap maker.',
            ]);

        $service = new MovieService;
        $service->echooutput = false;

        $this->assertTrue($service->update([
            'imdbid' => '0137523',
            'title' => 'Fight Club',
            'year' => '1999',
            'genre' => 'Drama',
            'actors' => 'Edward Norton, Brad Pitt',
            'director' => 'David Fincher',
            'rating' => '8.8',
            'plot' => 'An insomniac meets a soap maker.',
            'cover' => 1,
        ]));
    }

    #[Test]
    public function it_returns_existing_trailer_for_imdb_ids_with_meaningful_leading_zeroes(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;

        $service->update([
            'imdbid' => '0137523',
            'title' => 'Example Movie',
            'year' => '2024',
            'trailer' => 'https://example.test/embed/trailer',
        ]);

        $this->assertSame('https://example.test/embed/trailer', $service->getTrailer('0137523'));
    }

    #[Test]
    public function it_distinguishes_pending_movie_lookup_sentinels_from_failed_empty_values(): void
    {
        $this->assertTrue(imdb_id_needs_lookup(null));
        $this->assertTrue(imdb_id_needs_lookup('0'));
        $this->assertTrue(imdb_id_needs_lookup('0000000'));
        $this->assertTrue(imdb_id_needs_lookup('00000000'));
        $this->assertFalse(imdb_id_needs_lookup(''));
        $this->assertFalse(imdb_id_needs_lookup('0137523'));
    }

    #[Test]
    public function it_keeps_a_found_imdb_id_when_metadata_refresh_fails(): void
    {
        Cache::flush();

        $service = new class extends MovieService
        {
            public function updateMovieInfo(string $imdbId): bool
            {
                return false;
            }
        };
        $service->echooutput = false;

        Release::query()->insert([
            'id' => 2,
            'searchname' => 'Example.Movie.2024',
            'categories_id' => 2000,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $result = $service->doMovieUpdate('tt0137523', 'IMDb(scrape)', 2);

        $this->assertSame('0137523', $result);
        $this->assertSame('0137523', Release::query()->whereKey(2)->value('imdbid'));
        $this->assertNull(Release::query()->whereKey(2)->value('movieinfo_id'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function obfuscatedMovieSearchNames(): array
    {
        return [
            'observed mixed token 1' => ['uY8rY7zixideWnkhsIvN'],
            'observed mixed token 2' => ['nWbGhyUu6gUsnuAt'],
            'observed mixed token 3' => ['9D4G35n09zFf8pL'],
            'observed mixed token 4' => ['y1hTnTr9QfWzCvMUaSY'],
            'observed mixed token 5' => ['iXNRlJYZbQQqDWvIT'],
            'observed mixed token 6' => ['L3uhJ3HZ6jHJZbZMl4a'],
            'observed mixed token 7' => ['YVkm8BdNoj8UoxNkfMQALR'],
            'observed mixed token 8' => ['gosPtlYo6TWso9S'],
            'observed mixed token 9' => ['2TZwb1rnUCaaDOsI'],
            'observed mixed token 10' => ['CvnbK0VfTKELgG8WL5YS'],
            'observed mixed token 11' => ['uNnDRUqctxThlgw'],
            'observed mixed token 12' => ['TQzI8sqMBXFeGnElgH'],
            'numeric timestamp' => ['1547200918'],
            'numeric token' => ['350786012'],
            'another numeric token' => ['863712707'],
            'zero' => ['0'],
            'thirteen character token' => ['aB3kD9zQxY7wP'],
            'hash with media extension' => ['7f3a9c2b4e1d8a6f5b2c9e0d1a3f4b5c.mkv'],
        ];
    }

    #[Test]
    #[DataProvider('obfuscatedMovieSearchNames')]
    public function it_rejects_obfuscated_movie_search_names(string $releaseName): void
    {
        Cache::flush();

        $service = new TestableMovieService;

        $this->assertFalse($service->parseSearchName($releaseName));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function legitimateMovieSearchNames(): array
    {
        return [
            'dot separators' => ['The.Perfect.Storm.1991', 'The Perfect Storm', '1991'],
            'underscore separators' => ['Movie_Title_2024', 'Movie Title', '2024'],
            'scene release' => ['Inception 2010 1080p BluRay x264-SPARKS', 'Inception', '2010'],
            'short single-token title' => ['Transformers', 'Transformers', ''],
        ];
    }

    #[Test]
    #[DataProvider('legitimateMovieSearchNames')]
    public function it_parses_legitimate_movie_search_names(
        string $releaseName,
        string $expectedTitle,
        string $expectedYear,
    ): void {
        Cache::flush();

        $service = new TestableMovieService;

        $this->assertSame(
            ['title' => $expectedTitle, 'year' => $expectedYear],
            $service->parseSearchName($releaseName),
        );
    }

    #[Test]
    public function it_marks_obfuscated_releases_failed_without_searching_external_services(): void
    {
        $this->mock(ImdbScraper::class)->shouldNotReceive('search');
        $this->mock(TmdbClient::class)->shouldNotReceive('isConfigured');

        Release::query()->insert([
            'id' => 3,
            'searchname' => 'uY8rY7zixideWnkhsIvN',
            'categories_id' => 2999,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $service = new MovieService;
        $service->echooutput = true;
        $service->movieqty = 100;

        $consoleOutput = new BufferedOutput;
        Termwind::renderUsing($consoleOutput);

        try {
            $service->processMovieReleases();
        } finally {
            Termwind::renderUsing(null);
        }

        $output = $consoleOutput->fetch();

        $this->assertStringNotContainsString('Looking up:', $output);
        $this->assertStringContainsString('Failed to find IMDB IDs for 1 releases:', $output);
        $this->assertSame('', Release::query()->whereKey(3)->value('imdbid'));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function makeMovieServiceForTraktResponse(array $response): MovieService
    {
        $client = new class($response) extends TraktService
        {
            /**
             * @param  array<string, mixed>  $response
             */
            public function __construct(private array $response)
            {
                parent::__construct('test_trakt_key');
            }

            /**
             * @return array<string, mixed>|null
             */
            public function getMovieSummary(string $movie, string $extended = 'min'): ?array
            {
                return $this->response;
            }
        };

        $provider = new TraktProvider;
        $provider->client = $client;

        $service = new MovieService;
        $service->traktTv = $provider;
        $service->echooutput = false;

        $this->setMovieServiceProperty($service, 'traktcheck', 'test-key');

        return $service;
    }

    private function setMovieServiceProperty(MovieService $service, string $property, mixed $value): void
    {
        $reflectionProperty = new \ReflectionProperty($service, $property);
        $reflectionProperty->setValue($service, $value);
    }
}

class TestableMovieService extends MovieService
{
    /**
     * @return array{title: string, year: string}|false
     */
    public function parseSearchName(string $releaseName): array|false
    {
        if (! $this->parseMovieSearchName($releaseName)) {
            return false;
        }

        return [
            'title' => $this->currentTitle,
            'year' => $this->currentYear,
        ];
    }
}
