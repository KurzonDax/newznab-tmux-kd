<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use Database\Factories\VideoFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class TitleControllerTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        foreach (['2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        $this->createGenresTable();
        DB::table('genres')->insert(['id' => 1, 'title' => 'Adventure']);
        foreach (self::entityRoots() as [$root, $table, $key, $category]) {
            DB::table('root_categories')->insert(['id' => $category - 30, 'title' => ucfirst($root)]);
            DB::table('categories')->insert(['id' => $category, 'title' => 'HD', 'root_categories_id' => $category - 30]);
            Schema::create($table, function (Blueprint $table): void {
                $table->increments('id');
                foreach (['imdbid', 'tmdbid', 'traktid', 'title', 'year', 'rating', 'plot', 'genre', 'director', 'actors', 'artist', 'publisher', 'releasedate', 'review', 'url', 'author', 'publishdate', 'overview', 'platform', 'esrb', 'started', 'tracks', 'isbn', 'pages', 'trailer', 'classused', 'tvdb', 'tvmaze', 'trakt', 'imdb', 'tmdb'] as $column) {
                    $table->string($column)->nullable();
                }
                $table->integer('genres_id')->nullable();
                $table->boolean('cover')->default(false);
            });
        }
        Schema::create('tv_info', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id')->primary();
            $table->string('publisher')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('image')->default(false);
        });
        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->integer('series');
            $table->integer('episode');
            $table->string('firstaired')->nullable();
        });
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('title-artwork')]);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function entityRoots(): iterable
    {
        yield 'movies' => ['movies', 'movieinfo', 'imdbid', 2030];
        yield 'tv' => ['tv', 'videos', 'videos_id', 5030];
        yield 'audio' => ['audio', 'musicinfo', 'musicinfo_id', 3030];
        yield 'console' => ['console', 'consoleinfo', 'consoleinfo_id', 1030];
        yield 'games' => ['games', 'gamesinfo', 'gamesinfo_id', 4030];
        yield 'books' => ['books', 'bookinfo', 'bookinfo_id', 7030];
    }

    #[DataProvider('entityRoots')]
    public function test_titles_without_releases_render_an_overview_and_omit_missing_metadata(string $root, string $table, string $key, int $category): void
    {
        $record = ['id' => 12, 'imdbid' => '1234567', 'title' => 'A <quiet> title'];
        if ($root === 'tv') {
            $this->video($record);
        } else {
            DB::table($table)->insert($record);
        }
        $id = $root === 'movies' ? '1234567' : '12';
        $response = $this->actingAs($this->browserUser())->get('/title/'.$root.'/'.$id)->assertOk();
        $response->assertSee('A &lt;quiet&gt; title', false)->assertSee('No releases for this title.')
            ->assertSee('data-no-artwork', false)->assertDontSee('Unknown Director')->assertDontSee('aria-label="Release pages"', false);
        $this->assertSame(0, $response->viewData('results')->total());
    }

    public function test_movie_table_uses_all_allowed_releases_and_their_display_names(): void
    {
        DB::table('movieinfo')->insert(['id' => 12, 'imdbid' => '1234567', 'title' => 'A Movie', 'year' => '2024', 'director' => 'A Director', 'actors' => 'One, Two', 'plot' => 'A short plot.', 'tmdbid' => '42']);
        $this->release('Internal.1080p', ['imdbid' => '1234567', 'display_name' => 'Visible release 1080p', 'nfostatus' => -1, 'isrenamed' => 0]);
        $this->release('Passworded', ['imdbid' => '1234567', 'passwordstatus' => 2]);
        $this->release('Excluded', ['imdbid' => '1234567', 'categories_id' => 2040]);
        $this->release('Other movie', ['imdbid' => '7654321']);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'UHD', 'root_categories_id' => 2000]);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => 2040]);
        $response = $this->actingAs($user)->get('/title/movies/1234567')->assertOk();
        $response->assertSee('A Director')->assertSee('One, Two')->assertSee('A short plot.')->assertSee('Visible release 1080p')
            ->assertDontSee('Passworded')->assertDontSee('Excluded')->assertDontSee('Other movie')
            ->assertSee('https://www.themoviedb.org/movie/42', false)->assertDontSee('Runtime');
        $this->assertSame(1, $response->viewData('results')->total());
        $this->assertSame('A Movie', $response->viewData('results')->first()->row_data->entity->title);
    }

    public function test_tv_selects_the_newest_numbered_season_and_orders_packs_after_episodes(): void
    {
        $this->video(['id' => 12, 'title' => 'A Show', 'started' => '2022-01-01']);
        DB::table('tv_info')->insert(['videos_id' => 12, 'publisher' => 'FX', 'summary' => 'A show summary.']);
        foreach ([[1, 1], [3, 2], [3, 1], [0, 1]] as [$season, $episode]) {
            $episodeId = DB::table('tv_episodes')->insertGetId(['videos_id' => 12, 'series' => $season, 'episode' => $episode]);
            $this->release('Show.S'.$season.'E'.$episode.'.1080p', ['categories_id' => 5030, 'videos_id' => 12, 'tv_episodes_id' => $episodeId]);
        }
        $this->release('Show.S03.COMPLETE.2160p', ['categories_id' => 5030, 'videos_id' => 12]);
        $response = $this->actingAs($this->browserUser())->get('/title/tv/12')->assertOk();
        $response->assertSeeInOrder(['Season 1', 'Season 3', 'Specials'])
            ->assertSeeInOrder(['Show.S3E1.1080p', 'Show.S3E2.1080p', 'Show.S03.COMPLETE.2160p'])
            ->assertDontSee('Show.S1E1.1080p')->assertDontSee('Show.S0E1.1080p')->assertSee('2 episodes')->assertSee('1 season pack')
            ->assertSee('A show summary.')->assertDontSee('Cast')->assertDontSee('Returning');
        $this->assertSame(3, $response->viewData('selectedSeason'));
        $this->assertCount(3, $response->viewData('results'));
    }

    public function test_season_and_quality_fragments_return_only_the_requested_table(): void
    {
        $this->video(['id' => 12, 'title' => 'A Show']);
        foreach (['S01E01.720p', 'S02E01.1080p', 'S02E02.2160p'] as $name) {
            $this->release('Show.'.$name, ['categories_id' => 5030, 'videos_id' => 12]);
        }
        $response = $this->actingAs($this->browserUser())->get('/title/tv/12?season=2&quality[]=2160p&_fragment=releases')->assertOk();
        $response->assertSee('Show.S02E02.2160p')->assertDontSee('Show.S02E01.1080p')->assertDontSee('Show.S01E01.720p')
            ->assertDontSee('<!DOCTYPE html>', false);
        $this->assertSame(1, $response->viewData('results')->total());
    }

    public function test_every_fallback_release_remains_accessible_beyond_the_old_scan_limit(): void
    {
        $this->video(['id' => 12, 'title' => 'A Long Show']);
        for ($episode = 1; $episode <= 501; $episode++) {
            $this->release(sprintf('Long.Show.S01E%03d.1080p', $episode), ['categories_id' => 5030, 'videos_id' => 12]);
        }
        $response = $this->actingAs($this->browserUser())->get('/title/tv/12?page=6')->assertOk();
        $response->assertSee('Long.Show.S01E501.1080p')->assertSee('aria-label="Release pages"', false);
        $this->assertSame(501, $response->viewData('results')->total());
        $this->assertCount(1, $response->viewData('results'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function storedTrailers(): iterable
    {
        yield 'YouTube URL' => ['https://www.youtube.com/watch?v=Way9Dexny3w', 'https://www.youtube-nocookie.com/embed/Way9Dexny3w'];
        yield 'legacy iframe' => ['<iframe src="https://www.youtube.com/embed/Way9Dexny3w"></iframe>', 'https://www.youtube-nocookie.com/embed/Way9Dexny3w'];
        yield 'Trailer Addict' => ['https://v.traileraddict.com/12345', 'https://v.traileraddict.com/12345'];
    }

    #[DataProvider('storedTrailers')]
    public function test_stored_trailers_open_in_a_lazy_shared_modal_with_a_safe_embed_url(string $stored, string $embed): void
    {
        DB::table('movieinfo')->insert(['id' => 12, 'imdbid' => '1234567', 'title' => 'A Movie',
            'trailer' => $stored]);
        $response = $this->actingAs($this->browserUser())->get('/title/movies/1234567')->assertOk();
        $response->assertSee('data-trailer-url="'.$embed.'"', false)
            ->assertSee('x-data="trailerModal"', false)->assertSee('data-trailer-player', false)->assertDontSee('<iframe', false)
            ->assertDontSee('href="https://www.youtube', false);
        DB::table('movieinfo')->update(['trailer' => 'https://example.test/untrusted-frame']);
        $this->get('/title/movies/1234567')->assertOk()->assertDontSee('data-trailer-url', false)->assertDontSee('x-data="trailerModal"', false);
    }

    public function test_imdb_leading_zeroes_survive_title_release_and_watch_lookups(): void
    {
        DB::table('movieinfo')->insert(['id' => 12, 'imdbid' => '0111161', 'title' => 'The Shawshank Redemption']);
        $this->release('Shawshank.1080p', ['imdbid' => '0111161']);
        $user = $this->browserUser();
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '0111161']);
        $response = $this->actingAs($user)->get('/title/movies/0111161')->assertOk();
        $response->assertSee('Shawshank.1080p')->assertViewHas('watched', true)->assertSee('/watchlist/movies/0111161', false);
        $this->assertSame('0111161', $response->viewData('title')->entity->id);
        $this->assertSame(1, $response->viewData('results')->total());
        $this->followingRedirects()->get(route('movie.view', 'tt0111161'))->assertOk()->assertSee('The Shawshank Redemption');
    }

    public function test_movie_alias_keeps_filters_and_links_to_the_canonical_overview(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user)->get(route('movie.view', ['imdbid' => 'tt1234567', 'quality' => ['1080p'], 'page' => 2]))
            ->assertRedirect(route('title', ['root' => 'movies', 'id' => '1234567', 'quality' => ['1080p'], 'page' => 2]));
    }

    public function test_album_metadata_tracks_formats_and_artwork_use_stored_values(): void
    {
        DB::table('musicinfo')->insert(['id' => 12, 'title' => 'An Album', 'artist' => 'The Artist', 'publisher' => 'A Label',
            'year' => '', 'releasedate' => '2024-02-01', 'genres_id' => 1, 'tracks' => '1. First Song<br>2. Second Song',
            'url' => 'https://musicbrainz.org/release/album-id']);
        File::ensureDirectoryExists(config('nntmux_settings.covers_path').'/music');
        file_put_contents(config('nntmux_settings.covers_path').'/music/12.jpg', 'album');
        foreach (['MP3', 'FLAC', '24-bit.FLAC'] as $format) {
            $this->release('Album.'.$format, ['categories_id' => 3030, 'musicinfo_id' => 12]);
        }
        $response = $this->actingAs($this->browserUser())->get('/title/audio/12')->assertOk();
        $response->assertSee('The Artist')->assertSee('A Label')->assertSee('Adventure')->assertSee('First Song')->assertSee('Second Song')
            ->assertSee('/covers/music/12.jpg', false)->assertSee('https://musicbrainz.org/release/album-id', false);
        $this->assertSame('24-bit FLAC', $response->viewData('bestQuality'));
        $this->assertSame('2024', $response->viewData('title')->entity->year);
        $filtered = $this->get('/title/audio/12?quality[]=FLAC&quality[]=24-bit+FLAC&_fragment=releases')->assertOk();
        $filtered->assertDontSee('Album.MP3')->assertSee('Album.FLAC')->assertSee('Album.24-bit.FLAC');
        $this->assertSame(2, $filtered->viewData('results')->total());
        $this->assertSame(route('title', ['root' => 'audio', 'id' => '12']), $response->viewData('results')->first()->row_data->entity->titleUrl());
    }

    public function test_numeric_album_track_count_does_not_invent_a_track_list_or_provider_link(): void
    {
        DB::table('musicinfo')->insert(['id' => 12, 'title' => 'Count Album', 'tracks' => '12', 'url' => 'javascript:alert(1)']);
        $response = $this->actingAs($this->browserUser())->get('/title/audio/12')->assertOk();
        $this->assertSame('12', $response->viewData('title')->metadata['Tracks']);
        $this->assertSame([], $response->viewData('title')->tracks);
        $this->assertSame([], $response->viewData('title')->links);
        $response->assertDontSee('javascript:alert');
    }

    public function test_book_overview_escapes_metadata_and_uses_its_isbn_link(): void
    {
        DB::table('bookinfo')->insert(['id' => 12, 'title' => 'A Book', 'author' => 'An Author', 'pages' => '320',
            'isbn' => '978-0-123456-78-9', 'publishdate' => '2021-01-01', 'overview' => '<p>A &lt;quiet&gt; story.</p>']);
        $this->release('Book.EPUB', ['categories_id' => 7030, 'bookinfo_id' => 12]);
        $response = $this->actingAs($this->browserUser())->get('/title/books/12')->assertOk();
        $response->assertSee('An Author')->assertSee('320')->assertSee('https://isbndb.com/book/9780123456789', false)
            ->assertSee('A &lt;quiet&gt; story.', false)->assertDontSee('<quiet>', false)->assertSee('/title/books/12', false);
    }

    public function test_show_only_matches_are_available_as_specials_without_becoming_season_packs(): void
    {
        $this->video(['id' => 12, 'title' => 'Sterling Point']);
        $this->release('Sterling Point (2026)', ['categories_id' => 5030, 'videos_id' => 12, 'tv_episodes_id' => -6]);
        $response = $this->actingAs($this->browserUser())->get('/title/tv/12')->assertOk();
        $response->assertSee('Sterling Point (2026)')->assertSee('Specials');
        $this->assertSame(0, $response->viewData('seasonPackCount'));
        $this->assertSame(0, $response->viewData('selectedPackCount'));
        $this->assertSame(1, $response->viewData('results')->total());
    }

    public function test_title_watch_state_refreshes_and_treats_legacy_null_categories_as_unrestricted(): void
    {
        DB::table('movieinfo')->insert(['id' => 12, 'imdbid' => '1234567', 'title' => 'A Movie']);
        $user = $this->browserUser();
        $this->actingAs($user)->get('/title/movies/1234567')->assertOk()->assertViewHas('watched', false);
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '1234567', 'categories' => 'NULL']);
        $response = $this->get('/title/movies/1234567')->assertOk()->assertSee('All categories')->assertViewHas('watched', true);
        $response->assertSee('data-watch-remove=', false)->assertSee('/watchlist/movies/1234567', false);
        DB::table('user_movies')->update(['categories' => '2030']);
        $this->get('/title/movies/1234567')->assertOk()->assertViewHas('watchCategories', ['HD']);
    }

    /** @param array<string, mixed> $attributes */
    private function video(array $attributes): void
    {
        DB::table('videos')->insert(array_intersect_key(
            VideoFactory::new()->raw(['started' => null, ...$attributes]),
            array_flip(Schema::getColumnListing('videos')),
        ));
    }

    public function test_unknown_and_non_entity_roots_are_not_found_and_permissions_apply_to_titles(): void
    {
        $this->actingAs($this->createUserWithRole('User'));
        foreach (['all', 'xxx', 'other', 'unknown'] as $root) {
            $this->get('/title/'.$root.'/12')->assertNotFound();
        }
        $this->get('/title/movies/1234567')->assertForbidden();
    }

    public function test_missing_titles_are_not_found_for_an_authorized_user(): void
    {
        $this->actingAs($this->browserUser())->get('/title/movies/1234567')->assertNotFound();
    }
}
