<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\TvReleaseFilters;
use App\Data\TvShowFilters;
use App\Enums\ReleaseSort;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\TvReleaseList;
use App\Services\Releases\TvShowWall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\InteractsWithSecondaryProviders;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The TV releases screen, GET /tv (issue #777; check.mjs lines 27-117 and 134-150). */
final class TvReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use InteractsWithSecondaryProviders;
    use IsolatedSqliteDatabase;

    private const HD = 5040;

    private const UHD = 5045;

    private const SD = 5030;

    private const FOREIGN = 5020;

    private const OTHER = 5999;

    private const DRAMA = 1;

    private const COMEDY = 2;

    private const SCI_FI = 3;

    private const ENGLISH = 1;

    private const KOREAN = 2;

    private ?User $user = null;

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
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'repair_outcome', 'rescan_outcome', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'movieinfo_id', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'tv_info', 'networks', 'people', 'genres', 'video_genres',
            'video_people', 'tv_episodes', 'release_tv_episodes', 'release_audio_tags', 'release_video_clips', 'languages', 'release_audio_languages'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert(['id' => 5000, 'title' => 'TV', 'status' => 1]);
        foreach ([self::FOREIGN => 'Foreign', self::SD => 'SD', self::HD => 'HD', self::UHD => 'UHD'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 5000, 'status' => 1]);
        }
        DB::table('videos')->insert([
            ['id' => 11, 'type' => 0, 'title' => 'Glass Meridian', 'started' => '2024-01-01 00:00:00'],
            ['id' => 12, 'type' => 0, 'title' => 'Salt Harbour', 'started' => '2020-01-01 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NntpProviderPool::forgetConfiguredProviders();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_page_lists_releases_newest_first_with_the_show_line_and_releases_without_a_show(): void
    {
        DB::table('tv_episodes')->insert(['id' => 7, 'videos_id' => 11, 'series' => 1, 'episode' => 2, 'se_complete' => 'S01E02', 'title' => 'Second Tide', 'summary' => '', 'firstaired' => null]);
        DB::table('tv_episodes')->insert(['id' => 3, 'videos_id' => 11, 'series' => 1, 'episode' => 2, 'se_complete' => 'S01E02', 'title' => 'The Canonical Title', 'summary' => '', 'firstaired' => '2024-01-02']);
        $episode = $this->tv('Glass.Meridian.S01E02.1080p.WEB.h264-GRP', ['videos_id' => 11, 'postdate' => '2026-09-25 10:00:00']);
        DB::table('release_tv_episodes')->insert(['releases_id' => $episode, 'season' => 1, 'episode' => 2]);
        $pack = $this->tv('Glass.Meridian.S02.COMPLETE.1080p.WEB.h264-GRP', ['videos_id' => 11, 'postdate' => '2026-09-20 10:00:00']);
        DB::table('release_tv_episodes')->insert(['releases_id' => $pack, 'season' => 2, 'episode' => null]);
        $this->tv('Morning.Show.2026.09.22.1080p.WEB.h264-CLOWNS', ['videos_id' => 0, 'postdate' => '2026-09-24 10:00:00']);

        $response = $this->page('/tv')->assertOk()
            ->assertSee('<h1 data-part="page title">TV releases</h1>', false)
            ->assertSeeInOrder(['Glass.Meridian.S01E02', 'Morning.Show.2026.09.22', 'Glass.Meridian.S02.COMPLETE'])
            ->assertSee('Glass Meridian · S01E02 · The Canonical Title')
            ->assertSee('href="'.url('/tv/show/11/1?open=2').'"', false)
            ->assertSee('Glass Meridian · Season 2 pack')
            ->assertSee('href="'.url('/tv/show/11/2').'"', false)
            ->assertSee('2 hr ago')->assertSee('Sep 20, 2026')
            ->assertDontSee('style="', false);
        $noShow = $this->rowOf($response, 'Morning.Show.2026.09.22');
        $this->assertStringContainsString('data-noshow', $noShow);
        $this->assertStringNotContainsString('tv-show-line', $noShow);
        $this->assertStringNotContainsString('data-watch-picker', $noShow);
        $this->assertStringContainsString('tv-action-slot', $noShow);
        $this->assertStringNotContainsString('<img', $noShow);
        $withShow = $this->rowOf($response, 'Glass.Meridian.S01E02');
        $this->assertSame(['download', 'copy', 'cart', 'watch'], $this->actions($withShow));
        $this->assertStringContainsString('data-watch-picker="'.route('watchlist.picker', ['root' => 'tv', 'id' => 11]).'"', $withShow);
        $this->assertStringContainsString('title="Follow this show" aria-label="Follow Glass Meridian"><i class="far fa-bookmark" aria-hidden="true"></i></button>', $withShow);
        $this->assertStringContainsString('data-watch-on-title="Following this show · click to unfollow" data-watch-off-aria="Follow Glass Meridian" data-watch-on-aria="Unfollow Glass Meridian"', $withShow);
        $this->assertNoWatchWording((string) $response->getContent(), 'The TV releases list');
        $this->assertStringContainsString('href="'.route('details', md5('Glass.Meridian.S01E02.1080p.WEB.h264-GRP')).'"', $withShow);
    }

    public function test_an_excluded_sub_category_is_missing_from_rows_count_and_category_menu(): void
    {
        $this->tv('Visible HD release');
        $this->tv('Hidden foreign release', ['categories_id' => self::FOREIGN]);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::FOREIGN]);
        Cache::flush();

        $response = $this->page('/tv', $user)->assertOk()->assertSee('Visible HD release')->assertDontSee('Hidden foreign release')
            ->assertSee('Showing 1–1 of 1 release');
        $this->assertSame([self::SD => 'SD', self::HD => 'HD', self::UHD => 'UHD'], $response->viewData('categoryMenu'));
        $response->assertDontSee('data-value="'.self::FOREIGN.'"', false);
        $this->page('/tv?category[]='.self::FOREIGN, $user)->assertSee('Showing 1–1 of 1 release');
        $this->page('/browse/tv/'.self::FOREIGN, $user)->assertForbidden();
    }

    public function test_the_password_setting_decides_whether_passworded_releases_are_listed(): void
    {
        $this->tv('Clean release', ['passwordstatus' => 0]);
        $this->tv('Unchecked release', ['passwordstatus' => -1]);
        $this->tv('Passworded release', ['passwordstatus' => 1]);
        $this->tv('Broken archive', ['passwordstatus' => 2]);

        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '0']);
        Cache::flush();
        $this->page('/tv')->assertSee('Clean release')->assertSee('Unchecked release')->assertDontSee('Passworded release')
            ->assertDontSee('Broken archive')->assertSee('Showing 1–2 of 2 releases');

        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->page('/tv')->assertSee('Passworded release')->assertSee('chip-tone-password', false)->assertDontSee('Broken archive')
            ->assertSee('Showing 1–3 of 3 releases');
    }

    public function test_every_page_says_which_rows_it_shows_and_the_mirrored_half_keeps_the_order(): void
    {
        $expected = [];
        foreach (range(1, 275) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            $id = $this->tv('Paged '.$index, ['postdate' => $postdate]);
            $expected[] = [$postdate, $id];
        }
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);

        // page 2 is a deep page read from the front, page 4 lies past the middle and is read mirrored, page 6 is the last
        foreach ([1 => [1, 50], 2 => [51, 100], 4 => [151, 200], 6 => [251, 275]] as $page => [$from, $to]) {
            $response = $this->page('/tv'.($page > 1 ? '?page='.$page : ''))->assertOk()
                ->assertSee('Showing '.$from.'–'.$to.' of 275 releases')->assertSee('Page '.$page.' of 6');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $this->page('/tv?page=9')->assertRedirect(route('tv.releases', ['page' => 6]));
        $this->page('/tv?page=6')->assertDontSee('rel="next"', false)->assertSee('rel="prev"', false);
        $this->page('/tv')->assertSee('<span class="is-off" data-part="pager arrow">', false)->assertSee('aria-label="Page 6"', false)
            ->assertSee('Go to page');

        DB::table('releases')->where('name', 'Paged 5')->update(['resolution' => 1]);
        Cache::flush();
        $this->page('/tv?resolution[]=4k')->assertSee('Showing 1–1 of 1 release')->assertSee('Page 1 of 1')
            ->assertSee('Paged 5')->assertDontSee('rel="prev"', false)->assertDontSee('rel="next"', false)->assertDontSee('Go to page');
        $this->page('/tv?resolution[]=4k&source[]=dvd')->assertSee('Showing 0 releases')->assertSee('Page 1 of 1')
            ->assertSee('Nothing matches 4K · DVD.')->assertDontSee('<table', false);
    }

    public function test_filters_combine_or_within_a_menu_and_and_between_menus(): void
    {
        $this->tv('UHD web', ['resolution' => 1, 'source' => 1, 'categories_id' => self::UHD]);
        $this->tv('HD web', ['resolution' => 2, 'source' => 1]);
        $this->tv('HD remux', ['resolution' => 2, 'source' => 5]);
        $this->tv('HD bluray', ['resolution' => 2, 'source' => 2]);
        $this->tv('SD dvd', ['resolution' => 4, 'source' => 3, 'categories_id' => self::SD]);
        $this->tv('Unknown everything', ['resolution' => 0, 'source' => 0]);
        $this->release('A movie in 4K', ['categories_id' => 2040, 'resolution' => 1, 'source' => 1]);

        $this->assertListed('/tv?resolution[]=4k&resolution[]=1080p', ['HD bluray', 'HD remux', 'HD web', 'UHD web']);
        $this->assertListed('/tv?resolution[]=1080p&source[]=bluray', ['HD bluray', 'HD remux']);
        $this->assertListed('/tv?source[]=unknown&resolution[]=unknown', ['Unknown everything']);
        $this->assertListed('/tv?category[]='.self::UHD.'&category[]='.self::SD, ['SD dvd', 'UHD web']);
        $this->assertListed('/tv?category[]='.self::SD.'&resolution[]=4k', []);
        $this->assertListed('/tv?resolution[]=8k&source[]=vhs&category[]=2040', ['HD bluray', 'HD remux', 'HD web', 'SD dvd', 'UHD web', 'Unknown everything']);
        $set = $this->page('/tv?resolution[]=4k&resolution[]=1080p')->assertSee('title="Resolution: 4K, 1080p"', false);
        $this->assertSame('Resolution: 2 chosen', $this->cellText($set, 'resolution'));
        $this->assertMatchesRegularExpression('/class="checkbox-menu is-cell is-set"[^>]*data-name="resolution"/', (string) $set->getContent());
        $this->assertSame(['Source: any', 'Category: any'], [$this->cellText($set, 'source'), $this->cellText($set, 'category')]);
        $this->page('/tv?clear=1')->assertRedirect(route('tv.releases')); // Clear all, so the bare list is unfiltered (#881)
        $this->page('/tv')->assertSee('HD remux')->assertSeeInOrder(['HD remux', '<td>Remux</td>'], false);
    }

    public function test_the_four_sorts_order_the_list_and_the_chosen_one_is_remembered(): void
    {
        $this->tv('Posted early added late', ['postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']);
        $this->tv('Posted late added early', ['postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $user = $this->browserUser();

        $this->page('/tv', $user)->assertSeeInOrder(['Posted late added early', 'Posted early added late'])
            ->assertSee('<th class="tv-num">Posted</th>', false)->assertSee('<option value="posted" selected', false)
            ->assertSeeInOrder(['Posted: newest first', 'Posted: oldest first', 'Added: newest first', 'Added: oldest first']);
        foreach (['posted_oldest' => ['Posted early added late', 'Posted late added early', 'Posted'], 'newest' => ['Posted early added late', 'Posted late added early', 'Added'],
            'oldest' => ['Posted late added early', 'Posted early added late', 'Added'], 'posted' => ['Posted late added early', 'Posted early added late', 'Posted']] as $sort => [$first, $second, $column]) {
            $this->postJson('/profile/update-view', ['root' => 'tv', 'sort' => $sort])->assertOk();
            $this->page('/tv', User::query()->findOrFail($user->id))->assertSeeInOrder([$first, $second])
                ->assertSee('<th class="tv-num">'.$column.'</th>', false)->assertSee('<option value="'.$sort.'" selected', false);
        }
        $this->assertSame('posted', User::query()->findOrFail($user->id)->releaseViewPreferences('tv')['sort']);
        $this->postJson('/profile/update-view', ['root' => 'tv', 'sort' => 'grabs'])->assertUnprocessable();
        $this->postJson('/profile/update-view', ['root' => 'other', 'sort' => 'posted'])->assertUnprocessable();
    }

    public function test_a_same_show_batch_longer_than_four_collapses_to_three_rows_and_an_expander(): void
    {
        foreach (range(1, 6) as $index) {
            $this->tv('Batch '.$index, ['videos_id' => 11, 'postdate' => '2026-09-20 1'.$index.':00:00']);
        }
        foreach (range(1, 4) as $index) {
            $this->tv('Short run '.$index, ['videos_id' => 12, 'postdate' => '2026-09-19 1'.$index.':00:00']);
        }
        $this->tv('Later day same show', ['videos_id' => 12, 'postdate' => '2026-09-18 10:00:00']);
        foreach (range(1, 5) as $index) {
            $this->tv('No show '.$index, ['videos_id' => 0, 'postdate' => '2026-09-17 1'.$index.':00:00']);
        }

        $response = $this->page('/tv')->assertOk();
        foreach (['Batch 6', 'Batch 5', 'Batch 4'] as $name) {
            $this->assertStringNotContainsString(' hidden', $this->openingTag($response, $name), $name);
        }
        foreach (['Batch 3', 'Batch 2', 'Batch 1'] as $name) {
            $this->assertStringContainsString(' hidden', $this->openingTag($response, $name), $name);
        }
        $response->assertSee('Show 3 more from Glass Meridian posted in the same batch')->assertSee('data-label-open="Show fewer from Glass Meridian"', false)
            ->assertDontSee('more from Salt Harbour')->assertDontSee('data-more', false);
        foreach (['Short run 1', 'Later day same show', 'No show 1', 'No show 5'] as $name) {
            $this->assertStringNotContainsString(' hidden', $this->openingTag($response, $name), $name);
        }
        $this->assertSame(16, substr_count((string) $response->getContent(), '<tr data-release-row'), 'the page still holds every release');
    }

    public function test_chips_follow_the_completion_bands_and_what_each_release_has(): void
    {
        $this->tv('Complete release', ['completion' => 100]);
        $this->tv('Nearly complete', ['completion' => 96.4, 'repair_outcome' => 'failed', 'rescan_outcome' => 'failed']);
        $this->tv('Middling', ['completion' => 80]);
        $this->tv('Poor', ['completion' => 40, 'nfostatus' => 1, 'haspreview' => 1, 'jpgstatus' => 1]);

        $response = $this->page('/tv')->assertOk();
        $this->assertStringNotContainsString('% complete', $this->rowOf($response, 'Complete release'));
        $this->assertStringContainsString('chip-tone-completion-ok', $this->rowOf($response, 'Nearly complete'));
        $this->assertStringContainsString('96% complete', $this->rowOf($response, 'Nearly complete'));
        $this->assertStringNotContainsString('still repairing', $this->rowOf($response, 'Nearly complete'));
        $this->assertStringContainsString('chip-tone-completion-mid', $this->rowOf($response, 'Middling'));
        $this->assertStringContainsString('80% complete · still repairing', $this->rowOf($response, 'Middling'));
        $poor = $this->rowOf($response, 'Poor');
        $this->assertStringContainsString('chip-tone-completion-low', $poor);
        foreach (['nfo-badge' => 'NFO', 'preview-badge' => 'Preview', 'sample-badge' => 'Sample'] as $class => $word) {
            $this->assertMatchesRegularExpression('/'.$class.'[^>]*>\\s*'.$word.'\\s*<\\/button>/', $poor);
        }
        $this->assertStringNotContainsString('nfo-badge', $this->rowOf($response, 'Middling'));
    }

    public function test_the_list_has_no_files_or_grabs_column(): void
    {
        foreach (range(1, 5) as $index) {
            $this->tv('Batch '.$index, ['videos_id' => 11, 'postdate' => '2026-09-20 1'.$index.':00:00', 'grabs' => 7]);
        }

        $response = $this->page('/tv')->assertOk()
            ->assertSee('<colgroup><col class="tv-col-select"><col class="tv-col-art"><col><col class="tv-col-resolution"><col class="tv-col-source"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>', false)
            ->assertSee('<td colspan="8">', false)
            ->assertDontSee('<th class="tv-num">Files</th>', false)->assertDontSee('<th class="tv-num">Grabs</th>', false)
            ->assertDontSee('tv-col-files', false)->assertDontSee('tv-col-grabs', false)->assertDontSee('filelist-badge', false)
            ->assertDontSee('7 grabs', false);
        $this->assertSame(7, substr_count(strstr((string) $response->getContent(), '</thead>', true), '<th') - 1, 'seven headers over eight columns, the release header spanning two');
        $this->assertSame(8, substr_count($this->rowOf($response, 'Batch 5'), '<td'));
    }

    public function test_group_and_poster_chips_end_the_chip_line_as_one_unit(): void
    {
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.teevee'], ['id' => 2, 'name' => 'misc.test']]);
        $this->tv('Chips then origin', ['nfostatus' => 1, 'groups_id' => 1, 'fromname' => 'Uploader <up@example.invalid>']);
        $this->tv('Origin only', ['groups_id' => 2]);
        $this->tv('Poster only', ['fromname' => 'someone@example.invalid']);
        $this->tv('Nothing at all');

        $response = $this->page('/tv')->assertOk();
        $row = $this->rowOf($response, 'Chips then origin');
        $group = route('browse.all', ['group' => 'alt.binaries.teevee']);
        $poster = route('browse.all', ['poster' => 'Uploader <up@example.invalid>']);
        $this->assertMatchesRegularExpression('/<div class="tv-chips">.*nfo-badge.*<span class="tv-origin-pair">\s*'
            .'<a class="tv-origin-chip" href="'.preg_quote(e($group), '/').'" title="All releases in alt\.binaries\.teevee"><i class="fas fa-users" aria-hidden="true"><\/i>a\.b\.teevee<\/a>\s*'
            .'<a class="tv-origin-chip tv-origin-poster" href="'.preg_quote(e($poster), '/').'" title="All posts by Uploader &lt;up@example\.invalid&gt;"><i class="fas fa-user" aria-hidden="true"><\/i><span>Uploader &lt;up@example\.invalid&gt;<\/span><\/a>\s*'
            .'<\/span>\s*<\/div>/s', $row);
        $this->assertStringContainsString('?group=alt.binaries.teevee', $group);
        $this->assertStringContainsString('?poster=Uploader%20%3Cup%40example.invalid%3E', $poster);

        $originOnly = $this->rowOf($response, 'Origin only');
        $this->assertMatchesRegularExpression('/<div class="tv-chips">\s*<span class="tv-origin-pair">\s*<a class="tv-origin-chip" [^>]*title="All releases in misc\.test"><i [^>]*><\/i>misc\.test<\/a>\s*<\/span>\s*<\/div>/', $originOnly);
        $this->assertMatchesRegularExpression('/<div class="tv-chips">\s*<span class="tv-origin-pair">\s*<a class="tv-origin-chip tv-origin-poster" [^>]*title="All posts by someone@example\.invalid">/', $this->rowOf($response, 'Poster only'));
        $this->assertStringNotContainsString('tv-chips', $this->rowOf($response, 'Nothing at all'));
        $this->assertStringNotContainsString('target=', $row);
    }

    public function test_a_release_with_no_matched_show_gets_a_name_card_or_the_no_poster_tile(): void
    {
        $cards = [
            'Grand.Designs.NZ.S10E03.1080p.WEB' => ['Grand Designs NZ', 'S10E03'],
            'Show.Name.S01E01E02.1080p' => ['Show Name', 'S01E01–E02'],
            'Twin.Name.s02e05-e06.720p' => ['Twin Name', 'S02E05–E06'],
            'Days.of.Our.Lives.S59E1234.720p' => ['Days of Our Lives', 'S59E1234'],
            'Spaced Out S03 E14 x264' => ['Spaced Out', 'S03E14'],
            'Morning.Joe.2026.09.22.1080p.WEB' => ['Morning Joe', '2026-09-22'],
            'Daily_Talk_2026_09_21_720p' => ['Daily Talk', '2026-09-21'],
            'Bare.Episode.e12.720p' => ['Bare Episode', 'E12'],
            '24.S01E01.720p' => ['24', 'S01E01'],
            '[SiteTag].Some.Show.S01E02.1080p' => null,
            'www.Site.Tag.Show.S04E05' => ['www Site Tag Show', 'S04E05'],
        ];
        $tiles = ['[1/3] - "@AnimesHunt - Blue Lock S01 E14"', 'Packed.Show.S01E01.part01.rar', 'Plain.Upload.1080p.WEB', 'Glued.Show.S01E01x264', 'Bare.Number.E5.720p',
            // appendix A and the prototype's showName() refuse any name containing .rar or .partN, words included
            'The.Rare.Breed.S01E01.720p', 'Show.Part1.S01E02.720p'];
        foreach ([...array_keys($cards), ...$tiles] as $index => $name) {
            $this->tv($name, ['postdate' => '2026-09-2'.($index % 5).' 10:0'.intdiv($index, 5).':00']);
        }

        $response = $this->page('/tv')->assertOk();
        foreach ($cards as $name => $card) {
            $cell = $this->posterCell($this->rowOf($response, $name));
            $details = preg_quote(e(route('details', md5($name))), '/');
            if ($card === null) {
                $this->assertMatchesRegularExpression('/<a class="tv-placeholder" href="'.$details.'" tabindex="-1" aria-hidden="true">/', $cell, $name);

                continue;
            }
            $this->assertMatchesRegularExpression('/<a class="tv-placeholder is-card" href="'.$details.'" tabindex="-1" aria-hidden="true">\s*'
                .'<span class="tv-placeholder-title">'.preg_quote(e($card[0]), '/').'<\/span>\s*<span class="tv-placeholder-label">'.preg_quote($card[1], '/').'<\/span>\s*<\/a>/u', $cell, $name);
        }
        foreach ($tiles as $name) {
            $this->assertMatchesRegularExpression('/<a class="tv-placeholder" href="[^"]+" tabindex="-1" aria-hidden="true">\s*<i class="fas fa-tv" aria-hidden="true"><\/i>\s*<span class="tv-placeholder-label">No poster<\/span>\s*<\/a>/',
                $this->posterCell($this->rowOf($response, $name)), $name);
        }
    }

    public function test_a_matched_show_keeps_its_poster_cell_and_the_buttons_are_two_by_two(): void
    {
        $this->tv('Matched.Show.S01E01.1080p', ['videos_id' => 12, 'postdate' => '2026-09-24 10:00:00']);
        $this->tv('Unmatched.Show.S01E01.1080p', ['videos_id' => 0, 'postdate' => '2026-09-23 10:00:00']);
        $cart = $this->browserUser();
        DB::table('users_releases')->insert(['users_id' => $cart->id, 'releases_id' => DB::table('releases')->where('name', 'Unmatched.Show.S01E01.1080p')->value('id')]);

        $response = $this->page('/tv', $cart)->assertOk();
        $matched = $this->rowOf($response, 'Matched.Show.S01E01.1080p');
        $this->assertStringNotContainsString('tv-placeholder', $matched);
        $this->assertMatchesRegularExpression('/<span class="tv-no-poster"\s+data-part="row poster"\s*>Salt Harbour<\/span>/', $matched);
        $this->assertSame(['download', 'copy', 'cart', 'watch'], $this->actions($matched));
        $this->assertStringContainsString('<div class="tv-actions">', $matched);

        $unmatched = $this->rowOf($response, 'Unmatched.Show.S01E01.1080p');
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($unmatched));
        $this->assertMatchesRegularExpression('/data-cart="[0-9a-f]{32}" aria-pressed="true"/', $unmatched);
        $this->assertMatchesRegularExpression('/aria-pressed="true"[^>]*><i class="fas fa-cart-shopping" aria-hidden="true"><\/i><\/button>\s*<span class="tv-action tv-action-slot" aria-hidden="true"><\/span>\s*<\/div>/', $unmatched);
    }

    public function test_old_tv_browse_urls_and_header_links_lead_to_the_new_screen(): void
    {
        $this->page('/browse/tv')->assertRedirect(route('tv.releases'));
        $this->page('/browse/TV/'.self::HD)->assertRedirect(route('tv.releases', ['category' => [self::HD]]));
        $this->page('/browse/tv/HD')->assertRedirect(route('tv.releases', ['category' => [self::HD]]));
        $response = $this->page('/tv')->assertSee('href="'.route('tv.releases').'" class="public-menu-root">All TV</a>', false)
            ->assertSee('href="'.route('tv.releases', ['category' => [self::HD]]).'"', false);
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-tv"\s+aria-current="true"/', (string) $response->getContent());
    }

    public function test_the_list_fragment_is_the_list_alone_and_the_count_is_cached_under_the_browse_version(): void
    {
        $this->tv('First release');
        $this->page('/tv')->assertSee('Showing 1–1 of 1 release');
        $this->tv('Second release', ['postdate' => '2026-09-01 00:00:00']);
        $this->page('/tv')->assertSee('Showing 1–1 of 1 release');
        ReleaseBrowseService::bumpCacheVersion();
        $this->page('/tv')->assertSee('Showing 1–2 of 2 releases');

        $fragment = $this->page('/tv?_fragment=list&resolution[]=1080p')->assertOk()->assertSee('Showing 1–2 of 2 releases')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('tv-filters', $fragment);
    }

    public function test_the_filter_bar_holds_the_release_and_show_cells_and_clear_all_sits_on_the_showing_line(): void
    {
        $this->tv('A release');
        $response = $this->page('/tv')->assertOk();
        $html = (string) $response->getContent();

        // check.mjs 78, 133: no menu in the title row; the release bar then the show bar, eleven cells in order
        $title = (string) strstr((string) strstr($html, '<div class="tv-filters">'), '<div class="filter-row tv-bar-list">', true);
        $this->assertStringNotContainsString('checkbox-menu', $title);
        $this->assertStringContainsString('data-part="sort dropdown"', $title);
        $response->assertSeeInOrder(['<div class="filter-row tv-bar-list">', '<div class="filter-bar is-release" role="group" aria-label="The release">',
            'data-name="completion"', '<div class="filter-bar is-show" role="group" aria-label="The show">', 'data-name="status"', 'x-ref="list" class="tv-list-end"'], false);
        preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', $html, $cells);
        $this->assertSame(['category', 'resolution', 'source', 'audio', 'completion', 'genre', 'decade', 'language', 'network', 'rating', 'status'], $cells[1]);
        $this->assertSame(['Category: any', 'Resolution: any', 'Source: any', 'Audio: any', 'Completion: any', 'Genre: any', 'Premiered: any',
            'Language: any', 'Network: any', 'Rating: any', 'Status: any'], array_map(fn (string $name): string => $this->cellText($response, $name), $cells[1]));
        $this->assertSame(11, substr_count($html, '<span class="checkbox-menu-value is-any" x-ref="value">any</span>'));

        // check.mjs 396: Completion is one choice with radio items
        $completion = (string) strstr((string) strstr($html, 'data-name="completion"'), 'data-name="genre"', true);
        $this->assertSame(['Any completion', '100% only', '95% or more'], array_map(static fn (string $item): string => trim(strip_tags(substr($item, (int) strpos($item, '>') + 1))),
            array_slice(explode('<button type="button" class="checkbox-menu-item" role="menuitemradio"', $completion), 1)));
        $this->assertSame(3, substr_count($completion, '<span class="checkbox-menu-dot"></span>'));
        $this->assertStringContainsString('data-single="true"', $completion);

        // check.mjs 124, 411: Clear all in a fixed slot on the Showing line, hidden but in place while nothing is set
        $line = (string) strstr((string) strstr($html, '<nav class="pager-line is-fixed" aria-label="Pages">'), '</nav>', true);
        $this->assertMatchesRegularExpression('/data-part="showing line">[^<]*<\/span>\s*<a href="'.preg_quote(route('tv.releases', ['clear' => 1]), '/')
            .'" class="pager-line-clear is-hidden" data-clear-all aria-hidden="true" tabindex="-1">Clear all<\/a>\s*<span class="is-off" data-part="pager arrow">/', $line);
        $this->assertSame(1, substr_count($html, 'data-clear-all'));
    }

    public function test_setting_a_filter_marks_its_cell_and_shows_clear_all_while_the_bar_and_line_keep_their_parts(): void
    {
        $this->tv('Full', ['completion' => 100]);
        $this->tv('Nearly', ['completion' => 96]);
        $empty = $this->page('/tv');
        $set = $this->page('/tv?completion=95&resolution[]=1080p&resolution[]=4k')->assertOk();

        $this->assertSame('Completion: 95%+', $this->cellText($set, 'completion'));
        $set->assertSee('title="Completion: 95% or more"', false)->assertSee('title="Resolution: 4K, 1080p"', false)
            ->assertSee('data-value="95" data-text="95% or more" data-short="95%+" aria-checked="true"', false)
            ->assertSee('class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', false);
        $this->assertSame('Resolution: 2 chosen', $this->cellText($set, 'resolution'));
        $this->assertSame('Completion: 100%', $this->cellText($this->page('/tv?completion=100'), 'completion'));
        $this->assertSame('Completion: any', $this->cellText($this->page('/tv?completion=90'), 'completion'));

        // check.mjs 268-271, 379-389 as markup: the same cells and Showing-line parts in the same order, set or not
        $shape = static fn (TestResponse $response): array => [
            preg_replace('/ is-set| is-any| is-hidden|aria-checked="[a-z]+"|aria-hidden="[a-z]+"| tabindex="-1"| title="[^"]*"|>[^<]*<|data-part="filter menu button(, set)?"/', '', (string) strstr((string) strstr((string) $response->getContent(), '<div class="filter-row'), 'x-ref="list"', true)),
            preg_replace('/ is-hidden|aria-hidden="[a-z]+"| tabindex="-1"|>[^<]*<|data-part="filter menu button(, set)?"/', '', (string) strstr((string) strstr((string) $response->getContent(), '<nav class="pager-line'), '</nav>', true)),
        ];
        $this->assertSame($shape($empty), $shape($set));
    }

    public function test_long_menus_open_with_a_search_field_and_short_ones_do_not(): void
    {
        foreach (range(1, 6) as $extra) {
            DB::table('categories')->insert(['id' => 5050 + $extra, 'title' => 'Extra '.$extra, 'root_categories_id' => 5000, 'status' => 1]);
        }
        $names = ['Arabic', 'Bengali', 'Czech', 'Danish', 'Dutch', 'English', 'Finnish', 'French', 'German', 'Greek', 'Hindi'];
        foreach ($names as $index => $name) {
            DB::table('languages')->insert(['id' => $index + 1, 'name' => $name]);
            DB::table('release_audio_languages')->insert(['releases_id' => $this->tv('Dub '.$name), 'languages_id' => $index + 1]);
        }
        $response = $this->page('/tv')->assertOk();
        $this->assertCount(10, $response->viewData('categoryMenu'));
        $this->assertCount(12, $response->viewData('audioMenu'));
        $html = (string) $response->getContent();
        $category = (string) strstr((string) strstr($html, 'data-name="category"'), 'data-name="resolution"', true);
        $this->assertStringNotContainsString('checkbox-menu-search', $category);
        $this->assertStringContainsString('<div class="checkbox-menu-panel" role="menu" aria-label="Category"', $category);
        $audio = (string) strstr((string) strstr($html, 'data-name="audio"'), 'data-name="completion"', true);
        $this->assertStringContainsString('<div class="checkbox-menu-panel is-searchable" role="dialog" aria-label="Audio"', $audio);
        $this->assertStringContainsString('<div class="checkbox-menu-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><input type="text" x-ref="search" x-on:input="narrow" placeholder="Search audio languages" aria-label="Search audio languages" autocomplete="off"></div>', $audio);
        $this->assertStringContainsString('<div role="menu" aria-label="Audio">', $audio);
    }

    public function test_the_audio_menu_lists_english_first_then_a_to_z_and_the_language_menu_most_releases_first_for_all_users(): void
    {
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::KOREAN, 'name' => 'Korean'], ['id' => 3, 'name' => 'Arabic'], ['id' => 4, 'name' => 'Welsh']]);
        DB::table('videos')->insert(['id' => 13, 'type' => 0, 'title' => 'Third Show', 'started' => '2020-01-01 00:00:00']);
        foreach ([11 => 'ko', 12 => 'en', 13 => 'cn'] as $show => $language) {
            DB::table('tv_info')->insert(['videos_id' => $show, 'summary' => '', 'publisher' => '', 'original_language' => $language]);
        }
        $audio = static fn (int $release, array $languages) => DB::table('release_audio_languages')->insert(array_map(static fn (int $language): array => ['releases_id' => $release, 'languages_id' => $language], $languages));
        // Korean on three TV releases (one hidden from this user, one passworded), English and Arabic on two, Welsh only on a movie
        $audio($this->tv('K1', ['videos_id' => 11]), [self::KOREAN, self::ENGLISH]);
        $audio($this->tv('K2', ['videos_id' => 11, 'categories_id' => self::FOREIGN]), [self::KOREAN]);
        $audio($this->tv('K3', ['videos_id' => 11, 'passwordstatus' => 1]), [self::KOREAN, 3]);
        $audio($this->tv('E1', ['videos_id' => 12]), [self::ENGLISH, 3]);
        $this->tv('E2', ['videos_id' => 12]);
        $this->tv('C1', ['videos_id' => 13]);
        $audio($this->release('A movie', ['categories_id' => 2040]), [4, 3]);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::FOREIGN]);

        $response = $this->page('/tv', $user)->assertOk();
        // the languages present for all users (Welsh is only on a movie): English first, then A to Z, then Unknown
        $this->assertSame([(string) self::ENGLISH => 'English', '3' => 'Arabic', (string) self::KOREAN => 'Korean', 'unknown' => 'Unknown'], $response->viewData('audioMenu'));
        // the list orders the shows' languages by their releases (Korean 3, English 2, Cantonese 1); the wall by shows
        $this->assertSame(['ko' => 'Korean', 'en' => 'English', 'cn' => 'Cantonese'], $response->viewData('showOptions')['language']);
        $this->assertListed('/tv?language[]=ko', ['K1'], $user);
        $response->assertSeeInOrder(['data-name="audio"', '>English<', '>Arabic<', '>Korean<', '>Unknown<', 'data-name="completion"'], false);
        $this->assertSame(['cn' => 'Cantonese', 'en' => 'English', 'ko' => 'Korean'], $this->page('/tv/shows', $user)->viewData('options')['language']);
    }

    public function test_each_new_filter_returns_exactly_what_a_direct_predicate_returns_in_every_sort_and_page(): void
    {
        $facts = $this->filterFixture();
        $shows = [11 => ['genre' => [self::DRAMA], 'decade' => 2000, 'language' => 'ko', 'network' => 1, 'rating' => 'TV-14', 'status' => 2],
            12 => ['genre' => [self::COMEDY], 'decade' => 2010, 'language' => 'en', 'network' => 2, 'rating' => 'TV-MA', 'status' => 1],
            13 => ['genre' => [self::DRAMA, self::SCI_FI], 'decade' => 1990, 'language' => 'en', 'network' => 3, 'rating' => 'TV-14', 'status' => 1]];
        $show = static fn (callable $test): callable => static fn (array $fact): bool => isset($shows[$fact['show']]) && $test($shows[$fact['show']]);
        $cases = [
            'English audio' => [new TvReleaseFilters(audio: [(string) self::ENGLISH]), static fn (array $fact): bool => in_array(self::ENGLISH, $fact['audio'], true)],
            'Unknown audio' => [new TvReleaseFilters(audio: ['unknown']), static fn (array $fact): bool => $fact['audio'] === []],
            'Korean or Unknown audio' => [new TvReleaseFilters(audio: [(string) self::KOREAN, 'unknown']), static fn (array $fact): bool => $fact['audio'] === [] || in_array(self::KOREAN, $fact['audio'], true)],
            '100%' => [new TvReleaseFilters(completion: 100), static fn (array $fact): bool => $fact['completion'] >= 100],
            '95%+' => [new TvReleaseFilters(completion: 95), static fn (array $fact): bool => $fact['completion'] >= 95],
            'Genre' => [new TvReleaseFilters(shows: new TvShowFilters(genres: [self::DRAMA])), $show(static fn (array $s): bool => in_array(self::DRAMA, $s['genre'], true))],
            'Premiered' => [new TvReleaseFilters(shows: new TvShowFilters(decades: [2000, 2010])), $show(static fn (array $s): bool => in_array($s['decade'], [2000, 2010], true))],
            'Language' => [new TvReleaseFilters(shows: new TvShowFilters(languages: ['en'])), $show(static fn (array $s): bool => $s['language'] === 'en')],
            'Network' => [new TvReleaseFilters(shows: new TvShowFilters(networks: [1, 3])), $show(static fn (array $s): bool => in_array($s['network'], [1, 3], true))],
            'Rating' => [new TvReleaseFilters(shows: new TvShowFilters(ratings: ['TV-14'])), $show(static fn (array $s): bool => $s['rating'] === 'TV-14')],
            'Status' => [new TvReleaseFilters(shows: new TvShowFilters(statuses: ['running'])), $show(static fn (array $s): bool => $s['status'] === 1)],
            'everything' => [new TvReleaseFilters(categories: [self::HD], completion: 95, audio: [(string) self::ENGLISH], shows: new TvShowFilters(genres: [self::DRAMA], statuses: ['running'])),
                static fn (array $fact): bool => $fact['category'] === self::HD && $fact['completion'] >= 95 && in_array(self::ENGLISH, $fact['audio'], true) && $fact['show'] === 13],
        ];
        foreach ($cases as $case => [$filters, $keep]) {
            // the show filters read from the matching shows under the threshold and from the band index at or over it
            foreach ($filters->shows->any() ? [1_000, 1] : [TvReleaseList::SHOW_READ_LIMIT] as $limit) {
                $list = new TvReleaseList(app(ReleaseBrowseService::class), app(TvShowWall::class), $limit);
                foreach (ReleaseSort::cases() as $sort) {
                    if (! array_key_exists($sort->value, TvReleaseFilters::SORTS)) {
                        continue;
                    }
                    $sorted = new TvReleaseFilters($filters->categories, $filters->resolutions, $filters->sources, $sort, 1, $filters->audio, $filters->completion, $filters->shows);
                    $expected = $this->expectedOrder($facts, $keep, $sorted);
                    $total = $list->count($sorted, [self::FOREIGN]);
                    $this->assertSame(count($expected), $total, $case);
                    $read = [];
                    foreach (range(1, max(1, (int) ceil($total / TvReleaseFilters::PER_PAGE))) as $page) {
                        $read = [...$read, ...$list->pageIds($sorted->withPage($page), [self::FOREIGN], $total)];
                    }
                    $this->assertSame($expected, $read, $case.', '.$sort->value.', limit '.$limit);
                }
            }
            if ($case !== 'everything') {
                $this->assertGreaterThan(TvReleaseFilters::PER_PAGE, count($this->expectedOrder($facts, $keep, $filters)), $case.' reaches a page past the middle');
            }
        }
    }

    public function test_the_list_names_the_index_the_rule_chooses_for_each_filter_combination(): void
    {
        // 100 TV releases: HD 60, SD 30, UHD 10; 1080p 70, 4K 10, 720p 20; WEB 80, Blu-ray 15, Remux 5; a movie counts for nothing
        foreach (range(0, 99) as $index) {
            $this->tv('Counted '.$index, ['categories_id' => $index < 60 ? self::HD : ($index < 90 ? self::SD : self::UHD),
                'resolution' => $index % 10 < 7 ? 2 : ($index % 10 === 7 ? 1 : 3), 'source' => $index % 20 < 16 ? 1 : ($index % 20 < 19 ? 2 : 5)]);
        }
        foreach (range(1, 200) as $index) {
            $this->release('Movie '.$index, ['categories_id' => 2040, 'resolution' => 1, 'source' => 2]);
        }
        $list = new TvReleaseList(app(ReleaseBrowseService::class), app(TvShowWall::class), 50);
        $index = static fn (array $filters, int $total = 100): string => $list->pageIndex(new TvReleaseFilters(...$filters), $total);
        $added = ['sort' => ReleaseSort::AddedNewest];
        $drama = ['shows' => new TvShowFilters(genres: [self::DRAMA])];

        $this->assertSame('ix_releases_band_posted', $index([]));
        $this->assertSame('ix_releases_band_added', $index($added));
        $this->assertSame('ix_releases_band_posted', $index(['completion' => 95, 'audio' => ['unknown']]));
        $this->assertSame('ix_releases_band_cat_posted', $index(['categories' => [self::UHD]]));
        $this->assertSame('ix_releases_band_cat_added', $index(['categories' => [self::UHD], ...$added]));
        $this->assertSame('ix_releases_band_cat_posted', $index(['categories' => [self::UHD, self::SD]]));
        $this->assertSame('ix_releases_band_posted', $index(['categories' => [self::HD]]), 'HD holds more than half of the band');
        $this->assertSame('ix_releases_band_res_posted', $index(['categories' => [self::HD], 'resolutions' => ['4k']]));
        $this->assertSame('ix_releases_band_res_added', $index(['resolutions' => ['720p'], ...$added]));
        $this->assertSame('ix_releases_band_posted', $index(['resolutions' => ['1080p']]));
        $this->assertSame('ix_releases_band_src_posted', $index(['categories' => [self::SD], 'sources' => ['bluray']]), 'Blu-ray counts its remuxes: 20 < 30');
        $this->assertSame('ix_releases_band_cat_posted', $index(['categories' => [self::UHD], 'resolutions' => ['1080p'], 'sources' => ['web'], 'completion' => 100]));
        $this->assertSame('ix_releases_videos_posted', $index($drama, 49));
        $this->assertSame('ix_releases_videos_added', $index([...$drama, ...$added], 49));
        $this->assertSame('ix_releases_band_posted', $index($drama, 50));
        $this->assertSame('ix_releases_band_added', $index([...$drama, ...$added], 50));
        $this->assertSame('ix_releases_videos_posted', $index([...$drama, 'categories' => [self::UHD], 'audio' => ['unknown']], 3));
        $this->assertSame('ix_releases_band_posted', $index([...$drama, 'categories' => [self::UHD], 'completion' => 95], 60));

        // the show filters' own count decides, not the list's: 70 Drama releases (HD and UHD), 10 of them UHD
        DB::table('genres')->insert(['id' => self::DRAMA, 'title' => 'Drama', 'type' => 5000, 'disabled' => 0]);
        DB::table('video_genres')->insert(['videos_id' => 11, 'genres_id' => self::DRAMA]);
        DB::table('releases')->where('name', 'like', 'Counted %')->where('categories_id', '!=', self::SD)->update(['videos_id' => 11]);
        $uhdDrama = new TvReleaseFilters(categories: [self::UHD], shows: new TvShowFilters(genres: [self::DRAMA]));
        $this->assertSame(10, $list->count($uhdDrama, []));
        $this->assertSame('ix_releases_band_posted', $list->readIndex($uhdDrama, []));
        $this->assertSame('ix_releases_videos_posted', (new TvReleaseList(app(ReleaseBrowseService::class), app(TvShowWall::class), 71))->readIndex($uhdDrama, []));
    }

    public function test_the_url_carries_the_new_filters_and_the_empty_line_names_them(): void
    {
        DB::table('genres')->insert([['id' => self::DRAMA, 'title' => 'Drama', 'type' => 5000, 'disabled' => 0]]);
        DB::table('networks')->insert(['id' => 1, 'name' => 'HBO']);
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English']]);
        DB::table('tv_info')->insert(['videos_id' => 11, 'summary' => '', 'publisher' => '', 'original_language' => 'ko', 'premiered' => '2004-05-06',
            'networks_id' => 1, 'content_rating_us' => 'TV-14', 'status' => 2]);
        DB::table('video_genres')->insert(['videos_id' => 11, 'genres_id' => self::DRAMA]);
        DB::table('release_audio_languages')->insert(['releases_id' => $this->tv('Shown', ['videos_id' => 11, 'resolution' => 3]), 'languages_id' => self::ENGLISH]);
        $this->tv('No show', ['resolution' => 3]);

        $query = 'category[]='.self::HD.'&resolution[]=1080p&source[]=bluray&completion=95&audio[]='.self::ENGLISH.'&audio[]=unknown&audio[]=99'
            .'&language[]=ko&genre[]='.self::DRAMA.'&decade[]=2000&network[]=1&rating[]=TV-14&status[]=ended&person=4';
        $response = $this->page('/tv?'.$query)->assertOk()->assertSee('Showing 0 releases')
            ->assertSee('Nothing matches HD · 1080p · Blu-ray · 95%+ complete · English or Unknown audio · in Korean · Drama · 2000s · HBO · TV-14 · Ended.');
        $filters = $response->viewData('filters');
        $this->assertSame(['category' => [self::HD], 'resolution' => ['1080p'], 'source' => ['bluray'], 'audio' => [(string) self::ENGLISH, 'unknown'], 'completion' => 95,
            'genre' => [self::DRAMA], 'decade' => [2000], 'language' => ['ko'], 'network' => [1], 'rating' => ['TV-14'], 'status' => ['ended'], 'page' => 2], $filters->query(2));
        $this->assertSame('Language: Korean', $this->cellText($response, 'language'));
        $this->assertListed('/tv?language[]=ko', ['Shown']);
        $this->assertListed('/tv?audio[]='.self::ENGLISH, ['Shown']);
        $this->assertListed('/tv?audio[]=unknown', ['No show']);
        $this->assertListed('/tv?completion=95&resolution[]=720p', ['No show', 'Shown']);
    }

    public function test_the_last_dropdown_filters_are_remembered_and_a_bare_open_shows_them(): void
    {
        $this->rememberedFixture();
        $user = $this->user = $this->browserUser();

        // #881: each menu pick refreshes the list with every filter on screen, and that set is remembered
        $query = '';
        foreach (['category[]='.self::HD, 'resolution[]=1080p', 'audio[]='.self::ENGLISH, 'completion=95', 'genre[]='.self::DRAMA] as $pick) {
            $query .= ($query === '' ? '' : '&').$pick;
            $this->page('/tv?_fragment=list&'.$query)->assertOk();
        }
        $expected = ['category' => [self::HD], 'resolution' => ['1080p'], 'audio' => [(string) self::ENGLISH], 'completion' => 95, 'genre' => [self::DRAMA]];
        $this->assertSame($expected, $this->remembered($user, 'tv'));

        // a bare open puts them in the address bar and shows them on page 1: cells, rows, Clear all
        $this->page('/tv')->assertRedirect(route('tv.releases', $expected));
        $response = $this->opened('/tv')->assertOk()
            ->assertSee('class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', false)
            ->assertSee('data-filters-clock="'.Carbon::now()->getTimestampMs().'"', false);
        $this->assertSame(['Match'], $this->listedNames($response));
        $this->assertSame(['Category: HD', 'Resolution: 1080p', 'Audio: English', 'Completion: 95%+', 'Genre: Drama', 'Source: any'],
            array_map(fn (string $name): string => $this->cellText($response, $name), ['category', 'resolution', 'audio', 'completion', 'genre', 'source']));
        $this->assertSame(1, $response->viewData('filters')->page);
    }

    public function test_each_list_remembers_its_own_filters(): void
    {
        $this->tv('A release');
        $user = $this->user = $this->browserUser();
        $this->remember($user, 'movies', ['resolution' => ['4k']]);

        $this->page('/tv')->assertOk()->assertSee('class="pager-line-clear is-hidden"', false);
        $this->page('/tv?resolution[]=1080p')->assertOk();
        $this->assertSame(['resolution' => ['1080p']], $this->remembered($user, 'tv'));
        $this->assertSame(['resolution' => ['4k']], $this->remembered($user, 'movies'));
    }

    public function test_a_url_that_carries_filters_becomes_the_remembered_set_exactly_without_the_page_or_person(): void
    {
        $this->tv('HD release', ['postdate' => '2026-09-24 00:00:00']);
        $this->tv('SD release', ['categories_id' => self::SD, 'resolution' => 4]);
        $user = $this->user = $this->browserUser();
        $this->page('/tv?resolution[]=1080p&source[]=web&completion=100&genre[]='.self::DRAMA)->assertOk();

        // a header sub-category link, a bookmark, a show page's back link: its filters replace the whole set
        $this->page('/tv?category[]='.self::SD)->assertOk();
        $this->assertSame(['category' => [self::SD]], $this->remembered($user, 'tv'));
        $this->page('/tv')->assertRedirect(route('tv.releases', ['category' => [self::SD]]));
        $this->assertSame(['SD release'], $this->listedNames($this->opened('/tv')));

        $this->page('/tv?category[]='.self::HD.'&page=2&person=4')->assertRedirect(route('tv.releases', ['category' => [self::HD]]));
        $this->assertSame(['category' => [self::HD]], $this->remembered($user, 'tv'));
    }

    public function test_clear_all_forgets_the_remembered_filters(): void
    {
        $this->tv('A release');
        $user = $this->user = $this->browserUser();
        $filtered = $this->page('/tv?resolution[]=1080p&completion=100')->assertOk();

        preg_match('/<a href="([^"]+)" class="pager-line-clear" data-clear-all/', (string) $filtered->getContent(), $clearAll);
        $this->page(html_entity_decode($clearAll[1]))->assertRedirect(route('tv.releases'));
        $this->assertSame([], $this->remembered($user, 'tv'));
        $bare = $this->page('/tv')->assertOk()->assertSee('class="pager-line-clear is-hidden"', false);
        $this->assertFalse($bare->viewData('filters')->any());
    }

    public function test_a_remembered_value_no_longer_in_its_menu_is_dropped_without_an_error(): void
    {
        $this->tv('A release');
        $user = $this->user = $this->browserUser();
        $this->remember($user, 'tv', ['category' => [9999], 'resolution' => ['8k', '1080p'], 'audio' => ['77'], 'genre' => [42], 'status' => [['nested']], 'completion' => 90]);
        $this->page('/tv')->assertRedirect(route('tv.releases', ['resolution' => ['1080p']]));

        $this->remember($user, 'tv', ['category' => [9999], 'network' => [42]]);
        $this->assertFalse($this->page('/tv')->assertOk()->viewData('filters')->any());
        $this->remember($user, 'tv', 'not a list');
        $this->assertFalse($this->page('/tv')->assertOk()->viewData('filters')->any());
    }

    public function test_a_menu_pick_after_a_bare_open_keeps_the_remembered_filters(): void
    {
        $this->rememberedFixture();
        $user = $this->user = $this->browserUser();
        $this->page('/tv?category[]='.self::HD.'&resolution[]=1080p')->assertOk();

        // the page's own URL carries the remembered set, so the Audio pick's refresh keeps it
        $address = (string) $this->page('/tv')->headers->get('Location');
        $fragment = $this->page($address.'&audio[]='.self::ENGLISH.'&_fragment=list')->assertOk();
        $this->assertSame(['Low completion', 'Match', 'Not drama'], $this->listedNames($fragment));
        $expected = ['category' => [self::HD], 'resolution' => ['1080p'], 'audio' => [(string) self::ENGLISH]];
        $this->assertSame($expected, $this->remembered($user, 'tv'));
        $this->page('/tv')->assertRedirect(route('tv.releases', $expected));
        $this->assertSame('Audio: English', $this->cellText($this->opened('/tv'), 'audio'));
    }

    public function test_emptying_the_only_set_menu_clears_it(): void
    {
        $this->tv('HD release');
        $this->tv('SD release', ['categories_id' => self::SD, 'resolution' => 4]);
        $user = $this->user = $this->browserUser();
        $this->page('/tv?resolution[]=1080p')->assertOk();

        // unticking its only value, or picking "Any resolution", refreshes the list with no filter parameter
        $this->assertSame(['HD release', 'SD release'], $this->listedNames($this->page('/tv?_fragment=list')->assertOk()));
        $this->assertSame([], $this->remembered($user, 'tv'));
        $this->assertFalse($this->page('/tv')->assertOk()->viewData('filters')->any());
    }

    public function test_a_page_or_sort_in_the_url_keeps_the_remembered_set_and_the_page_is_honoured(): void
    {
        foreach (range(1, 120) as $index) {
            $this->tv('Wanted '.$index);
        }
        $this->tv('Unwanted', ['resolution' => 3]);
        $user = $this->user = $this->browserUser();
        $this->page('/tv?resolution[]=1080p')->assertOk();

        $this->page('/tv?page=3')->assertRedirect(route('tv.releases', ['resolution' => ['1080p'], 'page' => 3]));
        $third = $this->opened('/tv?page=3')->assertOk()->assertSee('Showing 101–120 of 120 releases');
        $this->assertSame(3, $third->viewData('filters')->page);
        foreach (['/tv?sort=oldest', '/tv?person=4', '/tv?page=1'] as $uri) {
            $this->page($uri)->assertRedirect(route('tv.releases', ['resolution' => ['1080p']]));
            $this->assertSame(['resolution' => ['1080p']], $this->remembered($user, 'tv'), $uri);
        }
    }

    public function test_a_save_from_an_older_list_request_never_replaces_a_newer_one(): void
    {
        $this->tv('A release');
        $user = $this->user = $this->browserUser();
        $now = Carbon::now()->getTimestampMs();

        // the page abandons the older request in the browser only; the server finishes it after the newer one
        $this->page('/tv?_fragment=list&resolution[]=1080p&_filters_at='.($now - 100))->assertOk();
        $this->page('/tv?_fragment=list&resolution[]=720p&_filters_at='.($now - 200))->assertOk();
        $this->assertSame(['resolution' => ['1080p']], $this->remembered($user, 'tv'));

        // a time ahead of the server's clock counts as now, so it never shuts later changes out
        $this->page('/tv?_fragment=list&source[]=web&_filters_at='.($now + 3_600_000))->assertOk();
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->page('/tv?_fragment=list&source[]=dvd&_filters_at='.Carbon::now()->getTimestampMs())->assertOk();
        $this->assertSame(['source' => ['dvd']], $this->remembered($user, 'tv'));

        // opening the list from a URL is later than every refresh the earlier page sent
        $this->page('/tv?category[]='.self::HD)->assertOk();
        $this->page('/tv?_fragment=list&source[]=web&_filters_at='.(Carbon::now()->getTimestampMs() - 1))->assertOk();
        $this->assertSame(['category' => [self::HD]], $this->remembered($user, 'tv'));
    }

    public function test_exclude_other_lists_every_category_but_other_and_the_url_carries_the_mode(): void
    {
        DB::table('categories')->insert(['id' => self::OTHER, 'title' => 'Other', 'root_categories_id' => 5000, 'status' => 1]);
        $this->tv('In HD');
        $this->tv('In SD', ['categories_id' => self::SD]);
        $this->tv('In Other', ['categories_id' => self::OTHER]);
        $user = $this->user = $this->browserUser();

        // the item sits under "Any category", then a separator, then the sub-categories
        $this->page('/tv')->assertOk()->assertSeeInOrder(['data-any', 'Any category', 'data-exclude-other="'.self::OTHER.'"', 'Exclude Other', 'checkbox-menu-rule', 'data-value="'], false);
        $set = $this->page('/tv?_fragment=list&category=exclude-other')->assertOk();
        $this->assertSame(['In HD', 'In SD'], $this->listedNames($set));
        $this->assertSame(['category' => 'exclude-other'], $this->remembered($user, 'tv'));
        $this->assertNotSame((new TvReleaseFilters(categories: $set->viewData('filters')->categories))->countKey(), $set->viewData('filters')->countKey());

        // a remembered Exclude Other comes back as Exclude Other, the mode in the URL, not the ids
        $this->page('/tv')->assertRedirect(route('tv.releases', ['category' => 'exclude-other']));
        $opened = $this->opened('/tv')->assertOk()->assertSee('title="Category: Exclude Other"', false);
        $this->assertSame('Category: Exclude Other', $this->cellText($opened, 'category'));
        $this->assertSame(['In HD', 'In SD'], $this->listedNames($opened));

        // ticked by hand, every sub-category the menu lists but Other becomes the mode; Other as well is the explicit list
        $menu = array_keys($opened->viewData('categoryMenu'));
        $byHand = implode('&', array_map(static fn (int $id): string => 'category[]='.$id, array_diff($menu, [self::OTHER])));
        $this->assertSame(['category' => 'exclude-other'], $this->page('/tv?'.$byHand)->viewData('filters')->query());
        $every = $this->page('/tv?'.$byHand.'&category[]='.self::OTHER)->assertOk();
        $this->assertSame('Category: '.count($menu).' chosen', $this->cellText($every, 'category'));
        $this->assertSame(['In HD', 'In Other', 'In SD'], $this->listedNames($every));
        $this->page('/tv?category=exclude-other&resolution[]=4k')->assertSee('Nothing matches excluding Other · 4K.');
    }

    public function test_a_remembered_exclude_other_sleeps_while_the_user_hides_other_and_applies_again_once_other_is_visible(): void
    {
        DB::table('categories')->insert(['id' => self::OTHER, 'title' => 'Other', 'root_categories_id' => 5000, 'status' => 1]);
        $this->tv('In HD');
        $this->tv('In Other', ['categories_id' => self::OTHER]);
        $user = $this->user = $this->browserUser();
        $this->remember($user, 'tv', ['category' => 'exclude-other']);
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::OTHER]);
        Cache::flush();

        $asleep = $this->opened('/tv')->assertOk()->assertDontSee('data-exclude-other', false)->assertSee('class="pager-line-clear is-hidden"', false);
        $this->assertSame('Category: any', $this->cellText($asleep, 'category'));
        $this->assertSame(['category' => 'exclude-other'], $asleep->viewData('filters')->query());
        $this->assertSame(['category' => 'exclude-other'], $this->remembered($user, 'tv'));

        DB::table('user_excluded_categories')->where('users_id', $user->id)->delete();
        Cache::flush();
        $awake = $this->opened('/tv')->assertOk();
        $this->assertSame('Category: Exclude Other', $this->cellText($awake, 'category'));
        $this->assertSame(['In HD'], $this->listedNames($awake));
    }

    public function test_a_menu_without_other_or_with_only_other_has_no_exclude_other_item(): void
    {
        $this->tv('In HD');
        $user = $this->user = $this->browserUser();
        $response = $this->page('/tv?category=exclude-other')->assertOk()->assertDontSee('data-exclude-other', false)->assertDontSee('Exclude Other');
        $this->assertSame('Category: any', $this->cellText($response, 'category'));
        $this->assertSame(['In HD'], $this->listedNames($response));

        DB::table('categories')->insert(['id' => self::OTHER, 'title' => 'Other', 'root_categories_id' => 5000, 'status' => 1]);
        $this->tv('In Other', ['categories_id' => self::OTHER]);
        foreach ([self::HD, self::UHD, self::SD, self::FOREIGN] as $hidden) {
            DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => $hidden]);
        }
        Cache::flush();
        $onlyOther = $this->page('/tv?category=exclude-other')->assertOk()->assertDontSee('data-exclude-other', false);
        $this->assertSame([self::OTHER => 'Other'], $onlyOther->viewData('categoryMenu'));
        $this->assertSame(['In Other'], $this->listedNames($onlyOther));
        $this->assertFalse($onlyOther->viewData('filters')->any());
    }

    public function test_the_page_needs_the_tv_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view tv');
        $this->page('/tv', $user)->assertForbidden();
    }

    /** #881's rows: one release matching every remembered filter, and one missing each. */
    private function rememberedFixture(): void
    {
        DB::table('genres')->insert(['id' => self::DRAMA, 'title' => 'Drama', 'type' => 5000, 'disabled' => 0]);
        DB::table('networks')->insert(['id' => 1, 'name' => 'HBO']);
        DB::table('languages')->insert(['id' => self::ENGLISH, 'name' => 'English']);
        foreach ([11, 12] as $show) {
            DB::table('tv_info')->insert(['videos_id' => $show, 'summary' => '', 'publisher' => '', 'original_language' => 'en', 'premiered' => '2004-05-06',
                'networks_id' => 1, 'content_rating_us' => 'TV-14', 'status' => 1]);
        }
        DB::table('video_genres')->insert(['videos_id' => 11, 'genres_id' => self::DRAMA]);
        foreach (['Match' => [], 'Wrong category' => ['categories_id' => self::SD], 'Wrong resolution' => ['resolution' => 3], 'Low completion' => ['completion' => 90],
            'Not drama' => ['videos_id' => 12], 'No audio' => []] as $name => $attributes) {
            $id = $this->tv($name, ['videos_id' => 11, ...$attributes]);
            if ($name !== 'No audio') {
                DB::table('release_audio_languages')->insert(['releases_id' => $id, 'languages_id' => self::ENGLISH]);
            }
        }
    }

    /** The response after the one redirect a bare open answers with while filters are remembered (#881). */
    private function opened(string $uri): TestResponse
    {
        $response = $this->page($uri);

        return $response->isRedirect() ? $this->page((string) $response->headers->get('Location')) : $response;
    }

    private function remember(User $user, string $root, mixed $filters): void
    {
        $user->view_prefs = [...($user->view_prefs ?? []), $root => ['filters' => $filters, 'filters_at' => 1]];
        $user->save();
    }

    private function remembered(User $user, string $root): mixed
    {
        return User::query()->findOrFail($user->id)->releaseViewPreferences($root)['filters'] ?? null;
    }

    /** @return list<string> the listed releases' names, A to Z */
    private function listedNames(TestResponse $response): array
    {
        return DB::table('releases')->whereIn('id', $this->listedIds($response))->orderBy('name')->pluck('name')->all();
    }

    public function test_the_chip_promises_recovery_only_while_an_engine_can_still_take_the_release(): void
    {
        $this->tv('Above target', ['completion' => 99]);
        $this->tv('At target', ['completion' => 95]);
        $this->tv('Nothing to rescan', ['completion' => 80, 'repair_outcome' => 'failed', 'declaredfiles' => 10, 'totalpart' => 12]);
        $this->tv('Rescan owed', ['completion' => 80, 'repair_outcome' => 'failed', 'declaredfiles' => null, 'totalpart' => 12]);
        $this->tv('No verdict', ['completion' => 80]);
        $this->tv('No NZB', ['completion' => 80, 'nzbstatus' => 0]);

        $response = $this->page('/tv')->assertOk();
        foreach (['Above target' => '99% complete', 'At target' => '95% complete', 'Nothing to rescan' => '80% complete', 'No NZB' => '80% complete'] as $name => $chip) {
            $row = $this->rowOf($response, $name);
            $this->assertMatchesRegularExpression('/>\s*'.preg_quote($chip, '/').'\s*</', $row, $name);
            $this->assertStringNotContainsString('still repairing', $row, $name);
            $this->assertStringContainsString('The site will not try to recover more of it."', $row, $name);
        }
        foreach (['Rescan owed', 'No verdict'] as $name) {
            $row = $this->rowOf($response, $name);
            $this->assertStringContainsString('80% complete · still repairing', $row, $name);
            $this->assertStringContainsString('The site may still recover more of it."', $row, $name);
        }
        $this->assertStringNotContainsString('as complete as it will get', (string) $response->getContent());
    }

    public function test_the_repair_target_comes_from_the_completionpercent_setting(): void
    {
        Settings::query()->updateOrInsert(['name' => 'completionpercent'], ['value' => '99']);
        $this->tv('Below the raised target', ['completion' => 97]);

        $this->assertStringContainsString('97% complete · still repairing', $this->rowOf($this->page('/tv')->assertOk(), 'Below the raised target'));
    }

    public function test_a_secondary_provider_still_reading_the_post_holds_the_label_until_its_position_passes_it(): void
    {
        $this->configureSecondaryProvider();
        ProductionTables::fromAuthority()->create('usenet_group_provider_cursors');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.tv', 'active' => 1]);
        $this->tv('Above target', ['completion' => 99, 'groups_id' => 1, 'postdate' => '2026-09-25 08:00:00']);
        $this->tv('Nothing to rescan', ['completion' => 80, 'repair_outcome' => 'failed', 'declaredfiles' => 10, 'totalpart' => 12, 'groups_id' => 1, 'postdate' => '2026-09-25 08:00:00']);
        // delaytime is unset, so the window closes two hours after the post.
        $this->secondaryPosition(1, '2026-09-25 09:00:00');

        $response = $this->page('/tv')->assertOk();
        $this->assertStringContainsString('99% complete · still repairing', $this->rowOf($response, 'Above target'));
        $this->assertStringContainsString('80% complete · still repairing', $this->rowOf($response, 'Nothing to rescan'));

        $this->secondaryPosition(1, '2026-09-25 11:00:00');
        $response = $this->page('/tv')->assertOk();
        $this->assertMatchesRegularExpression('/>\s*99% complete\s*</', $this->rowOf($response, 'Above target'));
        $this->assertMatchesRegularExpression('/>\s*80% complete\s*</', $this->rowOf($response, 'Nothing to rescan'));
    }

    public function test_the_page_reads_the_target_the_delay_and_the_secondary_positions_once_however_many_rows(): void
    {
        $this->configureSecondaryProvider();
        ProductionTables::fromAuthority()->create('usenet_group_provider_cursors');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.tv', 'active' => 1]);
        $this->secondaryPosition(1, '2026-09-25 09:00:00');
        foreach (range(1, 12) as $index) {
            $this->tv('Incomplete '.$index, ['completion' => 90 + $index % 9, 'groups_id' => 1, 'postdate' => '2026-09-25 08:00:00']);
        }

        $reads = $this->recordRepairReads();
        $this->page('/tv')->assertOk();
        $this->assertSame(['cursors' => 1, 'completionpercent' => 1, 'delaytime' => 1], $reads());

        $this->configureProviders([['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid']]);
        $reads = $this->recordRepairReads();
        $this->page('/tv')->assertOk();
        $this->assertSame(['cursors' => 0, 'completionpercent' => 1, 'delaytime' => 0], $reads());
    }

    /** @param array<string, mixed> $attributes */
    private function tv(string $name, array $attributes = []): int
    {
        return $this->release($name, ['categories_id' => self::HD, 'passwordstatus' => 0, 'resolution' => 2, 'source' => 1,
            'videos_id' => 0, 'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, ...$attributes]);
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();
        // Each render is a request of its own: nothing a request resolved survives into the next.
        $this->app->forgetScopedInstances();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    /** @param list<string> $names */
    private function assertListed(string $uri, array $names, ?User $user = null): void
    {
        $response = $this->page($uri, $user)->assertOk();
        $listed = DB::table('releases')->whereIn('id', $this->listedIds($response))->orderBy('name')->pluck('name')->all();
        $this->assertSame($names, $listed, $uri);
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
            if (str_contains($row, 'title="'.e($name)) || str_contains($row, '>'.e($name).'<')) {
                return strstr($row, '</tr>', true) ?: $row;
            }
        }
        $this->fail('No row for '.$name);
    }

    private function posterCell(string $row): string
    {
        $cell = strstr((string) strstr($row, '<td class="tv-art">'), '<td class="tv-what">', true);
        $this->assertIsString($cell);

        return $cell;
    }

    private function openingTag(TestResponse $response, string $name): string
    {
        return strstr($this->rowOf($response, $name), '>', true);
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

    /**
     * 300 TV releases over three shows and none, with an excluded category (Foreign), passworded
     * releases, three completions and five audio mixes (none twice); posted dates with ties, added dates in
     * another order.
     *
     * @return array<int, array{visible: bool, category: int, show: int, completion: int, audio: list<int>, posted: string, added: string}>
     */
    private function filterFixture(): array
    {
        DB::table('genres')->insert([['id' => self::DRAMA, 'title' => 'Drama', 'type' => 5000, 'disabled' => 0], ['id' => self::COMEDY, 'title' => 'Comedy', 'type' => 5000, 'disabled' => 0],
            ['id' => self::SCI_FI, 'title' => 'Sci-Fi', 'type' => 5000, 'disabled' => 0]]);
        DB::table('networks')->insert([['id' => 1, 'name' => 'HBO'], ['id' => 2, 'name' => 'abc'], ['id' => 3, 'name' => 'Channel 4']]);
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::KOREAN, 'name' => 'Korean'], ['id' => 3, 'name' => 'Japanese']]);
        DB::table('videos')->insert(['id' => 13, 'type' => 0, 'title' => 'Third Show', 'started' => '2020-01-01 00:00:00']);
        foreach ([11 => ['ko', '2004-05-06', 1, 'TV-14', 2, [self::DRAMA]], 12 => ['en', '2019-01-01', 2, 'TV-MA', 1, [self::COMEDY]],
            13 => ['en', '1995-01-01', 3, 'TV-14', 1, [self::DRAMA, self::SCI_FI]]] as $show => [$language, $premiered, $network, $rating, $status, $genres]) {
            DB::table('tv_info')->insert(['videos_id' => $show, 'summary' => '', 'publisher' => '', 'original_language' => $language, 'premiered' => $premiered,
                'networks_id' => $network, 'content_rating_us' => $rating, 'status' => $status]);
            foreach ($genres as $genre) {
                DB::table('video_genres')->insert(['videos_id' => $show, 'genres_id' => $genre]);
            }
        }
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '0']);
        $mixes = [[], [self::ENGLISH], [self::KOREAN], [self::ENGLISH, self::KOREAN], [], [3]];
        $facts = [];
        foreach (range(1, 300) as $index) {
            $fact = ['category' => $index % 9 === 0 ? self::FOREIGN : ($index % 2 === 0 ? self::HD : self::SD), 'show' => [11, 12, 13, 0][$index % 4],
                'completion' => [100, 97, 90][$index % 3], 'audio' => $mixes[$index % 6],
                'posted' => Carbon::parse('2026-01-01 00:00:00')->addHours($index % 7 === 0 ? $index - 1 : $index)->toDateTimeString(),
                'added' => Carbon::parse('2026-03-01 00:00:00')->addHours(($index * 37) % 300)->toDateTimeString()];
            $password = $index % 11 === 0 ? 1 : 0;
            $id = $this->tv('Filtered '.$index, ['categories_id' => $fact['category'], 'videos_id' => $fact['show'], 'completion' => $fact['completion'],
                'passwordstatus' => $password, 'postdate' => $fact['posted'], 'adddate' => $fact['added']]);
            foreach ($fact['audio'] as $language) {
                DB::table('release_audio_languages')->insert(['releases_id' => $id, 'languages_id' => $language]);
            }
            $facts[$id] = ['visible' => $password === 0 && $fact['category'] !== self::FOREIGN, ...$fact];
        }
        Cache::flush();

        return $facts;
    }

    /**
     * The visible releases the predicate keeps, in the filters' sort.
     *
     * @param  array<int, array{visible: bool, category: int, show: int, completion: int, audio: list<int>, posted: string, added: string}>  $facts
     * @return list<int>
     */
    private function expectedOrder(array $facts, callable $keep, TvReleaseFilters $filters): array
    {
        $kept = array_filter($facts, static fn (array $fact): bool => $fact['visible'] && $keep($fact));
        $date = $filters->sortsByAdded() ? 'added' : 'posted';
        uksort($kept, static fn (int $a, int $b): int => [$kept[$a][$date], $a] <=> [$kept[$b][$date], $b]);
        $ids = array_keys($kept);

        return $filters->ascending() ? $ids : array_reverse($ids);
    }

    /** A filter cell's text as check.mjs reads it (textContent): "Genre: 2 chosen". */
    private function cellText(TestResponse $response, string $name): string
    {
        $this->assertMatchesRegularExpression('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent());
        preg_match('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent(), $match);

        return html_entity_decode(strip_tags($match[1]), ENT_QUOTES);
    }
}
