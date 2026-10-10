<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Release;
use App\Models\ReleaseReport;
use App\Services\Releases\ReleaseSearchService;
use App\Support\ReleaseCompletion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsNoRetiredAddress;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The release details page of an Other release (the shelf page since issue #1032,
 * docs/proposals/generic-release-lists/SPEC.md 6) and today's page, which a release outside every
 * root keeps (every root has its own page: TvReleaseDetailsPageTest, MovieReleaseDetailsPageTest,
 * ShelfReleaseDetailsPageTest and the others).
 */
final class DetailsControllerTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsNoRetiredAddress;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const AUDIO_MP3 = 3010;

    private const OTHER_MISC = 10;

    private const BOOKS_EBOOK = 7020;

    /** A category under no root: such a release keeps today's details page (every root has its own page). */
    private const UNROOTED = 8010;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedInteger('predb_id')->nullable();
        });
        DB::table('root_categories')->insert(['id' => 3000, 'title' => 'Audio']);
        DB::table('categories')->insert(['id' => self::AUDIO_MP3, 'title' => 'MP3', 'root_categories_id' => 3000]);
        // The admin list page support already seeds root 1 (as "General"); it becomes the Other root.
        DB::table('root_categories')->updateOrInsert(['id' => 1], ['title' => 'Other']);
        DB::table('categories')->insert(['id' => self::OTHER_MISC, 'title' => 'Misc', 'root_categories_id' => 1]);
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table', '2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        ProductionTables::fromAuthority()->create('releases_groups');
        ProductionTables::fromAuthority()->create('release_regexes');
        Schema::create('dnzb_failures', function (Blueprint $table): void {
            $table->unsignedInteger('release_id');
            $table->unsignedInteger('failed');
        });
        ProductionTables::fromAuthority()->create('predb', ['id', 'title']);
        DB::statement('CREATE TABLE release_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, releases_id INTEGER NOT NULL, text VARCHAR(2000), isvisible INTEGER DEFAULT 1, username VARCHAR(255), users_id INTEGER, created_at DATETIME, updated_at DATETIME, host VARCHAR(45))');
        ProductionTables::fromAuthority()->create('movieinfo', ['id', 'imdbid', 'title', 'year', 'genre', 'director', 'actors', 'rating', 'trailer']);
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('details-artwork')]);
        $this->mock(ReleaseSearchService::class)->shouldReceive('searchSimilar')->andReturn([]);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_details_header_uses_the_shared_release_data_and_renders_each_tab(): void
    {
        $this->release('Raw.Release', ['categories_id' => self::OTHER_MISC, 'guid' => 'details-http', 'display_name' => 'Readable release', 'size' => 41943040, 'nfostatus' => 0,
            'videos_id' => null, 'tv_episodes_id' => null, 'imdbid' => null, 'musicinfo_id' => null, 'gamesinfo_id' => null,
            'consoleinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null]);
        // An Other release gets the shelf page (issue #1032): the name as the heading, the tabs, no Media info tab without media info.
        $response = $this->actingAs($this->browserUser())->get('/details/details-http')->assertOk()->assertViewIs('details.shelf.index');
        $response->assertSee('<h1 class="is-release-name" data-part="details heading">Readable release</h1>', false)->assertSee('40 MB')
            ->assertSeeInOrder(['id="tab-overview"', 'id="tab-files"', 'id="tab-nfo"', 'id="tab-comments"'], false)->assertDontSee('id="tab-media"', false)
            ->assertSee('No NFO was posted with this release.')->assertDontSee('Similar releases')->assertDontSee('data-details-header', false);
        $this->assertSame('Readable release', $response->viewData('release')->row_data->name);
        $this->assertSame('Readable release', $response->viewData('row')->name);
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-other"\s+aria-current="true"/', (string) $response->getContent());
    }

    public function test_an_audio_release_page_links_the_audio_list_and_not_the_old_album_match(): void
    {
        ProductionTables::fromAuthority()->create('musicinfo');
        $this->createGenresTable();
        foreach (['audio_genres', 'release_audio_genres', 'release_audio_evidence', 'release_audio_evidence_tracks', 'release_music_identifications', 'musicbrainz_release_group_genres', 'musicbrainz_release_tracks', 'musicbrainz_artists', 'musicbrainz_artist_aliases', 'release_music_identification_artists'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('musicinfo')->insert(['id' => 12, 'title' => 'An Album', 'artist' => 'The Artist', 'year' => '2021']);
        $this->detailRelease('Album.Release', ['musicinfo_id' => 12, 'categories_id' => self::AUDIO_MP3]);

        $response = $this->actingAs($this->browserUser())->get('/details/'.md5('Album.Release'))->assertOk()->assertViewIs('details.shelf.index');

        $html = (string) $response->getContent();
        $crumbs = (string) preg_replace('/>\s+</', '><', $this->between($html, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>'));
        $this->assertStringContainsString('<a href="'.route('audio.releases').'">Audio releases</a>', $crumbs);
        $this->assertStringNotContainsString('href="'.url('/browse/audio').'"', $html);
        $this->assertStringNotContainsString('/title/audio/12', $html);
        $this->assertStringNotContainsString('An Album', $html);
        $this->assertNoRetiredAddress($html, 'Audio release page');
    }

    public function test_an_other_release_page_is_the_shelf_page_crumbing_back_to_the_list_it_was_opened_from(): void
    {
        ProductionTables::fromAuthority()->create('musicinfo');
        ProductionTables::fromAuthority()->create('bookinfo');
        DB::table('musicinfo')->insert(['id' => 12, 'title' => 'An Album', 'artist' => 'The Artist', 'year' => '2021']);
        DB::table('bookinfo')->insert(['id' => 12, 'title' => 'A Book', 'author' => 'An Author', 'publishdate' => '2022-01-01']);
        $this->detailRelease('Other.Release', ['musicinfo_id' => 12, 'bookinfo_id' => 12]);

        // direct entry: "All releases › Other > Misc"; the Category fact reads the same
        $response = $this->actingAs($this->browserUser())->get('/details/'.md5('Other.Release'))->assertOk()->assertViewIs('details.shelf.index')
            ->assertViewMissing('music')->assertViewMissing('book')->assertViewMissing('otherReleases');
        $html = (string) $response->getContent();
        $crumbs = (string) preg_replace('/>\s+</', '><', $this->between($html, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>'));
        $this->assertSame('<a href="'.route('browse.all').'">All releases</a><span aria-hidden="true">›</span><span>Other &gt; Misc</span>', trim($crumbs));
        $this->assertMatchesRegularExpression('/<dt[^>]*>Category<\/dt><dd[^>]*>Other &gt; Misc<\/dd>/', $html, 'the Category fact');
        foreach (['Music Information', 'Book Information', 'Other releases of this title', 'id="other-releases"', 'An Album', 'A Book', 'data-report-note', 'data-watch-picker'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
        $this->assertNoRetiredAddress($html, 'Other release page');
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-other"\s+aria-current="true"/', $html);

        // opened from a generic list (the Referer): the breadcrumb names that list and returns to it
        foreach ([
            url('/browse/all?group=alt.binaries.demo') => ['Releases in alt.binaries.demo', route('browse.all', ['group' => 'alt.binaries.demo'])],
            url('/poster?name=Bob+%3Cb%40x%3E&page=2') => ['Posts by Bob <b@x>', route('poster-identity', ['name' => 'Bob <b@x>'])],
            url('/browse/all?poster=Bob%20%3Cb%40x%3E') => ['Posts by Bob <b@x>', route('browse.all', ['poster' => 'Bob <b@x>'])],
            url('/browse/other/20?page=2') => ['Other releases', route('browse', ['parentCategory' => 'other'])],
            url('/browse/all?watching=1') => ['All releases', route('browse.all', ['watching' => 1])],
            'https://elsewhere.test/browse/all?group=x' => ['All releases', route('browse.all')],
        ] as $referer => [$label, $url]) {
            $html = (string) $this->get('/details/'.md5('Other.Release'), ['Referer' => $referer])->assertOk()->getContent();
            $crumbs = (string) preg_replace('/>\s+</', '><', $this->between($html, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>'));
            $this->assertSame('<a href="'.e($url).'">'.e($label).'</a><span aria-hidden="true">›</span><span>Other &gt; Misc</span>', trim($crumbs), $referer);
        }
    }

    public function test_an_other_release_page_carries_its_reported_and_response_chips_note_and_pictures(): void
    {
        config(['nntmux_settings.covers_path' => $covers = $this->makeTempDirectory('details-pictures')]);
        mkdir($covers.'/preview');
        mkdir($covers.'/sample');
        $id = $this->detailRelease('Reported.Release', ['haspreview' => 1, 'jpgstatus' => 1]);
        $guid = md5('Reported.Release');
        foreach (['preview/'.$guid.'_thumb.jpg', 'preview/'.$guid.'.jpg', 'sample/'.$guid.'_thumb.jpg', 'sample/'.$guid.'.jpg'] as $file) {
            file_put_contents($covers.'/'.$file, 'image');
        }
        $user = $this->browserUser();
        ReleaseReport::factory()->count(2)->create(['releases_id' => $id, 'users_id' => $user->id]);

        $response = $this->actingAs($user)->get('/details/'.$guid)->assertOk()->assertViewIs('details.shelf.index');
        $response->assertSeeInOrder(['tv-details-chips', 'data-chip-variant="reported"', 'Reported (2)', 'tv-details-tabs', 'tv-details-pictures', 'class="tv-details-preview preview-badge"', 'class="tv-details-preview sample-badge"',
            '<div class="tv-report-note" role="note" data-report-note>', '<b>Reported 2 times</b> · Under review.', 'tv-details-facts'], false)
            ->assertDontSee('data-chip-variant="response"', false);
        ReleaseReport::factory()->create(['releases_id' => $id, 'users_id' => $user->id, 'response' => 'A public answer', 'response_is_public' => true, 'responded_by' => $user->id]);
        $this->get('/details/'.$guid)->assertOk()->assertSee('Reported (3)')->assertSee('data-chip-variant="response"', false)
            ->assertSee('<b>Reported 3 times</b> · A staff response was posted.', false)->assertSee('Response');
        $quiet = $this->detailRelease('Quiet.Release');
        $this->get('/details/'.md5('Quiet.Release'))->assertOk()->assertDontSee('data-report-note', false)->assertDontSee('tv-details-pictures', false)->assertDontSee('data-chip-variant="reported"', false);
        $this->assertSame(0, ReleaseReport::query()->where('releases_id', $quiet)->count());
    }

    public function test_comment_posts_return_to_the_comments_tab_and_blank_posts_do_not_change_the_count(): void
    {
        $this->detailRelease('Comment.Release');
        $url = '/details/'.md5('Comment.Release');
        $this->actingAs($this->browserUser())->post($url, ['txtAddComment' => 'Works well.'])
            ->assertRedirect($url.'#comments')->assertSessionHas('success');
        $this->get($url)->assertOk()->assertSee('Works well.')->assertSee('Comments (1)');
        $this->from($url.'#comments')->post($url, ['txtAddComment' => '   '])->assertSessionHasErrors('txtAddComment');
        $this->get($url)->assertOk()->assertSee('Comments (1)');
    }

    public function test_movie_trailers_use_the_shared_player_and_predb_and_public_reports_remain_visible(): void
    {
        $this->unrootedCategory();
        DB::table('settings')->updateOrInsert(['name' => 'trailers_display'], ['value' => '1']);
        DB::table('movieinfo')->insert(['imdbid' => '0111161', 'title' => 'Trailer Movie', 'trailer' => 'https://youtu.be/Way9Dexny3w']);
        DB::table('predb')->insert(['id' => 5, 'title' => 'Original.Scene.Release']);
        $id = $this->detailRelease('Trailer.Release', ['imdbid' => '0111161', 'predb_id' => 5, 'categories_id' => self::UNROOTED]);
        $user = $this->browserUser();
        ReleaseReport::factory()->create(['releases_id' => $id, 'users_id' => $user->id, 'description' => 'Original report text',
            'response' => 'Public staff response', 'response_is_public' => true, 'responded_by' => $user->id]);
        $response = $this->actingAs($user)->get('/details/'.md5('Trailer.Release'))->assertOk();
        $response->assertSee('data-trailer-url="https://www.youtube-nocookie.com/embed/Way9Dexny3w"', false)
            ->assertDontSee('<iframe', false)->assertSee('x-data="trailerModal"', false)
            ->assertSee('Original.Scene.Release')->assertSee('Original report text')->assertSee('Public staff response')
            ->assertDontSee('data-watch-picker', false);
        $this->assertNoWatchWording((string) $response->getContent(), 'Today\'s details page with a film trailer');
    }

    public function test_movie_genre_director_and_cast_are_escaped_plain_text_limited_to_eight_names(): void
    {
        $this->unrootedCategory();
        $markup = '"><script>alert(1)</script>';
        DB::table('movieinfo')->insert(['imdbid' => '0111162', 'title' => 'Plain Movie', 'genre' => 'Drama, '.$markup,
            'director' => 'Tom & Jerry', 'actors' => implode(', ', ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10'])]);
        $this->detailRelease('Plain.Movie.Release', ['imdbid' => '0111162', 'categories_id' => self::UNROOTED]);
        $response = $this->actingAs($this->browserUser())->get('/details/'.md5('Plain.Movie.Release'))->assertOk();
        $content = (string) $response->getContent();
        $response->assertSee('Drama, '.e($markup), false)->assertSee('Tom &amp; Jerry', false);
        $this->assertSame(1, preg_match_all('~>Cast</dt>\s*<dd[^>]*>([^<]*)</dd>~', $content, $castMatches), 'The page shows one Cast entry.');
        $this->assertSame('A1, A2, A3, A4, A5, A6, A7, A8', $castMatches[1][0]);
        $this->assertStringNotContainsString('<script>alert(1)', $content);
        $this->assertStringNotContainsString('?genre=', $content);
        $this->assertStringNotContainsString('?actors=', $content);
        $this->assertStringNotContainsString('?director=', $content);
    }

    public function test_completion_stays_in_the_header_above_the_tabs(): void
    {
        $id = $this->detailRelease('Incomplete.Release', ['completion' => 80]);
        $url = '/details/'.md5('Incomplete.Release');
        // the shelf page: the completion chip in the header's chip line, above the tabs
        $response = $this->actingAs($this->browserUser())->get($url)->assertOk();
        $response->assertSeeInOrder(['tv-details-chips', '80% complete', 'class="tv-details-tabs"'], false)
            ->assertDontSee(ReleaseCompletion::PENDING_LABEL)->assertDontSee('repair-badge');
        DB::table('releases')->where('id', $id)->update(['completion' => 99]);
        $this->get($url)->assertOk()->assertSeeInOrder(['tv-details-chips', '99% complete', 'class="tv-details-tabs"'], false)
            ->assertDontSee(ReleaseCompletion::PENDING_LABEL)->assertDontSee('repair-badge');
        DB::table('releases')->where('id', $id)->update(['completion' => 0]);
        $this->assertMatchesRegularExpression('/<dt[^>]*>Completion<\/dt><dd[^>]*>Not measured<\/dd>/', (string) $this->get($url)->assertOk()->getContent());
        $this->get($url)->assertOk()->assertDontSee('% complete')
            ->assertDontSee(ReleaseCompletion::PENDING_LABEL)->assertDontSee('repair-badge');
    }

    public function test_similar_releases_lists_the_same_root_matches_without_the_release_itself(): void
    {
        $current = $this->detailRelease('Some.Game.v1.0-GRP');
        $same = $this->detailRelease('Some.Game.v1.1-GRP', ['display_name' => 'Some Game update']);
        $sibling = $this->detailRelease('Some.Game.Soundtrack.ISO', ['categories_id' => 20, 'display_name' => 'Some Game disc image']);
        $book = $this->detailRelease('Some.Game.Strategy.Guide', ['categories_id' => self::BOOKS_EBOOK, 'display_name' => 'Some Game strategy guide']);
        $user = $this->browserUser();
        DB::table('categories')->insert(['id' => 3020, 'title' => '0day', 'root_categories_id' => 3000]);
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => 3020]);
        $searches = [];
        // search() runs MariaDB-only SQL; its rows carry id and categories_id and no categoryparentid.
        // categories_id comes back as a string here so the root comparison must not depend on its type.
        $this->partialMock(ReleaseSearchService::class, function (MockInterface $mock) use (&$searches, $current, $same, $sibling, $book): void {
            $mock->shouldReceive('search')->andReturnUsing(function (mixed ...$arguments) use (&$searches, $current, $same, $sibling, $book) {
                $searches[] = $arguments;

                return Release::query()->whereIn('id', [$current, $same, $sibling, $book])->orderBy('id')->get()
                    ->each(static function (Release $row): void {
                        $row->setRawAttributes(['categories_id' => (string) $row->categories_id] + $row->getAttributes());
                    });
            });
        });

        $response = $this->actingAs($user)->get('/details/'.md5('Some.Game.v1.0-GRP'))->assertOk()->assertViewIs('details.shelf.index');

        // the shelf page's Similar releases, within the Other band, newest posted first (ties: the newer id first)
        $this->assertSame([$sibling, $same], array_map(static fn (object $row): int => $row->id, $response->viewData('similar')));
        $response->assertSee('Similar releases')->assertSee('Some Game update')->assertSee('Some Game disc image')->assertDontSee('Some Game strategy guide');
        $this->assertCount(1, $searches);
        [$phrases, $limit, $excludedCategories, $categories] = [$searches[0][0], $searches[0][7], $searches[0][10], $searches[0][12]];
        $this->assertSame(['searchname' => getSimilarName('Some.Game.v1.0-GRP')], $phrases);
        $this->assertSame((int) config('nntmux.items_per_page'), $limit);
        $this->assertContains(3020, array_map('intval', $excludedCategories));
        $this->assertSame([1], $categories);
    }

    /** @return array<string, array{string, string}> */
    public static function hidingModes(): array
    {
        return [
            'the whole root switched off' => ['root', 'Other'],
            'only the sub-category unticked' => ['sub', 'Other - Misc'],
        ];
    }

    #[DataProvider('hidingModes')]
    public function test_a_release_in_a_hidden_category_is_refused_and_takes_no_comment(string $mode, string $name): void
    {
        DB::table('root_categories')->insert(['id' => 7000, 'title' => 'Books']);
        DB::table('categories')->insert(['id' => self::BOOKS_EBOOK, 'title' => 'Ebook', 'root_categories_id' => 7000]);
        $this->detailRelease('Hidden.Game-GRP');
        $this->detailRelease('Visible.Book-GRP', ['categories_id' => self::BOOKS_EBOOK]);
        $user = $this->browserUser();
        if ($mode === 'root') {
            $user->revokePermissionTo('view other');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user = $user->fresh();
        } else {
            DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::OTHER_MISC]);
        }
        $hidden = '/details/'.md5('Hidden.Game-GRP');

        foreach ([$this->actingAs($user)->get($hidden), $this->post($hidden, ['txtAddComment' => 'Should not land.'])] as $response) {
            $response->assertForbidden()->assertViewIs('errors.category-disabled')->assertViewHas('category', $name)
                ->assertSee($name.' is hidden in your account preferences.')->assertDontSee('Hidden.Game-GRP');
        }
        $this->assertSame(0, DB::table('release_comments')->count());

        $visible = '/details/'.md5('Visible.Book-GRP');
        $this->get($visible)->assertOk()->assertViewIs('details.shelf.index')->assertSee('Visible.Book-GRP');
        $this->post($visible, ['txtAddComment' => 'Lands.'])->assertRedirect($visible.'#comments');
        $this->assertSame(1, DB::table('release_comments')->count());
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);
        $end = strpos($html, $to, $start);
        $this->assertNotFalse($end, 'Missing '.$to);

        return substr($html, $start, $end - $start);
    }

    private function unrootedCategory(): void
    {
        DB::table('root_categories')->insert(['id' => 8000, 'title' => 'Unrooted']);
        DB::table('categories')->insert(['id' => self::UNROOTED, 'title' => 'Legacy', 'root_categories_id' => 8000]);
    }

    /** @param array<string, mixed> $attributes */
    private function detailRelease(string $name, array $attributes = []): int
    {
        return $this->release($name, ['categories_id' => self::OTHER_MISC, 'videos_id' => null, 'tv_episodes_id' => null, 'imdbid' => null, 'musicinfo_id' => null,
            'gamesinfo_id' => null, 'consoleinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null, ...$attributes]);
    }
}
