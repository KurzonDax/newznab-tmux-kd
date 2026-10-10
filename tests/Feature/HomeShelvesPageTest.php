<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Content;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The home page as shelves, GET / (issue #1037; docs/proposals/home-redesign/SPEC.md 2 to 5 and 7,
 * DATA-NOTES.md 3 to 5): the shelves a user ticked in the user's order, each shelf's content rule
 * and count line, the Following shelf and the last visit, the panel fragment, the empty states and
 * the stored preference.
 */
final class HomeShelvesPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const MISC = 10;

    private const HASHED = 20;

    private const MOVIE_HD = 2040;

    private const TV_HD = 5040;

    private const TV_UHD = 5045;

    private const AUDIO_MP3 = 3010;

    private const BOOKS_EBOOK = 7020;

    private const BOOKS_COMICS = 7030;

    private const CONSOLE_PS4 = 1180;

    private const PC_0DAY = 4010;

    private const XXX_X264 = 6040;

    private const ALL = ['Following', 'TV', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other'];

    private ?User $user = null;

    private ?int $lastUserId = null;

    private string $covers = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->covers = $this->makeTempDirectory('home-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'name', 'searchname', 'guid', 'display_name', 'categories_id', 'category_band', 'size', 'totalpart',
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source', 'predb_id']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'tv_episodes', 'movieinfo', 'consoleinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages'] as $table) {
            $tables->create($table);
        }
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        DB::table('root_categories')->where('id', 1)->update(['title' => 'Other']);
        DB::table('root_categories')->insert([
            ['id' => 1000, 'title' => 'Console', 'status' => 1], ['id' => 2000, 'title' => 'Movies', 'status' => 1], ['id' => 3000, 'title' => 'Audio', 'status' => 1],
            ['id' => 4000, 'title' => 'Games', 'status' => 1], ['id' => 5000, 'title' => 'TV', 'status' => 1], ['id' => 6000, 'title' => 'XXX', 'status' => 1],
            ['id' => 7000, 'title' => 'Books', 'status' => 1],
        ]);
        foreach ([self::MISC => [1, 'Misc'], self::HASHED => [1, 'Hashed'], self::MOVIE_HD => [2000, 'HD'], self::TV_HD => [5000, 'HD'], self::TV_UHD => [5000, 'UHD'],
            self::AUDIO_MP3 => [3000, 'MP3'], self::BOOKS_EBOOK => [7000, 'Ebook'], self::BOOKS_COMICS => [7000, 'Comics'], self::CONSOLE_PS4 => [1000, 'PS4'],
            self::PC_0DAY => [4000, '0day'], self::XXX_X264 => [6000, 'x264']] as $id => [$root, $title]) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => $root, 'status' => 1]);
        }
        DB::table('videos')->insert([
            ['id' => 11, 'type' => 0, 'title' => 'Glass Meridian', 'started' => '2024-01-01 00:00:00'],
            ['id' => 12, 'type' => 0, 'title' => 'Salt Harbour', 'started' => '2020-01-01 00:00:00'],
            ['id' => 13, 'type' => 0, 'title' => 'Unaired Pilot', 'started' => '2026-01-01 00:00:00'],
        ]);
        DB::table('movieinfo')->insert([
            ['id' => 41, 'imdbid' => '0000041', 'title' => 'Quiet Orbit', 'year' => '2024'],
            ['id' => 42, 'imdbid' => '0000042', 'title' => 'Paper Lanterns', 'year' => '2019'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_new_user_gets_the_default_shelves_in_order_on_the_first_visit_and_after_it(): void
    {
        $first = $this->page()->assertOk()->assertViewIs('content.home')
            ->assertSee('<h1 data-part="page title">Home</h1>', false)
            ->assertDontSee('Latest releases')->assertDontSee('style="', false);
        $this->assertSame(['Following', 'TV', 'Movies', 'Audio', 'Books'], $this->shelfNames($first));
        $html = (string) $first->getContent();
        // the heading row: the title and the one control
        $this->assertMatchesRegularExpression('/<div class="tv-filters home-heading">\s*<h1 data-part="page title">Home<\/h1>\s*<span class="tv-grow"><\/span>\s*'
            .'<button type="button" class="tv-details-button is-secondary home-tool" data-shelves-open><i class="fas fa-sliders" aria-hidden="true"><\/i>Shelves<\/button>\s*<\/div>/', $html);
        $this->assertNoWatchWording($html, 'Home');
        $this->assertSame(self::ALL, $this->dialogRows($first, false));
        $this->assertSame(['Following', 'TV', 'Movies', 'Audio', 'Books'], $this->dialogRows($first, true));
        // drag and drop only: no arrow buttons in the dialog
        $this->assertSame(9, substr_count($html, 'data-grip'));
        $this->assertDoesNotMatchRegularExpression('/home-shelf-row.{0,900}fa-(arrow|chevron|caret|angle)-(up|down)/s', $html);

        $stored = $this->home();
        $this->assertSame(Carbon::now()->getTimestamp(), $stored['seen_at']);
        $this->assertArrayNotHasKey('shelves', $stored);
        $this->assertArrayNotHasKey('ticked', $stored);
        // the first render stored seen_at under `home`: the default still applies
        $this->assertSame(['Following', 'TV', 'Movies', 'Audio', 'Books'], $this->shelfNames($this->page()->assertOk()));
    }

    public function test_the_tv_shelf_is_one_tile_per_show_with_a_release_added_in_the_last_day(): void
    {
        $this->rel('Glass.S01E01.HD', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-25 09:00:00', 'postdate' => '2026-09-25 08:00:00']);
        $this->rel('Glass.S01E01.UHD', ['categories_id' => self::TV_UHD, 'videos_id' => 11, 'adddate' => '2026-09-25 10:00:00', 'postdate' => '2026-09-25 07:00:00']);
        $this->rel('Glass.S01E02.HD', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-24 13:00:00', 'postdate' => '2026-09-24 12:30:00']);
        $this->rel('Glass.Old', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-23 10:00:00', 'postdate' => '2026-09-25 11:00:00']);
        $this->rel('Salt.S02E01', ['categories_id' => self::TV_HD, 'videos_id' => 12, 'adddate' => '2026-09-25 11:00:00', 'postdate' => '2026-09-25 10:30:00']);
        $this->rel('Unmatched.Show', ['categories_id' => self::TV_HD, 'videos_id' => 0, 'adddate' => '2026-09-25 11:00:00']);
        $this->rel('Hidden.Show', ['categories_id' => self::TV_HD, 'videos_id' => 13, 'adddate' => '2026-09-25 11:00:00', 'passwordstatus' => 1]);
        File::ensureDirectoryExists($this->covers.'/tvshows');
        File::put($this->covers.'/tvshows/11.jpg', 'jpg');

        $response = $this->page();
        $this->assertSame('5 today · 2 shows with new episodes', $this->countLine($response, 'TV'));
        $tiles = $this->tiles($response, 'TV');
        // by the show's newest posting
        $this->assertSame([['Salt Harbour', '1 episode today'], ['Glass Meridian', '3 episodes today']], array_map(static fn (array $tile): array => [$tile['title'], $tile['what']], $tiles));
        $this->assertSame(['show', '12'], [$tiles[0]['kind'], $tiles[0]['id']]);
        $html = (string) $response->getContent();
        // a button with the wall tile's look: the poster, or the title card without one; never a link
        $this->assertMatchesRegularExpression('/<button type="button" class="home-tile tv-tile"\s+data-tile data-kind="show" data-id="11" aria-expanded="false" title="Glass Meridian">\s*<span class="tv-tile-art">\s*'
            .'<img src="'.preg_quote(url('/covers/tvshows/11.jpg'), '/').'" alt="" loading="lazy">/', $html);
        $this->assertMatchesRegularExpression('/data-id="12"[^>]*>\s*<span class="tv-tile-art">\s*<span class="tv-tile-card">Salt Harbour<\/span>/', $html);
        $this->assertStringNotContainsString('<a class="tv-tile', $html);
        $response->assertSee('href="'.url('/tv').'">All TV', false)->assertSee('aria-label="Scroll TV left"', false)->assertSee('aria-label="Scroll TV right"', false);
    }

    public function test_the_tv_count_names_every_show_while_the_rail_holds_sixty(): void
    {
        foreach (range(1, 61) as $index) {
            DB::table('videos')->insert(['id' => 100 + $index, 'type' => 0, 'title' => 'Show '.$index]);
            $this->rel('Show.'.$index.'.S01E01', ['categories_id' => self::TV_HD, 'videos_id' => 100 + $index, 'adddate' => '2026-09-25 09:00:00',
                'postdate' => Carbon::parse('2026-09-25 08:00:00')->addMinutes($index)->toDateTimeString()]);
        }

        $response = $this->page();
        $this->assertSame('61 today · 61 shows with new episodes', $this->countLine($response, 'TV'));
        $titles = array_column($this->tiles($response, 'TV'), 'title');
        $this->assertCount(60, $titles);
        $this->assertSame(['Show 61', 'Show 60'], array_slice($titles, 0, 2));
        $this->assertNotContains('Show 1', $titles);
    }

    public function test_the_movies_shelf_is_one_tile_per_film_with_a_release_added_in_the_last_week(): void
    {
        $this->rel('Quiet.Orbit.1080p', ['categories_id' => self::MOVIE_HD, 'movieinfo_id' => 41, 'imdbid' => '0000041', 'adddate' => '2026-09-20 09:00:00', 'postdate' => '2026-09-25 09:00:00']);
        $this->rel('Quiet.Orbit.2160p', ['categories_id' => self::MOVIE_HD, 'movieinfo_id' => 41, 'imdbid' => '0000041', 'adddate' => '2026-09-25 11:00:00', 'postdate' => '2026-09-24 09:00:00']);
        $this->rel('Paper.Lanterns', ['categories_id' => self::MOVIE_HD, 'movieinfo_id' => 42, 'imdbid' => '0000042', 'adddate' => '2026-09-25 11:30:00', 'postdate' => '2026-09-25 11:00:00']);
        $this->rel('Paper.Lanterns.Old', ['categories_id' => self::MOVIE_HD, 'movieinfo_id' => 42, 'imdbid' => '0000042', 'adddate' => '2026-09-10 11:30:00']);
        File::ensureDirectoryExists($this->covers.'/movies');
        File::put($this->covers.'/movies/0000042-cover.jpg', 'jpg');

        $response = $this->page();
        $this->assertSame('2 today · 2 films this week', $this->countLine($response, 'Movies'));
        $this->assertSame([['Paper Lanterns (2019)', '1 release · 1 hr ago'], ['Quiet Orbit (2024)', '2 releases · 3 hr ago']],
            array_map(static fn (array $tile): array => [$tile['title'], $tile['what']], $this->tiles($response, 'Movies')));
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/class="home-tile tv-tile is-film"\s+data-tile data-kind="film" data-id="42"[^>]*>\s*<span class="tv-tile-art">\s*<img src="'
            .preg_quote(url('/covers/movies/0000042-cover.jpg'), '/').'"/', $html);
        $this->assertMatchesRegularExpression('/data-id="41"[^>]*>\s*<span class="tv-tile-art">\s*<span class="tv-tile-card"><span class="tv-tile-card-title">Quiet Orbit \(2024\)<\/span><small>2024<\/small>/', $html);

        foreach (range(1, 61) as $index) {
            DB::table('movieinfo')->insert(['id' => 100 + $index, 'imdbid' => (string) (1000000 + $index), 'title' => 'Film '.$index, 'year' => '2026']);
            $this->rel('Film.'.$index, ['categories_id' => self::MOVIE_HD, 'movieinfo_id' => 100 + $index, 'adddate' => '2026-09-22 09:00:00', 'postdate' => '2026-09-22 08:00:00']);
        }
        $response = $this->page();
        $this->assertSame('2 today · 63 films this week', $this->countLine($response, 'Movies'));
        $this->assertCount(60, $this->tiles($response, 'Movies'));
    }

    public function test_every_section_shelf_shows_its_newest_sixty_newest_first(): void
    {
        $user = $this->userWith(['shelves' => self::ALL, 'ticked' => ['Books', 'Console', 'PC', 'Adult', 'Other']]);
        foreach (range(1, 61) as $index) {
            $this->rel('Hashed.'.$index, ['categories_id' => self::HASHED, 'postdate' => Carbon::parse('2026-09-24 08:00:00')->addMinutes($index)->toDateTimeString()]);
        }
        $this->rel('Misc.Newest', ['categories_id' => self::MISC, 'postdate' => '2026-09-25 11:00:00', 'adddate' => '2026-09-25 11:30:00', 'size' => 2147483648]);
        $this->rel('Book.Older', ['categories_id' => self::BOOKS_EBOOK, 'postdate' => '2026-09-20 10:00:00', 'size' => 524288000]);
        $this->rel('Book.Newer', ['categories_id' => self::BOOKS_COMICS, 'postdate' => '2026-09-21 10:00:00', 'adddate' => '2026-09-22 12:00:00', 'size' => 104857600]);
        $this->rel('Console.Game', ['categories_id' => self::CONSOLE_PS4]);
        $this->rel('PC.Program', ['categories_id' => self::PC_0DAY]);
        $this->rel('Adult.Scene', ['categories_id' => self::XXX_X264]);
        $this->rel('A.Movie', ['categories_id' => self::MOVIE_HD]);

        $response = $this->page('/', $user);
        $this->assertSame(['Books', 'Console', 'PC', 'Adult', 'Other'], $this->shelfNames($response));
        $other = $this->tiles($response, 'Other');
        $this->assertCount(60, $other);
        $this->assertSame(['Misc.Newest', 'Hashed.61', 'Hashed.60'], array_slice(array_column($other, 'title'), 0, 3));
        $this->assertNotContains('Hashed.1', array_column($other, 'title'));
        $this->assertSame(['kind' => 'rel', 'class' => 'home-tile is-card', 'what' => '2.00 GB · 30 min ago', 'label' => 'Misc'],
            array_intersect_key($other[0], array_flip(['kind', 'class', 'what', 'label'])));
        $books = $this->tiles($response, 'Books');
        $this->assertSame([['Book.Newer', '100 MB · 3 days ago', 'Comics'], ['Book.Older', '500 MB · Sep 13, 2026', 'Ebook']],
            array_map(static fn (array $tile): array => [$tile['title'], $tile['what'], $tile['label']], $books));
        $this->assertSame(['Console.Game'], array_column($this->tiles($response, 'Console'), 'title'));
        $this->assertSame(['PC.Program'], array_column($this->tiles($response, 'PC'), 'title'));
        $this->assertSame(['Adult.Scene'], array_column($this->tiles($response, 'Adult'), 'title'));
        $response->assertDontSee('A.Movie')
            ->assertSee('href="'.url('/browse/other').'">All Other', false)->assertSee('href="'.url('/pc').'">All PC', false)
            ->assertSee('href="'.url('/adult').'">All Adult', false)->assertSee('href="'.url('/console').'">All Console', false);
    }

    public function test_the_audio_shelf_groups_releases_into_albums_and_keeps_untagged_ones_apart(): void
    {
        $first = $this->rel('Album.Copy.FLAC', ['categories_id' => self::AUDIO_MP3, 'postdate' => '2026-09-25 10:00:00', 'adddate' => '2026-09-25 11:00:00']);
        $second = $this->rel('Album.Copy.MP3', ['categories_id' => self::AUDIO_MP3, 'postdate' => '2026-09-25 09:00:00']);
        $noAlbum = $this->rel('Tagged.No.Album', ['categories_id' => self::AUDIO_MP3, 'postdate' => '2026-09-25 08:00:00']);
        $otherNoAlbum = $this->rel('Tagged.No.Album.Either', ['categories_id' => self::AUDIO_MP3, 'postdate' => '2026-09-25 07:00:00']);
        $this->rel('No.Tags.At.All', ['categories_id' => self::AUDIO_MP3, 'postdate' => '2026-09-25 06:00:00']);
        $performer = $this->rel('Track.Performer.Only', ['categories_id' => self::AUDIO_MP3, 'postdate' => '2026-09-25 05:00:00']);
        DB::table('release_audio_tags')->insert([
            ['releases_id' => $first, 'album' => 'Night Ferry', 'album_performer' => 'The Lanterns', 'performer' => 'Someone Else'],
            ['releases_id' => $second, 'album' => 'Night Ferry', 'album_performer' => 'The Lanterns', 'performer' => null],
            ['releases_id' => $noAlbum, 'album' => null, 'album_performer' => null, 'performer' => 'Solo Voice'],
            ['releases_id' => $otherNoAlbum, 'album' => '', 'album_performer' => null, 'performer' => 'Solo Voice'],
            ['releases_id' => $performer, 'album' => 'Night Ferry', 'album_performer' => null, 'performer' => 'A Tribute Band'],
        ]);

        $response = $this->page();
        $tiles = $this->tiles($response, 'Audio');
        $this->assertSame([
            ['Night Ferry', 'The Lanterns', (string) $first], ['Tagged.No.Album', 'Solo Voice', (string) $noAlbum], ['Tagged.No.Album.Either', 'Solo Voice', (string) $otherNoAlbum],
            ['No.Tags.At.All', '', null], ['Night Ferry', 'A Tribute Band', (string) $performer],
        ], array_map(static fn (array $tile): array => [$tile['title'], $tile['label'], $tile['title'] === 'No.Tags.At.All' ? null : $tile['id']], $tiles));
        $this->assertSame(['album', 'home-tile is-album', '1 hr ago'], [$tiles[0]['kind'], $tiles[0]['class'], $tiles[0]['what']]);
        // the square typographic tile: the disc icon and the performer, no cover picture
        $this->assertMatchesRegularExpression('/data-kind="album" data-id="'.$first.'"[^>]*>\s*<span class="tv-tile-art">\s*<span class="home-tile-card"><i class="fas fa-compact-disc" aria-hidden="true"><\/i>'
            .'<span class="home-tile-card-title">The Lanterns<\/span><\/span>\s*<\/span>\s*<b>Night Ferry<\/b>/', (string) $response->getContent());
        $this->assertStringNotContainsString('<img', $this->shelfHtml($response, 'Audio'));

        // the album panel holds the releases grouped into the tile, newest first, and carries no count
        $panel = $this->page('/?_fragment=panel&shelf=Audio&kind=album&id='.$first)->assertOk()
            ->assertSee('<h3>The Lanterns · Night Ferry</h3>', false)->assertSeeInOrder(['Album.Copy.FLAC', 'Album.Copy.MP3'])
            ->assertDontSee('Track.Performer.Only')->assertDontSee('data-panel-count', false)->assertDontSee('data-select', false);
        $this->assertSame(2, substr_count((string) $panel->getContent(), 'data-release-row'));
        $this->page('/?_fragment=panel&shelf=Audio&kind=album&id='.$noAlbum)->assertOk()->assertSee('<h3>Tagged.No.Album</h3>', false)->assertDontSee('Tagged.No.Album.Either');
    }

    public function test_the_following_shelf_lists_every_followed_title_newest_release_first_with_what_is_new(): void
    {
        $user = $this->userWith(['seen_at' => $this->at('2026-09-25 11:50:00'), 'last_visit' => $this->at('2026-09-24 12:00:00')]);
        $this->follow($user, 11, '5040|5045');
        $this->follow($user, 12, '5040');
        $this->follow($user, 13, '5040');
        $this->followFilm($user, '0000041', null);
        $this->followFilm($user, '0000042', '2000');
        // Glass Meridian: two since the last visit, one before it
        $this->rel('Glass.New.1', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-25 09:00:00']);
        $this->rel('Glass.New.2', ['categories_id' => self::TV_UHD, 'videos_id' => 11, 'adddate' => '2026-09-24 13:00:00']);
        $this->rel('Glass.Before', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-23 09:00:00']);
        // Salt Harbour follows HD only: the UHD release neither moves it forward nor gives it a badge
        $this->rel('Salt.HD.Old', ['categories_id' => self::TV_HD, 'videos_id' => 12, 'adddate' => '2026-09-20 09:00:00']);
        $this->rel('Salt.UHD.New', ['categories_id' => self::TV_UHD, 'videos_id' => 12, 'adddate' => '2026-09-25 11:00:00']);
        // a passworded release counts for nothing
        $this->rel('Quiet.Orbit', ['categories_id' => self::MOVIE_HD, 'imdbid' => '0000041', 'movieinfo_id' => 41, 'adddate' => '2026-09-25 10:00:00']);
        $this->rel('Quiet.Orbit.Locked', ['categories_id' => self::MOVIE_HD, 'imdbid' => '0000041', 'movieinfo_id' => 41, 'adddate' => '2026-09-25 11:30:00', 'passwordstatus' => 1]);
        $this->rel('Paper.Lanterns', ['categories_id' => self::MOVIE_HD, 'imdbid' => '0000042', 'movieinfo_id' => 42, 'adddate' => '2026-09-24 12:00:00']);

        $response = $this->page('/', $user);
        $this->assertSame('2 of 5 with something new', $this->countLine($response, 'Following'));
        $this->assertSame([
            ['film', '41', 'Quiet Orbit', '2 hr ago', '1 new', false],
            ['show', '11', 'Glass Meridian', '3 hr ago', '2 new', false],
            // added exactly at the last visit: not after it
            ['film', '42', 'Paper Lanterns', '1 day ago', '', true],
            ['show', '12', 'Salt Harbour', '5 days ago', '', true],
            ['show', '13', 'Unaired Pilot', 'no releases yet', '', true],
        ], array_map(static fn (array $tile): array => [$tile['kind'], $tile['id'], $tile['title'], $tile['what'], $tile['badge'], str_contains($tile['class'], 'is-faded')], $this->tiles($response, 'Following')));
        $response->assertSee('href="'.url('/watchlist').'">Manage Following', false);
        // the render ten minutes after the last one kept the previous visit
        $this->assertSame($this->at('2026-09-24 12:00:00'), $this->home($user)['last_visit']);
    }

    public function test_the_following_rail_holds_every_followed_title_and_only_the_sections_the_user_may_view(): void
    {
        $user = $this->userWith(['ticked' => ['Following']]);
        foreach (range(1, 61) as $index) {
            DB::table('videos')->insert(['id' => 100 + $index, 'type' => 0, 'title' => 'Followed '.$index]);
            $this->follow($user, 100 + $index, '5040');
        }
        $this->followFilm($user, '0000041', '2040');
        $this->assertCount(62, $this->tiles($this->page('/', $user), 'Following'));

        $tvOnly = $this->userWith([], ['movies']);
        $this->follow($tvOnly, 11, '5040');
        $this->followFilm($tvOnly, '0000041', '2040');
        $response = $this->page('/', $tvOnly);
        $this->assertSame(['Glass Meridian'], array_column($this->tiles($response, 'Following'), 'title'));
        $this->assertSame(['Following', 'TV', 'Audio', 'Books'], $this->shelfNames($response));
        $this->assertSame(['Following', 'TV', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other'], $this->dialogRows($response, false));

        // without TV and Movies there is no Following shelf to offer
        $neither = $this->userWith([], ['movies', 'tv']);
        $response = $this->page('/', $neither);
        $this->assertSame(['Audio', 'Books'], $this->shelfNames($response));
        $this->assertSame(['Audio', 'Books', 'Console', 'PC', 'Adult', 'Other'], $this->dialogRows($response, false));
        $this->page('/?_fragment=panel&shelf=TV&kind=show&id=11', $neither)->assertNotFound();
    }

    public function test_the_last_visit_moves_only_on_a_full_page_render_thirty_minutes_after_the_last(): void
    {
        $user = $this->userWith([]);
        $this->follow($user, 11, '5040');
        $this->rel('Glass.Early', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-25 11:00:00']);

        // a first visit counts nothing as new
        $first = $this->page('/', $user);
        $this->assertSame('0 of 1 with something new', $this->countLine($first, 'Following'));
        $this->assertSame('', $this->tiles($first, 'Following')[0]['badge']);
        $this->assertSame(['seen_at' => $this->at('2026-09-25 12:00:00')], $this->home($user));

        Carbon::setTestNow('2026-09-25 12:10:00');
        $this->page('/', $user);
        $this->assertSame(['seen_at' => $this->at('2026-09-25 12:10:00')], $this->home($user));

        $this->rel('Glass.Late', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-25 12:30:00']);
        Carbon::setTestNow('2026-09-25 13:00:00');
        $third = $this->page('/', $user);
        $this->assertSame(['seen_at' => $this->at('2026-09-25 13:00:00'), 'last_visit' => $this->at('2026-09-25 12:10:00')], $this->home($user));
        $this->assertSame('1 new', $this->tiles($third, 'Following')[0]['badge']);
        $this->assertSame('1 of 1 with something new', $this->countLine($third, 'Following'));

        // a fragment is not a visit: it writes neither time and still compares against the last visit
        Carbon::setTestNow('2026-09-25 15:00:00');
        $fragment = $this->page('/?_fragment=shelves', $user)->assertOk()->assertDontSee('<h1', false)->assertDontSee('<html', false);
        $this->assertSame('1 new', $this->tiles($fragment, 'Following')[0]['badge']);
        $this->page('/?_fragment=panel&shelf=Following&kind=show&id=11', $user)->assertOk();
        $this->actingAs($user)->post('/')->assertOk();
        $this->assertSame(['seen_at' => $this->at('2026-09-25 13:00:00'), 'last_visit' => $this->at('2026-09-25 12:10:00')], $this->home($user));
    }

    public function test_the_count_lines_count_what_the_user_may_see_and_a_hidden_section_has_no_shelf(): void
    {
        $user = $this->userWith(['ticked' => ['Books', 'Console', 'PC', 'Adult']], ['adult']);
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::BOOKS_COMICS]);
        $rows = [];
        foreach (range(1, 1200) as $index) {
            $rows[] = ['name' => 'Book.'.$index, 'searchname' => 'Book.'.$index, 'guid' => md5('Book.'.$index), 'categories_id' => self::BOOKS_EBOOK, 'passwordstatus' => 0,
                'adddate' => '2026-09-25 09:00:00', 'postdate' => '2026-09-25 08:00:00', 'size' => 1048576];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('releases')->insert($chunk);
        }
        $this->rel('Book.Locked', ['categories_id' => self::BOOKS_EBOOK, 'adddate' => '2026-09-25 09:00:00', 'passwordstatus' => 1]);
        $this->rel('Book.Excluded', ['categories_id' => self::BOOKS_COMICS, 'adddate' => '2026-09-25 09:00:00']);
        $this->rel('Console.This.Week', ['categories_id' => self::CONSOLE_PS4, 'adddate' => '2026-09-20 09:00:00']);
        $this->rel('Console.Locked.Today', ['categories_id' => self::CONSOLE_PS4, 'adddate' => '2026-09-25 09:00:00', 'passwordstatus' => 1]);
        $this->rel('PC.Last.Month', ['categories_id' => self::PC_0DAY, 'adddate' => '2026-08-20 09:00:00']);
        $this->rel('Adult.Scene', ['categories_id' => self::XXX_X264, 'adddate' => '2026-09-25 09:00:00']);

        $response = $this->page('/', $user);
        $this->assertSame(['Books', 'Console', 'PC'], $this->shelfNames($response));
        $this->assertSame('1,200 today', $this->countLine($response, 'Books'));
        $this->assertSame('1 this week', $this->countLine($response, 'Console'));
        $this->assertSame('none this week', $this->countLine($response, 'PC'));
        $response->assertDontSee('Book.Locked')->assertDontSee('Book.Excluded')->assertDontSee('Console.Locked.Today')->assertDontSee('Adult.Scene');
        $this->assertSame(['Console.This.Week'], array_column($this->tiles($response, 'Console'), 'title'));
        $this->assertSame(['PC.Last.Month'], array_column($this->tiles($response, 'PC'), 'title'));
        $this->page('/?_fragment=panel&shelf=Adult&kind=pic&id='.DB::table('releases')->where('name', 'Adult.Scene')->value('id'), $user)->assertNotFound();
        $this->page('/?_fragment=panel&shelf=Books&kind=rel&id='.DB::table('releases')->where('name', 'Book.Locked')->value('id'), $user)->assertNotFound();
        $this->page('/?_fragment=panel&shelf=Books&kind=rel&id='.DB::table('releases')->where('name', 'Book.Excluded')->value('id'), $user)->assertNotFound();
    }

    public function test_a_title_panel_shows_its_newest_releases_in_the_generic_row_without_a_select_cell(): void
    {
        $user = $this->userWith([]);
        $this->follow($user, 11, '5040');
        $this->followFilm($user, '0000041', '2040');
        $this->rel('Glass.HD.Older', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-24 09:00:00', 'postdate' => '2026-09-25 11:00:00']);
        $this->rel('Glass.HD.Newer', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-25 09:00:00', 'postdate' => '2026-09-20 11:00:00']);
        $this->rel('Glass.UHD.Newest', ['categories_id' => self::TV_UHD, 'videos_id' => 11, 'adddate' => '2026-09-25 10:00:00']);
        $this->rel('Glass.Locked', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-25 11:00:00', 'passwordstatus' => 1]);
        $this->rel('Salt.Only', ['categories_id' => self::TV_HD, 'videos_id' => 12, 'adddate' => '2026-09-25 09:00:00']);
        $this->rel('Quiet.Orbit.1080p', ['categories_id' => self::MOVIE_HD, 'imdbid' => '0000041', 'movieinfo_id' => 41, 'adddate' => '2026-09-25 09:00:00']);

        // the TV shelf's panel: every visible release of the show, newest added first
        $panel = $this->page('/?_fragment=panel&shelf=TV&kind=show&id=11', $user)->assertOk()
            ->assertSee('<h3>Glass Meridian</h3>', false)->assertSee('<span class="home-panel-count" data-panel-count>3 newest releases</span>', false)
            ->assertSeeInOrder(['Glass.UHD.Newest', 'Glass.HD.Newer', 'Glass.HD.Older'])->assertDontSee('Glass.Locked')->assertDontSee('Salt.Only')
            ->assertDontSee('data-select', false)->assertDontSee('<html', false)->assertDontSee('style="', false)
            ->assertSee('<th class="tv-num">Added</th>', false)
            // inside one section the Category cell is the sub-category alone
            ->assertSee('<td class="tv-category" title="TV &gt; UHD">UHD</td>', false)
            ->assertSee('href="'.route('tv.show', ['videosId' => 11]).'">All episodes and seasons', false)
            ->assertSee('data-close-panel', false);
        $html = (string) $panel->getContent();
        $this->assertSame(3, substr_count($html, 'data-release-row'));
        // the four buttons, Follow among them on a show row
        foreach (['download-nzb', 'data-copy-nzb=', 'data-cart=', 'data-watch-picker='] as $action) {
            $this->assertSame(3, substr_count($html, $action), $action);
        }
        $this->assertStringContainsString('<colgroup><col><col class="home-col-category"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>', $html);
        $this->assertNoWatchWording($html, 'A home panel');

        $this->page('/?_fragment=panel&shelf=TV&kind=show&id=12', $user)->assertOk()->assertSee('data-panel-count>newest release</span>', false)->assertDontSee('newest releases');

        // in Following the follow's own category list applies and the Category cell reads "Root > Sub"
        $following = $this->page('/?_fragment=panel&shelf=Following&kind=show&id=11', $user)->assertOk()
            ->assertSeeInOrder(['Glass.HD.Newer', 'Glass.HD.Older'])->assertDontSee('Glass.UHD.Newest')
            ->assertSee('2 newest releases')->assertSee('<td class="tv-category" title="TV &gt; HD">TV &gt; HD</td>', false);
        $this->assertStringContainsString('tv-feed is-shelf is-generic is-home-panel is-mixed', (string) $following->getContent());

        foreach (['Movies', 'Following'] as $shelf) {
            $this->page('/?_fragment=panel&shelf='.$shelf.'&kind=film&id=41', $user)->assertOk()->assertSee('<h3>Quiet Orbit</h3>', false)
                ->assertSee('Quiet.Orbit.1080p')->assertSee('newest release')
                ->assertSee('href="'.route('movies.film', ['movieinfoId' => 41]).'">All releases of this film', false)
                ->assertSee('data-watch-picker="'.route('watchlist.picker', ['root' => 'movies', 'id' => '0000041']).'"', false);
        }

        // a title the user does not follow has no Following panel; unknown shelves, kinds and ids have none
        foreach (['shelf=Following&kind=show&id=12', 'shelf=Following&kind=film&id=42', 'shelf=TV&kind=film&id=41', 'shelf=TV&kind=show&id=999', 'shelf=Nowhere&kind=show&id=11',
            'shelf=TV&kind=show&id=abc', 'shelf=TV&kind=show'] as $query) {
            $this->page('/?_fragment=panel&'.$query, $user)->assertNotFound();
        }
    }

    public function test_a_release_card_and_an_adult_tile_open_that_one_row_and_the_adult_tile_follows_the_adult_picture_rule(): void
    {
        $user = $this->userWith(['ticked' => ['Books', 'Adult']]);
        $book = $this->rel('A.Book', ['categories_id' => self::BOOKS_EBOOK]);
        $preview = $this->rel('Has.Preview', ['categories_id' => self::XXX_X264, 'haspreview' => 1, 'jpgstatus' => 1, 'postdate' => '2026-09-25 11:00:00', 'adddate' => '2026-09-25 11:30:00', 'size' => 1073741824]);
        $sample = $this->rel('Has.Sample', ['categories_id' => self::XXX_X264, 'jpgstatus' => 1, 'postdate' => '2026-09-25 10:00:00']);
        $this->rel('Has.Neither', ['categories_id' => self::XXX_X264, 'postdate' => '2026-09-25 09:00:00']);
        foreach (['preview' => ['Has.Preview'], 'sample' => ['Has.Preview', 'Has.Sample']] as $type => $names) {
            File::ensureDirectoryExists($this->covers.'/'.$type);
            foreach ($names as $name) {
                File::put($this->covers.'/'.$type.'/'.md5($name).'_thumb.jpg', 'jpg');
            }
        }

        $response = $this->page('/', $user);
        $tiles = $this->tiles($response, 'Adult');
        $this->assertSame(['Has.Preview', 'Has.Sample', 'Has.Neither'], array_column($tiles, 'title'));
        $this->assertSame(['pic', 'home-tile is-picture', 'x264 · 1.00 GB · 30 min ago'], [$tiles[0]['kind'], $tiles[0]['class'], $tiles[0]['what']]);
        $html = $this->shelfHtml($response, 'Adult');
        $this->assertMatchesRegularExpression('/data-id="'.$preview.'"[^>]*>\s*<span class="tv-tile-art">\s*<img src="'.preg_quote(url('/covers/preview/'.md5('Has.Preview').'_thumb.jpg'), '/').'" alt="" loading="lazy">/', $html);
        $this->assertMatchesRegularExpression('/data-id="'.$sample.'"[^>]*>\s*<span class="tv-tile-art">\s*<img src="'.preg_quote(url('/covers/sample/'.md5('Has.Sample').'_thumb.jpg'), '/').'"/', $html);
        $this->assertMatchesRegularExpression('/title="Has.Neither">\s*<span class="tv-tile-art">\s*<span class="home-tile-card is-no-picture"><i class="fas fa-image" aria-hidden="true"><\/i><small>No picture<\/small><\/span>/', $html);
        $this->assertSame(2, substr_count($html, '<img'));

        foreach (['shelf=Adult&kind=pic&id='.$preview => 'Has.Preview', 'shelf=Books&kind=rel&id='.$book => 'A.Book'] as $query => $name) {
            $panel = $this->page('/?_fragment=panel&'.$query, $user)->assertOk()->assertSee('<h3>'.$name.'</h3>', false)
                ->assertDontSee('data-panel-count', false)->assertDontSee('data-select', false)->assertDontSee('home-panel-after', false)
                // no Follow on a row that is no film or show
                ->assertDontSee('data-watch-picker', false)->assertSee('tv-action-slot', false);
            $this->assertSame(1, substr_count((string) $panel->getContent(), 'data-release-row'));
        }
        $this->page('/?_fragment=panel&shelf=Books&kind=rel&id='.$book, $user)->assertSee('<td class="tv-category" title="Books &gt; Ebook">Ebook</td>', false);
        // a tile of another shelf's section is not this shelf's
        $this->page('/?_fragment=panel&shelf=Books&kind=rel&id='.$preview, $user)->assertNotFound();
    }

    public function test_the_three_empty_states_and_the_front_page_content_under_the_shelves(): void
    {
        Content::query()->create(['title' => 'Site announcement', 'body' => 'Welcome to the site', 'contenttype' => Content::TYPE_INDEX, 'status' => 1, 'role' => 0]);
        $response = $this->page()->assertOk()
            ->assertSee('<b>Nothing followed yet.</b>Follow a show or a film from its page, or with the bookmark button on any release row, and its newest releases will be waiting here.', false)
            ->assertSee('<a href="'.route('tv.shows').'">Browse shows</a> · <a href="'.route('movies.films').'">Browse films</a>', false)
            ->assertSee('Nothing in TV in the last days.')->assertSee('Nothing in Books in the last days.')
            ->assertSeeInOrder(['data-shelf="Books"', '<article class="card home-content surface-prose">', 'Site announcement', 'Welcome to the site'], false);
        // a shelf with nothing keeps its heading row; the Following count line is left out
        $this->assertSame('', $this->countLine($response, 'Following'));
        $this->assertSame('none this week · 0 shows with new episodes', $this->countLine($response, 'TV'));
        $this->assertStringContainsString('All Books', $this->shelfHtml($response, 'Books'));
        $this->assertStringNotContainsString('data-rail-of', (string) $response->getContent());

        $none = $this->page('/', $this->userWith(['ticked' => []]))->assertOk()
            ->assertSee('<div class="home-empty" data-empty><b>No shelves.</b>Use Shelves to choose which sections appear.</div>', false)
            ->assertSee('Site announcement');
        $this->assertSame([], $this->shelfNames($none));
        $this->assertSame([], $this->dialogRows($none, true));
        $this->assertSame(self::ALL, $this->dialogRows($none, false));
    }

    public function test_the_order_and_the_ticks_are_saved_through_the_preference_endpoint_and_restored(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'movies', 'per' => 24])->assertOk();

        // an unticked shelf can be moved and keeps its place
        $order = ['Console', 'Following', 'TV', 'Movies', 'Audio', 'Books', 'PC', 'Adult', 'Other'];
        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => $order])->assertOk()
            ->assertExactJson(['success' => true, 'preferences' => ['shelves' => $order, 'ticked' => ['Following', 'TV', 'Movies', 'Audio', 'Books']]]);
        $response = $this->page('/', $user);
        $this->assertSame($order, $this->dialogRows($response, false));
        $this->assertSame(['Following', 'TV', 'Movies', 'Audio', 'Books'], $this->shelfNames($response));

        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => $order, 'ticked' => ['Console', 'TV', 'Books']])->assertOk();
        $this->assertSame(['Console', 'TV', 'Books'], $this->shelfNames($this->page('/', $user)));
        $this->assertSame(['shelves' => $order, 'ticked' => ['Console', 'TV', 'Books']], array_intersect_key($this->home($user), ['shelves' => 1, 'ticked' => 1]));

        // no shelves is a real choice
        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => $order, 'ticked' => []])->assertOk()->assertJsonPath('preferences.ticked', []);
        $this->assertSame([], $this->home($user)['ticked']);
        $this->page('/', $user)->assertSee('No shelves.');

        foreach ([['shelves' => ['TV', 'Nowhere']], ['shelves' => $order, 'ticked' => ['Nowhere']], ['ticked' => ['TV']], ['shelves' => ['TV', 'TV']], ['shelves' => 'TV'], ['shelves' => []], ['view' => 'table'], ['per' => 24], ['sort' => 'newest']] as $invalid) {
            $this->postJson('/profile/update-view', ['root' => 'home', ...$invalid])->assertUnprocessable();
        }
        foreach ([['shelves' => ['TV']], ['ticked' => ['TV']]] as $invalid) {
            $this->postJson('/profile/update-view', ['root' => 'movies', ...$invalid])->assertUnprocessable();
        }
        $stored = User::query()->findOrFail($user->id)->view_prefs;
        $this->assertSame([], $stored['home']['ticked']);
        $this->assertSame(24, $stored['movies']['per']);
        $this->assertArrayHasKey('seen_at', $stored['home']);
    }

    public function test_a_shelf_of_a_section_the_user_may_not_view_keeps_its_stored_place_and_tick(): void
    {
        $user = $this->userWith(['shelves' => ['Adult', 'TV', 'Following', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Other'], 'ticked' => ['Adult', 'TV']], ['adult']);
        $response = $this->page('/', $user);
        $this->assertSame(['TV'], $this->shelfNames($response));
        $listed = ['TV', 'Following', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Other'];
        $this->assertSame($listed, $this->dialogRows($response, false));

        // the dialog posts the rows it lists: Other dragged to the top, Books ticked
        $moved = ['Other', 'TV', 'Following', 'Movies', 'Audio', 'Books', 'Console', 'PC'];
        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => $moved])->assertOk();
        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => $moved, 'ticked' => ['TV', 'Books']])->assertOk();
        $this->assertSame(['shelves' => ['Adult', 'Other', 'TV', 'Following', 'Movies', 'Audio', 'Books', 'Console', 'PC'], 'ticked' => ['Adult', 'TV', 'Books']],
            array_intersect_key($this->home($user), ['shelves' => 1, 'ticked' => 1]));
        $this->assertSame(['TV', 'Books'], $this->shelfNames($this->page('/', $user)));
    }

    public function test_a_page_issues_the_same_statements_whatever_it_holds_and_none_per_tile(): void
    {
        $counts = [];
        foreach ([0, 5, 25] as $followed) {
            $user = $this->userWith([]);
            foreach (range(1, $followed) as $index) {
                if ($followed === 0) {
                    break;
                }
                DB::table('videos')->insertOrIgnore(['id' => 200 + $index, 'type' => 0, 'title' => 'Followed '.$index]);
                $this->follow($user, 200 + $index, '5040');
                $this->rel('Followed.'.$followed.'.'.$index, ['categories_id' => self::TV_HD, 'videos_id' => 200 + $index, 'adddate' => '2026-09-20 09:00:00']);
            }
            $counts[$followed] = $this->statements($user);
        }
        $this->assertSame([$counts[0], $counts[0]], [$counts[5], $counts[25]], 'the followed titles add no statement');

        $user = $this->userWith([]);
        $this->rel('One.Show', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'adddate' => '2026-09-25 09:00:00']);
        $one = $this->statements($user);
        foreach (range(1, 60) as $index) {
            DB::table('videos')->insert(['id' => 300 + $index, 'type' => 0, 'title' => 'Show '.$index]);
            $this->rel('Show.'.$index, ['categories_id' => self::TV_HD, 'videos_id' => 300 + $index, 'adddate' => '2026-09-25 09:00:00']);
            $this->rel('Book.'.$index, ['categories_id' => self::BOOKS_EBOOK]);
        }
        $this->assertSame($one, $this->statements($user), 'the tiles add no statement');
        $this->assertSame($counts[0], $one);

        // the number changes only with the ticked shelves
        $fewer = $this->userWith(['ticked' => ['TV']]);
        $this->assertLessThan($one, $this->statements($fewer));

        // every count on releases is bounded by an adddate window or by one followed title; no window function
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page('/', $user);
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        foreach ($statements as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('over (', $sql);
            if (stripos($sql, 'count(*)') !== false && stripos($sql, 'from "releases"') !== false) {
                $this->assertMatchesRegularExpression('/"adddate" >= \?|"r"\."(videos_id|imdbid)" = "f"\./', $sql, $sql);
            }
        }
    }

    /** The statements of one full page for a user whose caches are warm. */
    private function statements(User $user): int
    {
        $this->page('/', $user)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page('/', $user)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @param array<string, mixed> $attributes */
    private function rel(string $name, array $attributes = []): int
    {
        return $this->release($name, ['passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null, 'movieinfo_id' => null, 'videos_id' => 0,
            'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0, 'categories_id' => self::MISC, ...$attributes]);
    }

    /**
     * A user with a stored home preference who may view every section but the named ones.
     *
     * @param  array<string, mixed>  $home
     * @param  list<string>  $without
     */
    private function userWith(array $home, array $without = []): User
    {
        $user = $this->browserUser();
        foreach ($without as $root) {
            $user->revokePermissionTo('view '.$root);
        }
        if ($home !== []) {
            DB::table('users')->where('id', $user->id)->update(['view_prefs' => json_encode(['home' => $home])]);
        }

        return $user;
    }

    private function follow(User $user, int $videosId, ?string $categories): void
    {
        DB::table('user_series')->insert(['users_id' => $user->id, 'videos_id' => $videosId, 'categories' => $categories]);
    }

    private function followFilm(User $user, string $imdbId, ?string $categories): void
    {
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => $imdbId, 'categories' => $categories]);
    }

    private function at(string $time): int
    {
        return Carbon::parse($time)->getTimestamp();
    }

    /** @return array<string, mixed> the stored `home` preference */
    private function home(?User $user = null): array
    {
        return User::query()->findOrFail(($user ?? $this->user)->id)->view_prefs['home'] ?? [];
    }

    /** A page as a user; another user than the last one starts a fresh session, as a browser would. */
    private function page(string $uri = '/', ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();
        $user ??= $this->user ??= $this->browserUser();
        if ($this->lastUserId !== null && $this->lastUserId !== $user->id) {
            $this->flushSession();
        }
        $this->lastUserId = $user->id;

        return $this->actingAs($user)->get($uri);
    }

    private function xpath(TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8"><div>'.$response->getContent().'</div>');

        return new \DOMXPath($document);
    }

    /** @return list<string> the shelves on the page, in order */
    private function shelfNames(TestResponse $response): array
    {
        return array_map(static fn (\DOMNode $node): string => (string) $node->nodeValue, iterator_to_array($this->xpath($response)->query('//section[@data-shelf]/@data-shelf'), false));
    }

    /** @return list<string> the dialog's rows in order, or only the ticked ones */
    private function dialogRows(TestResponse $response, bool $ticked): array
    {
        $rows = $this->xpath($response)->query('//*[@data-drag-zone]/*[@data-key]'.($ticked ? '[.//*[@data-shelf-tick][@aria-checked="true"]]' : '').'/@data-key');

        return array_map(static fn (\DOMNode $node): string => (string) $node->nodeValue, iterator_to_array($rows, false));
    }

    private function countLine(TestResponse $response, string $shelf): string
    {
        return trim((string) $this->xpath($response)->query('//section[@data-shelf="'.$shelf.'"]//*[@data-shelf-count]')->item(0)?->textContent);
    }

    private function shelfHtml(TestResponse $response, string $shelf): string
    {
        $xpath = $this->xpath($response);
        $section = $xpath->query('//section[@data-shelf="'.$shelf.'"]')->item(0);

        return $section === null ? '' : (string) $section->ownerDocument->saveHTML($section);
    }

    /** @return list<array{kind: string, id: string, class: string, title: string, what: string, badge: string, label: string}> a shelf's tiles in rail order */
    private function tiles(TestResponse $response, string $shelf): array
    {
        $xpath = $this->xpath($response);
        $text = static fn (string $query, \DOMNode $tile): string => trim((string) $xpath->query($query, $tile)->item(0)?->textContent);
        $tiles = [];
        foreach ($xpath->query('//section[@data-shelf="'.$shelf.'"]//button[@data-tile]') as $tile) {
            $this->assertSame('false', $tile->getAttribute('aria-expanded'));
            $tiles[] = [
                'kind' => $tile->getAttribute('data-kind'),
                'id' => $tile->getAttribute('data-id'),
                'class' => trim((string) preg_replace('/\s+/', ' ', $tile->getAttribute('class'))),
                'title' => $tile->getAttribute('title'),
                'what' => $text('.//*[contains(@class, "tv-tile-what")]', $tile),
                'badge' => $text('.//*[@data-badge]', $tile),
                'label' => $text('.//*[contains(@class, "home-tile-chip")] | .//*[contains(@class, "home-tile-card")]//*[contains(@class, "home-tile-card-title")][not(../*[contains(@class, "home-tile-chip")])] | .//small', $tile),
            ];
        }

        return $tiles;
    }
}
