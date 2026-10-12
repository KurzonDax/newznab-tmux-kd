<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\Support\ReadsRenderedMarkup;
use Tests\TestCase;

/**
 * Issue #1057: admin cover images are served by the covers route from the file that exists,
 * and an image that cannot be loaded gives way to the "no cover" picture.
 */
final class AdminCoverImagesTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;
    use ReadsRenderedMarkup;

    private string $covers;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage(itemsPerPage: 25);
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createGenresTable();
        $this->createBookInfoTable();
        $this->createGamesInfoTable();
        $this->createMusicInfoTable();
        DB::table('genres')->insert(['id' => 1, 'title' => 'Invented genre', 'type' => 1, 'disabled' => false]);

        $this->covers = $this->makeTempDirectory('nntmux-admin-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
        $this->administrator = $this->admin();
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_book_list_and_edit_pages_show_the_cover_file_that_exists_with_the_placeholder_as_its_fallback(): void
    {
        $webp = $this->book('Invented Book With A WebP Cover', cover: 1);
        $jpg = $this->book('Invented Book With A JPEG Cover', cover: 1);
        $this->coverFile('book', $webp.'.webp');
        $this->coverFile('book', $jpg.'.jpg');

        $list = $this->page(route('admin.book-list'));
        foreach ([$webp => 'webp', $jpg => 'jpg'] as $id => $extension) {
            $this->assertCoverWithFallback($list, url('/covers/book/'.$id.'.'.$extension));
            $this->assertCoverWithFallback($this->page(route('admin.book-edit', ['id' => $id])), url('/covers/book/'.$id.'.'.$extension));
        }
        $this->assertCount(2, $this->openingTags($list, 'img', 'x-data="imageFallback"'));
    }

    public function test_a_book_whose_cover_file_is_missing_shows_what_a_book_without_a_cover_shows(): void
    {
        $flagged = $this->book('Invented Book Missing Its File', cover: 1);
        $this->book('Invented Book Without A Cover', cover: 0);

        $list = $this->page(route('admin.book-list'));
        $this->assertSame([], $this->openingTags($list, 'img', 'covers/'));
        $this->assertSame([], $this->openingTags($list, 'img', 'x-data="imageFallback"'));
        $this->assertCount(2, $this->elements($this->xpath($list), '//td/div[i[contains(concat(" ", @class, " "), " fa-book ")]]'));

        $edit = $this->page(route('admin.book-edit', ['id' => $flagged]));
        $this->assertSame([], $this->openingTags($edit, 'img', 'covers/'));
        $this->assertStringContainsString('No cover image available', $edit);
    }

    public function test_the_game_and_music_edit_pages_show_the_cover_file_that_exists_and_no_image_without_one(): void
    {
        $game = (int) DB::table('gamesinfo')->insertGetId(['title' => 'Invented Game', 'asin' => 'invented-game', 'genres_id' => 1, 'cover' => 1, 'trailer' => '', 'created_at' => now(), 'updated_at' => now()]);
        $album = (int) DB::table('musicinfo')->insertGetId(['title' => 'Invented Album', 'asin' => 'invented-album', 'artist' => 'Invented Artist', 'year' => '2001', 'genres_id' => 1, 'cover' => 1, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([['games', $game, route('admin.game-edit', ['id' => $game]), 'Game Cover'], ['music', $album, route('admin.music-edit', ['id' => $album]), 'Album Cover']] as [$type, $id, $url, $alt]) {
            $without = $this->page($url);
            $this->assertSame([], $this->openingTags($without, 'img', 'alt="'.$alt.'"'), $type.' without a file');
            $this->assertSame([], $this->openingTags($without, 'img', 'covers/'), $type.' without a file');

            $this->coverFile($type, $id.'.jpg');
            $with = $this->openingTags($this->page($url), 'img', 'alt="'.$alt.'"');
            $this->assertCount(1, $with, $type);
            $this->assertSame(url('/covers/'.$type.'/'.$id.'.jpg'), $this->attribute($with[0], 'src'));
        }
    }

    public function test_the_show_edit_cover_falls_back_to_the_placeholder(): void
    {
        ProductionTables::fromAuthority()->create('videos');
        ProductionTables::fromAuthority()->create('tv_info');
        DB::table('videos')->insert(['id' => 41, 'type' => 0, 'title' => 'Invented Show', 'started' => '2020-01-01 00:00:00', 'countries_id' => 'US']);
        DB::table('tv_info')->insert(['videos_id' => 41, 'summary' => 'Invented summary', 'publisher' => 'Invented Network', 'image' => 1]);

        $page = $this->page(route('admin.show-edit', ['id' => 41]));

        $images = $this->openingTags($page, 'img', 'x-data="imageFallback"');
        $this->assertCount(1, $images);
        $this->assertSame('Invented Show', $this->attribute($images[0], 'alt'));
        $this->assertSame(asset('assets/images/no-cover.png'), $this->attribute($images[0], 'data-fallback-src'));
    }

    public function test_the_placeholder_is_a_published_file_and_no_view_builds_an_address_under_storage_covers(): void
    {
        $this->assertFileExists(public_path('assets/images/no-cover.png'));
        $this->assertFileDoesNotExist(public_path('images/no-cover.png'));

        foreach (File::allFiles(resource_path('views')) as $view) {
            $source = $view->getContents();
            $this->assertStringNotContainsString('storage/covers', $source, $view->getRelativePathname());
            $this->assertStringNotContainsString("asset('images/no-cover.png')", $source, $view->getRelativePathname());
        }
    }

    private function page(string $url): string
    {
        return (string) $this->actingAs($this->administrator)->get($url)->assertOk()->getContent();
    }

    /**
     * The page shows one cover image at the given address, mounted as an `imageFallback` root
     * whose placeholder is the published "no cover" picture.
     */
    private function assertCoverWithFallback(string $html, string $address): void
    {
        $images = $this->openingTags($html, 'img', 'src="'.$address.'"');
        $this->assertCount(1, $images, $address);
        $this->assertSame('imageFallback', $this->attribute($images[0], 'x-data'));
        $this->assertSame(asset('assets/images/no-cover.png'), $this->attribute($images[0], 'data-fallback-src'));
    }

    private function book(string $title, int $cover): int
    {
        return (int) DB::table('bookinfo')->insertGetId([
            'title' => $title, 'author' => 'Invented Author', 'asin' => 'invented-'.md5($title), 'genre' => 'Fiction', 'cover' => $cover,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function coverFile(string $type, string $filename): void
    {
        File::ensureDirectoryExists($this->covers.'/'.$type);
        File::put($this->covers.'/'.$type.'/'.$filename, 'invented image bytes');
    }
}
