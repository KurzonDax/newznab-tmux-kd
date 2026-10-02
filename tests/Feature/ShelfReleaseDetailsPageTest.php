<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Release;
use App\Models\User;
use App\Services\Releases\ReleaseSearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsNoRetiredAddress;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The release details page of a Books or PC release, or of a Console release with no game,
 * GET /details/{guid} ("shelf" is the prototype's name for the three sections;
 * docs/proposals/books-console-pc-redesign/SPEC.md 5A; DATA-CONTRACT.md 4.3, 4.4 and 6; the
 * details checks of prototype/check.mjs).
 */
final class ShelfReleaseDetailsPageTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsNoRetiredAddress;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const EBOOK = 7020;

    private const ZERO_DAY = 4010;

    private const ANDROID = 4070;

    /** A sub-category an admin added under PC, which no Category menu order lists. */
    private const PC_CUSTOM = 4100;

    private const PS3 = 1080;

    private const PS4 = 1180;

    private const GB = 1073741824;

    private ?User $user = null;

    private int $nextRelease = 0;

    /** @var list<array{int, string, list<int>}> searchSimilar's calls: release id, name, exclusions */
    private array $similarCalls = [];

    /** @var list<int> the ids of the rows searchSimilar answers with */
    private array $similarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        Carbon::setTestNow('2026-09-25 12:00:00');
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'name', 'searchname', 'guid', 'display_name', 'categories_id', 'category_band', 'size', 'totalpart',
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'repair_outcome', 'rescan_outcome', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id',
            'videos_id', 'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'predb_id', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages', 'releases_groups', 'release_regexes', 'release_comments', 'release_nfos', 'video_data', 'audio_data',
            'release_subtitles', 'media_infos', 'media_info_probes', 'media_info_tracks', 'predb', 'release_tv_episodes', 'tv_episodes', 'tv_info', 'networks',
            'video_genres', 'video_people', 'genres', 'consoleinfo', 'console_genres'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 7000, 'title' => 'Books', 'status' => 1], ['id' => 4000, 'title' => 'PC', 'status' => 1],
            ['id' => 1000, 'title' => 'Console', 'status' => 1]]);
        DB::table('categories')->insert([
            ['id' => self::EBOOK, 'title' => 'Ebook', 'root_categories_id' => 7000, 'status' => 1],
            ['id' => self::ZERO_DAY, 'title' => '0day', 'root_categories_id' => 4000, 'status' => 1],
            ['id' => self::ANDROID, 'title' => 'Phone-Android', 'root_categories_id' => 4000, 'status' => 1],
            ['id' => self::PC_CUSTOM, 'title' => 'Emulators', 'root_categories_id' => 4000, 'status' => 1],
            ['id' => self::PS3, 'title' => 'PS3', 'root_categories_id' => 1000, 'status' => 1],
            ['id' => self::PS4, 'title' => 'PS4', 'root_categories_id' => 1000, 'status' => 1],
        ]);
        DB::table('usenet_groups')->insert(['id' => 99, 'name' => 'alt.binaries.example']);
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('shelf-details-covers')]);
        $search = Mockery::mock(ReleaseSearchService::class)->makePartial();
        $search->shouldReceive('searchSimilar')->andReturnUsing(function (mixed $id, mixed $name, array $exclusions = []): array {
            $this->similarCalls[] = [(int) $id, (string) $name, $exclusions];

            return Release::query()->whereIn('id', $this->similarIds)->get()->sortBy(fn (Release $release): int|false => array_search($release->id, $this->similarIds, true))->values()->all();
        });
        $this->app->instance(ReleaseSearchService::class, $search);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return array<string, array{int, string, string, string}> */
    public static function sections(): array
    {
        return [
            'Books' => [self::EBOOK, 'books.releases', 'Book releases', 'Ebook'],
            'PC' => [self::ZERO_DAY, 'pc.releases', 'PC releases', '0day'],
            'Console with no game' => [self::PS3, 'console.releases', 'Console releases', 'PS3'],
        ];
    }

    #[DataProvider('sections')]
    public function test_the_page_is_the_release_only_form_with_the_crumb_to_its_list_and_no_cover_or_pictures(int $category, string $route, string $list, string $sub): void
    {
        $id = $this->shelf('Some.Release.Name-GRP', ['categories_id' => $category, 'haspreview' => 1, 'jpgstatus' => 1]);

        $response = $this->details($id)->assertOk()->assertViewIs('details.shelf.index');

        $crumbs = $this->between($response, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>');
        $this->assertSame('<a href="'.route($route).'">'.$list.'</a><span aria-hidden="true">›</span><span>'.$sub.'</span>', (string) preg_replace('/>\s+</', '><', $crumbs));
        $response->assertSee('<div class="tv-details-head is-release-only">', false)->assertSee('<div class="tv-details-columns is-release-only">', false)
            ->assertSee('<h1 class="is-release-name" data-part="details heading">Some.Release.Name-GRP</h1>', false)
            ->assertDontSee('tv-details-art', false)->assertDontSee('tv-about', false)->assertDontSee('tv-details-pictures', false)
            ->assertDontSee('tv-details-preview', false)->assertDontSee('No cover')->assertDontSee('style="', false)
            ->assertSee('x-data="movieReleaseDetails"', false)->assertSee('data-nzb-link-base="'.url('/api/v1/api').'"', false);
    }

    public function test_release_pages_render_no_retired_address(): void
    {
        foreach (['Books' => self::EBOOK, 'PC' => self::ZERO_DAY, 'Console with no game' => self::PS3] as $section => $category) {
            $id = $this->shelf('Retired.Address.'.$this->nextRelease.'-GRP', ['categories_id' => $category]);
            $response = $this->details($id)->assertOk()->assertViewIs('details.shelf.index');
            $this->assertNoRetiredAddress((string) $response->getContent(), $section.' release page');
        }
    }

    public function test_a_console_release_with_no_game_opens_this_page_and_one_with_a_stored_game_gets_the_game_page(): void
    {
        // No game id, the lookup's "found nothing" (-2), and a game id that names no stored game.
        foreach ([null, -2, 999] as $gameId) {
            $id = $this->shelf('No.Game.'.$this->nextRelease.'.PS3', ['categories_id' => self::PS3, 'consoleinfo_id' => $gameId]);
            $this->details($id)->assertOk()->assertViewIs('details.shelf.index');
        }

        // A stored game's release gets the Console game page (issue #935), which also reads the game's companies, modes and perspectives.
        foreach (['companies', 'console_companies', 'game_modes', 'console_game_modes', 'player_perspectives', 'console_player_perspectives'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('consoleinfo')->insert(['id' => 5, 'title' => 'Some Game', 'releasedate' => '2010-03-01', 'cover' => 0]);
        $game = $this->shelf('Some.Game.PS3-GRP', ['categories_id' => self::PS3, 'consoleinfo_id' => 5]);
        $this->details($game)->assertOk()->assertViewIs('details.console.index');
    }

    public function test_the_chip_line_has_no_resolution_preview_sample_or_clip_chip_and_the_buttons_have_no_follow(): void
    {
        $id = $this->shelf('Some.Release.Name-GRP', ['completion' => 94, 'passwordstatus' => 1, 'nfostatus' => 1, 'haspreview' => 1, 'jpgstatus' => 1, 'videostatus' => 1,
            'resolution' => 2, 'fromname' => 'paperboat <pb@example.invalid>']);
        DB::table('release_nfos')->insert(['releases_id' => $id, 'nfo' => 'NFO']);
        DB::table('release_video_clips')->insert(['releases_id' => $id, 'extension' => 'mp4', 'mime' => 'video/mp4', 'duration_seconds' => 30]);
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => 'HEVC', 'videocodec' => 'V_MPEGH/ISO/HEVC', 'videowidth' => 1920, 'videoheight' => 1080]);

        $response = $this->details($id)->assertOk();
        $html = (string) $response->getContent();
        $chips = $this->between($response, '<div class="tv-chips tv-details-chips">', '<div class="tv-chips tv-details-origin">');
        $this->assertSeeOrder($chips, ['94% complete', 'Password', 'mediainfo-badge', 'nfo-badge']);
        foreach (['resolution-chip', 'preview-badge', 'sample-badge', 'clip-badge', 'chip-tone-clip', 'tv-source-chip'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $origin = $this->between($response, '<div class="tv-chips tv-details-origin">', '</div>');
        $this->assertStringContainsString('href="'.route('browse.all', ['group' => 'alt.binaries.example']).'"', $origin);
        $this->assertStringContainsString('href="'.route('browse.all', ['poster' => 'paperboat <pb@example.invalid>']).'"', $origin);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($html));
        $response->assertDontSee('data-watch-picker', false)->assertDontSee('Report')->assertDontSee('Edit release');
        $this->assertNoWatchWording($html, 'The Books, Console and PC details page');
    }

    public function test_the_media_info_tab_and_panel_are_there_only_with_media_info(): void
    {
        $without = $this->shelf('No.Media.Info-GRP');
        $response = $this->details($without)->assertOk();
        $this->assertSame(['Overview', 'Files (1)', 'NFO', 'Comments (0)'], $this->tabs((string) $response->getContent()));
        $response->assertDontSee('id="media"', false)->assertDontSee('data-tab="media"', false)->assertSee('data-has-media="0"', false);

        $with = $this->shelf('Has.Media.Info-GRP');
        DB::table('video_data')->insert(['releases_id' => $with, 'videoformat' => 'HEVC', 'videocodec' => 'V_MPEGH/ISO/HEVC', 'videowidth' => 1920, 'videoheight' => 1080]);
        $media = $this->details($with)->assertOk();
        $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs((string) $media->getContent()));
        $media->assertSee('<section id="media" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-media" data-details-panel hidden>', false)
            ->assertSee('data-has-media="1"', false);
    }

    /** @return array<string, array{int}> */
    public static function rowTypes(): array
    {
        return ['a PC release (ShelfReleaseRow)' => [self::ZERO_DAY], 'a Console release with no game (ConsoleReleaseRow)' => [self::PS3]];
    }

    #[DataProvider('rowTypes')]
    public function test_files_has_no_number_and_the_facts_read_a_dash_when_no_file_count_is_stored(int $category): void
    {
        $id = $this->shelf('Some.Release.Name-GRP', ['categories_id' => $category, 'totalpart' => 0]);

        $response = $this->details($id);
        $this->assertSame('Files', $this->tabs((string) $response->getContent())[1]);
        $this->assertSame('—', $this->facts($this->overview($response))['Files']);

        DB::table('releases')->where('id', $id)->update(['totalpart' => 12]);
        $counted = $this->details($id);
        $this->assertSame('Files (12)', $this->tabs((string) $counted->getContent())[1]);
        $this->assertSame('12', $this->facts($this->overview($counted))['Files']);
    }

    public function test_the_category_reads_root_and_sub_category_and_only_a_console_release_lists_genre_after_it(): void
    {
        $book = $this->shelf('Some.Book-GRP', ['categories_id' => self::EBOOK, 'grabs' => 7]);
        $pc = $this->shelf('Some.App-GRP', ['categories_id' => self::ZERO_DAY]);
        $console = $this->shelf('Some.Disc.PS3-GRP', ['categories_id' => self::PS3, 'consoleinfo_id' => -2]);

        $this->assertSame(['Category' => 'Books &gt; Ebook', 'Size' => '1.00 GB', 'Files' => '1', 'Completion' => '100%', 'Posted' => 'Sep 20, 2026, 10:00 AM',
            'Added' => 'Sep 20, 2026, 11:00 AM', 'Grabs' => '7', 'Group' => 'alt.binaries.example', 'Poster' => '—', 'Password status' => 'None detected'],
            $this->facts($this->overview($this->details($book))));
        $pcFacts = $this->facts($this->overview($this->details($pc)));
        $this->assertSame('PC &gt; 0day', $pcFacts['Category']);
        $this->assertArrayNotHasKey('Genre', $pcFacts);
        $consoleFacts = $this->facts($this->overview($this->details($console)));
        $this->assertSame(['Category', 'Genre', 'Size', 'Files', 'Completion', 'Posted', 'Added', 'Grabs', 'Group', 'Poster', 'Password status'], array_keys($consoleFacts));
        $this->assertSame(['Console &gt; PS3', '—'], [$consoleFacts['Category'], $consoleFacts['Genre']]);
    }

    public function test_a_poster_address_breaks_before_its_at_sign(): void
    {
        $id = $this->shelf('Some.Release.Name-GRP', ['fromname' => 'paperboat <pb@example.invalid>']);

        $overview = $this->overview($this->details($id));
        $this->assertMatchesRegularExpression('/<dt\s*>Poster<\/dt><dd\s*>paperboat &lt;pb<wbr>@example\.invalid&gt;<\/dd>/', $overview);
    }

    public function test_the_predb_block_shows_only_with_a_match(): void
    {
        $id = $this->shelf('Some.Release.Name-GRP');
        $this->details($id)->assertDontSee('PreDB');

        DB::table('predb')->insert(['id' => 5, 'title' => 'Some.Release.Name-GRP', 'source' => 'abgx', 'predate' => '2026-09-19 08:30:00', 'category' => 'EBOOK']);
        DB::table('releases')->where('id', $id)->update(['predb_id' => 5]);
        $predb = $this->between($this->details($id), '<section class="tv-details-predb" aria-labelledby="predb-heading">', '</section>');
        $this->assertStringContainsString('<h3 id="predb-heading">PreDB</h3>', $predb);
        $this->assertSame(['Title' => 'Some.Release.Name-GRP', 'Source' => 'abgx', 'Pre date' => 'Sep 19, 2026, 8:30 AM', 'Category' => 'EBOOK'], $this->facts($predb));
    }

    public function test_similar_releases_list_today_s_search_with_the_category_column_newest_posted_first_without_this_release(): void
    {
        $current = $this->shelf('Some.App.v1.0-GRP');
        $android = $this->shelf('Some.App.Android-GRP', ['categories_id' => self::ANDROID, 'totalpart' => 0, 'postdate' => '2026-09-22 10:00:00']);
        $custom = $this->shelf('Some.App.Emulated-GRP', ['categories_id' => self::PC_CUSTOM, 'totalpart' => 40, 'postdate' => '2026-09-21 10:00:00']);
        $day = $this->shelf('Some.App.v1.1-GRP', ['totalpart' => 3, 'postdate' => '2026-09-21 10:00:00']);
        $this->excludeForUser(self::PS4);
        // The search's answer is put newest posted first (ties: the higher id first), and this release is left out whatever it returns.
        $this->similarIds = [$custom, $current, $day, $android];

        $response = $this->details($current)->assertOk();
        $this->assertSame([[$current, 'Some.App.v1.0-GRP', [self::PS4]]], $this->similarCalls);
        $similar = $this->similarSection($response);
        $this->assertStringContainsString('<h2 id="similar-releases-heading">Similar releases</h2>', $similar);
        $this->assertSame(['Release', 'Category', 'Size', 'Files', 'Posted', 'Actions'], $this->headings($similar));
        $this->assertStringContainsString('<col class="tv-col-details-category"><col class="tv-col-size"><col class="tv-col-files"><col class="tv-col-posted"><col class="tv-col-actions">', $similar);
        $this->assertSame([$android, $day, $custom], $this->rowIds($similar));
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($similar, 'data-similar-sort'));
        preg_match_all('/<button type="button" data-similar-sort="([a-z]+)"/', $similar, $sortable);
        $this->assertSame(['category', 'size', 'posted'], $sortable[1]);
        // data-category is the sub-category's place in PcReleaseList::CATEGORY_ORDER; an admin's own sub-category comes after the last.
        preg_match_all('/data-category="(\d+)" data-id="(\d+)"/', $similar, $keys);
        $this->assertSame(['6', '0', '8'], $keys[1]);
        $this->assertSame([(string) $android, (string) $day, (string) $custom], $keys[2]);
        $this->assertStringContainsString('<td class="tv-category" title="PC &gt; Phone-Android">Phone-Android</td>', $similar);
        $this->assertStringContainsString('<td class="tv-category" title="PC &gt; 0day">0day</td>', $similar);
        $this->assertSame(['—', '3', '40'], $this->fileCells($similar));
        foreach (['type="checkbox"', 'Grabs', 'data-watch', 'is-current', 'resolution-chip', 'tv-game-line', 'href="'.route('details', $this->guid($current)).'"'] as $absent) {
            $this->assertStringNotContainsString($absent, $similar, $absent);
        }
        $this->assertSame(3, substr_count($similar, 'tv-action tv-action-slot'));

        $this->similarIds = [];
        $this->details($current)->assertOk()->assertDontSee('Similar releases')->assertDontSee('data-similar-releases', false);
    }

    public function test_a_console_similar_row_with_a_game_shows_its_game_line(): void
    {
        DB::table('consoleinfo')->insert(['id' => 5, 'title' => 'Some Game', 'releasedate' => '2010-03-01', 'cover' => 0]);
        $current = $this->shelf('Some.Game.Disc.PS3-GRP', ['categories_id' => self::PS3, 'consoleinfo_id' => -2]);
        $withGame = $this->shelf('Some.Game.PS4-GRP', ['categories_id' => self::PS4, 'consoleinfo_id' => 5, 'postdate' => '2026-09-22 10:00:00']);
        $noGame = $this->shelf('Some.Game.Other.PS3-GRP', ['categories_id' => self::PS3, 'postdate' => '2026-09-21 10:00:00']);
        $this->similarIds = [$withGame, $noGame];

        $similar = $this->similarSection($this->details($current)->assertOk()->assertViewIs('details.shelf.index'));
        $this->assertSame([$withGame, $noGame], $this->rowIds($similar));
        $this->assertSame(1, substr_count($similar, 'tv-game-line'));
        $this->assertMatchesRegularExpression('/Some\.Game\.PS4-GRP<\/a>\s*<span class="tv-game-line">Some Game · 2010<\/span>/', $similar);
        $this->assertStringContainsString('<td class="tv-category" title="Console &gt; PS4">PS4</td>', $similar);
    }

    public function test_a_hidden_category_is_refused(): void
    {
        $id = $this->shelf('Hidden.App-GRP', ['categories_id' => self::ZERO_DAY]);
        $this->excludeForUser(self::ZERO_DAY);

        $this->details($id)->assertForbidden()->assertViewIs('errors.category-disabled')->assertDontSee('Hidden.App');
    }

    public function test_comments_still_post_to_the_details_url_and_return_to_the_comments_tab(): void
    {
        $id = $this->shelf('Some.Book-GRP', ['categories_id' => self::EBOOK]);
        $url = '/details/'.$this->guid($id);

        $this->actingAs($this->user())->post($url, ['txtAddComment' => 'Works well.'])->assertRedirect($url.'#comments');
        $response = $this->details($id)->assertSee('Works well.');
        $this->assertSame('Comments (1)', $this->tabs((string) $response->getContent())[3]);
    }

    /** @param array<string, mixed> $attributes */
    private function shelf(string $name, array $attributes = []): int
    {
        $number = ++$this->nextRelease;
        $posted = (string) ($attributes['postdate'] ?? '2026-09-20 10:00:00');

        return $this->release($name, ['categories_id' => self::ZERO_DAY, 'passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'consoleinfo_id' => null, 'gamesinfo_id' => null, 'bookinfo_id' => null,
            'musicinfo_id' => null, 'anidbid' => null, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0,
            'size' => self::GB, 'totalpart' => 1, 'groups_id' => 99, 'guid' => md5('shelf release '.$number),
            'adddate' => Carbon::parse($posted)->addHour()->toDateTimeString(), ...$attributes, 'postdate' => $posted]);
    }

    private function excludeForUser(int $category): void
    {
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user()->id, 'categories_id' => $category]);
    }

    private function user(): User
    {
        return $this->user ??= $this->browserUser();
    }

    private function guid(int $id): string
    {
        return (string) DB::table('releases')->where('id', $id)->value('guid');
    }

    private function details(int $id): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($this->user())->get('/details/'.$this->guid($id));
    }

    private function overview(TestResponse $response): string
    {
        return $this->between($response, 'aria-labelledby="tab-overview" data-details-panel>', '<section id="files"');
    }

    private function similarSection(TestResponse $response): string
    {
        return $this->between($response, '<section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>', '</section>');
    }

    /** @return list<string> */
    private function buttons(string $html): array
    {
        preg_match('/<div class="tv-details-actions">(.*?)<\/div>/s', $html, $match);
        preg_match_all('/<\/i>(?:<span[^>]*>)*([^<]+)/', $match[1] ?? '', $labels);

        return array_map('trim', $labels[1]);
    }

    /** @return list<string> */
    private function tabs(string $html): array
    {
        preg_match_all('/<button type="button" role="tab"[^>]*>([^<]+)<\/button>/', $html, $matches);

        return array_map('trim', $matches[1]);
    }

    /** @return array<string, string> the first facts grid's labels and values, in order */
    private function facts(string $html): array
    {
        preg_match('/<dl class="tv-details-facts">(.*?)<\/dl>/s', $html, $match);
        preg_match_all('/<dt[^>]*>([^<]+)<\/dt><dd[^>]*>([^<]*)<\/dd>/', $match[1] ?? '', $facts);

        return array_combine($facts[1], $facts[2]);
    }

    /** @return list<int> release ids in table order */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-copy-nzb="([0-9a-f]{32})"/', $html, $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    /** @return list<string> each row's Files cell text (the fourth cell: Release, Category, Size, Files) */
    private function fileCells(string $html): array
    {
        preg_match_all('/<tr[^>]*data-release-row[^>]*>(.*?)<\/tr>/s', $html, $rows);

        return array_map(static function (string $row): string {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            return trim(strip_tags($cells[1][3] ?? ''));
        }, $rows[1]);
    }

    /** @return list<string> */
    private function headings(string $html): array
    {
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $cells);

        return array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);
    }

    /** @return array<string, string> the sorted heading's key and direction */
    private function sortedHeadings(string $html, string $attribute): array
    {
        preg_match_all('/<th[^>]*aria-sort="([a-z]+)"[^>]*><button type="button" '.$attribute.'="([a-z]+)"/', $html, $sorted);

        return array_combine($sorted[2], $sorted[1]);
    }

    /** @param list<string> $needles */
    private function assertSeeOrder(string $html, array $needles): void
    {
        $last = -1;
        foreach ($needles as $needle) {
            $at = strpos($html, $needle, $last + 1);
            $this->assertNotFalse($at, 'Missing '.$needle);
            $last = $at;
        }
    }

    private function between(TestResponse $response, string $from, string $to): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);

        return trim(substr($html, $start, strpos($html, $to, $start) - $start));
    }
}
