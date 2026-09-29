<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\ImdbScraper;
use App\Services\MetadataProcessing\MovieCredits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The admin movie edit form's genre, director and actors reach the film's rows, as saved. */
final class AdminMovieEditCreditsTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    private const string IMDB_ID = '0137523';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        Search::spy();
        Http::preventStrayRequests();
        config(['tmdb.api_key' => '', 'nntmux_api.omdb_api_key' => '', 'nntmux_api.trakttv_api_key' => '', 'nntmux_api.fanarttv_api_key' => '']);
        $scraper = $this->mock(ImdbScraper::class);
        $scraper->shouldReceive('fetchById')->andReturnFalse();
        $scraper->shouldReceive('wasBlockedByWaf')->andReturnFalse();
        $scraper->shouldReceive('getLastFailureReason', 'getLastFallbackFailureReason', 'getLastFetchSource')->andReturnNull();

        $tables = ProductionTables::fromAuthority();
        foreach (['movieinfo', 'genres', 'people', 'movie_genres', 'movie_people'] as $table) {
            $tables->create($table);
        }
        $tables->create('releases', ['id', 'guid', 'imdbid', 'movieinfo_id']);
        DB::table('movieinfo')->insert([
            'imdbid' => self::IMDB_ID, 'title' => 'Fight Club', 'cover' => 1,
            'genre' => 'Drama', 'director' => 'David Fincher', 'actors' => 'Brad Pitt', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_edited_text_reaches_the_rows_as_it_was_saved(): void
    {
        $admin = $this->admin();
        $longGenre = 'Action, Adventure, Comedy, Crime, Documentary, Drama, Family, Science Fiction';

        $this->actingAs($admin)->post(route('admin.movie-edit'), $this->form([
            'genre' => $longGenre,
            'director' => 'Joe Russo, Anthony Russo',
            'actors' => 'Robert Downey, Jr., Chris Evans',
        ]))->assertRedirect();

        $id = (int) DB::table('movieinfo')->where('imdbid', self::IMDB_ID)->value('id');
        $this->assertSame(substr($longGenre, 0, 64), DB::table('movieinfo')->where('id', $id)->value('genre'));
        $this->assertSame(['Action', 'Adventure', 'Comedy', 'Crime', 'Documentary', 'Drama', 'Family'], $this->genreTitles($id));
        $this->assertSame(['Joe Russo', 'Anthony Russo'], $this->names($id, MovieCredits::ROLE_DIRECTOR));
        $this->assertSame(['Robert Downey Jr.', 'Chris Evans'], $this->names($id, MovieCredits::ROLE_CAST));

        // An emptied field is not saved, so its rows follow the text that stays.
        $this->actingAs($admin)->post(route('admin.movie-edit'), $this->form([
            'genre' => 'Drama',
            'director' => 'Joe Russo, Anthony Russo',
            'actors' => '',
        ]))->assertRedirect();

        $this->assertSame(['Drama'], $this->genreTitles($id));
        $this->assertSame(['Robert Downey Jr.', 'Chris Evans'], $this->names($id, MovieCredits::ROLE_CAST));
    }

    /**
     * @param  array<string, string>  $credits
     * @return array<string, string>
     */
    private function form(array $credits): array
    {
        return $credits + [
            'id' => self::IMDB_ID,
            'action' => 'submit',
            'title' => 'Fight Club',
            'year' => '1999',
            'rating' => '8.8',
            'plot' => 'An insomniac.',
            'tagline' => '',
            'language' => 'English',
        ];
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
}
