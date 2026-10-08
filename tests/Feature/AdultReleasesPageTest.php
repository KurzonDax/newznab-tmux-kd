<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\AdultReleaseFilters;
use App\Enums\ReleaseSort;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\Releases\AdultReleaseList;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * The Adult releases screen, GET /adult (issue #887; docs/proposals/adult-redesign/SPEC.md 5,
 * DATA-CONTRACT.md 6 and the list checks of prototype/check.mjs).
 */
final class AdultReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const DVD = 6010;

    private const X264 = 6040;

    private const CLIPHD = 6041;

    private const VR = 6046;

    private const WEBDL = 6090;

    private const OTHER = 6999;

    private const ENGLISH = 1;

    private const HINDI = 2;

    private ?User $user = null;

    private string $covers = '';

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
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 6000, 'title' => 'XXX', 'status' => 1], ['id' => 2000, 'title' => 'Movies', 'status' => 1]]);
        foreach ([self::OTHER => 'Other', self::WEBDL => 'WEBDL', self::VR => 'VR', self::CLIPHD => 'ClipsHD', self::X264 => 'x264', self::DVD => 'DVD'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 6000, 'status' => 1]);
        }
        foreach ([2040 => 'HD', 2080 => 'WEB-DL'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 2000, 'status' => 1]);
        }
        $this->covers = $this->makeTempDirectory('adult-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_page_lists_the_adult_band_only_newest_posted_first_with_its_header(): void
    {
        $this->adult('Adult.Newer.1080p', ['postdate' => '2026-09-25 10:00:00']);
        $this->adult('Adult.Older.720p', ['postdate' => '2026-09-20 10:00:00']);
        $this->adult('Adult.Other.Upload', ['postdate' => '2026-09-24 10:00:00', 'categories_id' => self::OTHER]);
        $this->release('A.Movie.2024.1080p', ['categories_id' => 2040, 'postdate' => '2026-09-25 11:00:00']);

        $response = $this->page('/adult')->assertOk()
            ->assertSee('<h1 data-part="page title">Adult releases</h1>', false)
            ->assertSee('data-preference-root="xxx"', false)
            ->assertSeeInOrder(['Adult.Newer.1080p', 'Adult.Other.Upload', 'Adult.Older.720p'])
            ->assertDontSee('A.Movie.2024.1080p')
            ->assertSee('Showing 1–3 of 3 releases')->assertSee('2 hr ago')->assertSee('Sep 20, 2026')
            ->assertDontSee('style="', false)->assertDontSee('x-tv-search', false)->assertDontSee('data-watch-picker', false)
            ->assertDontSee('class="segmented"', false)->assertDontSee('data-expand', false);
        $html = (string) $response->getContent();
        // the name search sits between the heading and the sort menu
        $this->assertMatchesRegularExpression('/<h1 data-part="page title">Adult releases<\/h1>\s*(<!--.*?-->\s*)?<div class="tv-search tv-name-search">\s*<label>\s*<i class="fas fa-magnifying-glass" aria-hidden="true"><\/i>\s*'
            .'<input type="text" x-ref="nameSearch" value="" placeholder="Search release names" autocomplete="off" aria-label="Search release names"/s', $html);
        // hidden by a class that keeps its place: the base styles force display: none on [hidden]
        $this->assertSame(1, preg_match('/<button type="button" class="tv-name-search-clear is-hidden" x-ref="nameClear" aria-label="Clear the name search"[^>]*aria-hidden="true" tabindex="-1"\s*>/', $html));
        $this->assertDoesNotMatchRegularExpression('/tv-name-search-clear[^>]*\shidden[\s>]/', $html);
        $response->assertSeeInOrder(['tv-name-search', '<span class="tv-grow"></span>', 'data-part="sort dropdown"'], false);
        $this->assertNoWatchWording($html, 'The Adult releases list');
        $this->assertSame('/adult', route('adult.releases', [], false));
    }

    public function test_every_page_says_which_rows_it_shows_and_the_mirrored_half_keeps_the_order(): void
    {
        $expected = [];
        foreach (range(1, 275) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            $id = $this->adult('Paged '.$index, ['postdate' => $postdate]);
            $expected[] = [$postdate, $id];
        }
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);

        // page 2 is read from the front, page 4 lies past the middle and is read mirrored, page 6 is the last
        foreach ([1 => [1, 50], 2 => [51, 100], 4 => [151, 200], 6 => [251, 275]] as $page => [$from, $to]) {
            $response = $this->page('/adult'.($page > 1 ? '?page='.$page : ''))->assertOk()
                ->assertSee('Showing '.$from.'–'.$to.' of 275 releases')->assertSee('Page '.$page.' of 6');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $this->page('/adult?page=9')->assertRedirect(route('adult.releases', ['page' => 6]));
        $this->page('/adult')->assertSee('aria-label="Page 6"', false)->assertSee('Go to page');
    }

    public function test_release_filters_combine_or_within_a_menu_and_and_between_menus_and_there_is_no_source(): void
    {
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::HINDI, 'name' => 'Hindi']]);
        $this->adult('UHD x264', ['resolution' => 1, 'source' => 1], [self::ENGLISH]);
        $this->adult('HD x264', ['resolution' => 2, 'source' => 2, 'completion' => 96], [self::HINDI]);
        $this->adult('HD dvd', ['resolution' => 2, 'categories_id' => self::DVD, 'completion' => 90], [self::ENGLISH, self::HINDI]);
        $this->adult('SD other', ['resolution' => 4, 'categories_id' => self::OTHER]);

        $this->assertListed('/adult?resolution[]=4k&resolution[]=1080p', ['HD dvd', 'HD x264', 'UHD x264']);
        $this->assertListed('/adult?resolution[]=1080p&category[]='.self::DVD, ['HD dvd']);
        $this->assertListed('/adult?category[]='.self::DVD.'&category[]='.self::OTHER, ['HD dvd', 'SD other']);
        $this->assertListed('/adult?audio[]='.self::ENGLISH, ['HD dvd', 'UHD x264']);
        $this->assertListed('/adult?audio[]=unknown', ['SD other']);
        $this->assertListed('/adult?audio[]='.self::HINDI.'&audio[]=unknown', ['HD dvd', 'HD x264', 'SD other']);
        $this->assertListed('/adult?completion=100', ['SD other', 'UHD x264']);
        $this->assertListed('/adult?completion=95&resolution[]=1080p', ['HD x264']);
        // no Source filter: a source in the URL is ignored
        $this->assertListed('/adult?_fragment=list&source[]=bluray', ['HD dvd', 'HD x264', 'SD other', 'UHD x264']);
        $response = $this->page('/adult')->assertOk()->assertDontSee('data-name="source"', false)->assertDontSee('<th>Source</th>', false)
            ->assertDontSee('Blu-ray');
        preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', (string) $response->getContent(), $cells);
        $this->assertSame(['category', 'resolution', 'audio', 'completion'], $cells[1]);
        $this->assertSame(['Category: any', 'Resolution: any', 'Audio: any', 'Completion: any'], array_map(fn (string $name): string => $this->cellText($response, $name), $cells[1]));
        $response->assertSeeInOrder(['<div class="filter-row tv-bar-list is-adult">', '<div class="filter-bar is-release" role="group" aria-label="The release">',
            'data-name="completion"', 'x-ref="list" class="tv-list-end"'], false);
    }

    public function test_each_filter_and_the_name_search_return_exactly_what_a_direct_predicate_returns_in_every_sort_and_page(): void
    {
        $facts = $this->filterFixture();
        $has = static fn (array $fact, string $text): bool => str_contains(mb_strtolower($fact['name']), $text);
        $cases = [
            'no filter' => [new AdultReleaseFilters, static fn (array $fact): bool => true],
            'Other' => [new AdultReleaseFilters(categories: [self::OTHER]), static fn (array $fact): bool => $fact['category'] === self::OTHER],
            'Exclude Other' => [new AdultReleaseFilters(categories: [self::X264, self::DVD], excludeOther: true), static fn (array $fact): bool => $fact['category'] !== self::OTHER],
            '1080p' => [new AdultReleaseFilters(resolutions: ['1080p']), static fn (array $fact): bool => $fact['resolution'] === 2],
            'English audio' => [new AdultReleaseFilters(audio: [(string) self::ENGLISH]), static fn (array $fact): bool => in_array(self::ENGLISH, $fact['audio'], true)],
            'Unknown audio' => [new AdultReleaseFilters(audio: ['unknown']), static fn (array $fact): bool => $fact['audio'] === []],
            'Hindi or Unknown audio' => [new AdultReleaseFilters(audio: [(string) self::HINDI, 'unknown']), static fn (array $fact): bool => $fact['audio'] === [] || in_array(self::HINDI, $fact['audio'], true)],
            '100%' => [new AdultReleaseFilters(completion: 100), static fn (array $fact): bool => $fact['completion'] >= 100],
            '95%+' => [new AdultReleaseFilters(completion: 95), static fn (array $fact): bool => $fact['completion'] >= 95],
            'name search' => [new AdultReleaseFilters(search: 'scene'), static fn (array $fact): bool => $has($fact, 'scene')],
            'name search and filters' => [new AdultReleaseFilters(categories: [self::X264], resolutions: ['1080p', '720p'], search: 'Scene'),
                static fn (array $fact): bool => $has($fact, 'scene') && $fact['category'] === self::X264 && in_array($fact['resolution'], [2, 3], true)],
        ];
        $list = app(AdultReleaseList::class);
        foreach ($cases as $case => [$filters, $keep]) {
            foreach (array_keys(AdultReleaseFilters::SORTS) as $sort) {
                $sorted = new AdultReleaseFilters($filters->categories, $filters->resolutions, ReleaseSort::from($sort), 1, $filters->audio, $filters->completion,
                    $filters->excludeOther, $filters->search);
                $expected = $this->expectedOrder($facts, $keep, $sorted);
                $total = $list->count($sorted, [self::VR]);
                $this->assertSame(count($expected), $total, $case);
                $read = [];
                foreach (range(1, max(1, (int) ceil($total / AdultReleaseFilters::PER_PAGE))) as $page) {
                    $read = [...$read, ...$list->pageIds($sorted->withPage($page), [self::VR], $total)];
                }
                $this->assertSame($expected, $read, $case.', '.$sort);
            }
            if ($case !== 'name search and filters') {
                $this->assertGreaterThan(AdultReleaseFilters::PER_PAGE, count($this->expectedOrder($facts, $keep, $filters)), $case.' reaches a page past the middle');
            }
        }
    }

    public function test_the_name_search_matches_the_display_name_or_else_the_search_name_with_wildcards_literal(): void
    {
        $this->adult('raw.name.one', ['display_name' => 'Shown Scene One', 'searchname' => 'hidden words']);
        $this->adult('raw.name.two', ['display_name' => '  ', 'searchname' => 'Fallback Scene Two']);
        $this->adult('raw.name.three', ['display_name' => '100%_Real!Deal', 'searchname' => 'x']);
        $this->adult('raw.name.four', ['display_name' => '100 Real Deal', 'searchname' => 'x', 'categories_id' => self::DVD]);

        $this->assertListed('/adult?q=scene', ['raw.name.one', 'raw.name.two']);
        $this->assertListed('/adult?q=hidden', []);
        $this->assertListed('/adult?q=%25_Real', ['raw.name.three']);
        $this->assertListed('/adult?q=l!D', ['raw.name.three']);
        $this->assertListed('/adult?q=100', ['raw.name.four', 'raw.name.three']);
        $this->page('/adult?q=100')->assertSee('Showing 1–2 of 2 releases');
        $this->page('/adult?q=%20%20')->assertSee('Showing 1–4 of 4 releases');
        $this->assertListed('/adult?q=100&category[]='.self::DVD, ['raw.name.four']);
        $this->page('/adult?_fragment=list&q=100')->assertSee('Showing 1–2 of 2 releases');

        $set = $this->page('/adult?q=Scene')->assertOk()->assertSee('value="Scene"', false)
            ->assertSee('class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', false);
        $this->assertTrue($set->viewData('filters')->any());
        $this->assertMatchesRegularExpression('/<button type="button" class="tv-name-search-clear" x-ref="nameClear" aria-label="Clear the name search" title="Clear the name search" x-on:click="clearNameSearch"\s*>/', (string) $set->getContent());
        $this->page('/adult?q=zzqqxx&category[]='.self::X264)->assertSee('No releases match x264 · names containing “zzqqxx”.', false)
            ->assertDontSee('<table', false);
        $this->page('/adult?clear=1&q=Scene')->assertRedirect(route('adult.releases'));
    }

    public function test_the_name_search_is_carried_by_the_pager_and_never_remembered(): void
    {
        foreach (range(1, 60) as $index) {
            $this->adult('Scene '.$index, ['postdate' => Carbon::parse('2026-01-01')->addHours($index)->toDateTimeString(), 'resolution' => 2]);
        }
        $this->adult('Unrelated', ['resolution' => 2]);
        $user = $this->user = $this->browserUser();
        $this->page('/adult?_fragment=list&resolution[]=1080p')->assertOk();
        $this->assertSame(['resolution' => ['1080p']], $this->remembered($user));

        // a bare open with a name search applies the remembered filters and keeps the search; nothing new is remembered
        $this->page('/adult?q=Scene')->assertRedirect(route('adult.releases', ['resolution' => ['1080p'], 'q' => 'Scene']));
        $opened = $this->opened('/adult?q=Scene')->assertOk()->assertSee('Showing 1–50 of 60 releases')
            ->assertSee('href="'.e(route('adult.releases', ['resolution' => ['1080p'], 'q' => 'Scene', 'page' => 2])).'"', false);
        $this->assertSame('Scene', $opened->viewData('filters')->search);
        $this->assertSame(['resolution' => ['1080p']], $this->remembered($user));
        $this->page('/adult?_fragment=list&resolution[]=1080p&q=Scene')->assertOk();
        $this->assertSame(['resolution' => ['1080p']], $this->remembered($user));

        $second = $this->page('/adult?resolution[]=1080p&q=Scene&page=2')->assertOk()->assertSee('Showing 51–60 of 60 releases');
        $this->assertNotContains('Unrelated', $this->listedNames($second));
        $this->assertSame(['resolution' => ['1080p'], 'q' => 'Scene', 'page' => 2], $second->viewData('filters')->query());
        $this->page('/adult')->assertRedirect(route('adult.releases', ['resolution' => ['1080p']]));
    }

    public function test_a_chosen_sub_category_with_no_release_stays_ticked_and_shows_its_empty_result(): void
    {
        foreach ([self::OTHER, self::X264, self::DVD, self::CLIPHD] as $category) {
            $this->adult('In '.$category, ['categories_id' => $category]);
        }
        $user = $this->user = $this->browserUser();

        $menu = $this->page('/adult')->assertOk()->viewData('categoryMenu');
        $this->assertSame([self::DVD => 'DVD', self::X264 => 'x264', self::CLIPHD => 'ClipsHD', self::OTHER => 'Other'], $menu);

        $chosen = $this->page('/adult?category[]='.self::WEBDL)->assertOk()->assertSee('Showing 0 releases')->assertSee('No releases match WEBDL.')
            ->assertDontSee('<table', false);
        $this->assertSame([self::DVD => 'DVD', self::X264 => 'x264', self::CLIPHD => 'ClipsHD', self::WEBDL => 'WEBDL', self::OTHER => 'Other'], $chosen->viewData('categoryMenu'));
        $this->assertSame('Category: WEBDL', $this->cellText($chosen, 'category'));
        $this->assertMatchesRegularExpression('/data-value="'.self::WEBDL.'"[^>]*aria-checked="true"/', (string) $chosen->getContent());
        $this->assertSame(['category' => [self::WEBDL]], $this->remembered($user));
        $this->assertSame('Category: WEBDL', $this->cellText($this->opened('/adult'), 'category'));

        // a hidden sub-category is still dropped
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::VR]);
        Cache::flush();
        $this->page('/adult?category[]='.self::VR, User::query()->findOrFail($user->id))->assertOk()->assertSee('Showing 1–4 of 4 releases');
    }

    public function test_exclude_other_lists_every_category_but_other_and_is_remembered_as_a_mode(): void
    {
        $this->adult('In x264');
        $this->adult('In DVD', ['categories_id' => self::DVD]);
        $this->adult('In Other', ['categories_id' => self::OTHER]);
        $user = $this->user = $this->browserUser();

        $this->page('/adult')->assertOk()->assertSeeInOrder(['data-any', 'Any category', 'data-exclude-other', 'Exclude Other', 'checkbox-menu-rule', 'data-value="'.self::DVD.'"'], false);
        $set = $this->page('/adult?category=exclude-other')->assertOk()->assertSee('title="Category: Exclude Other"', false);
        $this->assertSame(['In DVD', 'In x264'], $this->listedNames($set));
        $this->assertSame('Category: Exclude Other', $this->cellText($set, 'category'));
        $this->assertSame(['category' => 'exclude-other'], $this->remembered($user));
        $this->page('/adult?category=exclude-other&resolution[]=4k')->assertSee('No releases match excluding Other · 4K.');

        // ticking every sub-category but Other by hand is the mode; a sub-category that gains its first release joins it
        $this->page('/adult?_fragment=list&category[]='.self::X264.'&category[]='.self::DVD)->assertOk();
        $this->assertSame(['category' => 'exclude-other'], $this->remembered($user));
        $this->adult('In ClipsHD', ['categories_id' => self::CLIPHD]);
        Cache::flush();
        $this->page('/adult')->assertRedirect(route('adult.releases', ['category' => 'exclude-other']));
        $opened = $this->opened('/adult');
        $this->assertSame(['In ClipsHD', 'In DVD', 'In x264'], $this->listedNames($opened));
        $this->assertSame([self::DVD, self::X264, self::CLIPHD], $opened->viewData('filters')->categories);
    }

    public function test_a_row_picture_is_the_preview_else_the_sample_else_the_no_picture_tile(): void
    {
        $preview = $this->adult('Has.Preview', ['haspreview' => 1, 'jpgstatus' => 1]);
        $this->adult('Has.Sample', ['haspreview' => 0, 'jpgstatus' => 1]);
        $this->adult('Preview.Flag.Without.File', ['haspreview' => 1, 'jpgstatus' => 1]);
        $this->adult('Nothing', ['haspreview' => 1, 'jpgstatus' => 0]);
        $this->image('preview', md5('Has.Preview').'_thumb');
        $this->image('sample', md5('Has.Preview').'_thumb');
        $this->image('sample', md5('Has.Sample').'_thumb');
        $this->image('sample', md5('Preview.Flag.Without.File').'_thumb');
        $this->assertGreaterThan(0, $preview);

        $response = $this->page('/adult')->assertOk()
            ->assertSee('<colgroup><col class="tv-col-select"><col class="tv-col-picture"><col><col class="tv-col-resolution"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>', false);
        $details = static fn (string $name): string => preg_quote(e(route('details', md5($name))), '/');
        $this->assertMatchesRegularExpression('/<td class="tv-art is-picture">\s*<a href="'.$details('Has.Preview').'" tabindex="-1" aria-hidden="true" data-picture="preview" title="View the image preview">\s*'
            .'<img src="'.preg_quote(url('/covers/preview/'.md5('Has.Preview').'_thumb.jpg'), '/').'" alt="" loading="lazy"/', $this->rowOf($response, 'Has.Preview'));
        $this->assertMatchesRegularExpression('/data-picture="sample" title="View sample image">\s*<img src="'.preg_quote(url('/covers/sample/'.md5('Has.Sample').'_thumb.jpg'), '/').'"/',
            $this->rowOf($response, 'Has.Sample'));
        $this->assertMatchesRegularExpression('/data-picture="sample"[^>]*>\s*<img src="'.preg_quote(url('/covers/sample/'.md5('Preview.Flag.Without.File').'_thumb.jpg'), '/').'"/',
            $this->rowOf($response, 'Preview.Flag.Without.File'));
        $nothing = $this->rowOf($response, 'Nothing');
        $this->assertMatchesRegularExpression('/<a class="tv-placeholder is-no-picture" href="'.$details('Nothing').'" tabindex="-1" aria-hidden="true">\s*<i class="fas fa-image" aria-hidden="true"><\/i>\s*'
            .'<span class="tv-placeholder-label">No picture<\/span>\s*<\/a>/', $nothing);
        $this->assertStringNotContainsString('<img', $nothing);
        $this->assertStringNotContainsString('data-picture', $nothing);
    }

    public function test_a_clip_row_has_one_preview_chip_with_a_play_icon_opening_the_video_preview_with_its_poster(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        $timed = $this->adult('Timed.Clip', ['videostatus' => 1, 'nfostatus' => 1, 'haspreview' => 1, 'jpgstatus' => 1, 'groups_id' => 1]);
        $untimed = $this->adult('Untimed.Clip', ['videostatus' => 1]);
        $this->adult('Legacy.Clip', ['videostatus' => 1]);
        $this->adult('Image.Only', ['videostatus' => 0, 'haspreview' => 1]);
        $this->adult('No.Clip', ['videostatus' => 0]);
        DB::table('release_video_clips')->insert([
            ['releases_id' => $timed, 'extension' => 'mp4', 'mime' => 'video/mp4', 'duration_seconds' => 30],
            ['releases_id' => $untimed, 'extension' => 'webm', 'mime' => 'video/webm', 'duration_seconds' => null],
        ]);
        foreach (['preview' => [md5('Timed.Clip'), md5('Timed.Clip').'_thumb', md5('Image.Only').'_thumb', md5('Image.Only')], 'sample' => [md5('Timed.Clip').'_thumb']] as $type => $names) {
            foreach ($names as $name) {
                $this->image($type, $name);
            }
        }

        // the list reads no clip length (SPEC 5.10)
        DB::enableQueryLog();
        $response = $this->page('/adult')->assertOk()->assertDontSee('Clip · ')->assertDontSee('clip-badge', false)->assertDontSee('>Clip<', false);
        $this->assertSame(0, collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'duration_seconds'))->count());
        DB::disableQueryLog();

        $row = $this->rowOf($response, 'Timed.Clip');
        $chip = $this->previewChip($row);
        $this->assertMatchesRegularExpression('/^<button [^>]*data-chip-variant="preview"[^>]*>\s*<i class="fas fa-play" aria-hidden="true"><\/i>\s*Preview\s*<\/button>$/', $chip);
        $this->assertStringContainsString('chip-tone-preview', $chip);
        $this->assertStringContainsString('data-video-url="'.route('preview.video', md5('Timed.Clip')).'" data-video-type="video/mp4"', $chip);
        $this->assertStringContainsString('data-poster-url="'.$this->url('preview', md5('Timed.Clip')).'"', $chip);
        $this->assertStringContainsString('data-image-title="Video preview"', $chip);
        $this->assertStringContainsString('title="Play the video preview"', $chip);
        $this->assertStringNotContainsString('data-image-url', $chip);
        $this->assertStringNotContainsString('data-full-url', $chip);
        $this->assertMatchesRegularExpression('/nfo-badge.*preview-badge.*sample-badge.*<span class="tv-origin-pair">/s', $row);
        $this->assertStringContainsString('data-picture="preview" title="Play the video preview"', $row);
        $this->assertStringContainsString('data-video-type="video/webm"', $this->previewChip($this->rowOf($response, 'Untimed.Clip')));
        $legacy = $this->previewChip($this->rowOf($response, 'Legacy.Clip'));
        $this->assertStringContainsString('data-video-type="video/ogg"', $legacy);
        $this->assertStringNotContainsString('data-poster-url', $legacy, 'A clip without any image opens the player without a poster.');

        $image = $this->rowOf($response, 'Image.Only');
        $imageChip = $this->previewChip($image);
        $this->assertMatchesRegularExpression('/^<button [^>]*>\s*Preview\s*<\/button>$/', $imageChip, 'no icon without a clip');
        $this->assertStringContainsString('data-image-url="'.$this->url('preview', md5('Image.Only').'_thumb').'" data-full-url="'.$this->url('preview', md5('Image.Only')).'"', $imageChip);
        $this->assertStringContainsString('data-image-title="Image preview"', $imageChip);
        $this->assertStringContainsString('title="View the image preview"', $imageChip);
        $this->assertStringNotContainsString('data-video-url', $imageChip);
        $this->assertStringNotContainsString('data-poster-url', $imageChip);
        $this->assertStringContainsString('data-picture="preview" title="View the image preview"', $image);
        $this->assertStringNotContainsString('preview-badge', $this->rowOf($response, 'No.Clip'));
        $this->assertStringNotContainsString('tv-chips', $this->rowOf($response, 'No.Clip'));
    }

    /**
     * The clip's poster is the full-size Preview, else the Preview thumb, else the Sample thumb,
     * read from the files on disk whatever haspreview and jpgstatus say; those flags still decide
     * the picture and the image chips.
     */
    public function test_the_clip_poster_takes_the_best_image_on_disk_whatever_the_image_flags_say(): void
    {
        $this->adult('Thumbs.Only', ['videostatus' => 1, 'haspreview' => 1, 'jpgstatus' => 1]);
        $this->adult('Sample.Only', ['videostatus' => 1, 'jpgstatus' => 1]);
        $this->adult('Stale.Preview', ['videostatus' => 1, 'haspreview' => 0]);
        $this->adult('Stale.Sample', ['videostatus' => 1, 'jpgstatus' => 0]);
        foreach ([md5('Thumbs.Only').'_thumb', md5('Stale.Preview'), md5('Stale.Preview').'_thumb'] as $name) {
            $this->image('preview', $name);
        }
        foreach ([md5('Thumbs.Only').'_thumb', md5('Sample.Only').'_thumb', md5('Stale.Sample').'_thumb', md5('Stale.Preview').'_thumb'] as $name) {
            $this->image('sample', $name);
        }

        $response = $this->page('/adult')->assertOk();
        $posters = [];
        foreach (['Thumbs.Only', 'Sample.Only', 'Stale.Preview', 'Stale.Sample'] as $name) {
            preg_match('/data-poster-url="([^"]*)"/', $this->previewChip($this->rowOf($response, $name)), $match);
            $posters[$name] = $match[1] ?? null;
        }
        $this->assertSame([
            'Thumbs.Only' => $this->url('preview', md5('Thumbs.Only').'_thumb'),
            'Sample.Only' => $this->url('sample', md5('Sample.Only').'_thumb'),
            'Stale.Preview' => $this->url('preview', md5('Stale.Preview')),
            'Stale.Sample' => $this->url('sample', md5('Stale.Sample').'_thumb'),
        ], $posters);

        // The flags still decide the picture and the Sample chip: a stale flag shows none.
        $sampleOnly = $this->rowOf($response, 'Sample.Only');
        $this->assertStringContainsString('data-picture="sample" title="View sample image"', $sampleOnly);
        $this->assertStringContainsString('sample-badge', $sampleOnly);
        foreach (['Stale.Preview', 'Stale.Sample'] as $name) {
            $stale = $this->rowOf($response, $name);
            $this->assertStringContainsString('is-no-picture', $stale, $name);
            $this->assertStringNotContainsString('sample-badge', $stale, $name);
            $this->assertSame(1, substr_count($stale, 'preview-badge'), $name);
        }
        $this->assertSame(['haspreview' => 0, 'jpgstatus' => 0], (array) DB::table('releases')->where('name', 'Stale.Preview')->first(['haspreview', 'jpgstatus']), 'nothing writes the flags');
        $this->assertSame(['haspreview' => 0, 'jpgstatus' => 0], (array) DB::table('releases')->where('name', 'Stale.Sample')->first(['haspreview', 'jpgstatus']));
    }

    public function test_the_table_has_no_source_files_or_grabs_column_and_the_buttons_have_no_follow(): void
    {
        $this->adult('A release', ['grabs' => 7, 'fromname' => 'Uploader <up@example.invalid>']);
        $response = $this->page('/adult')->assertOk()->assertDontSee('<th class="tv-num">Files</th>', false)->assertDontSee('<th class="tv-num">Grabs</th>', false)
            ->assertDontSee('filelist-badge', false)->assertSee('<table class="tv-feed is-adult"', false);
        $this->assertSame(6, substr_count(strstr((string) $response->getContent(), '</thead>', true), '<th') - 1, 'six headers over seven columns');
        $row = $this->rowOf($response, 'A release');
        $this->assertSame(7, substr_count($row, '<td'));
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($row));
        $this->assertStringContainsString('<span class="tv-action tv-action-slot" aria-hidden="true"></span>', $row);
        $this->assertStringNotContainsString('<b>', $row);
    }

    public function test_the_list_fragment_is_the_list_alone_and_the_count_is_cached_under_the_browse_version(): void
    {
        $this->adult('First release');
        $this->page('/adult')->assertSee('Showing 1–1 of 1 release');
        $this->adult('Second release', ['postdate' => '2026-09-01 00:00:00']);
        $this->page('/adult')->assertSee('Showing 1–1 of 1 release');
        ReleaseBrowseService::bumpCacheVersion();
        $this->page('/adult')->assertSee('Showing 1–2 of 2 releases');

        $fragment = $this->page('/adult?_fragment=list&q=release')->assertOk()->assertSee('Showing 1–2 of 2 releases')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('tv-filters', $fragment);
        $this->assertNotSame((new AdultReleaseFilters(search: 'a'))->countKey(), (new AdultReleaseFilters(search: 'b'))->countKey());
        $this->assertNotSame((new AdultReleaseFilters)->countKey(), (new AdultReleaseFilters(categories: [self::DVD], excludeOther: true))->countKey());
        $this->assertTrue(Cache::has('adult_releases_value_counts'));
        $this->assertFalse(Cache::has('movie_releases_value_counts'));
    }

    public function test_the_page_needs_the_adult_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view adult');
        $this->page('/adult', $user)->assertForbidden()->assertSee('Adult is hidden in your account preferences.');
    }

    public function test_the_header_menu_sends_adult_to_the_new_list_and_marks_adult_current(): void
    {
        $this->adult('A release');
        $response = $this->page('/adult')->assertOk()
            ->assertSee('href="'.route('adult.releases').'" class="public-menu-root">All Adult</a>', false)
            ->assertSee('href="'.e(route('adult.releases', ['category' => [self::DVD]])).'"', false)
            ->assertSee('href="'.e(route('adult.releases', ['category' => [self::WEBDL]])).'"', false)
            ->assertDontSee('href="'.url('/browse/xxx').'"', false);
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-xxx"\s+aria-current="true"/', (string) $response->getContent());
        $this->assertSame(1, substr_count((string) $response->getContent(), 'aria-current="true"'));
    }

    public function test_the_four_sorts_order_the_list_and_the_adult_sort_and_filters_are_remembered_under_xxx(): void
    {
        $this->adult('Posted early added late', ['postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']);
        $this->adult('Posted late added early', ['postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $user = $this->user = $this->browserUser();

        $this->page('/adult')->assertSeeInOrder(['Posted late added early', 'Posted early added late'])->assertSee('<th class="tv-num">Posted</th>', false);
        $this->postJson('/profile/update-view', ['root' => 'xxx', 'sort' => 'newest'])->assertOk();
        $this->page('/adult', User::query()->findOrFail($user->id))->assertSeeInOrder(['Posted early added late', 'Posted late added early'])
            ->assertSee('<th class="tv-num">Added</th>', false)->assertSee('<option value="newest" selected', false);
        $this->assertSame('newest', User::query()->findOrFail($user->id)->releaseViewPreferences('xxx')['sort']);
        $this->postJson('/profile/update-view', ['root' => 'xxx', 'sort' => 'grabs'])->assertUnprocessable();

        $this->page('/adult?_fragment=list&resolution[]=1080p&completion=95', User::query()->findOrFail($user->id))->assertOk();
        $this->assertSame(['resolution' => ['1080p'], 'completion' => 95], $this->remembered($user));
        $this->assertNull(User::query()->findOrFail($user->id)->releaseViewPreferences('movies')['filters'] ?? null);
        $this->page('/adult', User::query()->findOrFail($user->id))->assertRedirect(route('adult.releases', ['resolution' => ['1080p'], 'completion' => 95]));
    }

    public function test_without_a_secondary_provider_the_chip_shows_no_pending_suffix(): void
    {
        $this->adult('Above target', ['completion' => 99]);
        $this->adult('No verdict', ['completion' => 80]);

        $response = $this->page('/adult')->assertOk();
        $above = $this->rowOf($response, 'Above target');
        $this->assertMatchesRegularExpression('/>\s*99% complete\s*</', $above);
        $this->assertStringNotContainsString('late headers pending', $above);
        $noVerdict = $this->rowOf($response, 'No verdict');
        $this->assertMatchesRegularExpression('/>\s*80% complete\s*</', $noVerdict);
        $this->assertStringNotContainsString('late headers pending', $noVerdict);
    }

    /** The response after the one redirect a bare open answers with while filters are remembered (#881). */
    private function opened(string $uri): TestResponse
    {
        $response = $this->page($uri);

        return $response->isRedirect() ? $this->page((string) $response->headers->get('Location')) : $response;
    }

    private function remembered(User $user): mixed
    {
        return User::query()->findOrFail($user->id)->releaseViewPreferences('xxx')['filters'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $audio  languages.id of the release's audio tracks
     */
    private function adult(string $name, array $attributes = [], array $audio = []): int
    {
        $id = $this->release($name, ['categories_id' => self::X264, 'passwordstatus' => 0, 'resolution' => 2, 'source' => 0, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0,
            'videostatus' => 0, ...$attributes]);
        foreach ($audio as $language) {
            DB::table('release_audio_languages')->insert(['releases_id' => $id, 'languages_id' => $language]);
        }

        return $id;
    }

    private function image(string $type, string $basename): void
    {
        File::ensureDirectoryExists($this->covers.'/'.$type);
        File::put($this->covers.'/'.$type.'/'.$basename.'.jpg', 'jpg');
    }

    private function url(string $type, string $basename): string
    {
        return url('/covers/'.$type.'/'.$basename.'.jpg');
    }

    /** The row's one Preview chip, its button tag through its closing tag. */
    private function previewChip(string $row): string
    {
        $this->assertSame(1, preg_match_all('/<button [^>]*preview-badge[^>]*>.*?<\/button>/s', $row, $matches), 'exactly one Preview chip');

        return $matches[0][0];
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    /** @param list<string> $names */
    private function assertListed(string $uri, array $names, ?User $user = null): void
    {
        $response = $this->page($uri, $user)->assertOk();
        $this->assertSame($names, $this->listedNames($response), $uri);
    }

    /** @return list<string> the listed releases' names, A to Z */
    private function listedNames(TestResponse $response): array
    {
        return DB::table('releases')->whereIn('id', $this->listedIds($response))->orderBy('name')->pluck('name')->all();
    }

    /** @return list<int> */
    private function listedIds(TestResponse $response): array
    {
        preg_match_all('/data-select value="([0-9a-f]{32})"/', (string) $response->getContent(), $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    private function rowOf(TestResponse $response, string $name): string
    {
        foreach (explode('<tr data-release-row', (string) $response->getContent()) as $row) {
            if (str_contains($row, 'title="'.e($name).'"')) {
                return strstr($row, '</tr>', true) ?: $row;
            }
        }
        $this->fail('No row for '.$name);
    }

    /** @return list<string> */
    private function actions(string $row): array
    {
        preg_match_all('/class="tv-action(?: tv-action-download download-nzb)?" (?:data-copy-nzb|data-cart|data-watch-picker|title="Download)/', $row, $matches);

        return array_map(static fn (string $match): string => match (true) {
            str_contains($match, 'Download') => 'download', str_contains($match, 'copy') => 'copy',
            str_contains($match, 'cart') => 'cart', default => 'watch',
        }, $matches[0]);
    }

    /** A filter cell's text as check.mjs reads it (textContent): "Category: 2 chosen". */
    private function cellText(TestResponse $response, string $name): string
    {
        $this->assertMatchesRegularExpression('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent());
        preg_match('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent(), $match);

        return html_entity_decode(strip_tags($match[1]), ENT_QUOTES);
    }

    /**
     * 320 Adult releases over x264, DVD, Other and an excluded category (VR), passworded releases,
     * three completions, four resolutions, five audio mixes and names with and without "Scene";
     * posted dates with ties, added dates in another order.
     *
     * @return array<int, array{visible: bool, name: string, category: int, resolution: int, completion: int, audio: list<int>, posted: string, added: string}>
     */
    private function filterFixture(): array
    {
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::HINDI, 'name' => 'Hindi'], ['id' => 3, 'name' => 'Japanese']]);
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '0']);
        $mixes = [[], [self::ENGLISH], [self::HINDI], [self::ENGLISH, self::HINDI], [], [3]];
        $facts = [];
        foreach (range(1, 320) as $index) {
            $name = ($index % 3 === 0 ? 'Studio.Scene.' : 'Studio.Set.').$index;
            $fact = ['name' => $name, 'category' => $index % 9 === 0 ? self::VR : ($index % 5 === 0 ? self::OTHER : ($index % 2 === 0 ? self::X264 : self::DVD)),
                'resolution' => [2, 1, 3, 4][$index % 4], 'completion' => [100, 97, 90][$index % 3], 'audio' => $mixes[$index % 6],
                'posted' => Carbon::parse('2026-01-01 00:00:00')->addHours($index % 7 === 0 ? $index - 1 : $index)->toDateTimeString(),
                'added' => Carbon::parse('2026-03-01 00:00:00')->addHours(($index * 37) % 320)->toDateTimeString()];
            $password = $index % 11 === 0 ? 1 : 0;
            $id = $this->adult($name, ['categories_id' => $fact['category'], 'resolution' => $fact['resolution'], 'completion' => $fact['completion'],
                'passwordstatus' => $password, 'postdate' => $fact['posted'], 'adddate' => $fact['added']], $fact['audio']);
            $facts[$id] = ['visible' => $password === 0 && $fact['category'] !== self::VR, ...$fact];
        }
        Cache::flush();

        return $facts;
    }

    /**
     * The visible releases the predicate keeps, in the filters' sort.
     *
     * @param  array<int, array{visible: bool, name: string, category: int, resolution: int, completion: int, audio: list<int>, posted: string, added: string}>  $facts
     * @return list<int>
     */
    private function expectedOrder(array $facts, callable $keep, AdultReleaseFilters $filters): array
    {
        $kept = array_filter($facts, static fn (array $fact): bool => $fact['visible'] && $keep($fact));
        $date = $filters->sortsByAdded() ? 'added' : 'posted';
        uksort($kept, static fn (int $a, int $b): int => [$kept[$a][$date], $a] <=> [$kept[$b][$date], $b]);
        $ids = array_keys($kept);

        return $filters->ascending() ? $ids : array_reverse($ids);
    }
}
