<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The TV releases screen, GET /tv (issue #777; check.mjs lines 27-117 and 134-150). */
final class TvReleasesPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const HD = 5040;

    private const UHD = 5045;

    private const SD = 5030;

    private const FOREIGN = 5020;

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
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'repair_outcome', 'rescan_outcome', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'tv_episodes', 'release_tv_episodes', 'release_audio_tags', 'release_video_clips'] as $table) {
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
        $this->page('/tv?resolution[]=4k&resolution[]=1080p')->assertSee('Resolution: 4K, 1080p')->assertSee('checkbox-menu is-set', false)
            ->assertSee('Source: any')->assertSee('Category: any');
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
        $this->postJson('/profile/update-view', ['root' => 'movies', 'sort' => 'posted'])->assertUnprocessable();
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

    public function test_old_tv_browse_urls_and_header_links_lead_to_the_new_screen(): void
    {
        $this->page('/browse/tv')->assertRedirect(route('tv.releases'));
        $this->page('/browse/TV/'.self::HD)->assertRedirect(route('tv.releases', ['category' => [self::HD]]));
        $this->page('/browse/tv/HD')->assertRedirect(route('tv.releases', ['category' => [self::HD]]));
        $this->page('/tv')->assertSee('href="'.route('tv.releases').'" data-browse-root', false)
            ->assertSee('href="'.route('tv.releases', ['category' => [self::HD]]).'"', false);
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

    public function test_the_page_needs_the_tv_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view tv');
        $this->page('/tv', $user)->assertForbidden();
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

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    /** @param list<string> $names */
    private function assertListed(string $uri, array $names): void
    {
        $response = $this->page($uri)->assertOk();
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
            if (str_contains($row, 'title="'.$name) || str_contains($row, '>'.$name.'<')) {
                return strstr($row, '</tr>', true) ?: $row;
            }
        }
        $this->fail('No row for '.$name);
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
}
