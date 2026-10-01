<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
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
            ProductionTables::fromAuthority()->create($table);
        }
        ProductionTables::fromAuthority()->create('console_genres');
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
        yield 'audio' => ['audio', 'musicinfo', 'musicinfo_id', 3030];
        yield 'console' => ['console', 'consoleinfo', 'consoleinfo_id', 1030];
        yield 'games' => ['games', 'gamesinfo', 'gamesinfo_id', 4030];
        yield 'books' => ['books', 'bookinfo', 'bookinfo_id', 7030];
    }

    #[DataProvider('entityRoots')]
    public function test_titles_without_releases_render_an_overview_and_omit_missing_metadata(string $root, string $table, string $key, int $category): void
    {
        DB::table($table)->insert(['id' => 12, 'title' => 'A <quiet> title']);
        $response = $this->actingAs($this->browserUser())->get('/title/'.$root.'/12')->assertOk();
        $response->assertSee('A &lt;quiet&gt; title', false)->assertSee('No releases for this title.')
            ->assertSee('data-no-artwork', false)->assertDontSee('Unknown Director')->assertDontSee('aria-label="Release pages"', false);
        $this->assertSame(0, $response->viewData('results')->total());
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

    public function test_a_console_game_shows_every_genre_in_order_on_its_genre_line(): void
    {
        DB::table('genres')->insert(['id' => 2, 'title' => 'Shooter', 'type' => 1000]);
        DB::table('consoleinfo')->insert(['id' => 12, 'title' => 'A Game', 'platform' => 'PS5', 'genres_id' => 2]);
        DB::table('console_genres')->insert([
            ['consoleinfo_id' => 12, 'genres_id' => 1, 'position' => 1],
            ['consoleinfo_id' => 12, 'genres_id' => 2, 'position' => 0],
        ]);

        $response = $this->actingAs($this->browserUser())->get('/title/console/12')->assertOk();

        $this->assertSame('Shooter,Adventure', $response->viewData('title')->metadata['Genre']);
        $response->assertSee('Shooter,Adventure');
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

    public function test_unknown_and_non_entity_roots_are_not_found_and_permissions_apply_to_titles(): void
    {
        $this->actingAs($this->createUserWithRole('User'));
        foreach (['all', 'movies', 'tv', 'xxx', 'other', 'unknown'] as $root) {
            $this->get('/title/'.$root.'/12')->assertNotFound();
        }
        $this->get('/title/console/12')->assertForbidden();
    }

    public function test_missing_titles_are_not_found_for_an_authorized_user(): void
    {
        $this->actingAs($this->browserUser())->get('/title/console/12')->assertNotFound();
        $this->get('/title/movies/1234567')->assertNotFound();
    }
}
