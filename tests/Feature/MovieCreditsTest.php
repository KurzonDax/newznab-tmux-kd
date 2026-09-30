<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Controllers\Api\XML_Response;
use App\Models\Category;
use App\Services\ImdbScraper;
use App\Services\MetadataProcessing\MovieCredits;
use App\Services\MetadataProcessing\PeopleRows;
use App\Services\MovieService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProductionTables;
use Tests\Support\RecordsTransactionStatements;
use Tests\Unit\ImdbScraperTestCase;

/**
 * A film's genres and people are rows on the shared `genres` and `people` tables, written
 * when the film is fetched (from TMDB, else from the saved text) and refreshed when a new
 * release of a film whose record is over 30 days old arrives.
 */
final class MovieCreditsTest extends ImdbScraperTestCase
{
    use RecordsTransactionStatements;

    private const string IMDB_ID = '0137523';

    protected function setUp(): void
    {
        parent::setUp();
        Search::spy();
        config([
            'nntmux.echocli' => false,
            'nntmux_api.omdb_api_key' => '',
            'nntmux_api.trakttv_api_key' => '',
            'nntmux_api.fanarttv_api_key' => '',
            'tmdb.api_key' => 'test-key',
            'tmdb.retry_times' => 1,
            'tmdb.retry_delay' => 0,
        ]);
        $scraper = $this->mock(ImdbScraper::class);
        $scraper->shouldReceive('fetchById')->andReturnFalse()->byDefault();
        $scraper->shouldReceive('wasBlockedByWaf')->andReturnFalse()->byDefault();
        $scraper->shouldReceive('getLastFailureReason', 'getLastFallbackFailureReason', 'getLastFetchSource')->andReturnNull()->byDefault();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-09-27 12:00:00');

        $tables = ProductionTables::fromAuthority();
        foreach (['movieinfo', 'genres', 'people', 'movie_genres', 'movie_people', 'video_people'] as $table) {
            $tables->create($table);
        }
        $tables->create('releases', ['id', 'guid', 'searchname', 'categories_id', 'imdbid', 'movieinfo_id', 'movie_record_lookup_attempts', 'movie_record_lookup_attempted_at']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_fetch_writes_the_three_values_the_genres_every_director_and_twelve_distinct_cast(): void
    {
        $this->fakeTmdb($this->tmdbMovie());

        $this->assertTrue($this->service()->updateMovieInfo(self::IMDB_ID));

        $film = DB::table('movieinfo')->where('imdbid', self::IMDB_ID)->first();
        $this->assertSame(0, $film->vote_count);
        $this->assertSame('R', $film->content_rating_us);
        $this->assertSame('en', $film->original_language);
        $this->assertSame(['Drama', 'Thriller'], $this->genreTitles((int) $film->id));
        $this->assertSame([Category::MOVIE_ROOT], DB::table('genres')->distinct()->pluck('type')->map(intval(...))->all());
        $this->assertSame(['David Fincher', 'Second Director'], $this->names((int) $film->id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(array_map(static fn (int $id): string => 'Actor '.$id, range(101, 112)), $this->names((int) $film->id, MovieCredits::ROLE_CAST));
        $this->assertSame(range(0, 11), DB::table('movie_people')->where('role', MovieCredits::ROLE_CAST)->orderBy('position')->pluck('position')->map(intval(...))->all());
        // The text the frozen API reads is written as before: the first director, the whole cast.
        $this->assertSame('David Fincher', $film->director);
        $this->assertStringEndsWith('Actor 113, Actor 114', $film->actors);
        $this->assertTrue(Cache::has(MovieService::tmdbCacheKey('tt'.self::IMDB_ID)));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'movie/550?') && $request['append_to_response'] === 'credits,release_dates');
    }

    public function test_a_later_fetch_with_other_credits_replaces_the_rows_and_one_with_the_same_credits_writes_nothing(): void
    {
        $this->fakeTmdb(
            $this->tmdbMovie(),
            $this->tmdbMovie([
                'vote_count' => 25,
                'genres' => [['id' => 35, 'name' => 'Comedy']],
                'credits' => ['cast' => [['id' => 201, 'name' => 'Solo']], 'crew' => [['id' => 301, 'name' => 'New Director', 'department' => 'Directing', 'job' => 'Director']]],
            ]),
            $this->tmdbMovie([
                'vote_count' => 25,
                'genres' => [['id' => 35, 'name' => 'Comedy']],
                'credits' => ['cast' => [['id' => 201, 'name' => 'Solo']], 'crew' => [['id' => 301, 'name' => 'New Director', 'department' => 'Directing', 'job' => 'Director']]],
            ]),
        );
        $service = $this->service();
        $service->updateMovieInfo(self::IMDB_ID);
        Cache::flush();

        $service->updateMovieInfo(self::IMDB_ID);

        $id = $this->filmId();
        $this->assertSame(25, DB::table('movieinfo')->where('id', $id)->value('vote_count'));
        $this->assertSame(['Comedy'], $this->genreTitles($id));
        $this->assertSame(['New Director'], $this->names($id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(['Solo'], $this->names($id, MovieCredits::ROLE_CAST));

        Cache::flush();
        $writes = $this->recordLinkWrites();
        $service->updateMovieInfo(self::IMDB_ID);

        Http::assertSentCount(6);
        $this->assertSame([], $writes());
        $this->assertSame(['Solo'], $this->names($id, MovieCredits::ROLE_CAST));
    }

    public function test_tmdb_people_are_found_by_tmdb_id_claimed_by_name_or_inserted(): void
    {
        DB::table('people')->insert([
            ['id' => 50, 'name' => 'Existing Spelling', 'tmdb_id' => 101],
            ['id' => 60, 'name' => 'Actor 102', 'tmdb_id' => null],
            ['id' => 70, 'name' => 'Actor 103', 'tmdb_id' => 999],
        ]);
        $this->fakeTmdb($this->tmdbMovie());

        $this->service()->updateMovieInfo(self::IMDB_ID);

        $cast = DB::table('movie_people')->where('role', MovieCredits::ROLE_CAST)->orderBy('position')->pluck('people_id')->map(intval(...))->all();
        $this->assertSame(50, $cast[0]);
        $this->assertSame('Existing Spelling', DB::table('people')->where('id', 50)->value('name'));
        $this->assertSame(60, $cast[1]);
        $this->assertSame(102, DB::table('people')->where('id', 60)->value('tmdb_id'));
        $this->assertNotSame(70, $cast[2]);
        $this->assertSame(103, DB::table('people')->where('id', $cast[2])->value('tmdb_id'));
        $this->assertSame(999, DB::table('people')->where('id', 70)->value('tmdb_id'));
    }

    public function test_a_claim_that_meets_a_tmdb_id_another_row_holds_falls_back_to_that_row(): void
    {
        DB::table('people')->insert([
            ['id' => 1, 'name' => 'Brad Pitt', 'tmdb_id' => null],
            ['id' => 2, 'name' => 'Brad Pitt', 'tmdb_id' => 287],
            ['id' => 3, 'name' => 'Edward Norton', 'tmdb_id' => 819],
        ]);
        $people = app(PeopleRows::class);

        $this->assertSame(2, $people->claim(1, 287, 'Brad Pitt'));
        $this->assertNull(DB::table('people')->where('id', 1)->value('tmdb_id'));

        // A claim another worker already won, for the same person or another one.
        $this->assertSame(3, $people->claim(3, 819, 'Edward Norton'));
        $inserted = $people->claim(3, 820, 'Edward Norton');
        $this->assertSame(820, DB::table('people')->where('id', $inserted)->value('tmdb_id'));
        $this->assertSame(4, DB::table('people')->count());
    }

    public function test_when_tmdb_returns_nothing_the_rows_come_from_the_saved_text(): void
    {
        Http::fake(['*find/tt0137523*' => Http::response(['movie_results' => []])]);
        $this->mock(ImdbScraper::class)->shouldReceive('fetchById')->andReturn([
            'title' => 'Fight Club',
            'year' => '1999',
            'genre' => ['Drama', 'Thriller'],
            'director' => ['David Fincher'],
            'actors' => ['Robert Downey, Jr.', 'Edward Norton'],
        ])->byDefault();

        $this->assertTrue($this->service()->updateMovieInfo(self::IMDB_ID));

        $id = $this->filmId();
        $this->assertNull(DB::table('movieinfo')->where('id', $id)->value('vote_count'));
        $this->assertSame(['Drama', 'Thriller'], $this->genreTitles($id));
        $this->assertSame(['David Fincher'], $this->names($id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(['Robert Downey Jr.', 'Edward Norton'], $this->names($id, MovieCredits::ROLE_CAST));
        $this->assertSame(0, DB::table('people')->whereNotNull('tmdb_id')->count());
    }

    public function test_a_list_tmdb_leaves_empty_alone_comes_from_the_saved_text(): void
    {
        $this->fakeTmdb($this->tmdbMovie(['credits' => ['cast' => [], 'crew' => $this->tmdbMovie()['credits']['crew']]]));
        $this->mock(ImdbScraper::class)->shouldReceive('fetchById')->andReturn([
            'title' => 'Fight Club',
            'year' => '1999',
            'genre' => 'Imdb Genre',
            'director' => 'Imdb Director',
            'actors' => 'Brad Pitt, Edward Norton',
        ])->byDefault();

        $this->service()->updateMovieInfo(self::IMDB_ID);

        $id = $this->filmId();
        $this->assertSame(['Drama', 'Thriller'], $this->genreTitles($id));
        $this->assertSame(['David Fincher', 'Second Director'], $this->names($id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(['Brad Pitt', 'Edward Norton'], $this->names($id, MovieCredits::ROLE_CAST));
    }

    public function test_a_fetch_with_no_tmdb_genres_links_the_saved_genres_on_tmdbs_list_and_drops_a_cut_off_director(): void
    {
        $this->fakeTmdb($this->tmdbMovie(['genres' => [], 'credits' => ['cast' => $this->tmdbMovie()['credits']['cast'], 'crew' => []]]));
        $this->mock(ImdbScraper::class)->shouldReceive('fetchById')->andReturn([
            'title' => 'Fight Club',
            'year' => '1999',
            'genre' => 'Action, Adventure, Science F',
            'director' => 'Pedro Almodóvar, Alejandro González Iñárritu, Alfonso Cuarón, Gu',
            'actors' => 'Brad Pitt',
        ])->byDefault();

        $this->service()->updateMovieInfo(self::IMDB_ID);

        $id = $this->filmId();
        $this->assertSame('Action, Adventure, Science F', DB::table('movieinfo')->where('id', $id)->value('genre'));
        $this->assertSame(['Action', 'Adventure'], $this->genreTitles($id));
        $this->assertSame(['Pedro Almodóvar', 'Alejandro González Iñárritu', 'Alfonso Cuarón'], $this->names($id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(0, DB::table('genres')->where('title', 'Science F')->count());
    }

    public function test_a_name_from_text_prefers_the_person_tmdb_identified_then_the_lowest_id(): void
    {
        DB::table('people')->insert([
            ['id' => 5, 'name' => 'Brad Pitt', 'tmdb_id' => null],
            ['id' => 6, 'name' => 'Brad Pitt', 'tmdb_id' => 287],
            ['id' => 7, 'name' => 'Edward Norton', 'tmdb_id' => null],
            ['id' => 8, 'name' => 'Edward Norton', 'tmdb_id' => null],
        ]);
        $id = $this->insertFilm(['actors' => 'Brad Pitt, Edward Norton, Meat Loaf']);

        app(MovieCredits::class)->syncFromText($id, '', '', 'Brad Pitt, Edward Norton, Meat Loaf');

        $this->assertSame([6, 7, 9], DB::table('movie_people')->orderBy('position')->pluck('people_id')->map(intval(...))->all());
    }

    public function test_the_cast_is_twelve_distinct_people_taking_the_next_name_after_a_repeat(): void
    {
        $names = array_map(static fn (int $n): string => 'Actor '.$n, [1, 2, 1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13]);
        $id = $this->insertFilm(['actors' => implode(', ', $names)]);

        app(MovieCredits::class)->syncFromText($id, '', '', implode(', ', $names));

        $this->assertSame(array_map(static fn (int $n): string => 'Actor '.$n, range(1, 12)), $this->names($id, MovieCredits::ROLE_CAST));
    }

    public function test_a_text_name_another_writer_adds_from_text_after_the_lookup_is_one_person_whatever_its_case(): void
    {
        $this->caseInsensitivePeopleNames();
        $film = $this->insertFilm();
        $other = $this->insertFilm(['imdbid' => '0000002']);
        $this->interleaveAfterFirstNameLookup(static fn (): bool => app(MovieCredits::class)->syncFromText($other, '', '', 'jose garcia'));

        app(MovieCredits::class)->syncFromText($film, '', '', 'JOSE GARCIA');

        $this->assertSame(1, DB::table('people')->count());
        $this->assertSame(['jose garcia'], $this->names($film, MovieCredits::ROLE_CAST));
        $this->assertSame(['jose garcia'], $this->names($other, MovieCredits::ROLE_CAST));
    }

    public function test_a_text_name_another_writer_adds_from_tmdb_after_the_lookup_is_one_person(): void
    {
        $this->caseInsensitivePeopleNames();
        $film = $this->insertFilm();
        $other = $this->insertFilm(['imdbid' => '0000002']);
        $this->interleaveAfterFirstNameLookup(static fn (): bool => app(MovieCredits::class)->sync($other, [], [], [['name' => 'Jane Roe', 'tmdb_id' => 500]]));

        app(MovieCredits::class)->syncFromText($film, '', '', 'JANE ROE');

        $this->assertSame([['name' => 'Jane Roe', 'tmdb_id' => 500]], $this->people());
        $this->assertSame(['Jane Roe'], $this->names($film, MovieCredits::ROLE_CAST));
        $this->assertSame(['Jane Roe'], $this->names($other, MovieCredits::ROLE_CAST));
    }

    public function test_a_tmdb_person_another_writer_adds_from_text_after_the_lookup_is_one_person(): void
    {
        $this->caseInsensitivePeopleNames();
        $film = $this->insertFilm();
        $other = $this->insertFilm(['imdbid' => '0000002']);
        $this->interleaveAfterFirstNameLookup(static fn (): bool => app(MovieCredits::class)->syncFromText($other, '', '', 'JANE ROE'));

        app(MovieCredits::class)->sync($film, [], [], [['name' => 'Jane Roe', 'tmdb_id' => 500]]);

        $this->assertSame([['name' => 'JANE ROE', 'tmdb_id' => 500]], $this->people());
        $this->assertSame(['JANE ROE'], $this->names($film, MovieCredits::ROLE_CAST));
        $this->assertSame(['JANE ROE'], $this->names($other, MovieCredits::ROLE_CAST));
    }

    public function test_a_tmdb_person_with_an_empty_name_is_linked_only_when_a_row_holds_its_tmdb_id(): void
    {
        DB::table('people')->insert(['id' => 40, 'name' => 'Known Person', 'tmdb_id' => 902]);
        $id = $this->insertFilm();

        app(MovieCredits::class)->sync($id, [], [['name' => '  ', 'tmdb_id' => 900]], [
            ['name' => '', 'tmdb_id' => 901],
            ['name' => ' ', 'tmdb_id' => 902],
            ['name' => 'Named', 'tmdb_id' => 903],
        ]);

        $this->assertSame([['name' => 'Known Person', 'tmdb_id' => 902], ['name' => 'Named', 'tmdb_id' => 903]], $this->people());
        $this->assertSame([], $this->names($id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(['Known Person', 'Named'], $this->names($id, MovieCredits::ROLE_CAST));
    }

    public function test_a_tmdb_cast_member_left_out_for_an_empty_name_gives_the_place_to_the_next_one(): void
    {
        $cast = [];
        foreach (range(201, 213) as $tmdbId) {
            $cast[] = ['id' => $tmdbId, 'name' => $tmdbId === 203 ? '' : 'Actor '.$tmdbId];
        }
        $this->fakeTmdb($this->tmdbMovie(['credits' => ['cast' => $cast, 'crew' => $this->tmdbMovie()['credits']['crew']]]));

        $this->service()->updateMovieInfo(self::IMDB_ID);

        $this->assertSame(
            array_map(static fn (int $tmdbId): string => 'Actor '.$tmdbId, [201, 202, ...range(204, 213)]),
            $this->names($this->filmId(), MovieCredits::ROLE_CAST),
        );
        $this->assertNull(DB::table('people')->where('tmdb_id', 203)->value('id'));
    }

    public function test_a_tmdb_cast_whose_every_member_is_left_out_comes_from_the_saved_text(): void
    {
        $this->fakeTmdb($this->tmdbMovie(['credits' => [
            'cast' => [['id' => 201, 'name' => ''], ['id' => 202, 'name' => '  ']],
            'crew' => $this->tmdbMovie()['credits']['crew'],
        ]]));
        $this->mock(ImdbScraper::class)->shouldReceive('fetchById')->andReturn([
            'title' => 'Fight Club',
            'year' => '1999',
            'genre' => 'Imdb Genre',
            'director' => 'Imdb Director',
            'actors' => 'Brad Pitt, Edward Norton',
        ])->byDefault();

        $this->service()->updateMovieInfo(self::IMDB_ID);

        $this->assertSame(['Brad Pitt', 'Edward Norton'], $this->names($this->filmId(), MovieCredits::ROLE_CAST));
        $this->assertSame(0, DB::table('people')->whereIn('tmdb_id', [201, 202])->count());
    }

    public function test_a_new_release_refreshes_a_film_whose_record_is_over_30_days_old(): void
    {
        $id = $this->insertFilm(['title' => 'Fight Club', 'updated_at' => '2026-08-28 11:59:59']);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'a', 'categories_id' => Category::MOVIE_HD, 'imdbid' => self::IMDB_ID]);
        $this->fakeTmdb($this->tmdbMovie());
        $service = $this->service();
        // A title left over from an earlier release must not make TMDB reject the film.
        $property = new \ReflectionProperty(MovieService::class, 'currentTitle');
        $property->setValue($service, 'A Different Film Entirely');

        $service->doMovieUpdate('', 'test', 1);

        $this->assertSame('2026-09-27 12:00:00', DB::table('movieinfo')->where('id', $id)->value('updated_at'));
        $this->assertSame(['Drama', 'Thriller'], $this->genreTitles($id));
        $this->assertSame($id, DB::table('releases')->where('id', 1)->value('movieinfo_id'));
    }

    public function test_a_new_release_does_not_refresh_a_film_refreshed_within_30_days(): void
    {
        $id = $this->insertFilm(['title' => 'Fight Club', 'updated_at' => '2026-08-28 12:00:01']);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'a', 'categories_id' => Category::MOVIE_HD, 'imdbid' => self::IMDB_ID]);
        Http::fake();

        $this->service()->doMovieUpdate('', 'test', 1);

        Http::assertNothingSent();
        $this->assertSame('2026-08-28 12:00:01', DB::table('movieinfo')->where('id', $id)->value('updated_at'));
        $this->assertSame(0, DB::table('movie_people')->count());
        $this->assertSame($id, DB::table('releases')->where('id', 1)->value('movieinfo_id'));
    }

    public function test_the_fill_migration_writes_what_sync_writes_and_a_second_run_changes_nothing(): void
    {
        DB::table('people')->insert(['id' => 1, 'name' => 'Robert Downey Jr.', 'tmdb_id' => 3223]);
        $first = $this->insertFilm(['imdbid' => '0000001', 'genre' => 'Action, Adventure, Science F', 'director' => 'Joe Russo, Anthony Russo', 'actors' => "Robert Downey, Jr., Chris\tEvans, Chris Evans"]);
        $second = $this->insertFilm(['imdbid' => '0000002', 'genre' => 'Drama', 'director' => 'Pedro Almodóvar, Alejandro González Iñárritu, Alfonso Cuarón, Gu', 'actors' => 'Robert Downey Jr., Gwyneth Paltrow']);
        $this->insertFilm(['imdbid' => '0000003']);
        $fill = require database_path('migrations/2026_09_27_100100_fill_movie_genres_and_people.php');

        $fill->up();
        $filled = $this->linkRows();

        DB::table('movie_genres')->delete();
        DB::table('movie_people')->delete();
        $credits = app(MovieCredits::class);
        foreach (DB::table('movieinfo')->orderBy('id')->get() as $film) {
            $credits->syncFromText((int) $film->id, $film->genre, $film->director, $film->actors);
        }
        $this->assertSame($filled, $this->linkRows());
        $this->assertSame(['Action', 'Adventure'], $this->genreTitles($first));
        $this->assertSame(['Robert Downey Jr.', 'Chris Evans'], $this->names($first, MovieCredits::ROLE_CAST));
        $this->assertSame(['Robert Downey Jr.', 'Gwyneth Paltrow'], $this->names($second, MovieCredits::ROLE_CAST));
        $this->assertSame(['Pedro Almodóvar', 'Alejandro González Iñárritu', 'Alfonso Cuarón'], $this->names($second, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(1, (int) DB::table('movie_people')->where(['movieinfo_id' => $first, 'role' => MovieCredits::ROLE_CAST, 'position' => 0])->value('people_id'));

        $writes = $this->recordLinkWrites();
        $fill->up();
        $this->assertSame([], $writes());
        $this->assertSame($filled, $this->linkRows());
    }

    public function test_the_rss_movie_text_is_the_same_before_and_after_sync(): void
    {
        $id = $this->insertFilm(['rating' => '8.8', 'plot' => 'An insomniac.', 'year' => '1999', 'genre' => 'Drama, Thriller', 'director' => 'David Fincher', 'actors' => 'Brad Pitt, Edward Norton, Robert Downey, Jr.']);

        $before = $this->rssMovieText($id);
        $this->assertTrue(app(MovieCredits::class)->syncFromText($id, 'Drama, Thriller', 'David Fincher', 'Brad Pitt, Edward Norton, Robert Downey, Jr.'));

        $this->assertSame(3, DB::table('movie_people')->where('role', MovieCredits::ROLE_CAST)->count());
        $this->assertSame($before, $this->rssMovieText($id));
        $this->assertStringContainsString('Brad Pitt, Edward Norton, Robert Downey, Jr.', $before);
    }

    public function test_a_film_with_no_rows_gets_them_after_its_lock_with_no_delete(): void
    {
        $id = $this->insertFilm();

        $transactions = $this->transactionStatements(fn () => $this->credits()->sync($id, ['Drama'], [$this->person('David Fincher', 7467)], [$this->person('Brad Pitt', 287)]));

        $this->assertCount(1, $transactions);
        $this->assertParentLockedFirst($transactions[0], 'movieinfo', $id, ['movie_genres', 'movie_people']);
        $this->assertSame(0, $this->deletesOn($transactions[0], 'movie_genres'));
        $this->assertSame(0, $this->deletesOn($transactions[0], 'movie_people'));
        $this->assertSame(['Drama'], $this->genreTitles($id));
        $this->assertSame(['Brad Pitt'], $this->names($id, MovieCredits::ROLE_CAST));
    }

    public function test_a_films_stored_rows_are_replaced_after_its_lock(): void
    {
        $id = $this->insertFilm();
        $this->credits()->sync($id, ['Drama'], [$this->person('David Fincher', 7467)], [$this->person('Brad Pitt', 287)]);

        $transactions = $this->transactionStatements(fn () => $this->credits()->sync($id, ['Comedy'], [$this->person('New Director', 301)], [$this->person('Solo', 201)]));

        $this->assertCount(1, $transactions);
        $this->assertParentLockedFirst($transactions[0], 'movieinfo', $id, ['movie_genres', 'movie_people']);
        $this->assertSame(1, $this->deletesOn($transactions[0], 'movie_genres'));
        $this->assertSame(1, $this->deletesOn($transactions[0], 'movie_people'));
        $this->assertSame(['Comedy'], $this->genreTitles($id));
        $this->assertSame(['New Director'], $this->names($id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(['Solo'], $this->names($id, MovieCredits::ROLE_CAST));
    }

    public function test_a_film_with_genres_and_no_people_deletes_only_its_genres(): void
    {
        $id = $this->insertFilm();
        $this->credits()->sync($id, ['Drama'], [], []);

        $transactions = $this->transactionStatements(fn () => $this->credits()->sync($id, ['Drama', 'Thriller'], [$this->person('David Fincher', 7467)], []));

        $this->assertCount(1, $transactions);
        $this->assertParentLockedFirst($transactions[0], 'movieinfo', $id, ['movie_genres', 'movie_people']);
        $this->assertSame(1, $this->deletesOn($transactions[0], 'movie_genres'));
        $this->assertSame(0, $this->deletesOn($transactions[0], 'movie_people'));
        $this->assertSame(['Drama', 'Thriller'], $this->genreTitles($id));
        $this->assertSame(['David Fincher'], $this->names($id, MovieCredits::ROLE_DIRECTOR));
    }

    public function test_inside_an_open_transaction_a_film_with_no_rows_still_deletes_first(): void
    {
        $id = $this->insertFilm();

        // The enclosing transaction's snapshot may predate rows another writer committed.
        $transactions = $this->transactionStatements(fn () => DB::transaction(fn () => $this->credits()->sync($id, ['Drama'], [], [$this->person('Brad Pitt', 287)])));

        $this->assertCount(1, $transactions);
        $this->assertSame(1, $this->deletesOn($transactions[0], 'movie_genres'));
        $this->assertSame(1, $this->deletesOn($transactions[0], 'movie_people'));
        $this->assertSame(['Drama'], $this->genreTitles($id));
        $this->assertSame(['Brad Pitt'], $this->names($id, MovieCredits::ROLE_CAST));
    }

    private function service(): MovieService
    {
        return new MovieService;
    }

    /**
     * @param  array<string, mixed>  ...$movies  One TMDB details response per fetch, in order.
     */
    private function fakeTmdb(array ...$movies): void
    {
        $sequence = Http::sequence();
        foreach ($movies as $movie) {
            $sequence->push($movie);
        }
        Http::fake([
            '*find/tt0137523*' => Http::response(['movie_results' => [['id' => 550]]]),
            '*movie/550?*' => $sequence,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tmdbMovie(array $overrides = []): array
    {
        $cast = [];
        foreach ([101, 102, 101, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114] as $tmdbId) {
            $cast[] = ['id' => $tmdbId, 'name' => 'Actor '.$tmdbId];
        }

        return array_replace([
            'id' => 550,
            'imdb_id' => 'tt0137523',
            'title' => 'Fight Club',
            'overview' => 'An insomniac.',
            'tagline' => '',
            'release_date' => '1999-10-15',
            'vote_average' => 8.4,
            'vote_count' => 0,
            'original_language' => 'en',
            'genres' => [['id' => 18, 'name' => 'Drama'], ['id' => 53, 'name' => 'Thriller']],
            'credits' => [
                'cast' => $cast,
                'crew' => [
                    ['id' => 7467, 'name' => 'David Fincher', 'department' => 'Directing', 'job' => 'Director'],
                    ['id' => 1, 'name' => 'An Assistant', 'department' => 'Directing', 'job' => 'First Assistant Director'],
                    ['id' => 7468, 'name' => 'Second Director', 'department' => 'Directing', 'job' => 'Director'],
                    ['id' => 7467, 'name' => 'David Fincher', 'department' => 'Directing', 'job' => 'Director'],
                ],
            ],
            'release_dates' => ['results' => [
                ['iso_3166_1' => 'DE', 'release_dates' => [['certification' => '18']]],
                ['iso_3166_1' => 'US', 'release_dates' => [['certification' => ''], ['certification' => 'R'], ['certification' => 'NC-17']]],
            ]],
        ], $overrides);
    }

    private function credits(): MovieCredits
    {
        return app(MovieCredits::class);
    }

    /**
     * @return array{name: string, tmdb_id: ?int}
     */
    private function person(string $name, ?int $tmdbId): array
    {
        return ['name' => $name, 'tmdb_id' => $tmdbId];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertFilm(array $values = []): int
    {
        return (int) DB::table('movieinfo')->insertGetId($values + [
            'imdbid' => self::IMDB_ID,
            'genre' => '',
            'director' => '',
            'actors' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function filmId(): int
    {
        return (int) DB::table('movieinfo')->where('imdbid', self::IMDB_ID)->value('id');
    }

    /**
     * @return list<string>
     */
    private function genreTitles(int $movieinfoId): array
    {
        return DB::table('movie_genres')->join('genres', 'genres.id', '=', 'movie_genres.genres_id')
            ->where('movieinfo_id', $movieinfoId)->orderBy('position')->pluck('title')->all();
    }

    /**
     * @return list<string>
     */
    private function names(int $movieinfoId, int $role): array
    {
        return DB::table('movie_people')->join('people', 'people.id', '=', 'movie_people.people_id')
            ->where('movieinfo_id', $movieinfoId)->where('role', $role)->orderBy('position')->pluck('name')->all();
    }

    /**
     * @return array{genres: list<array<string, mixed>>, people: list<array<string, mixed>>}
     */
    private function linkRows(): array
    {
        return [
            'genres' => DB::table('movie_genres')->orderBy('movieinfo_id')->orderBy('position')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'people' => DB::table('movie_people')->orderBy('movieinfo_id')->orderBy('role')->orderBy('position')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * @return list<array{name: string, tmdb_id: ?int}>
     */
    private function people(): array
    {
        return DB::table('people')->orderBy('id')->get(['name', 'tmdb_id'])
            ->map(static fn (object $row): array => ['name' => (string) $row->name, 'tmdb_id' => $row->tmdb_id === null ? null : (int) $row->tmdb_id])
            ->all();
    }

    /**
     * `people.name` is utf8mb4_unicode_ci in production, so case (and accents) do not tell
     * two names apart. SQLite compares bytes; the rebuilt column gets SQLite's NOCASE, which
     * ignores ASCII case. (An ICU collation would cover accents too, but registering one
     * crashes PHP 8.5's Pdo\Sqlite on shutdown.) The writer serialises every insert by name
     * under one lock, whatever the spelling, so an accent difference takes the same path.
     */
    private function caseInsensitivePeopleNames(): void
    {
        $statement = ProductionTables::fromAuthority()->createStatement('people');
        $collated = preg_replace('/^(\s*"name" \w+)/m', '$1 COLLATE NOCASE', $statement, 1, $count);
        $this->assertSame(1, $count);
        DB::statement('DROP TABLE "people"');
        DB::statement((string) $collated);
        DB::table('people')->insert(['name' => 'Jose Garcia']);
        $this->assertSame(1, DB::table('people')->where('name', 'JOSE GARCIA')->count());
        DB::table('people')->delete();
    }

    /**
     * Runs the other writer once, right after this writer's first name lookup on `people`
     * returns: as another worker adding the same person between that lookup and this
     * writer's insert.
     */
    private function interleaveAfterFirstNameLookup(\Closure $otherWriter): void
    {
        $done = false;
        DB::listen(static function ($query) use (&$done, $otherWriter): void {
            if ($done || preg_match('/^\s*select\b.*\bfrom "people"\s.*"name" = \?/is', $query->sql) !== 1) {
                return;
            }
            $done = true;
            $otherWriter();
        });
    }

    /**
     * Records every write to the link and name tables from now on.
     *
     * @return \Closure(): list<string>
     */
    private function recordLinkWrites(): \Closure
    {
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*"(movie_genres|movie_people|people|genres)"/is', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        return static function () use (&$writes): array {
            return $writes;
        };
    }

    private function rssMovieText(int $movieinfoId): string
    {
        $film = DB::table('movieinfo')->where('id', $movieinfoId)->first(['imdbid', 'rating', 'plot', 'year', 'genre', 'director', 'actors']);
        $release = [
            'searchname' => 'Fight.Club.1999.1080p',
            'guid' => 'release-guid',
            'adddate' => '2026-09-27 12:00:00',
            'postdate' => '2026-09-27 12:00:00',
            'category_name' => 'Movies > HD',
            'categories_id' => Category::MOVIE_HD,
            'size' => 1,
            'group_name' => 'alt.binaries.test',
            'fromname' => 'poster',
            'passwordstatus' => 0,
            'nfostatus' => 0,
            'parentid' => Category::MOVIE_ROOT,
            'musicinfo_id' => 0,
            'consoleinfo_id' => 0,
            '_totalrows' => 1,
        ] + (array) $film;

        $xml = (new XML_Response([
            'Parameters' => [
                'extended' => '0', 'del' => '0', 'token' => 'test-token', 'requests' => 1, 'apilimit' => 100,
                'grabs' => 0, 'downloadlimit' => 100, 'oldestapi' => '', 'oldestgrab' => '', 'uid' => 1,
            ],
            'Data' => [(object) $release],
            'Server' => ['server' => ['title' => 'NNTmux Tests', 'strapline' => 'Testing', 'email' => 'noreply@example.test', 'meta' => 'usenet', 'url' => 'https://indexer.example.test']],
            'Offset' => 0,
            'Type' => 'rss',
        ]))->returnXML();
        $this->assertIsString($xml);

        return $xml;
    }
}
