<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BrowseRoot;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Services\Search\SearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class ReleaseBrowserControllerTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        foreach ([1000 => 'Console', 2000 => 'Movies', 3000 => 'Audio', 4000 => 'PC', 5000 => 'TV', 6000 => 'XXX', 7000 => 'Books', 1 => 'Other'] as $id => $title) {
            DB::table('root_categories')->updateOrInsert(['id' => $id], ['title' => $title]);
            DB::table('categories')->insert(['id' => $id + 30, 'title' => 'HD', 'root_categories_id' => $id]);
        }
        foreach (['2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_browse_renders_video_summary_with_release_id_as_the_video_primary_key(): void
    {
        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            foreach (['containerformat', 'overallbitrate', 'videoduration', 'videoformat', 'videocodec', 'videoaspect', 'videolibrary'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['videowidth', 'videoheight', 'videoframerate'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
        });
        $releaseId = $this->release('Video summary regression');
        DB::table('video_data')->insert([
            'releases_id' => $releaseId, 'videoheight' => 1080, 'videocodec' => 'x264', 'videoformat' => 'AVC',
        ]);

        $this->actingAs($this->browserUser())->get('/browse/all?view=table')
            ->assertOk()
            ->assertSee('Video summary regression')
            ->assertSee('1080p · x264');
    }

    public function test_year_picker_offers_complete_choices_even_without_matching_metadata(): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        $response = $this->actingAs($this->browserUser())->get('/browse/movies?year=custom&year_from=1970&year_to=1975')->assertOk();
        $response->assertSee('All Years')->assertSee('Decades')->assertSee('Custom Range')->assertSee('Individual Years')
            ->assertSee('value="1900"', false)->assertSee('value="'.(date('Y') + 1).'"', false)
            ->assertSee('value="1975"', false)->assertSee('name="year_from"', false)->assertSee('name="year_to"', false)
            ->assertSee('Apply year');
        $this->assertSame(0, $response->viewData('results')->total());
    }

    #[DataProvider('yearContexts')]
    public function test_every_year_capable_category_uses_its_own_year_in_every_supported_view(string $root, string $table, string $foreignKey, int $category): void
    {
        if ($table !== '') {
            $this->createCoverCatalogSchema($table);
        }
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.year.test'], ['id' => 2, 'name' => 'alt.year.other']]);
        $years = [1969, 1970, 1975, 1979, 1980];
        foreach ($years as $year) {
            if ($table !== '') {
                $this->insertTitles($table, ['id' => $year, 'imdbid' => (string) $year, 'title' => 'Year fixture '.$year,
                    'year' => (string) $year, 'started' => $year.'-06-15', 'releasedate' => $year.'-06-15', 'publishdate' => $year.'-06-15']);
            }
            if ($root === 'tv') {
                DB::table('tv_episodes')->insert(['id' => $year, 'videos_id' => $year, 'series' => 1, 'episode' => 1, 'title' => 'Episode '.$year, 'firstaired' => '2005-01-01']);
            }
            $this->release('Year fixture '.$year, ['categories_id' => $category, 'isrenamed' => 1, 'nfostatus' => 1,
                'postdate' => ($root === 'xxx' ? $year : 2001).'-06-15', 'adddate' => '2020-01-01', 'groups_id' => 1, 'fromname' => 'Year poster',
                ...($foreignKey !== '' ? [$foreignKey => $year] : []), 'tv_episodes_id' => $root === 'tv' ? $year : 0]);
        }
        $user = $this->browserUser();
        $this->actingAs($user);
        $cases = [
            'year=1970s' => [1970, 1975, 1979], 'year=1975' => [1975],
            'year=custom&year_from=1970&year_to=1975' => [1970, 1975],
            'year=custom&year_from=1975' => [1975, 1979, 1980],
            'year=custom&year_to=1975' => [1969, 1970, 1975],
            'year=custom&year_from=1980&year_to=1970' => [1970, 1975, 1979, 1980],
            'year=custom&year_from=&year_to=' => $years,
            'year=broken' => $years, 'year[]=1970' => $years,
            'year=custom&year_from[]=1970&year_to=1975' => [1969, 1970, 1975],
            'year=1900' => [], 'year='.(date('Y') + 1) => [],
        ];
        $views = BrowseRoot::fromRoute($root)->views();
        foreach (['/browse/'.$root, '/browse/'.$root.'/'.$category] as $path) {
            foreach ($views as $view) {
                foreach ($cases as $query => $expected) {
                    $response = $this->get($path.'?view='.$view.'&'.$query)->assertOk();
                    $this->assertSame(count($expected), $response->viewData('results')->total(), $path.' '.$view.' '.$query);
                    foreach ($years as $year) {
                        if (in_array($year, $expected, true)) {
                            $response->assertSee('Year fixture '.$year);
                        } else {
                            $response->assertDontSee('Year fixture '.$year);
                        }
                    }
                    $response->assertSee('All Years')->assertSee('Decades')->assertSee('Individual Years')->assertSee('Custom Range');
                }
            }
        }
        $this->release('Outside identity', ['categories_id' => $category, 'postdate' => '1975-06-15',
            'groups_id' => 2, 'fromname' => 'Another poster', ...($foreignKey !== '' ? [$foreignKey => 1975] : [])]);
        foreach (['group=alt.year.test', 'poster=Year%20poster'] as $restriction) {
            $page = $this->get('/browse/'.$root.'?view=covers&year=custom&year_from=1970&year_to=1975&'.$restriction)->assertOk();
            $page->assertSee('Custom Range')->assertSee('Apply year')->assertDontSee('Outside identity');
            $this->assertSame('table', $page->viewData('browserState')->view);
            $this->assertSame(2, $page->viewData('results')->total());
        }
        if (in_array($root, ['movies', 'tv'], true)) {
            DB::table($root === 'movies' ? 'user_movies' : 'user_series')->insert(['users_id' => $user->id, $foreignKey => 1970]);
            foreach ($views as $view) {
                $page = $this->get('/browse/'.$root.'?view='.$view.'&year=1970s&watching=1')->assertOk();
                $this->assertSame(1, $page->viewData('results')->total());
                $page->assertSee('Year fixture 1970')->assertDontSee('Year fixture 1975');
            }
        }
    }

    public function test_custom_year_range_survives_pagination_sort_views_and_legacy_movie_entry(): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        for ($id = 1; $id <= 26; $id++) {
            DB::table('movieinfo')->insert(['id' => $id, 'imdbid' => (string) $id, 'title' => 'Paged year '.$id, 'year' => $id <= 25 ? '1975' : '1980', 'genre' => 'Drama']);
            $this->release('Paged year '.$id, ['imdbid' => (string) $id, 'isrenamed' => 1, 'nfostatus' => 1]);
        }
        $this->actingAs($this->browserUser());
        $query = 'year=custom&year_from=1970&year_to=1975&genre=Drama&per=24&page=2';
        $legacy = $this->get('/Movies?'.$query)->assertRedirect();
        $this->get($legacy->headers->get('Location'))->assertOk()->assertSee('Apply year');
        foreach (['table', 'cards', 'covers'] as $view) {
            $response = $this->get('/browse/movies?'.$query.'&view='.$view.'&size=l&sort=posted')->assertOk();
            $this->assertSame(25, $response->viewData('results')->total());
            $this->assertCount(1, $response->viewData('results')->items());
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame('1970', $xpath->query('//*[@name="year_from"]/@value')->item(0)->nodeValue);
            $this->assertSame('1975', $xpath->query('//*[@name="year_to"]/@value')->item(0)->nodeValue);
            $clear = $xpath->query('//*[@data-year-clear]/@href')->item(0)->nodeValue;
            parse_str(parse_url($clear, PHP_URL_QUERY), $parameters);
            $this->assertSame(['genre' => 'Drama', 'per' => '24', 'view' => $view, 'size' => 'l', 'sort' => 'posted'], $parameters);
            $this->get($clear)->assertOk()->assertViewHas('results', static fn ($rows): bool => $rows->total() === 26);
            $this->assertStringContainsString('year_from=1970', $response->viewData('results')->url(1));
            $this->assertStringContainsString('year_to=1975', $response->viewData('results')->url(1));
        }
    }

    public static function yearContexts(): iterable
    {
        yield 'movies' => ['movies', 'movieinfo', 'imdbid', 2030];
        yield 'tv' => ['tv', 'videos', 'videos_id', 5030];
        yield 'audio' => ['audio', 'musicinfo', 'musicinfo_id', 3030];
        yield 'console' => ['console', 'consoleinfo', 'consoleinfo_id', 1030];
        yield 'games' => ['games', 'gamesinfo', 'gamesinfo_id', 4030];
        yield 'books' => ['books', 'bookinfo', 'bookinfo_id', 7030];
        yield 'adult' => ['xxx', '', '', 6030];
    }

    public function test_long_cover_metadata_preserves_complete_values_and_actions(): void
    {
        $this->createCoverCatalogSchema('musicinfo');
        $publisher = str_repeat('Harbor Records copyright and publishing rights worldwide. ', 12).str_repeat('X', 180);
        foreach ([1, 2] as $id) {
            DB::table('musicinfo')->insert(['id' => $id, 'title' => 'Album '.$id, 'publisher' => $publisher, 'artist' => 'A fictional artist']);
            $this->release('Album.'.$id.'.'.str_repeat('Long.Release.Name.', 15), ['categories_id' => 3030, 'musicinfo_id' => $id, 'nfostatus' => 1]);
        }
        $this->actingAs($this->browserUser());
        foreach (['s', 'l', 'xl'] as $size) {
            $response = $this->get('/browse/audio?view=covers&size='.$size)->assertOk();
            if ($size === 'xl') {
                $response->assertSee($publisher)->assertSee('data-row-action="download"', false)->assertSee('data-row-action="basket"', false);
            }
        }
    }

    public function test_tv_covers_group_identified_episodes_and_explicit_packs_and_keep_internal_posted_order(): void
    {
        $this->createCoverCatalogSchema('videos');
        DB::table('videos')->insert(['id' => 1, 'title' => 'Harbor Street', 'started' => '2024-01-01']);
        DB::table('tv_info')->insert(['videos_id' => 1, 'publisher' => 'Harbor Network']);
        foreach ([1, 2, 3] as $number) {
            DB::table('tv_episodes')->insert(['id' => $number, 'videos_id' => 1, 'series' => 2, 'episode' => $number, 'title' => 'Episode '.$number]);
        }
        $this->release('Harbor.Street.S02E01.New', ['videos_id' => 1, 'tv_episodes_id' => 1, 'categories_id' => 5030, 'postdate' => '2026-09-13', 'adddate' => '2026-09-10']);
        $this->release('Harbor.Street.S02E01.Old', ['videos_id' => 1, 'tv_episodes_id' => 1, 'categories_id' => 5030, 'postdate' => '2026-09-09', 'grabs' => 999]);
        $this->release('Harbor.Street.S02E01-E02', ['videos_id' => 1, 'tv_episodes_id' => 1, 'categories_id' => 5030, 'postdate' => '2026-09-11']);
        $this->release('Harbor.Street.S02.COMPLETE', ['videos_id' => 1, 'tv_episodes_id' => 0, 'categories_id' => 5030, 'postdate' => '2026-09-12']);
        $this->release('Unidentified.S02E01', ['categories_id' => 5030]);
        $this->actingAs($this->browserUser());
        foreach (['s', 'l', 'xl'] as $size) {
            $this->get('/browse/tv?view=covers&size='.$size)->assertOk()->assertSee('Harbor Network')->assertSee('Harbor.Street.S02E01.New')->assertSee('Harbor.Street.S02.COMPLETE');
        }
        foreach (['posted', 'posted_oldest', 'newest', 'oldest', 'title', 'grabs'] as $sort) {
            $response = $this->get('/browse/tv?view=covers&size=xl&sort='.$sort.'&letter=Z')->assertOk();
            $response->assertDontSee('Jump by initial')->assertDontSee('Unidentified');
            $page = $response->viewData('results');
            $this->assertSame(3, $page->total());
            $covers = $page->getCollection()->keyBy('id');
            $this->assertSame(4, $covers['1']->releaseCount);
            $this->assertSame(2, $covers['2']->releaseCount);
            $this->assertSame(1, $covers['3']->releaseCount);
            $this->assertSame('Harbor.Street.S02E01.New', $covers['1']->releases[0]->row_data->name, $sort);
            $this->assertSame('Harbor.Street.S02.COMPLETE', $covers['1']->releases[1]->row_data->name, $sort);
        }
    }

    public function test_tv_outer_sort_uses_release_values_instead_of_show_or_episode_names(): void
    {
        $this->createCoverCatalogSchema('videos');
        DB::table('videos')->insert(['id' => 1, 'title' => 'Harbor']);
        foreach ([1 => ['Zulu', '2026-09-13', '2026-09-10', 2], 2 => ['Alpha', '2026-09-11', '2026-09-12', 8], 3 => ['Middle', '2026-09-12', '2026-09-11', 1]] as $id => [$name, $posted, $added, $grabs]) {
            DB::table('tv_episodes')->insert(['id' => $id, 'videos_id' => 1, 'series' => 1, 'episode' => $id, 'title' => 'Episode '.$id]);
            $this->release($name, ['categories_id' => 5030, 'videos_id' => 1, 'tv_episodes_id' => $id, 'postdate' => $posted, 'adddate' => $added, 'grabs' => $grabs]);
        }
        $this->actingAs($this->browserUser());
        foreach (['posted' => '1', 'posted_oldest' => '2', 'newest' => '2', 'oldest' => '1', 'title' => '2', 'grabs' => '2'] as $sort => $first) {
            $page = $this->get('/browse/tv?view=covers&sort='.$sort)->assertOk()->viewData('results');
            $this->assertSame($first, $page->first()->id, $sort);
        }
    }

    public function test_cached_episode_groups_recheck_current_release_visibility(): void
    {
        $this->createCoverCatalogSchema('videos');
        DB::table('videos')->insert(['id' => 1, 'title' => 'Harbor']);
        DB::table('tv_episodes')->insert(['id' => 1, 'videos_id' => 1, 'series' => 1, 'episode' => 1, 'title' => 'First']);
        $id = $this->release('Harbor.S01E01', ['categories_id' => 5030, 'videos_id' => 1, 'tv_episodes_id' => 1]);
        $this->actingAs($this->browserUser());
        $this->get('/browse/tv?view=covers')->assertOk()->assertSee('Harbor.S01E01');
        DB::table('releases')->where('id', $id)->update(['passwordstatus' => 2]);
        $this->get('/browse/tv?view=covers')->assertOk()->assertDontSee('Harbor.S01E01');
    }

    public function test_release_facts_are_a_separate_block_after_the_complete_name(): void
    {
        $this->release('A short name', ['nfostatus' => 1]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-release-title]/parent::*/following-sibling::*[@data-release-facts-row]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-release-title]/parent::*//*[contains(@class,"release-chip")]')->length);
    }

    public function test_six_release_sorts_have_distinct_meanings(): void
    {
        $this->release('Zulu', ['postdate' => '2026-09-13', 'adddate' => '2026-09-10', 'grabs' => 2]);
        $this->release('Alpha', ['postdate' => '2026-09-11', 'adddate' => '2026-09-12', 'grabs' => 8]);
        $this->release('Middle', ['postdate' => '2026-09-12', 'adddate' => '2026-09-11', 'grabs' => 1]);
        $this->actingAs($this->browserUser());
        foreach (['posted' => 'Zulu', 'posted_oldest' => 'Alpha', 'newest' => 'Alpha', 'oldest' => 'Zulu', 'title' => 'Alpha', 'grabs' => 'Alpha'] as $sort => $first) {
            $response = $this->get('/browse/all?sort='.$sort)->assertOk();
            $this->assertSame($first, $response->viewData('results')->items()[0]->row_data->name, $sort);
            $this->assertSame(['posted', 'posted_oldest', 'newest', 'oldest', 'title', 'grabs'], array_keys($response->viewData('sortOptions')));
        }
        foreach (['year', 'rating', 'artist', 'size', 'files'] as $removed) {
            $this->get('/browse/all?sort='.$removed)->assertOk()
                ->assertViewHas('browserState', static fn ($state): bool => $state->sort === 'newest');
        }
    }

    public function test_group_filter_counts_all_matching_rows_and_bounds_the_page(): void
    {
        $user = $this->browserUser();
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.movies'], ['id' => 2, 'name' => 'alt.binaries.movies.other']]);
        for ($index = 1; $index <= 49; $index++) {
            $this->release('Movie '.$index, ['groups_id' => 1]);
        }
        $this->release('Outside group', ['groups_id' => 2]);

        $response = $this->actingAs($user)->get('/browse/all?group=alt.binaries.movies&per=24&page=2')->assertOk();
        $page = $response->viewData('results');
        $this->assertSame(49, $page->total());
        $this->assertCount(24, $page->items());
        $this->assertSame(2, $page->currentPage());
        $response->assertSee('Releases in alt.binaries.movies')->assertDontSee('Outside group');
        $this->get('/browse/all?group=alt.binaries.movies&per=24&page=999')
            ->assertRedirect('/browse/all?group=alt.binaries.movies&per=24&page=3');
    }

    public function test_poster_identity_filter_is_byte_exact_including_spaces_and_case(): void
    {
        $identity = ' Sender <person+tag@Host.test> ';
        $this->release('Exact identity', ['fromname' => $identity]);
        $this->release('Trimmed identity', ['fromname' => trim($identity)]);
        $this->release('Case variant', ['fromname' => strtolower($identity)]);
        $this->release('Extended identity', ['fromname' => $identity.'suffix']);

        $response = $this->actingAs($this->browserUser())->get('/browse/all?poster='.rawurlencode($identity))->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Posts by '.$identity)->assertSee('Exact identity')
            ->assertDontSee('Trimmed identity')->assertDontSee('Case variant')->assertDontSee('Extended identity');
    }

    public function test_canonical_roots_and_numeric_subcategories_never_fall_back_to_all_releases(): void
    {
        $roots = ['console' => 1030, 'movies' => 2030, 'audio' => 3030, 'games' => 4030, 'tv' => 5030, 'xxx' => 6030, 'books' => 7030, 'other' => 31];
        foreach ($roots as $root => $categoryId) {
            $this->release($root.' release', ['categories_id' => $categoryId]);
        }
        $this->actingAs($this->browserUser());
        foreach ($roots as $root => $categoryId) {
            foreach (['/browse/'.$root, '/browse/'.$root.'/'.$categoryId] as $url) {
                $response = $this->get($url)->assertOk();
                $this->assertSame(1, $response->viewData('results')->total(), $url);
                $response->assertSee($root.' release');
            }
        }
        $this->get('/browse/movies/3030')->assertNotFound();
        $this->get('/browse/movies/9999')->assertNotFound();
        $this->get('/browse/not-a-root')->assertNotFound();
    }

    public function test_search_and_sort_apply_before_pagination_and_explicit_preferences_override_saved_values(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'all', 'per' => 24, 'thumbs' => true])->assertOk();
        for ($index = 30; $index >= 1; $index--) {
            $this->release(sprintf('Wanted %02d', $index));
        }
        $this->release('Unwanted match', ['display_name' => 'Outside']);
        $this->release('Encoded name', ['display_name' => 'Wanted 00']);

        $response = $this->get('/browse/all?q=Wanted&sort=title')->assertOk();
        $page = $response->viewData('results');
        $this->assertSame(31, $page->total());
        $this->assertCount(24, $page->items());
        $this->assertSame('Wanted 00', $page->items()[0]->row_data->name);
        $this->assertSame('Wanted 23', $page->items()[23]->row_data->name);
        $this->assertTrue($response->viewData('browserState')->thumbs);
        $override = $this->get('/browse/all?q=Wanted&sort=title&per=48&thumbs=0')->assertOk();
        $this->assertCount(31, $override->viewData('results')->items());
        $this->assertFalse($override->viewData('browserState')->thumbs);
    }

    public function test_table_renders_one_escaped_row_with_separate_dates_and_four_native_actions(): void
    {
        $name = '<One & "release">';
        $identity = ' Sender <person+tag@Host.test> ';
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.movies']);
        $id = $this->release('Internal name', ['display_name' => $name, 'fromname' => $identity, 'groups_id' => 1, 'totalpart' => 12]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $table = '//table[@data-release-table]';
        $this->assertSame(1, $xpath->query($table.'/tbody/tr')->length);
        $headers = array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query($table.'/thead/tr/th')));
        $this->assertSame(['Select all', 'Release', 'Category', 'Size', 'Files', 'Added', 'Posted', 'Stats', 'Actions'], $headers);
        $this->assertSame($name, $xpath->query($table.'//a[@data-release-title]')->item(0)->textContent);
        $this->assertSame(4, $xpath->query($table.'//*[@data-row-action]')->length);
        $this->assertSame(1, $xpath->query($table.'//button[@data-report-release-id="'.$id.'"]')->length);
        $this->assertSame($name, $xpath->evaluate('string('.$table.'//button[@data-report-release-id="'.$id.'"]/@data-release-display-name)'));
        $response->assertSee('Sep 12, 2026 23:30')
            ->assertSee(url('/browse/all').'?'.http_build_query(['group' => 'alt.binaries.movies']))
            ->assertSee(url('/browse/all').'?'.http_build_query(['poster' => $identity], '', '&', PHP_QUERY_RFC3986))
            ->assertDontSee('<One & "release">', false);
    }

    public function test_toolbar_and_both_pagers_remain_available_on_empty_and_last_pages(): void
    {
        $this->actingAs($this->browserUser());
        $response = $this->get('/browse/all?q=missing&per=24')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//nav[@aria-label="Release pages"]')->length);
        $this->assertSame(4, $xpath->query('//nav[@aria-label="Release pages"]//button[@disabled]')->length);
        $this->assertSame(6, $xpath->query('//nav[@aria-label="Release pages"]//button[@data-preference="per"]')->length);
        $response->assertSee('Page 1 of 1')->assertSee('0 releases')
            ->assertSee('Search in All releases')->assertSee('No releases match.')
            ->assertSee('Clear filters')->assertDontSee('data-preference="view" data-value="cards"', false);
    }

    public function test_basket_add_and_remove_return_current_user_counts_without_changing_another_users_basket(): void
    {
        $first = $this->browserUser();
        $second = $this->browserUser();
        $this->release('One');
        $this->release('Two');
        $this->actingAs($first)->postJson('/cart/add', ['id' => md5('One').','.md5('Two')])
            ->assertOk()->assertJsonPath('cartCount', 2);
        $this->flushSession();
        $this->actingAs($second)->postJson('/cart/add', ['id' => md5('One')])
            ->assertOk()->assertJsonPath('cartCount', 1);
        $this->flushSession();
        $this->actingAs($first)->postJson('/cart/delete/'.md5('One'))
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('cartCount', 1);
        $firstPage = $this->get('/browse/all?sort=title')->assertOk();
        $this->assertFalse($firstPage->viewData('results')->items()[0]->row_data->in_basket);
        $this->flushSession();
        $secondPage = $this->actingAs($second)->get('/browse/all?sort=title')->assertOk();
        $this->assertTrue($secondPage->viewData('results')->items()[0]->row_data->in_basket);
    }

    public function test_movie_filters_use_linked_metadata_before_counting_and_offer_only_available_values(): void
    {
        ProductionTables::fromAuthority()->create('movieinfo', ['id', 'imdbid', 'title', 'year', 'genre', 'rating', 'cover']);
        DB::table('movieinfo')->insert([
            ['imdbid' => '1234567', 'title' => 'Recent drama', 'year' => '2026', 'genre' => 'Drama, Mystery', 'rating' => '8.2'],
            ['imdbid' => '1234568', 'title' => 'Old drama', 'year' => '2020', 'genre' => 'Drama', 'rating' => '9.0'],
            ['imdbid' => '1234569', 'title' => 'Another genre', 'year' => '2026', 'genre' => 'Melodrama', 'rating' => '7.0'],
        ]);
        $this->release('Matched', ['imdbid' => '1234567']);
        $this->release('Older', ['imdbid' => '1234568']);
        $this->release('Different genre', ['imdbid' => '1234569']);
        $this->release('Unmatched');
        $response = $this->actingAs($this->browserUser())->get('/browse/movies?year=2026&genre=Drama')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Matched')->assertDontSee('Older')->assertDontSee('Different genre')->assertDontSee('Unmatched');
        $response->assertSee('value="2020"', false)->assertSee('value="Mystery"', false)
            ->assertDontSee('aria-label="Network"', false);
        $sorted = $this->get('/browse/movies?sort=rating')->assertOk();
        $this->assertSame('newest', $sorted->viewData('browserState')->sort);
        $this->assertSame('Unmatched', $sorted->viewData('results')->items()[0]->row_data->name);
        $this->assertSame(5, substr_count($response->getContent(), 'data-row-action='));
    }

    public function test_tv_filters_use_year_and_network_and_watching_is_scoped_to_the_current_user(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->date('started');
        });
        Schema::create('tv_info', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id')->primary();
            $table->string('publisher');
        });
        DB::table('videos')->insert([
            ['id' => 1, 'title' => 'Followed show', 'started' => '2026-01-01'],
            ['id' => 2, 'title' => 'Another show', 'started' => '2026-01-01'],
            ['id' => 3, 'title' => 'Older show', 'started' => '2020-01-01'],
        ]);
        DB::table('tv_info')->insert([
            ['videos_id' => 1, 'publisher' => 'Network A'], ['videos_id' => 2, 'publisher' => 'Network B'], ['videos_id' => 3, 'publisher' => 'Network A'],
        ]);
        foreach ([1, 2, 3] as $id) {
            $this->release('Episode '.$id, ['categories_id' => 5030, 'videos_id' => $id]);
        }
        $user = $this->browserUser();
        DB::table('user_series')->insert([
            ['users_id' => $user->id, 'videos_id' => 1], ['users_id' => $user->id + 1, 'videos_id' => 2],
        ]);
        $response = $this->actingAs($user)->get('/browse/tv?year=2026&network=Network%20A')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Episode 1')->assertDontSee('Episode 2')->assertDontSee('Episode 3')
            ->assertDontSee('aria-label="Genre"', false)->assertDontSee('value="rating"', false);
        $watching = $this->get('/browse/tv?watching=1')->assertOk();
        $this->assertSame(1, $watching->viewData('results')->total());
        $watching->assertSee('Episode 1')->assertDontSee('Episode 2');
    }

    #[DataProvider('metadataRoots')]
    public function test_other_root_filters_apply_to_metadata_and_adult_posted_year(string $root, int $categoryId, string $table, string $foreignKey, array $first, array $second, string $filters): void
    {
        $this->createGenresTable();
        $this->createMusicInfoTable();
        $this->createConsoleInfoTable();
        $this->createBookInfoTable();
        $this->createGamesInfoTable();
        DB::table('genres')->insert(['id' => 1, 'title' => 'Adventure', 'type' => 1]);
        if ($table !== '') {
            DB::table($table)->insert(['id' => 1, 'title' => 'First title', ...$first]);
            DB::table($table)->insert(['id' => 2, 'title' => 'Second title', ...$second]);
        }
        $this->release('Matching release', ['categories_id' => $categoryId, ...($foreignKey === '' ? ['postdate' => '2026-09-13 12:00:00'] : [$foreignKey => 1])]);
        $this->release('Nonmatching release', ['categories_id' => $categoryId, ...($foreignKey === '' ? ['postdate' => '2020-09-13 12:00:00'] : [$foreignKey => 2])]);
        $response = $this->actingAs($this->browserUser())->get('/browse/'.$root.'?'.$filters)->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Matching release')->assertDontSee('Nonmatching release');
        if ($root === 'audio') {
            $sorted = $this->get('/browse/audio?sort=artist')->assertOk();
            $this->assertSame('Nonmatching release', $sorted->viewData('results')->items()[0]->row_data->name);
        }
    }

    /** @return iterable<string, array{string, int, string, string, array<string, mixed>, array<string, mixed>, string}> */
    public static function metadataRoots(): iterable
    {
        yield 'audio label and year' => ['audio', 3030, 'musicinfo', 'musicinfo_id', ['year' => '2026', 'publisher' => 'Label A', 'artist' => 'Zebra', 'genres_id' => 1], ['year' => '2020', 'publisher' => 'Label B', 'artist' => 'Alpha'], 'year=2026&label=Label%20A&genre=Adventure'];
        yield 'console platform and publisher' => ['console', 1030, 'consoleinfo', 'consoleinfo_id', ['releasedate' => '2026-01-01', 'platform' => 'Switch', 'publisher' => 'Studio A', 'genres_id' => 1], ['releasedate' => '2020-01-01', 'platform' => 'PS5', 'publisher' => 'Studio B'], 'year=2026&platform=Switch&publisher=Studio%20A&genre=Adventure'];
        yield 'games platform and publisher' => ['games', 4030, 'gamesinfo', 'gamesinfo_id', ['releasedate' => '2026-01-01', 'publisher' => 'Studio A', 'genres_id' => 1], ['releasedate' => '2020-01-01', 'publisher' => 'Studio B'], 'year=2026&platform=PC&publisher=Studio%20A&genre=Adventure'];
        yield 'books author and genre' => ['books', 7030, 'bookinfo', 'bookinfo_id', ['publishdate' => '2026-01-01', 'author' => 'Writer A', 'genre' => 'Adventure'], ['publishdate' => '2020-01-01', 'author' => 'Writer B', 'genre' => 'History'], 'year=2026&author=Writer%20A&genre=Adventure'];
        yield 'adult year is posted year' => ['xxx', 6030, '', '', [], [], 'year=2026'];
    }

    public function test_legacy_group_link_redirects_to_the_canonical_exact_filter(): void
    {
        $this->actingAs($this->browserUser())->get('/browse/group?g=alt.binaries.movies&per=24&page=2')
            ->assertRedirect('/browse/all?per=24&page=2&group=alt.binaries.movies');
    }

    public function test_poster_pagination_preserves_the_untrimmed_identity(): void
    {
        $identity = ' Exact <poster@Host.test> ';
        for ($index = 0; $index < 25; $index++) {
            $this->release('Exact '.$index, ['fromname' => $identity]);
        }
        $this->release('Trimmed', ['fromname' => trim($identity)]);
        $first = $this->actingAs($this->browserUser())->get(route('browse.all', ['poster' => $identity, 'per' => 24]))->assertOk();
        $second = $this->get($first->viewData('results')->nextPageUrl())->assertOk();
        $this->assertSame(25, $second->viewData('results')->total());
        $this->assertCount(1, $second->viewData('results')->items());
        $second->assertSee('Posts by '.$identity)->assertDontSee('Trimmed');
    }

    public function test_basket_page_uses_the_shared_table_and_only_current_users_releases(): void
    {
        $user = $this->browserUser();
        $this->release('Saved release');
        $this->release('Outside basket');
        $this->actingAs($user)->postJson('/cart/add', ['id' => md5('Saved release')])->assertOk();
        $this->get('/cart/index')->assertRedirect('/basket');
        $response = $this->get('/basket')->assertOk();
        $response->assertSee('data-release-table', false)->assertSee('Saved release')->assertDontSee('Outside basket')
            ->assertSee('data-in-basket="1"', false)->assertSee('Sep 12, 2026 23:30');
        $this->assertSame(1, $response->viewData('results')->total());
    }

    public function test_legacy_adult_navigation_opens_the_canonical_subcategory_table(): void
    {
        $this->release('Adult release', ['categories_id' => 6030]);
        $this->release('Movie release');
        $this->actingAs($this->browserUser())->get('/XXX/HD?t=6030&per=24&parentCategory=movies&id=2030')
            ->assertRedirect('/browse/xxx/6030?per=24');
        $this->get('/browse/xxx/6030?per=24')->assertOk()->assertViewIs('browse.index')
            ->assertSee('data-release-table', false)->assertSee('Adult release')->assertDontSee('Movie release');
    }

    public function test_search_accepts_q_and_uses_the_root_preferences_and_shared_browser(): void
    {
        config(['search.default' => 'browser-test', 'nntmux.mysql_search_fallback' => false]);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('isFuzzyEnabled')->andReturn(false);
        $driver->shouldReceive('isSuggestEnabled')->andReturn(true);
        $driver->shouldReceive('isAutocompleteEnabled')->andReturn(false);
        $driver->shouldReceive('suggest')->with('Requested title', null)->once()->andReturn([['suggest' => 'Corrected title', 'docs' => 7]]);
        $driver->shouldReceive('searchReleasesFiltered')->once()->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        app(SearchService::class)->extend('browser-test', static fn () => $driver);
        $this->actingAs($this->browserUser())->postJson('/profile/update-view', ['root' => 'movies', 'per' => 24, 'view' => 'cards'])->assertOk();

        $response = $this->get('/search?q=Requested%20title&t=2030&subject=Old%20title&ob=size_desc&sort=title&view=cards')->assertOk();
        $response->assertSee('data-release-table', false)->assertSee('Requested title')->assertSee('No releases match.')
            ->assertDontSee('data-value="cards"', false)->assertDontSee('renamed and post-processed only');
        $this->assertSame(24, $response->viewData('results')->perPage());
        $response->assertSee('q=Corrected%20title', false)->assertDontSee('search=Corrected', false);
    }

    public function test_search_page_beyond_the_end_redirects_to_the_actual_last_page(): void
    {
        config(['search.default' => 'browser-test', 'nntmux.mysql_search_fallback' => true]);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('searchReleasesFiltered')->once()->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => false]);
        app(SearchService::class)->extend('browser-test', static fn () => $driver);
        for ($index = 1; $index <= 49; $index++) {
            $this->release('Title '.$index);
        }
        $response = $this->actingAs($this->browserUser())->get('/search?q=Title&per=24&page=999')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('3', $parameters['page']);
        $this->assertSame('Title', $parameters['q']);
    }

    public function test_numeric_subcategory_and_all_browse_respect_personal_exclusions_and_root_permissions(): void
    {
        $user = $this->browserUser();
        $this->release('Excluded movie');
        $this->release('Allowed audio', ['categories_id' => 3030]);
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => 2030]);
        $this->actingAs($user)->get('/browse/movies/2030')->assertForbidden();
        $this->get('/browse/all')->assertOk()->assertDontSee('Excluded movie')->assertSee('Allowed audio');
        $user->revokePermissionTo('view audio');
        $this->get('/browse/audio')->assertForbidden();
    }

    public function test_table_preserves_report_indicators_without_exposing_private_staff_responses(): void
    {
        Schema::create('release_reports', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('releases_id');
            $table->string('response')->nullable();
            $table->boolean('response_is_public')->default(false);
        });
        $public = $this->release('Public report');
        $private = $this->release('Private report');
        DB::table('release_reports')->insert([
            ['releases_id' => $public, 'response' => 'Public answer', 'response_is_public' => true],
            ['releases_id' => $private, 'response' => 'Private answer', 'response_is_public' => false],
        ]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//a[@data-report-summary]')->length);
        $this->assertSame(1, $xpath->query('//a[@data-public-response]')->length);
        $response->assertDontSee('Private answer');
    }

    public function test_table_thumbnails_use_each_rows_root_shape_and_a_placeholder_for_missing_artwork(): void
    {
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('browser-covers')]);
        $this->release('A movie');
        $this->release('B album', ['categories_id' => 3030]);
        $this->release('C adult', ['categories_id' => 6030]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all?thumbs=1&sort=title')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $shapes = array_map(static fn (\DOMNode $node): string => $node->nodeValue, iterator_to_array($xpath->query('//table[@data-release-table]//*[@data-shape]/@data-shape')));
        $this->assertSame(['tall', 'square', 'wide'], $shapes);
        $this->assertSame(0, $xpath->query('//table[@data-release-table]//img')->length);
    }

    public function test_table_sizes_use_megabytes_below_one_gigabyte(): void
    {
        $this->release('Small release', ['size' => 524288000]);
        $this->release('Large release', ['size' => 1610612736]);
        $this->actingAs($this->browserUser())->get('/browse/all')->assertOk()
            ->assertSee('500.00 MB')->assertSee('1.50 GB');
    }

    public function test_existing_minimum_completion_links_still_filter_the_whole_result_set(): void
    {
        $this->release('Complete release', ['completion' => 100]);
        $this->release('Incomplete release', ['completion' => 90]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all?minc=95')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Complete release')->assertDontSee('Incomplete release')->assertSee('Clear filters');
    }

    #[DataProvider('watchedRoots')]
    public function test_watching_preserves_per_title_category_choices(string $root, int $categoryId, string $table, string $key): void
    {
        ProductionTables::fromAuthority()->create('movieinfo', ['id', 'imdbid', 'title', 'year', 'genre', 'rating']);
        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('started')->nullable();
        });
        ProductionTables::fromAuthority()->create('tv_info', ['videos_id', 'publisher']);
        DB::table('categories')->insert(['id' => $categoryId + 10, 'title' => 'Excluded quality', 'root_categories_id' => $categoryId - 30]);
        $user = $this->browserUser();
        DB::table($table)->insert([
            ['users_id' => $user->id, $key => 123, 'categories' => $categoryId.'|'.($categoryId + 20)],
            ['users_id' => $user->id, $key => 456, 'categories' => null],
        ]);
        $this->release('Selected quality', ['categories_id' => $categoryId, $key => 123]);
        $this->release('Unwanted quality', ['categories_id' => $categoryId + 10, $key => 123]);
        $this->release('Unrestricted title', ['categories_id' => $categoryId + 10, $key => 456]);
        $response = $this->actingAs($user)->get('/browse/'.$root.'?watching=1')->assertOk();
        $this->assertSame(2, $response->viewData('results')->total());
        $response->assertSee('Selected quality')->assertSee('Unrestricted title')->assertDontSee('Unwanted quality');
        if ($root === 'tv') {
            $this->followingRedirects()->get('/myshows/browse')->assertOk()->assertSee('Selected quality')->assertDontSee('Unwanted quality');
        }
    }

    public static function watchedRoots(): iterable
    {
        yield 'movies' => ['movies', 2030, 'user_movies', 'imdbid'];
        yield 'tv' => ['tv', 5030, 'user_series', 'videos_id'];
    }

    public function test_my_shows_browse_uses_the_saved_cover_view_and_canonical_expansion(): void
    {
        $this->createCoverCatalogSchema('videos');
        $user = $this->browserUser();
        DB::table('videos')->insert(['id' => 1, 'title' => 'Followed show']);
        DB::table('user_series')->insert(['users_id' => $user->id, 'videos_id' => 1]);
        DB::table('tv_episodes')->insert(['id' => 1, 'videos_id' => 1, 'series' => 1, 'episode' => 1, 'title' => 'Pilot']);
        $this->release('Followed encoding', ['videos_id' => 1, 'tv_episodes_id' => 1, 'categories_id' => 5030]);
        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'tv', 'view' => 'covers'])->assertOk();

        $this->get('/myshows/browse?q=Followed&watching=0')->assertRedirect('/browse/tv?q=Followed&watching=1');
        $response = $this->followingRedirects()->get('/myshows/browse?q=Followed')->assertOk();
        $response->assertSee('data-cover-tile="1"', false)->assertSee('Followed show');
        $this->followingRedirects()->get('/myshows/browse?view=covers&_fragment=cover&cover=1')->assertOk()
            ->assertSee('data-episode-releases', false)->assertSee('Followed encoding');
    }

    #[DataProvider('cardsRoots')]
    public function test_cards_only_include_renamed_releases_that_finished_processing(string $root, int $categoryId): void
    {
        $done = ['categories_id' => $categoryId, 'isrenamed' => 1, 'passwordstatus' => 0, 'nfostatus' => 1, 'additional_pp_claim_token' => null];
        $this->release('Eligible release', $done);
        $this->release('Original name', [...$done, 'isrenamed' => 0]);
        $this->release('Password pending', [...$done, 'passwordstatus' => -1]);
        $this->release('NFO pending', [...$done, 'nfostatus' => -1]);
        $this->release('Claimed release', [...$done, 'additional_pp_claim_token' => 'active-claim']);
        $response = $this->actingAs($this->browserUser())->get('/browse/'.$root.'?view=cards')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('data-release-cards', false)->assertSee('Eligible release')->assertDontSee('Original name')
            ->assertDontSee('Password pending')->assertDontSee('NFO pending')->assertDontSee('Claimed release')
            ->assertSee('renamed and post-processed only')->assertSee('(4 not shown)');
        $this->assertSame(4, $response->viewData('results')->hiddenCount);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-release-cards]//*[@data-release-select]')->length);
        $this->assertSame(4, $xpath->query('//*[@data-release-cards]//*[@data-row-action]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-release-cards]//*[contains(@class,"filelist-badge")]')->length);
        $labels = array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query('//*[@data-release-cards]//*[contains(@class,"release-browser-card-value")]/span')));
        $this->assertSame(['Size', 'Added', 'Posted', 'Grabs'], $labels);
        $this->assertSame(match ($root) {
            'audio' => 'square', 'xxx' => 'wide', default => 'tall'
        }, $xpath->query('//*[@data-release-cards]//*[@data-shape]/@data-shape')->item(0)->nodeValue);
        $this->assertSame(1, $xpath->query('//button[@data-value="cards" and @aria-pressed="true"]')->length);
        $response->assertDontSee('aria-label="Thumbnails"', false);
        $table = $this->get('/browse/'.$root.'?view=table')->assertOk();
        $this->assertSame(5, $table->viewData('results')->total());
        $table->assertSee('Original name')->assertSee('data-release-table', false)->assertDontSee('renamed and post-processed only');
    }

    public static function cardsRoots(): iterable
    {
        yield 'movies' => ['movies', 2030];
        yield 'tv' => ['tv', 5030];
        yield 'audio' => ['audio', 3030];
        yield 'console' => ['console', 1030];
        yield 'books' => ['books', 7030];
        yield 'adult' => ['xxx', 6030];
    }

    #[DataProvider('entityCoverRoots')]
    public function test_covers_group_releases_into_titles_and_keep_titles_without_artwork(string $root, string $table, string $foreignKey, int $categoryId, string $unit): void
    {
        $this->createCoverCatalogSchema($table);
        $this->insertTitles($table,
            ['id' => 1234567, 'imdbid' => '1234567', 'title' => 'A title without artwork', 'year' => '2024', 'rating' => '8.7'],
            ['id' => 1234568, 'imdbid' => '1234568', 'title' => 'Another title', 'year' => '2025', 'rating' => '7.1'],
        );
        $this->release('First encoding', [$foreignKey => '1234567', 'categories_id' => $categoryId, 'isrenamed' => 0, 'nfostatus' => -1]);
        $this->release('Second encoding', [$foreignKey => '1234567', 'categories_id' => $categoryId]);
        $this->release('Third encoding', [$foreignKey => '1234568', 'categories_id' => $categoryId]);
        $this->release('Unmatched release', ['categories_id' => $categoryId]);

        $response = $this->actingAs($this->browserUser())->get('/browse/'.$root.'?view=covers&sort=title')->assertOk();
        $this->assertSame(2, $response->viewData('results')->total());
        $response->assertSee('A title without artwork')->assertSee('Another title')->assertSee('2 '.$unit)
            ->assertDontSee('Unmatched release')->assertDontSee('First encoding')->assertDontSee('Second encoding');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//*[@data-cover-tile]')->length);
        $this->assertSame('2', $xpath->evaluate('string(//*[@data-cover-tile="1234567"]//*[@data-cover-count])'));
        $this->assertSame(0, $xpath->query('//*[@data-cover-tile]//*[@data-row-action]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-cover-tile]//*[@data-no-artwork]')->length);
    }

    #[DataProvider('coverArtworkCases')]
    public function test_cover_artwork_uses_the_entity_id_and_existing_extension(string $root, string $table, string $foreignKey, int $categoryId, string $type, int $id, string $extension): void
    {
        $this->createCoverCatalogSchema($table);
        $covers = $this->makeTempDirectory('catalog-artwork');
        config(['nntmux_settings.covers_path' => $covers]);
        mkdir($covers.'/'.$type);
        file_put_contents($covers.'/'.$type.'/'.$id.'.'.$extension, 'image fixture');
        DB::table($table)->insert(['id' => $id, 'title' => 'Artwork title', 'cover' => 1]);
        $this->release('Artwork encoding', [$foreignKey => $id, 'categories_id' => $categoryId]);

        $this->actingAs($this->browserUser())->get('/browse/'.$root.'?view=covers')->assertOk()
            ->assertSee('src="'.url('/covers/'.$type.'/'.$id.'.'.$extension).'"', false)
            ->assertDontSee('src="'.url('/covers/'.$type.'/1').'"', false);
    }

    public static function coverArtworkCases(): iterable
    {
        yield 'audio album id' => ['audio', 'musicinfo', 'musicinfo_id', 3030, 'music', 42, 'webp'];
        yield 'PC game id' => ['games', 'gamesinfo', 'gamesinfo_id', 4030, 'games', 73, 'jpg'];
    }

    public static function entityCoverRoots(): iterable
    {
        yield 'movies' => ['movies', 'movieinfo', 'imdbid', 2030, 'titles'];
        yield 'audio' => ['audio', 'musicinfo', 'musicinfo_id', 3030, 'albums'];
        yield 'console' => ['console', 'consoleinfo', 'consoleinfo_id', 1030, 'games'];
        yield 'games' => ['games', 'gamesinfo', 'gamesinfo_id', 4030, 'games'];
        yield 'books' => ['books', 'bookinfo', 'bookinfo_id', 7030, 'books'];
    }

    public function test_cover_size_changes_layout_without_changing_title_pagination_and_saved_preferences_apply(): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        for ($id = 1; $id <= 26; $id++) {
            DB::table('movieinfo')->insert(['id' => $id, 'imdbid' => (string) $id, 'title' => sprintf('Movie %02d', $id)]);
            $this->release(sprintf('Encoding %02d', $id), ['imdbid' => (string) $id]);
        }
        $this->actingAs($this->browserUser())->postJson('/profile/update-view', ['root' => 'movies', 'view' => 'covers', 'size' => 'l', 'per' => 24])->assertOk();
        foreach (['s', 'l', 'xl'] as $size) {
            $response = $this->get('/browse/movies?size='.$size.'&sort=title&page=2')->assertOk();
            $this->assertSame(26, $response->viewData('results')->total());
            $this->assertCount(2, $response->viewData('results')->items());
            $response->assertSee('Movie 25')->assertSee('Movie 26')->assertDontSee('Movie 24')
                ->assertSee('aria-label="Cover size"', false)->assertDontSee('aria-label="Thumbnails"', false);
            $this->assertSame('covers', $response->viewData('browserState')->view);
        }
        $saved = $this->get('/browse/movies')->assertOk();
        $this->assertSame('l', $saved->viewData('browserState')->size);
        $this->assertCount(24, $saved->viewData('results')->items());
        $this->get('/browse/movies?per=48')->assertOk()->assertSee('26 titles');
        $this->get('/browse/movies?per=24&page=999')->assertRedirect('/browse/movies?per=24&page=2');
    }

    #[DataProvider('entityCoverRoots')]
    public function test_cover_search_and_metadata_filters_apply_before_counting_titles(string $root, string $table, string $foreignKey, int $categoryId, string $unit): void
    {
        $this->createCoverCatalogSchema($table);
        foreach ([1 => ['Wanted title', '2024'], 2 => ['Wanted older title', '2023'], 3 => ['Outside title', '2024']] as $id => [$title, $year]) {
            $this->insertTitles($table, [
                'id' => $id, 'imdbid' => (string) $id, 'title' => $title, 'year' => $year,
                'releasedate' => $year.'-01-01', 'publishdate' => $year.'-01-01', 'started' => $year.'-01-01',
            ]);
            $this->release('Encoding '.$id, [$foreignKey => (string) $id, 'categories_id' => $categoryId]);
        }
        $this->release('Another encoding', [$foreignKey => '1', 'categories_id' => $categoryId]);
        $this->actingAs($this->browserUser());
        $response = $this->get('/browse/'.$root.'?view=covers&q=Wanted&year=2024')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Wanted title')->assertDontSee('Wanted older title')->assertDontSee('Outside title');
        $this->assertSame(2, $response->viewData('results')->items()[0]->releaseCount);
        $changed = $this->get('/browse/'.$root.'?view=covers&q=Outside&year=2024')->assertOk();
        $this->assertSame(1, $changed->viewData('results')->total());
        $changed->assertSee('Outside title')->assertDontSee('Wanted title');
    }

    #[DataProvider('entityCoverRoots')]
    public function test_expanding_a_cover_returns_every_allowed_release_with_shared_table_actions(string $root, string $table, string $foreignKey, int $categoryId, string $unit): void
    {
        $this->createCoverCatalogSchema($table);
        $this->insertTitles($table, ['id' => 1, 'imdbid' => '1', 'title' => 'Wanted title']);
        DB::table('categories')->insert(['id' => $categoryId + 10, 'title' => 'Excluded quality', 'root_categories_id' => $categoryId - 30]);
        $user = $this->browserUser();
        $user->syncExcludedCategories([$categoryId + 10]);
        for ($id = 1; $id <= 5; $id++) {
            $this->release('Encoding '.$id, [$foreignKey => '1', 'categories_id' => $categoryId, 'nfostatus' => 1]);
        }
        $this->release('Excluded encoding', [$foreignKey => '1', 'categories_id' => $categoryId + 10]);
        $this->release('Unmatched encoding', ['categories_id' => $categoryId]);
        $this->actingAs($user);
        $page = $this->get('/browse/'.$root.'?view=covers&q=Wanted')->assertOk();
        $this->assertSame(5, $page->viewData('results')->items()[0]->releaseCount);
        $page->assertDontSee('Encoding 5');
        $expanded = $this->get('/browse/'.$root.'?view=covers&q=Wanted&_fragment=cover&cover=1')->assertOk();
        $expanded->assertSee('Wanted title')->assertSee('Encoding 1')->assertSee('Encoding 5')
            ->assertDontSee('Excluded encoding')->assertDontSee('Unmatched encoding')
            ->assertSee('data-release-table', false)->assertSee('Title page')->assertSee('Download selected');
        $document = new \DOMDocument;
        @$document->loadHTML($expanded->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(5, $xpath->query('//*[@data-release-select]')->length);
        $this->assertSame(in_array($root, ['movies', 'tv'], true) ? 25 : 20, $xpath->query('//*[@data-row-action]')->length);
        $this->assertSame(5, $xpath->query('//*[contains(@class,"nfo-badge")]')->length);
        $this->get('/browse/'.$root.'?view=covers&_fragment=cover&cover=999')->assertNotFound();
    }

    #[DataProvider('entityCoverRoots')]
    public function test_cover_expansion_paginates_matching_encodings_without_changing_the_outer_page(string $root, string $table, string $foreignKey, int $categoryId, string $unit): void
    {
        $this->createCoverCatalogSchema($table);
        $this->insertTitles($table,
            ['id' => 1, 'imdbid' => '1', 'title' => 'Wanted title'],
            ['id' => 2, 'imdbid' => '2', 'title' => 'Wanted title'],
        );
        DB::table('categories')->insert(['id' => $categoryId + 10, 'title' => 'Excluded', 'root_categories_id' => $categoryId - 30]);
        $user = $this->browserUser();
        $user->syncExcludedCategories([$categoryId + 10]);
        $attributes = [$foreignKey => '1', 'categories_id' => $categoryId];
        for ($id = 1; $id <= 251; $id++) {
            $this->release(sprintf('Encoding %03d', $id), $attributes);
        }
        $this->release('Different identity', [...$attributes, $foreignKey => '2']);
        $this->release('Excluded encoding', [...$attributes, 'categories_id' => $categoryId + 10]);
        $this->release('Passworded encoding', [...$attributes, 'passwordstatus' => 2]);
        $this->release('Incomplete encoding', [...$attributes, 'completion' => 20]);
        $this->actingAs($user);
        $url = '/browse/'.$root.'?view=covers&q=Wanted&minc=95&sort=title&page=3&per=48&_fragment=cover&cover=1';
        foreach ([[null, 1, 24, 1, 251, 228], [24, 2, 24, 2, 227, 204], [48, 2, 48, 2, 203, 156], [100, 2, 100, 2, 151, 52], [100, 999, 51, 3, 51, 1], [24, 0, 24, 1, 251, 228], [500, 1, 24, 1, 251, 228]] as [$per, $page, $count, $current, $first, $last]) {
            DB::enableQueryLog();
            $response = $this->get($url.'&release_page='.$page.($per === null ? '' : '&release_per='.$per))->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();
            $rowQueries = array_values(array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select "r".*')));
            $this->assertCount(1, $rowQueries);
            $this->assertStringContainsString('limit '.(in_array($per, [24, 48, 100], true) ? $per : 24), $rowQueries[0]['query']);
            $rows = $response->viewData('rows');
            $this->assertInstanceOf(LengthAwarePaginator::class, $rows);
            $this->assertSame(251, $rows->total());
            $this->assertSame($current, $rows->currentPage());
            $this->assertCount($count, $rows->items());
            $this->assertSame(sprintf('Encoding %03d', $first), $rows->first()->row_data->name);
            $this->assertSame(sprintf('Encoding %03d', $last), $rows->last()->row_data->name);
            $this->assertSame(3, $response->viewData('state')->page);
            $this->assertSame(48, $response->viewData('state')->per);
            $this->assertSame($count, substr_count($response->getContent(), 'data-release-select'));
            $response->assertSee('251 releases')->assertSee('Releases per page')->assertSee('changeCoverPage')
                ->assertDontSee('Different identity')->assertDontSee('Excluded encoding')->assertDontSee('Passworded encoding')->assertDontSee('Incomplete encoding');
        }
        $this->get(str_replace('q=Wanted', 'q=Missing', $url))->assertNotFound();
    }

    public function test_followed_movie_covers_refresh_membership_and_quality_restrictions_without_waiting_for_the_catalog_cache(): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        DB::table('movieinfo')->insert([
            ['id' => 1, 'imdbid' => '1', 'title' => 'Followed title'],
            ['id' => 2, 'imdbid' => '2', 'title' => 'Another user title'],
        ]);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'Another quality', 'root_categories_id' => 2000]);
        $this->release('HD encoding', ['imdbid' => '1']);
        $this->release('Other encoding', ['imdbid' => '1', 'categories_id' => 2040]);
        $this->release('Other user encoding', ['imdbid' => '2']);
        $user = $this->browserUser();
        DB::table('user_movies')->insert([
            ['users_id' => $user->id, 'imdbid' => '1', 'categories' => '2030'],
            ['users_id' => $user->id + 1, 'imdbid' => '2', 'categories' => null],
        ]);
        $this->actingAs($user);
        $url = '/browse/movies?view=covers&watching=1';
        $first = $this->get($url)->assertOk()->assertDontSee('Another user title');
        $this->assertSame(1, $first->viewData('results')->total());
        $this->assertSame(1, $first->viewData('results')->items()[0]->releaseCount);
        $this->get($url.'&_fragment=cover&cover=1')->assertOk()->assertSee('HD encoding')->assertDontSee('Other encoding');
        DB::table('user_movies')->where('users_id', $user->id)->update(['categories' => '2030|2040']);
        $changed = $this->get($url)->assertOk();
        $this->assertSame(2, $changed->viewData('results')->items()[0]->releaseCount);
        $this->get($url.'&_fragment=cover&cover=1')->assertOk()->assertSee('HD encoding')->assertSee('Other encoding');
        DB::table('user_movies')->where('users_id', $user->id)->delete();
        $removed = $this->get($url)->assertOk();
        $this->assertSame(0, $removed->viewData('results')->total());
        $this->get($url.'&_fragment=cover&cover=1')->assertNotFound();
    }

    #[DataProvider('entityCoverRoots')]
    public function test_cover_sorting_uses_title_and_newest_added_release_before_pagination(string $root, string $table, string $foreignKey, int $categoryId, string $unit): void
    {
        $this->createCoverCatalogSchema($table);
        foreach ([1 => ['Zulu', '2024', '9.2', 'Alpha artist'], 2 => ['Alpha', '2025', '8.1', 'Zulu artist']] as $id => [$title, $year, $rating, $artist]) {
            $this->insertTitles($table, [
                'id' => $id, 'imdbid' => (string) $id, 'title' => $title, 'year' => $year,
                'rating' => $rating, 'artist' => $artist, 'started' => $year.'-01-01',
            ]);
            $this->release('Encoding '.$id, [$foreignKey => (string) $id, 'categories_id' => $categoryId,
                'postdate' => $id === 1 ? '2026-09-13 12:00:00' : '2026-09-12 12:00:00',
                'adddate' => $id === 1 ? '2026-09-12 12:00:00' : '2026-09-13 12:00:00', 'grabs' => $id === 1 ? 50 : 100]);
        }
        $this->actingAs($this->browserUser());
        foreach (['title' => 'Zulu', 'newest' => 'Alpha', 'oldest' => 'Zulu', 'posted' => 'Zulu', 'posted_oldest' => 'Alpha', 'grabs' => 'Alpha'] as $sort => $first) {
            $response = $this->get('/browse/'.$root.'?view=covers&sort='.$sort)->assertOk();
            $this->assertSame($first, $response->viewData('results')->items()[0]->title, $root.' '.$sort);
        }
    }

    #[DataProvider('trendingRoots')]
    public function test_trending_ranks_downloads_from_seven_days_and_keeps_all_allowed_encodings(string $root, string $table, string $key, int $category): void
    {
        $this->travelTo(now()->setDateTime(2026, 9, 14, 12, 0));
        $this->createCoverCatalogSchema($table);
        Schema::create('user_downloads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->dateTime('timestamp');
        });
        foreach ([1 => 'Old favorite', 2 => 'This week', 3 => 'No recent grabs'] as $id => $title) {
            DB::table($table)->insert(['id' => $id, 'imdbid' => (string) $id, 'title' => $title]);
            $release = $this->release($title, [$key => (string) $id, 'categories_id' => $category, 'grabs' => $id === 1 ? 9999 : 1]);
            DB::table('user_downloads')->insert(['releases_id' => $release, 'timestamp' => '2026-09-01 12:00:00']);
            if ($id < 3) {
                for ($n = 0; $n < $id; $n++) {
                    DB::table('user_downloads')->insert(['releases_id' => $release, 'timestamp' => '2026-09-07 12:00:00']);
                }
            }
        }
        $this->release('Another encoding without grabs', [$key => '2', 'categories_id' => $category]);
        DB::table('categories')->insert(['id' => $category + 10, 'root_categories_id' => $category - 30, 'title' => 'Excluded']);
        $excluded = $this->release('Excluded popular encoding', [$key => '1', 'categories_id' => $category + 10]);
        for ($n = 0; $n < 8; $n++) {
            DB::table('user_downloads')->insert(['releases_id' => $excluded, 'timestamp' => '2026-09-14 11:00:00']);
        }
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => $category + 10]);
        $this->actingAs($user);
        $url = '/browse/'.$root.'?view=covers&sort=grabs&trending=1';
        $response = $this->get($url)->assertOk();
        $covers = $response->viewData('results');
        $this->assertSame(2, $covers->total());
        $this->assertSame(['This week', 'Old favorite'], array_map(fn ($cover) => $cover->title, $covers->items()));
        $this->assertSame(2, $covers->items()[0]->releaseCount);
        $response->assertSee('Rank 1')->assertSee('Rank 2')->assertDontSee('No recent grabs');
        $this->get($url.'&_fragment=cover&cover=2')->assertOk()->assertSee('Another encoding without grabs');
    }

    public static function trendingRoots(): array
    {
        return [['movies', 'movieinfo', 'imdbid', 2030]];
    }

    public static function letterCoverRoots(): array
    {
        return array_filter(iterator_to_array(self::entityCoverRoots()), static fn (array $case): bool => in_array($case[0], ['audio', 'books'], true));
    }

    #[DataProvider('letterCoverRoots')]
    public function test_cover_letters_jump_to_the_first_matching_title_page_without_filtering_the_catalog(string $root, string $table, string $foreignKey, int $categoryId, string $unit): void
    {
        $this->createCoverCatalogSchema($table);
        for ($id = 1; $id <= 27; $id++) {
            $title = $id === 1 ? '123 title' : ($id <= 25 ? sprintf('Alpha %02d', $id) : 'Zulu '.$id);
            DB::table($table)->insert(['id' => $id, 'title' => $title]);
            $this->release('Encoding '.$id, [$foreignKey => $id, 'categories_id' => $categoryId]);
        }
        $this->actingAs($this->browserUser());
        $url = '/browse/'.$root.'?view=covers&per=24&letter=Z';
        $this->get($url)->assertRedirect($url.'&sort=title&page=2');
        $page = $this->get($url.'&sort=title&page=2')->assertOk();
        $this->assertSame(27, $page->viewData('results')->total());
        $page->assertSee('Alpha 25')->assertSee('Zulu 26')->assertDontSee('Alpha 24')
            ->assertSee('aria-label="Jump by initial"', false)->assertSee('data-letter="Z" aria-pressed="true"', false);
        $manual = $this->get($url.'&sort=title&page=1')->assertOk();
        $manual->assertSee('123 title')->assertDontSee('Zulu 26');
        $this->get('/browse/'.$root.'?view=covers&per=24&letter=%23')->assertRedirect('/browse/'.$root.'?view=covers&per=24&letter=%23&sort=title&page=1');
        $this->get('/browse/'.$root.'?view=covers&per=24&letter=Q')->assertRedirect('/browse/'.$root.'?view=covers&per=24&letter=Q&sort=title&page=1');
    }

    public function test_adult_covers_use_preview_then_sample_then_placeholder_and_expand_one_release(): void
    {
        $covers = $this->makeTempDirectory('adult-cover-images');
        config(['nntmux_settings.covers_path' => $covers]);
        foreach (['preview', 'sample'] as $type) {
            mkdir($covers.'/'.$type);
        }
        foreach (['A preview' => [1, 1], 'B sample' => [0, 1], 'C missing preview' => [1, 1], 'D no artwork' => [0, 0]] as $title => [$preview, $sample]) {
            $this->release($title, ['categories_id' => 6030, 'haspreview' => $preview, 'jpgstatus' => $sample]);
            if ($preview && $title !== 'C missing preview') {
                file_put_contents($covers.'/preview/'.md5($title).'_thumb.jpg', 'image');
            }
            if ($sample) {
                file_put_contents($covers.'/sample/'.md5($title).'_thumb.jpg', 'image');
            }
        }
        $this->actingAs($this->browserUser());
        $page = $this->get('/browse/xxx?view=covers&sort=title&size=l')->assertOk();
        $this->assertSame(4, $page->viewData('results')->total());
        $page->assertSee('PREVIEW')->assertSee('SAMPLE')->assertSee('500.00 MB')->assertDontSee('data-cover-count', false)
            ->assertDontSee('data-cover-watch', false)->assertDontSee('data-value="xl"', false)->assertDontSee('Jump by initial');
        $items = $page->viewData('results')->items();
        $this->assertStringContainsString('/preview/'.md5('A preview').'_thumb.jpg', $items[0]->artwork);
        $this->assertStringContainsString('/sample/'.md5('B sample').'_thumb.jpg', $items[1]->artwork);
        $this->assertStringContainsString('/sample/'.md5('C missing preview').'_thumb.jpg', $items[2]->artwork);
        $this->assertNull($items[3]->artwork);
        $expanded = $this->get('/browse/xxx?view=covers&_fragment=cover&release_page=999&release_per=100&cover='.md5('B sample'))->assertOk();
        $this->assertSame(1, $expanded->viewData('rows')->total());
        $this->assertSame(1, $expanded->viewData('rows')->currentPage());
        $this->assertSame(100, $expanded->viewData('rows')->perPage());
        $expanded->assertSee('B sample')->assertDontSee('A preview')->assertDontSee('Title page')->assertSee('sample', false);
        $this->assertSame(1, substr_count($expanded->getContent(), 'data-release-select'));
        $this->get('/browse/xxx?view=covers&size=xl')->assertOk()->assertViewHas('browserState', static fn ($state): bool => $state->size === 's');
    }

    #[DataProvider('entityCoverRoots')]
    public function test_large_covers_render_entity_metadata_and_extra_large_covers_offer_all_encodings(string $root, string $table, string $foreignKey, int $categoryId, string $unit): void
    {
        $this->createCoverCatalogSchema($table);
        $this->insertTitles($table, ['id' => 1, 'imdbid' => '1', 'title' => 'Metadata title', 'year' => '2024', 'rating' => '8.7',
            'genre' => 'Mystery', 'artist' => 'An artist', 'author' => 'An author', 'publisher' => 'A publisher',
            'platform' => 'PS5', 'esrb' => 'T', 'releasedate' => '2024-01-01', 'publishdate' => '2024-01-01', 'genres_id' => 1]);
        DB::table('genres')->insert(['id' => 1, 'title' => 'Mystery']);
        if ($root === 'tv') {
            DB::table('tv_info')->insert(['videos_id' => 1, 'publisher' => 'A network']);
        }
        for ($id = 1; $id <= 5; $id++) {
            $this->release('Encoding '.$id, [$foreignKey => '1', 'categories_id' => $categoryId, 'nfostatus' => 1]);
        }
        $this->actingAs($this->browserUser());
        $large = $this->get('/browse/'.$root.'?view=covers&size=l')->assertOk();
        $large->assertSee('5 releases')->assertDontSee('Encoding 1')->assertSee(match ($root) {
            'tv' => 'A network', 'console' => 'PS5', default => 'Mystery'
        });
        $xl = $this->get('/browse/'.$root.'?view=covers&size=xl')->assertOk();
        $xl->assertSee('Metadata title')->assertSee('data-cover-release', false)->assertSee('View all 5 releases');
        $this->assertSame(2, substr_count($xl->getContent(), 'data-cover-release='));
        $this->assertSame(2, substr_count($xl->getContent(), 'data-row-action="download"'));
        $this->assertSame(in_array($root, ['movies', 'tv'], true) ? 1 : 0, preg_match_all('/\sdata-cover-watch(?:=|\s|>)/', $large->getContent()));
    }

    public function test_legacy_cover_pages_redirect_to_the_shared_browser_with_filters_and_category_preserved(): void
    {
        $this->actingAs($this->browserUser());
        foreach (['Movies' => ['movies', 2030], 'Audio' => ['audio', 3030], 'Console' => ['console', 1030], 'Books' => ['books', 7030], 'Games' => ['games', 4030]] as $legacy => [$root, $category]) {
            $response = $this->get('/'.$legacy.'?t='.$category.'&title=Wanted&ob=title_asc&page=2&per=24')->assertRedirect();
            $location = $response->headers->get('Location');
            $this->assertSame('/browse/'.$root.'/'.$category, parse_url($location, PHP_URL_PATH));
            parse_str(parse_url($location, PHP_URL_QUERY), $query);
            $this->assertEquals(['page' => '2', 'per' => '24', $root === 'movies' ? 'title' : 'q' => 'Wanted', 'sort' => 'title', 'view' => 'covers'], $query);
            $this->get('/'.$legacy.'?t=9999')->assertNotFound();
        }
        $this->get('/trending-movies')->assertRedirect('/browse/movies?view=covers&sort=grabs&trending=1');
    }

    public function test_legacy_movie_title_filter_stays_title_specific_and_combines_with_the_query(): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        foreach ([['Matrix', 'Keanu'], ['Matrix sequel', 'Someone'], ['Another film', 'Keanu Matrix']] as $index => [$title, $actors]) {
            DB::table('movieinfo')->insert(['imdbid' => $index + 1, 'title' => $title, 'actors' => $actors]);
            $this->release('Encoding '.$index, ['imdbid' => $index + 1]);
        }
        $this->actingAs($this->browserUser());
        $title = $this->followingRedirects()->get('/Movies?title=Matrix')->assertOk();
        $this->assertSame(2, $title->viewData('results')->total());
        $title->assertSee('Matrix sequel')->assertDontSee('Another film');
        $combined = $this->followingRedirects()->get('/Movies?q=Keanu&title=Matrix')->assertOk();
        $this->assertSame(1, $combined->viewData('results')->total());
        $combined->assertSee('Matrix')->assertDontSee('Matrix sequel')->assertDontSee('Another film');
    }

    #[DataProvider('movieCoverQueries')]
    public function test_movie_cover_search_uses_index_keys_once_for_results_and_filter_options(string $text, array $fields): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        DB::table('movieinfo')->insert([
            ['imdbid' => '0123456', 'title' => 'Indexed match', 'year' => '2024', 'genre' => 'Drama', 'rating' => '8'],
            ['imdbid' => '7654321', 'title' => 'Needle SQL match', 'year' => '2024', 'genre' => 'Drama', 'rating' => '8'],
            ['imdbid' => '3', 'title' => 'Wrong year', 'year' => '2023', 'genre' => 'Drama', 'rating' => '8'],
            ['imdbid' => '4', 'title' => 'Wrong genre', 'year' => '2024', 'genre' => 'Comedy', 'rating' => '8'],
            ['imdbid' => '5', 'title' => 'Wrong rating', 'year' => '2024', 'genre' => 'Drama', 'rating' => '5'],
        ]);
        $this->release('Selected encoding', ['imdbid' => '0123456']);
        $this->release('Unselected encoding', ['imdbid' => '7654321']);
        foreach ([3, 4, 5] as $id) {
            $this->release('Filtered encoding '.$id, ['imdbid' => (string) $id]);
        }
        $search = Mockery::mock(SearchServiceInterface::class);
        $search->shouldReceive('searchEntityFields')->once()
            ->with('movies', $fields, 'imdbid', 500, 0)
            ->andReturn(['ids' => [1, 3, 4, 5], 'keys' => ['0123456', '3', '4', '5'], 'available' => true, 'has_more' => false]);
        $this->app->instance(SearchServiceInterface::class, $search);

        $response = $this->actingAs($this->browserUser())->get('/browse/movies?'.http_build_query(['view' => 'covers', 'q' => $text, 'year' => '2024', 'genre' => 'Drama', 'rating' => '7']))->assertOk();

        $this->assertSame(['0123456'], array_map(static fn ($cover) => $cover->id, $response->viewData('results')->items()));
        $this->assertSame(1, $response->viewData('results')->total());
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function movieCoverQueries(): iterable
    {
        yield 'unqualified' => ['Needle', ['all' => 'Needle']];
        yield 'title' => ['title:Needle', ['title' => 'Needle']];
        yield 'actor alias' => ['actor:Needle', ['actors' => 'Needle']];
        yield 'director' => ['director:Needle', ['director' => 'Needle']];
        yield 'plot' => ['plot:Needle', ['plot' => 'Needle']];
        yield 'phrases and exclusions' => ['actor:"Hugh Jackman" director:(scorsese -spielberg)', ['actors' => '"Hugh Jackman"', 'director' => '(scorsese -spielberg)']];
    }

    #[DataProvider('movieIndexAvailability')]
    public function test_movie_cover_fallback_preserves_phrases_exclusions_and_field_constraints(bool $available): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        foreach ([
            ['Part Two', 'Hugh Jackman'], ['Two Part', 'Hugh Jackman'],
            ['Part Two cam', 'Hugh Jackman'], ['Part Two', 'Other Actor'],
        ] as $id => [$title, $actors]) {
            DB::table('movieinfo')->insert(['imdbid' => (string) ($id + 1), 'title' => $title, 'actors' => $actors]);
            $this->release('Encoding '.$id, ['imdbid' => (string) ($id + 1)]);
        }
        $search = Mockery::mock(SearchServiceInterface::class);
        $search->shouldReceive('searchEntityFields')->once()
            ->with('movies', ['all' => '-cam', 'title' => '"Part Two"', 'actors' => 'Jackman'], 'imdbid', 500, 0)
            ->andReturn(['ids' => [], 'keys' => [], 'available' => $available, 'has_more' => false]);
        $this->app->instance(SearchServiceInterface::class, $search);

        $response = $this->actingAs($this->browserUser())->get('/browse/movies?'.http_build_query([
            'view' => 'covers', 'q' => 'title:"Part Two" actor:Jackman -cam',
        ]))->assertOk();

        $this->assertSame(['1'], array_map(static fn ($cover) => $cover->id, $response->viewData('results')->items()));
    }

    /** @return iterable<string, array{bool}> */
    public static function movieIndexAvailability(): iterable
    {
        yield 'empty' => [true];
        yield 'unavailable' => [false];
    }

    public function test_movie_cover_index_pages_are_combined_and_refreshed_for_expansion(): void
    {
        $this->createCoverCatalogSchema('movieinfo');
        foreach ([1, 2] as $id) {
            DB::table('movieinfo')->insert(['imdbid' => (string) $id, 'title' => 'Indexed movie '.$id]);
            $this->release('Encoding '.$id, ['imdbid' => (string) $id]);
        }
        $search = Mockery::mock(SearchServiceInterface::class);
        $search->shouldReceive('searchEntityFields')->twice()
            ->with('movies', ['title' => 'Needle'], 'imdbid', 500, 0)
            ->andReturn(
                ['ids' => [10], 'keys' => ['1'], 'available' => true, 'has_more' => true],
                ['ids' => [20], 'keys' => ['2'], 'available' => true, 'has_more' => false],
            );
        $search->shouldReceive('searchEntityFields')->once()
            ->with('movies', ['title' => 'Needle'], 'imdbid', 500, 10)
            ->andReturn(['ids' => [20], 'keys' => ['2'], 'available' => true, 'has_more' => false]);
        $this->app->instance(SearchServiceInterface::class, $search);
        $url = '/browse/movies?view=covers&q=title:Needle';

        $response = $this->actingAs($this->browserUser())->get($url)->assertOk();
        $this->assertSame(2, $response->viewData('results')->total());
        $this->get($url.'&_fragment=cover&cover=1')->assertNotFound();
    }

    public function test_legacy_cover_pages_reject_array_categories_without_a_server_error(): void
    {
        $this->actingAs($this->browserUser());
        foreach (['Movies', 'Audio', 'Console', 'Books', 'Games'] as $path) {
            $this->get('/'.$path.'?t[]=2030')->assertNotFound();
        }
    }

    private function createCoverCatalogSchema(string $entityTable): void
    {
        config(['search.default' => 'cover-test']);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('isAvailable')->andReturn(false);
        $driver->shouldReceive('searchEntityFields')->andReturn(['ids' => [], 'keys' => [], 'available' => false, 'has_more' => false]);
        app(SearchService::class)->extend('cover-test', static fn () => $driver);
        $this->registerSqliteFunction('YEAR', static fn (?string $date): ?string => $date === null ? null : substr($date, 0, 4));
        ProductionTables::fromAuthority()->create($entityTable);
        $this->createGenresTable();
        ProductionTables::fromAuthority()->create('release_nfos', ['releases_id']);
        ProductionTables::fromAuthority()->create('dnzb_failures', ['release_id', 'failed']);
        if ($entityTable === 'videos') {
            ProductionTables::fromAuthority()->create('tv_episodes', ['id', 'videos_id', 'series', 'episode', 'title', 'firstaired']);
            ProductionTables::fromAuthority()->create('tv_info', ['videos_id', 'publisher', 'image']);
        }
    }

    /**
     * Insert title rows shared by several catalog tables, keeping the columns this table has.
     *
     * @param  array<string, mixed>  ...$rows
     */
    private function insertTitles(string $table, array ...$rows): void
    {
        $columns = array_flip(Schema::getColumnListing($table));
        DB::table($table)->insert(array_map(static fn (array $row): array => array_intersect_key($row, $columns), $rows));
    }

    public function test_cards_share_the_dto_processing_decisions_and_exclude_empty_outstanding_claims(): void
    {
        $cases = [
            'Found NFO' => [1, 0, null, true], 'No NFO' => [0, 0, null, true],
            'Failed NFO' => [-9, 0, null, true], 'Skipped NFO' => [-10, 0, null, true],
            'First retry' => [-1, 0, null, false], 'Last retry' => [-8, 0, null, false],
            'Password unchecked' => [1, -1, null, false], 'Empty claim' => [1, 0, '', false],
        ];
        foreach ($cases as $name => [$nfo, $password, $claim, $done]) {
            $this->release($name, ['isrenamed' => 1, 'nfostatus' => $nfo, 'passwordstatus' => $password, 'additional_pp_claim_token' => $claim]);
        }
        $table = $this->actingAs($this->browserUser())->get('/browse/movies?view=table')->assertOk();
        foreach ($table->viewData('results') as $release) {
            $this->assertSame($cases[$release->row_data->name][3], $release->row_data->pp_done, $release->row_data->name);
        }
        $cards = $this->get('/browse/movies?view=cards')->assertOk();
        $this->assertEqualsCanonicalizing(['Found NFO', 'No NFO', 'Failed NFO', 'Skipped NFO'], $cards->viewData('results')->getCollection()->map(static fn ($release) => $release->row_data->name)->all());
        $this->assertSame(4, $cards->viewData('results')->hiddenCount);
    }

    public function test_cards_count_and_filter_before_pagination_and_remember_the_view(): void
    {
        for ($index = 0; $index < 34; $index++) {
            $this->release('Wanted '.$index, ['isrenamed' => 1, 'nfostatus' => $index < 29 ? 1 : -1]);
        }
        $this->release('Outside search', ['isrenamed' => 0]);
        $this->actingAs($this->browserUser())->postJson('/profile/update-view', ['root' => 'movies', 'view' => 'cards', 'per' => 24])->assertOk();
        $response = $this->get('/browse/movies?q=Wanted&page=2')->assertOk();
        $this->assertSame(29, $response->viewData('results')->total());
        $this->assertSame(5, $response->viewData('results')->hiddenCount);
        $this->assertCount(5, $response->viewData('results')->items());
        $response->assertSee('data-release-cards', false)->assertDontSee('Outside search')->assertSee('(5 not shown)');
        $this->get('/browse/movies?q=Wanted&page=999')->assertRedirect('/browse/movies?q=Wanted&page=2');
        $table = $this->get('/browse/movies?q=Wanted&view=table')->assertOk();
        $this->assertSame(34, $table->viewData('results')->total());
    }

    public function test_cards_are_not_offered_for_all_games_other_group_or_poster_lists(): void
    {
        $this->actingAs($this->browserUser());
        foreach (['/browse/all', '/browse/games', '/browse/other', '/browse/movies?group=example', '/browse/movies?poster=example'] as $path) {
            $this->get($path.(str_contains($path, '?') ? '&' : '?').'view=cards')->assertOk()
                ->assertSee('data-release-table', false)->assertDontSee('data-release-cards', false)
                ->assertDontSee('data-value="cards"', false)->assertDontSee('renamed and post-processed only');
        }
    }

    public function test_empty_cards_keep_both_pagers_and_show_the_hidden_count_and_clear_action(): void
    {
        $this->release('Wanted pending release', ['nfostatus' => -1]);
        $response = $this->actingAs($this->browserUser())->get('/browse/movies?view=cards&q=Wanted')->assertOk();
        $this->assertSame(0, $response->viewData('results')->total());
        $this->assertSame(1, $response->viewData('results')->hiddenCount);
        $response->assertSee('No releases match.')->assertSee('(1 not shown)')->assertSee('Clear filters');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//nav[@aria-label="Release pages"]')->length);
        $this->assertSame(4, $xpath->query('//nav[@aria-label="Release pages"]//button[@disabled]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-browser-empty]//button')->length);
        $this->get('/browse/movies?view=cards&q=Wanted&page=999')->assertRedirect('/browse/movies?view=cards&q=Wanted&page=1');
    }
}
