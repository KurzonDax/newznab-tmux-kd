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
use App\Services\Releases\RememberedListFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsImportedChip;
use Tests\Support\AssertsReleaseDeleteDialog;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The Adult releases screen, GET /adult (issue #887; docs/proposals/adult-redesign/SPEC.md 5,
 * DATA-CONTRACT.md 6 and the list checks of prototype/check.mjs), and its Previews wall, GET
 * /adult/previews (issue #1218; SPEC 5B, DATA-CONTRACT tests 11-14 and the wall checks).
 */
final class AdultReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsImportedChip;
    use AssertsReleaseDeleteDialog;
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
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source', 'is_imported']);
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
            ->assertDontSee('data-expand', false);
        $html = (string) $response->getContent();
        // the "Releases | Previews" switch, then the name search, sit between the heading and the sort menu (SPEC 5B.1)
        $this->assertMatchesRegularExpression('/<h1 data-part="page title">Adult releases<\/h1>\s*(<!--.*?-->\s*)*<div class="segmented" data-view-switch="data-view-switch" role="group" aria-label="View">\s*'
            .'<a href="'.preg_quote(route('adult.releases'), '/').'" aria-current="page" data-part="view switch, current">Releases<\/a>\s*'
            .'<a href="'.preg_quote(route('adult.previews'), '/').'" data-part="view switch, other">Previews<\/a>\s*<\/div>\s*'
            .'<div class="tv-search tv-name-search">\s*<label>\s*<i class="fas fa-magnifying-glass" aria-hidden="true"><\/i>\s*'
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

        // the list shows no clip length (SPEC 5.10); the seconds the wall's tag shows come with the one clip read (DATA-CONTRACT 4.3)
        DB::enableQueryLog();
        $response = $this->page('/adult')->assertOk()->assertDontSee('Clip · ')->assertDontSee('clip-badge', false)->assertDontSee('>Clip<', false)->assertDontSee('second video');
        $this->assertSame(1, collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'release_video_clips'))->count());
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

    public function test_an_admin_gets_the_red_delete_button_right_of_cart_in_every_row_and_the_confirmation_dialog(): void
    {
        $first = $this->adult('Studio.Scene.One.1080p');
        $second = $this->adult('Studio.Scene.Two.720p', ['postdate' => '2026-09-01 00:00:00']);
        $admin = $this->browserUser('Admin');

        $response = $this->page('/adult', $admin)->assertOk();
        foreach ([$first => 'Studio.Scene.One.1080p', $second => 'Studio.Scene.Two.720p'] as $id => $name) {
            $row = $this->rowOf($response, $name);
            $this->assertSame(['download', 'copy', 'cart', 'delete'], $this->actions($row), $name);
            $this->assertStringContainsString('<button type="button" class="tv-action" data-delete-release="'.route('admin.release-delete', $this->guidOf($id)).'" data-release-name="'.$name
                .'" title="Delete release" aria-label="Delete '.$name.'"><i class="fas fa-trash" aria-hidden="true"></i></button>', $row);
            $this->assertStringNotContainsString('tv-action-slot', $row);
        }
        // the dialog sits inside the screen, after the floating bar, which keeps its three buttons (one at a time, no bulk delete)
        $response->assertSeeInOrder(['x-data="tvReleases"', 'class="tv-bulk"', 'Clear selection</button>', 'x-data="releaseDeleteDialog"'], false)
            ->assertDontSee('Delete selected');
        $this->assertReleaseDeleteDialog((string) $response->getContent());
        // a list drawn again in place (a filter, the name search, the last row deleted) keeps the buttons
        $fragment = $this->page('/adult?_fragment=list', $admin)->assertOk();
        $this->assertSame(['download', 'copy', 'cart', 'delete'], $this->actions($this->rowOf($fragment, 'Studio.Scene.One.1080p')));
        $fragment->assertDontSee('releaseDeleteDialog', false);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_a_user_or_moderator_gets_the_empty_cell_and_no_delete_dialog(string $role): void
    {
        $this->adult('Studio.Scene.One.1080p');
        $response = $this->page('/adult', $this->browserUser($role))->assertOk();
        $this->assertNoReleaseDelete((string) $response->getContent(), $role);
        $row = $this->rowOf($response, 'Studio.Scene.One.1080p');
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($row));
        $this->assertStringContainsString('<span class="tv-action tv-action-slot" aria-hidden="true"></span>', $row);
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

    /**
     * DATA-CONTRACT test 11: the wall lists the visible Adult releases with haspreview = 1 or jpgstatus = 1, 40 a page, in
     * the list's order for each sort, with and without a filter; pages past the middle are read mirrored and return the
     * same ids as the unmirrored read.
     */
    public function test_the_wall_lists_only_visible_releases_with_a_picture_40_a_page_in_the_list_order_for_each_sort(): void
    {
        $facts = $this->filterFixture();
        foreach ($facts as $id => &$fact) {
            $fact['picture'] = $id % 3 === 0 || $id % 4 === 0;
            DB::table('releases')->where('id', $id)->update(['haspreview' => $id % 3 === 0 ? 1 : 0, 'jpgstatus' => $id % 4 === 0 ? 1 : 0]);
        }
        unset($fact);
        $wall = app(AdultReleaseList::class)->previews();
        $this->assertSame([40, 50], [$wall->perPage(), app(AdultReleaseList::class)->perPage()]);
        $cases = [
            'no filter' => [new AdultReleaseFilters, static fn (array $fact): bool => $fact['picture']],
            '1080p' => [new AdultReleaseFilters(resolutions: ['1080p']), static fn (array $fact): bool => $fact['picture'] && $fact['resolution'] === 2],
            'name search' => [new AdultReleaseFilters(search: 'scene'), static fn (array $fact): bool => $fact['picture'] && str_contains(mb_strtolower($fact['name']), 'scene')],
        ];
        foreach ($cases as $case => [$filters, $keep]) {
            foreach (array_keys(AdultReleaseFilters::SORTS) as $sort) {
                $sorted = new AdultReleaseFilters($filters->categories, $filters->resolutions, ReleaseSort::from($sort), search: $filters->search);
                $expected = $this->expectedOrder($facts, $keep, $sorted);
                $total = $wall->count($sorted, [self::VR]);
                $this->assertSame(count($expected), $total, $case);
                $read = [];
                foreach (range(1, max(1, (int) ceil($total / 40))) as $page) {
                    $ids = $wall->pageIds($sorted->withPage($page), [self::VR], $total);
                    $this->assertLessThanOrEqual(40, count($ids));
                    $read = [...$read, ...$ids];
                }
                $this->assertSame($expected, $read, $case.', '.$sort);
            }
        }
        $this->assertGreaterThan(80, count($this->expectedOrder($facts, $cases['no filter'][1], new AdultReleaseFilters)), 'three pages, the last past the middle');
    }

    /** DATA-CONTRACT test 11: the wall's pager line, last page and past-the-end redirect count 40 a page; the list still pages by 50. */
    public function test_the_wall_pager_line_and_last_page_count_40_a_page(): void
    {
        $expected = [];
        foreach (range(1, 105) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index)->toDateTimeString();
            $expected[] = $this->adult('Pictured '.$index, ['postdate' => $postdate, 'haspreview' => $index % 2, 'jpgstatus' => 1 - $index % 2]);
        }
        $this->adult('Unpictured', ['postdate' => '2026-02-01 00:00:00']);
        $order = array_reverse($expected);

        foreach ([1 => [1, 40], 2 => [41, 80], 3 => [81, 105]] as $page => [$from, $to]) {
            $response = $this->page('/adult/previews'.($page > 1 ? '?page='.$page : ''))->assertOk()
                ->assertSee('Showing '.$from.'–'.$to.' of 105 releases')->assertSee('Page '.$page.' of 3');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->cardIds($response), 'page '.$page);
        }
        $this->page('/adult/previews?page=9')->assertRedirect(route('adult.previews', ['page' => 3]));
        $this->page('/adult/previews')->assertSee('aria-label="Page 3"', false)->assertDontSee('aria-label="Page 4"', false)->assertSee('Go to page');
        $this->page('/adult?page=2')->assertSee('Showing 51–100 of 106 releases')->assertSee('Page 2 of 3');
    }

    /** DATA-CONTRACT test 11: the wall's count is its rows' and is cached apart from the list's count. */
    public function test_the_wall_count_matches_its_rows_and_is_cached_apart_from_the_list_count(): void
    {
        $this->adult('With.Preview', ['haspreview' => 1]);
        $this->adult('With.Sample', ['jpgstatus' => 1]);
        $this->adult('No.Picture.Flag');
        $this->adult('Passworded.Preview', ['haspreview' => 1, 'passwordstatus' => 1]);
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '0']);

        $this->page('/adult/previews')->assertSee('Showing 1–2 of 2 releases');
        $this->page('/adult')->assertSee('Showing 1–3 of 3 releases');
        $this->adult('Later.Preview', ['haspreview' => 1, 'postdate' => '2026-09-01 00:00:00']);
        $this->page('/adult/previews')->assertSee('Showing 1–2 of 2 releases');
        ReleaseBrowseService::bumpCacheVersion();
        $wall = $this->page('/adult/previews')->assertSee('Showing 1–3 of 3 releases');
        $this->assertSame(['Later.Preview', 'With.Preview', 'With.Sample'], DB::table('releases')->whereIn('id', $this->cardIds($wall))->orderBy('name')->pluck('name')->all());

        // the fragment is the wall alone: the pager line, the cards and the bottom pager
        $fragment = (string) $this->page('/adult/previews?_fragment=list&q=Preview')->assertOk()->assertSee('Showing 1–2 of 2 releases')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('tv-filters', $fragment);
        $this->assertStringNotContainsString('<table', $fragment);
        $this->assertSame(2, substr_count($fragment, '<li class="adult-card" data-release-row>'));
    }

    /** SPEC 5B.2, appendix A: the wall's empty lines. */
    public function test_the_wall_says_when_there_is_no_picture_and_names_the_filters_that_match_nothing(): void
    {
        $this->adult('No.Picture.Yet', ['resolution' => 2]);

        $this->page('/adult/previews')->assertOk()->assertSee('Showing 0 releases')->assertSee('<p class="tv-empty">There are no adult releases with a picture yet.</p>', false)
            ->assertDontSee('class="adult-wall"', false);
        $this->opened('/adult/previews?resolution[]=1080p&q=Scene')->assertOk()->assertSee('<p class="tv-empty">No releases match 1080p · names containing “Scene”.</p>', false);
    }

    /**
     * DATA-CONTRACT test 12: one remembered set of filters and sort for the list and the wall; Clear all on either clears
     * both; the name search is never remembered; the switch is rendered bare (the page keeps it in step with the
     * address) and opening the other page with what it carries leaves the remembered set as it was.
     */
    public function test_the_wall_and_the_list_share_one_remembered_set_of_filters_and_sort(): void
    {
        $this->adult('Studio.Scene.1080p', ['resolution' => 2, 'haspreview' => 1]);
        $user = $this->user = $this->browserUser();

        // a change on the wall is remembered for the list
        $this->page('/adult/previews?_fragment=list&resolution[]=1080p&q=Scene')->assertOk();
        $this->assertSame(['resolution' => ['1080p']], $this->remembered($user));
        $this->page('/adult')->assertRedirect(route('adult.releases', ['resolution' => ['1080p']]));
        $this->page('/adult?q=Scene')->assertRedirect(route('adult.releases', ['resolution' => ['1080p'], 'q' => 'Scene']));
        // and one on the list for the wall
        $this->page('/adult?_fragment=list&completion=95&resolution[]=1080p&q=Scene')->assertOk();
        $this->assertSame(['resolution' => ['1080p'], 'completion' => 95], $this->remembered($user));
        $this->page('/adult/previews')->assertRedirect(route('adult.previews', ['resolution' => ['1080p'], 'completion' => 95]));

        // the switch's other half is rendered bare on both pages, whatever the address carries
        $query = ['resolution' => ['1080p'], 'completion' => 95, 'q' => 'Scene'];
        $list = (string) $this->page('/adult?'.http_build_query($query))->assertOk()->getContent();
        $this->assertStringContainsString('<a href="'.route('adult.previews').'" data-part="view switch, other">Previews</a>', $list);
        $wall = $this->page('/adult/previews?'.http_build_query($query))->assertOk()
            ->assertSee('<h1 data-part="page title">Adult previews</h1>', false)
            ->assertSee('<a href="'.route('adult.releases').'" data-part="view switch, other">Releases</a>', false)
            ->assertSee('<a href="'.route('adult.previews').'" aria-current="page" data-part="view switch, current">Previews</a>', false)
            ->assertSee('data-view-switch', false)->assertSee('data-preference-root="xxx"', false)->assertSee('value="Scene"', false);
        // what the switch carries after the change above opens the other page without changing the remembered set
        $this->assertSame(['resolution' => ['1080p'], 'completion' => 95], $this->remembered($user));
        $this->assertSame(['Studio.Scene.1080p'], DB::table('releases')->whereIn('id', $this->cardIds($wall))->pluck('name')->all());

        // the sort is shared
        $this->postJson('/profile/update-view', ['root' => 'xxx', 'sort' => 'newest'])->assertOk();
        $this->page('/adult/previews?resolution[]=1080p', User::query()->findOrFail($user->id))->assertSee('<option value="newest" selected', false)->assertSee('Added Sep 13, 2026');

        // Clear all on the wall clears both
        $this->page('/adult/previews?clear=1', User::query()->findOrFail($user->id))->assertRedirect(route('adult.previews'));
        $this->assertSame([], $this->remembered($user));
        $this->page('/adult', User::query()->findOrFail($user->id))->assertOk();
        $this->page('/adult/previews', User::query()->findOrFail($user->id))->assertOk()
            ->assertSee('href="'.route('adult.previews', [RememberedListFilters::CLEAR => 1]).'"', false);
    }

    /**
     * DATA-CONTRACT test 13: a card's thumbnail is the preview thumbnail, else the sample thumbnail, else the "No picture"
     * tile; a preview of a release with a clip carries the clip tag, with or without seconds; the thumbnail is the dialog's
     * own trigger with the Preview or Sample chip's data.
     */
    public function test_a_card_thumbnail_is_the_preview_else_the_sample_and_a_clip_preview_carries_its_tag(): void
    {
        $timed = $this->adult('Timed.Clip', ['haspreview' => 1, 'videostatus' => 1, 'postdate' => '2026-09-25 11:00:00']);
        $untimed = $this->adult('Untimed.Clip', ['haspreview' => 1, 'videostatus' => 1, 'postdate' => '2026-09-25 10:00:00']);
        $this->adult('Preview.Only', ['haspreview' => 1, 'jpgstatus' => 1, 'postdate' => '2026-09-25 09:00:00']);
        $sampleClip = $this->adult('Sample.With.Clip', ['jpgstatus' => 1, 'videostatus' => 1, 'postdate' => '2026-09-25 08:00:00']);
        $this->adult('Flags.Without.Files', ['haspreview' => 1, 'jpgstatus' => 1, 'postdate' => '2026-09-25 07:00:00']);
        $this->adult('Nothing', ['postdate' => '2026-09-25 06:00:00']);
        DB::table('release_video_clips')->insert([
            ['releases_id' => $timed, 'extension' => 'mp4', 'mime' => 'video/mp4', 'duration_seconds' => 30],
            ['releases_id' => $untimed, 'extension' => 'mp4', 'mime' => 'video/mp4', 'duration_seconds' => null],
            ['releases_id' => $sampleClip, 'extension' => 'mp4', 'mime' => 'video/mp4', 'duration_seconds' => 12],
        ]);
        foreach (['Timed.Clip', 'Untimed.Clip', 'Preview.Only'] as $name) {
            $this->image('preview', md5($name).'_thumb');
        }
        $this->image('preview', md5('Preview.Only'));
        $this->image('sample', md5('Preview.Only').'_thumb');
        $this->image('sample', md5('Sample.With.Clip').'_thumb');

        $response = $this->page('/adult/previews')->assertOk()->assertSee('Showing 1–5 of 5 releases')->assertDontSee('title="Nothing"', false)
            ->assertSeeInOrder(['title="Timed.Clip"', 'title="Untimed.Clip"', 'title="Preview.Only"', 'title="Sample.With.Clip"', 'title="Flags.Without.Files"'], false);
        $details = static fn (string $name): string => e(route('details', md5($name)));

        $card = $this->cardOf($response, 'Timed.Clip');
        $this->assertStringContainsString('<a class="adult-card-thumb has-clip" href="'.$details('Timed.Clip').'" data-thumb data-guid="'.md5('Timed.Clip').'" data-release-display-name="Timed.Clip"', $card);
        $this->assertStringContainsString('data-video-url="'.route('preview.video', md5('Timed.Clip')).'" data-video-type="video/mp4"', $card);
        $this->assertStringContainsString('data-poster-url="'.$this->url('preview', md5('Timed.Clip').'_thumb').'"', $card);
        $this->assertStringContainsString('data-image-title="Video preview" aria-label="Play the 30-second video clip of Timed.Clip"', $card);
        $this->assertStringContainsString('<img src="'.$this->url('preview', md5('Timed.Clip').'_thumb').'" alt="" loading="lazy">', $card);
        $this->assertStringContainsString('<span class="adult-card-clip" aria-hidden="true">Clip · 30 s</span>', $card);
        $this->assertStringContainsString('<span class="adult-card-play" aria-hidden="true">', $card);

        $card = $this->cardOf($response, 'Untimed.Clip');
        $this->assertStringContainsString('aria-label="Play the video clip of Untimed.Clip"', $card);
        $this->assertStringContainsString('<span class="adult-card-clip" aria-hidden="true">Clip</span>', $card);

        $card = $this->cardOf($response, 'Preview.Only');
        $this->assertStringContainsString('<a class="adult-card-thumb" href="'.$details('Preview.Only').'" data-thumb data-guid="'.md5('Preview.Only').'" data-release-display-name="Preview.Only"'
            ."\n".'           data-image-url="'.$this->url('preview', md5('Preview.Only').'_thumb').'" data-full-url="'.$this->url('preview', md5('Preview.Only')).'" data-image-title="Image preview"', $card);
        $this->assertStringContainsString('aria-label="View the preview image of Preview.Only"', $card);
        $this->assertStringNotContainsString('adult-card-clip', $card);
        $this->assertStringNotContainsString('data-video-url', $card);

        // a sample opens the Sample dialog even when the release has a clip, as the list's picture does
        $card = $this->cardOf($response, 'Sample.With.Clip');
        $this->assertStringContainsString('data-image-url="'.$this->url('sample', md5('Sample.With.Clip').'_thumb').'" data-full-url="" data-image-title="Sample image"', $card);
        $this->assertStringContainsString('aria-label="View the sample image of Sample.With.Clip"', $card);
        $this->assertStringNotContainsString('adult-card-clip', $card);
        $this->assertStringNotContainsString('data-video-url', $card);

        // flagged but off disk: the list's dashed tile, a plain link to details
        $card = $this->cardOf($response, 'Flags.Without.Files');
        $this->assertMatchesRegularExpression('/<a class="adult-card-thumb tv-placeholder is-no-picture" href="'.preg_quote($details('Flags.Without.Files'), '/').'" tabindex="-1" aria-hidden="true">\s*'
            .'<i class="fas fa-image" aria-hidden="true"><\/i>\s*<span class="tv-placeholder-label">No picture<\/span>\s*<\/a>/', $card);
        $this->assertStringNotContainsString('<img', $card);
        $this->assertStringNotContainsString('data-thumb', $card);
    }

    /**
     * DATA-CONTRACT test 13 and SPEC 5B.4-5B.5: the card's name, its chips (resolution, Completion under 100%, Password,
     * Media Info only, the Media Info tooltip naming the whole summary), its foot, and no selection.
     */
    public function test_a_card_shows_its_name_the_four_chip_kinds_its_size_and_date_and_the_buttons_without_selection(): void
    {
        $tables = ProductionTables::fromAuthority();
        $tables->create('video_data');
        $tables->create('audio_data');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $id = $this->adult('Studio_Scene-[Part.1]', ['haspreview' => 1, 'jpgstatus' => 1, 'videostatus' => 1, 'completion' => 97, 'passwordstatus' => 1, 'nfostatus' => 1,
            'is_imported' => 1, 'groups_id' => 1, 'fromname' => 'poster <p@example.invalid>', 'resolution' => 2, 'size' => 2587017379, 'postdate' => '2026-09-25 10:00:00',
            'adddate' => '2026-09-25 11:30:00']);
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => 'AVC', 'videocodec' => 'avc1', 'videoheight' => 1080]);
        DB::table('audio_data')->insert(['releases_id' => $id, 'audioid' => 1, 'audioformat' => 'AAC LC', 'audiochannels' => '2']);
        $this->image('preview', md5('Studio_Scene-[Part.1]').'_thumb');

        $response = $this->page('/adult/previews')->assertOk()->assertDontSee('class="tv-bulk"', false)
            ->assertDontSee('data-select', false)->assertDontSee('releaseDeleteDialog', false);
        $this->assertSame(1, preg_match('/<ul class="adult-wall" aria-label="Releases">(.*?)<\/ul>/s', (string) $response->getContent(), $wall));
        $this->assertStringNotContainsString('type="checkbox"', $wall[1], 'no select boxes on the wall');
        $card = $this->cardOf($response, 'Studio_Scene-[Part.1]');
        $this->assertStringContainsString('<a class="tv-release-name adult-card-name" href="'.e(route('details', md5('Studio_Scene-[Part.1]'))).'" title="Studio_Scene-[Part.1]">'
            .'Studio_<wbr>Scene-<wbr>[Part.<wbr>1]<wbr></a>', $card);
        preg_match('/<div class="tv-chips adult-card-chips">(.*?)<\/div>/s', $card, $chips);
        $this->assertMatchesRegularExpression('/^\s*<span class="resolution-chip resolution-chip-1080"\s*>1080p<\/span>\s*<span [^>]*chip-tone-completion-ok[^>]*>\s*97% complete\s*<\/span>\s*'
            .'<span [^>]*chip-tone-password[^>]*>.*?Password\s*<\/span>\s*<button [^>]*mediainfo-badge[^>]*>.*?H\.264 · AAC 2\.0\s*<\/button>\s*$/s', $chips[1]);
        $this->assertStringContainsString('title="View media info: H.264 · AAC 2.0"', $chips[1]);
        foreach (['chip-tone-nfo', 'preview-badge', 'sample-badge', 'tv-origin', 'chip-tone-imported', 'data-part'] as $absent) {
            $this->assertStringNotContainsString($absent, $chips[1], $absent);
        }
        $this->assertMatchesRegularExpression('/<div class="adult-card-facts" title="Posted Sep 25, 2026, 10:00 AM · Added Sep 25, 2026, 11:30 AM">\s*<span class="adult-card-size">2.41 GB<\/span>\s*'
            .'<span class="adult-card-date">Posted 2 hr ago<\/span>/', $card);
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($card));
        $this->assertStringNotContainsString('data-part="row action', $card);
        // the list's other chips stay on the list
        $this->assertStringContainsString('View media info"', $this->rowOf($this->page('/adult'), 'Studio_Scene-[Part.1]'));
    }

    /** SPEC 5B.6: an admin's card has Delete right of Cart and the wall carries the list's confirmation; the date follows the Added sort. */
    public function test_an_admin_gets_delete_on_every_card_and_the_confirmation_dialog(): void
    {
        $this->adult('Studio.Scene.One.1080p', ['haspreview' => 1, 'adddate' => '2026-09-25 11:30:00']);
        $admin = $this->browserUser('Admin');
        $admin->view_prefs = ['xxx' => ['sort' => 'newest']];
        $admin->save();

        $wall = $this->page('/adult/previews', User::query()->findOrFail($admin->id))->assertOk()
            ->assertSee('x-on:release-deleted="releaseDeleted"', false)->assertDontSee('class="tv-bulk"', false)->assertDontSee('Delete selected');
        $this->assertReleaseDeleteDialog((string) $wall->getContent());
        $card = $this->cardOf($wall, 'Studio.Scene.One.1080p');
        $this->assertSame(['download', 'copy', 'cart', 'delete'], $this->actions($card));
        $this->assertStringNotContainsString('tv-action-slot', $card);
        $this->assertStringContainsString('<span class="adult-card-date">Added 30 min ago</span>', $card);
        // a wall drawn again in place keeps the buttons
        $fragment = $this->page('/adult/previews?_fragment=list', User::query()->findOrFail($admin->id))->assertOk()->assertDontSee('releaseDeleteDialog', false);
        $this->assertSame(['download', 'copy', 'cart', 'delete'], $this->actions($this->cardOf($fragment, 'Studio.Scene.One.1080p')));
    }

    /**
     * DATA-CONTRACT test 14: the Media Info chip names the video by format first and the audio by the dialog's short name,
     * on the list row and the wall card alike.
     */
    public function test_the_media_info_chip_reads_h264_and_aac_on_the_list_and_the_wall(): void
    {
        $tables = ProductionTables::fromAuthority();
        $tables->create('video_data');
        $tables->create('audio_data');
        $id = $this->adult('Plain.Names', ['haspreview' => 1]);
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => 'AVC', 'videocodec' => 'avc1', 'videoheight' => 1080]);
        DB::table('audio_data')->insert(['releases_id' => $id, 'audioid' => 1, 'audioformat' => 'AAC LC', 'audiochannels' => '2']);
        $this->image('preview', md5('Plain.Names').'_thumb');

        $this->assertMatchesRegularExpression('/mediainfo-badge[^>]*title="View media info">\s*<i [^>]*><\/i>\s*H\.264 · AAC 2\.0\s*<\/button>/', $this->rowOf($this->page('/adult'), 'Plain.Names'));
        $this->assertMatchesRegularExpression('/mediainfo-badge[^>]*>\s*<i [^>]*><\/i>\s*H\.264 · AAC 2\.0\s*<\/button>/', $this->cardOf($this->page('/adult/previews'), 'Plain.Names'));
        $this->page('/adult')->assertDontSee('avc1')->assertDontSee('AAC LC');
    }

    public function test_the_wall_needs_the_adult_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view adult');
        $this->page('/adult/previews', $user)->assertForbidden()->assertSee('Adult is hidden in your account preferences.');
        $this->assertSame('/adult/previews', route('adult.previews', [], false));
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

    private function guidOf(int $id): string
    {
        return (string) DB::table('releases')->where('id', $id)->value('guid');
    }

    /** @return list<int> the wall's cards' release ids, in page order (each card's Download link names its guid) */
    private function cardIds(TestResponse $response): array
    {
        $guids = [];
        foreach (array_slice(explode('<li class="adult-card" data-release-row>', (string) $response->getContent()), 1) as $card) {
            $this->assertSame(1, preg_match('/getnzb\/([0-9a-f]{32})"/', $card, $match));
            $guids[] = $match[1];
        }
        $ids = DB::table('releases')->whereIn('guid', $guids)->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $guids);
    }

    private function cardOf(TestResponse $response, string $name): string
    {
        foreach (explode('<li class="adult-card" data-release-row>', (string) $response->getContent()) as $card) {
            if (str_contains($card, 'title="'.e($name).'"')) {
                return strstr($card, '</li>', true) ?: $card;
            }
        }
        $this->fail('No card for '.$name);
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
        preg_match_all('/class="tv-action(?: tv-action-download download-nzb)?" (?:data-copy-nzb|data-cart|data-watch-picker|data-delete-release|title="Download)/', $row, $matches);

        return array_map(static fn (string $match): string => match (true) {
            str_contains($match, 'Download') => 'download', str_contains($match, 'copy') => 'copy',
            str_contains($match, 'cart') => 'cart', str_contains($match, 'delete') => 'delete', default => 'watch',
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

    public function test_an_imported_release_ends_its_own_chips_with_the_imported_chip_ahead_of_the_group_and_poster(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.example']);
        $this->adult('Imported with chips', ['is_imported' => 1, 'completion' => 80, 'nfostatus' => 1, 'haspreview' => 1, 'jpgstatus' => 1, 'groups_id' => 1, 'fromname' => 'someone@example.invalid']);
        $this->adult('Imported alone', ['is_imported' => 1]);
        $this->adult('Indexed with chips', ['nfostatus' => 1, 'groups_id' => 1]);

        $response = $this->page('/adult')->assertOk();
        $row = $this->rowOf($response, 'Imported with chips');
        $this->assertImportedChip($row, 'An Adult row');
        $this->assertImportedChipPlaced($row, ['% complete', 'nfo-badge', 'preview-badge', 'sample-badge'], ['tv-origin-pair'], 'An Adult row');
        $alone = $this->rowOf($response, 'Imported alone');
        $this->assertImportedChip($alone, 'An Adult row with no other chip');
        $this->assertStringContainsString('<div class="tv-chips">', $alone);
        $this->assertNoImportedChip($this->rowOf($response, 'Indexed with chips'), 'An Adult row that was not imported');
    }

    public function test_imported_only_lists_exactly_the_visible_imported_releases_from_the_imported_index(): void
    {
        $this->adult('Imported scene', ['is_imported' => 1, 'completion' => 40]);
        $this->adult('Imported dvd', ['is_imported' => 1, 'categories_id' => self::DVD]);
        $this->adult('Imported locked', ['is_imported' => 1, 'passwordstatus' => 1]);
        $this->adult('Indexed scene');
        $this->adult('Indexed partial', ['completion' => 96]);
        $user = $this->user = $this->browserUser();

        $response = $this->page('/adult?completion=imported')->assertOk()->assertSee('Showing 1–2 of 2 releases');
        $this->assertSame(['Imported dvd', 'Imported scene'], $this->listedNames($response));
        $this->assertImportedOnlyChosenLast((string) $response->getContent());
        $this->assertSame('Completion: Imported', $this->cellText($response, 'completion'));
        $this->assertSame(['completion' => 'imported'], $response->viewData('filters')->query());
        $this->assertListed('/adult?completion=95', ['Imported dvd', 'Indexed partial', 'Indexed scene']);
        $this->assertListed('/adult?completion=imported&category[]='.self::DVD, ['Imported dvd']);
        $this->assertListed('/adult?completion=imported&q=scene', ['Imported scene']);
        $this->page('/adult?completion=imported&q=nothing')->assertOk()->assertSee('Showing 0 releases')->assertSee('imported only');
        $this->assertSame('imported', $this->remembered($user)['completion']);

        $list = app(AdultReleaseList::class);
        $count = fn (AdultReleaseFilters $filters): string => (fn (AdultReleaseFilters $filters): string => $this->countIndex($filters))->call($list, $filters);
        foreach ([[], ['categories' => [self::DVD]], ['resolutions' => ['4k']], ['audio' => ['unknown']], ['search' => 'scene']] as $set) {
            foreach (['posted' => [ReleaseSort::PostedNewest, ReleaseSort::PostedOldest], 'added' => [ReleaseSort::AddedNewest, ReleaseSort::AddedOldest]] as $date => $sorts) {
                foreach ($sorts as $sort) {
                    $filters = new AdultReleaseFilters(...$set, completion: AdultReleaseFilters::IMPORTED_ONLY, sort: $sort);
                    $this->assertSame('ix_releases_imported_'.$date, $list->readIndex($filters, []), json_encode([array_keys($set), $sort->value]));
                    $this->assertContains($count($filters), ['ix_releases_imported_posted', 'ix_releases_imported_added']);
                }
            }
        }
        $this->assertSame('ix_releases_band_count', $count(new AdultReleaseFilters(completion: 95)));
    }
}
