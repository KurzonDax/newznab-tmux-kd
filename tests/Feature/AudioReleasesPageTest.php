<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\AudioReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Enums\ReleaseSort;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\AudioProcessing\AudioGenres;
use App\Services\Releases\AudioReleaseList;
use App\Services\Releases\AudioReleaseRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsNoRetiredAddress;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The Audio releases list, GET /audio (issue #962; docs/proposals/audio-redesign/SPEC.md 1, 4 and 5,
 * DATA-CONTRACT.md 4.1-4.5 and the list checks of prototype/check.mjs on audio.html).
 */
final class AudioReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsNoRetiredAddress;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const MP3 = 3010;

    private const VIDEO = 3020;

    private const AUDIOBOOK = 3030;

    private const LOSSLESS = 3040;

    private const PODCAST = 3050;

    private const FOREIGN = 3060;

    private const AUDIO_OTHER = 3999;

    private const EBOOK = 7020;

    private const ROCK = 11;

    private const METAL = 12;

    private const SYNTH = 13;

    private const JAZZ = 14;

    private const SYNTH_NAME = 'Synth-pop, Disco';

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        Carbon::setTestNow('2026-10-04 12:00:00');
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'name', 'searchname', 'guid', 'display_name', 'categories_id', 'category_band', 'size', 'totalpart',
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages', 'genres', 'audio_genres', 'release_audio_genres',
            'release_audio_evidence', 'release_music_identifications', 'musicbrainz_release_group_genres', 'musicbrainz_release_tracks', 'musicbrainz_artists', 'musicbrainz_artist_aliases', 'release_music_identification_artists', 'music_cover_art_lookups'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 3000, 'title' => 'Audio', 'status' => 1], ['id' => 7000, 'title' => 'Books', 'status' => 1]]);
        foreach ([self::AUDIO_OTHER => 'Other', self::FOREIGN => 'Foreign', self::PODCAST => 'Podcast', self::LOSSLESS => 'Lossless', self::AUDIOBOOK => 'Audiobook',
            self::VIDEO => 'Video', self::MP3 => 'MP3'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 3000, 'status' => 1]);
        }
        DB::table('categories')->insert(['id' => self::EBOOK, 'title' => 'Ebook', 'root_categories_id' => 7000, 'status' => 1]);
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('audio-list-covers')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_list_shows_the_audio_band_only_newest_posted_first_with_its_header(): void
    {
        $this->audio('Audio.Newer', ['postdate' => '2026-10-04 10:00:00']);
        $this->audio('Audio.Older', ['postdate' => '2026-09-20 10:00:00', 'categories_id' => self::LOSSLESS]);
        $this->audio('Audio.Other.Upload', ['postdate' => '2026-10-03 10:00:00', 'categories_id' => self::AUDIO_OTHER]);
        $this->release('A.Book', ['categories_id' => self::EBOOK, 'postdate' => '2026-10-04 11:00:00']);

        $response = $this->page('/audio')->assertOk()
            ->assertSee('<h1 data-part="page title">Audio releases</h1>', false)
            ->assertSee('<title>Audio releases', false)
            ->assertSee('data-preference-root="audio"', false)
            ->assertSeeInOrder(['Audio.Newer', 'Audio.Other.Upload', 'Audio.Older'])
            ->assertDontSee('A.Book')
            ->assertSee('Showing 1–3 of 3 releases')->assertSee('2 hr ago')->assertSee('Sep 20, 2026')
            ->assertDontSee('style="', false);
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<h1 data-part="page title">Audio releases<\/h1>.*?<div class="tv-search tv-name-search">\s*<label>\s*<i class="fas fa-magnifying-glass" aria-hidden="true"><\/i>\s*'
            .'<input type="text" x-ref="nameSearch" value="" placeholder="Search releases, artists or albums" autocomplete="off" aria-label="Search release names, artists and albums"/s', $html);
        $this->assertNoWatchWording($html, 'The Audio releases list');
        $this->assertSame('/audio', route('audio.releases', [], false));
    }

    public function test_category_exclude_other_completion_the_sort_and_paging_behave_as_on_console(): void
    {
        $expected = [];
        foreach (range(1, 120) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            $expected[] = [$postdate, $this->audio('Paged '.$index, ['postdate' => $postdate, 'categories_id' => [self::LOSSLESS, self::MP3, self::AUDIO_OTHER][$index % 3],
                'completion' => [100, 96, 80][$index % 3]])];
        }
        $this->audio('A podcast', ['categories_id' => self::PODCAST, 'postdate' => '2025-01-01 00:00:00']);
        $expected[] = ['2025-01-01 00:00:00', (int) DB::table('releases')->where('name', 'A podcast')->value('id')];
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);
        foreach ([1 => [1, 50], 2 => [51, 100], 3 => [101, 121]] as $page => [$from, $to]) {
            $response = $this->page('/audio'.($page > 1 ? '?page='.$page : ''))->assertOk()->assertSee('Showing '.$from.'–'.$to.' of 121 releases');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $this->page('/audio?page=9')->assertRedirect(route('audio.releases', ['page' => 3]));

        $this->assertSame([self::MP3 => 'MP3', self::LOSSLESS => 'Lossless', self::PODCAST => 'Podcast', self::AUDIO_OTHER => 'Other'],
            $this->page('/audio')->viewData('categoryMenu'), 'the site order, only sub-categories holding releases');
        $this->page('/audio?category[]='.self::MP3)->assertSee('Showing 1–40 of 40 releases');
        $this->page('/audio?category=exclude-other')->assertSee('title="Category: Exclude Other"', false)->assertSee('Showing 1–50 of 81 releases');
        $this->page('/audio?completion=95')->assertSee('Showing 1–50 of 81 releases');
        $this->page('/audio?completion=100&category[]='.self::MP3)->assertSee('Showing 0 releases')->assertSee('No releases match MP3 · 100% complete.');
        $this->page('/audio?category=exclude-other&completion=100')->assertSee('Showing 1–41 of 41 releases');
        $this->postJson('/profile/update-view', ['root' => 'audio', 'sort' => 'posted_oldest'])->assertOk();
        $this->page('/audio?clear=1')->assertRedirect(route('audio.releases'));
        $oldest = $this->page('/audio', User::query()->findOrFail($this->user->id))->assertOk();
        $this->assertSame(array_slice(array_reverse($order), 0, 50), $this->listedIds($oldest));
        preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', (string) $oldest->getContent(), $cells);
        $this->assertSame(['category', 'completion', 'genre', 'year'], $cells[1]);
        $oldest->assertSeeInOrder(['<div class="filter-row tv-bar-list is-shelf">', '<div class="filter-bar is-release" role="group" aria-label="The release">',
            'data-name="category"', 'data-name="completion"', '<div class="filter-bar is-game" role="group" aria-label="The music">', 'data-name="genre"',
            'data-name="year"', 'x-ref="list" class="tv-list-end"'], false);
        $this->assertSame(['Category: any', 'Completion: any', 'Genre: any', 'Year: any'], array_map(fn (string $name): string => $this->cellText($oldest, $name),
            ['category', 'completion', 'genre', 'year']));
    }

    public function test_a_genre_linked_after_the_menu_was_cached_still_filters_and_an_id_of_no_audio_genre_is_ignored(): void
    {
        $this->genre(self::ROCK, 'Rock');
        $this->genre(self::METAL, 'Metal');
        $first = $this->audio('Loud.Album', ['postdate' => '2026-10-01 00:00:00']);
        $second = $this->audio('Heavy.Album', ['postdate' => '2026-10-02 00:00:00']);
        $this->tag($first, ['genre' => 'Rock'], [self::ROCK]);
        $this->tag($second, ['genre' => 'Rock'], [self::ROCK]);
        $this->page('/audio')->assertOk();
        $this->assertSame([self::ROCK => 'Rock'], Cache::get('audio_releases_genre_menu'));

        app(AudioGenres::class)->replace($second, [self::METAL]);
        $set = $this->page('/audio?genre[]='.self::METAL)->assertOk()->assertSee('Showing 1–1 of 1 release');
        $this->assertSame(['Heavy.Album'], $this->listedNames($set));
        $this->assertSame([self::ROCK => 'Rock'], Cache::get('audio_releases_genre_menu'), 'the cached menu does not list it yet');
        $this->assertSame([self::METAL], $set->viewData('filters')->genres);
        $this->assertSame('Genre: Metal', $this->cellText($set, 'genre'), 'the cell names the genre that filters the list');
        $this->assertSame(['genre' => [self::METAL]], $this->remembered($this->user, 'audio'));

        DB::table('genres')->insert(['id' => 99, 'title' => 'Shared Genre', 'type' => 3000, 'disabled' => 0]);
        foreach (['/audio?genre[]=99', '/audio?genre[]=999', '/audio?genre[]=abc', '/audio?genre[]=unknown'] as $uri) {
            $ignored = $this->page($uri)->assertOk()->assertSee('Showing 1–2 of 2 releases');
            $this->assertSame([], $ignored->viewData('filters')->genres, $uri);
        }
    }

    public function test_the_music_menus_and_every_filter_return_exactly_what_a_direct_predicate_returns_in_every_sort_and_on_every_page(): void
    {
        $releases = $this->catalogue();
        $list = app(AudioReleaseList::class);
        $unknown = AudioReleaseFilters::GENRE_UNKNOWN;
        $genreSets = [[self::ROCK], [self::ROCK, self::METAL], [$unknown], [self::METAL, $unknown], [self::SYNTH, self::JAZZ, $unknown], []];
        $others = [[], ['categories' => [self::LOSSLESS]], ['completion' => 95], ['decades' => [1990]], ['decades' => [1940, 2000]], ['yearFrom' => 2001, 'yearTo' => 2004],
            ['yearFrom' => 1998], ['search' => 'Blades'], ['search' => 'choir'], ['categories' => [self::MP3, self::AUDIO_OTHER], 'completion' => 95, 'decades' => [2000, 2010], 'search' => 'o']];
        $checked = 0;
        foreach ($genreSets as $genres) {
            foreach ($others as $other) {
                foreach (array_keys(ReleaseListFilters::SORTS) as $sortKey) {
                    $sort = ReleaseSort::from($sortKey);
                    $filters = new AudioReleaseFilters(...[...$other, 'sort' => $sort, 'genres' => $genres]);
                    $expected = $this->expected($releases, $filters);
                    $label = json_encode([$genres, $other, $sort->value], JSON_THROW_ON_ERROR);
                    $total = $list->count($filters, []);
                    $this->assertSame(count($expected), $total, $label);
                    foreach (range(1, max(1, (int) ceil($total / 50))) as $page) {
                        $this->assertSame(array_slice($expected, ($page - 1) * 50, 50), $list->pageIds($filters->withPage($page), [], $total), $label.' page '.$page);
                    }
                    $checked++;
                }
            }
        }
        $this->assertSame(6 * 10 * 4, $checked, 'every music menu set, alone and with each other filter, in the four sorts');
        $this->assertGreaterThan(100, count($this->expected($releases, new AudioReleaseFilters(genres: [$unknown]))), 'the Unknown list runs over three pages and is mirrored');
        $this->assertGreaterThan(100, count($this->expected($releases, new AudioReleaseFilters(genres: [self::ROCK]))), 'the genre-led list runs over three pages');

        // over HTTP, the same lists
        $response = $this->page('/audio?genre[]='.self::METAL.'&genre[]=unknown&completion=95&category[]='.self::LOSSLESS.'&page=2')->assertOk();
        $expected = $this->expected($releases, new AudioReleaseFilters(categories: [self::LOSSLESS], completion: 95, genres: [self::METAL, $unknown]));
        $this->assertSame(array_slice($expected, 50, 50), $this->listedIds($response));
        $response->assertSee('Showing 51–'.min(100, count($expected)).' of '.count($expected).' releases');
        $this->assertSame('Genre: 2 chosen', $this->cellText($response, 'genre'));
    }

    public function test_unknown_is_a_release_with_no_tag_row_no_tag_genre_or_a_tag_genre_of_only_unknown_never_one_with_a_genre(): void
    {
        $this->genre(self::ROCK, 'Rock');
        $this->audio('No.Tag.Row', ['postdate' => '2026-09-20 00:00:00']);
        $this->tag($this->audio('No.Tag.Genre', ['postdate' => '2026-09-19 00:00:00']), ['genre' => null, 'recorded_year' => 2010]);
        $this->tag($this->audio('Only.Unknown', ['postdate' => '2026-09-21 00:00:00']), ['genre' => 'unknown', 'recorded_year' => 2012]);
        $this->tag($this->audio('With.Rock', ['postdate' => '2026-09-22 00:00:00']), ['genre' => 'Rock; Unknown', 'recorded_year' => 2014], [self::ROCK]);

        $response = $this->page('/audio?genre[]=unknown')->assertOk()->assertSee('Showing 1–3 of 3 releases')
            ->assertSeeInOrder(['Only.Unknown', 'No.Tag.Row', 'No.Tag.Genre']);
        $this->assertNotContains('With.Rock', $this->listedNames($response));
        $this->assertSame('Genre: Unknown', $this->cellText($response, 'genre'));
        $this->page('/audio?genre[]='.self::ROCK.'&genre[]=unknown')->assertSee('Showing 1–4 of 4 releases');
        $this->assertListed('/audio?genre[]=unknown&decade[]=2010', ['No.Tag.Genre', 'Only.Unknown']);
        $this->assertListed('/audio?genre[]=unknown&genre[]='.self::ROCK.'&year_from=2014', ['With.Rock']);
    }

    public function test_year_reads_decades_a_range_and_from_alone_as_one_year_and_drops_releases_with_no_tagged_year(): void
    {
        foreach ([1944 => 'Year1944', 1987 => 'Year1987', 1998 => 'Year1998', 1999 => 'Year1999', 2001 => 'Year2001', 2004 => 'Year2004',
            2005 => 'Year2005', 2015 => 'Year2015', 2024 => 'Year2024'] as $year => $name) {
            $this->tag($this->audio($name), ['recorded_year' => $year]);
        }
        $this->tag($this->audio('Undated.Tag'), ['recorded_year' => null, 'recorded_date' => null]);
        $this->audio('No.Tag');

        $this->assertListed('/audio?decade[]=1990', ['Year1998', 'Year1999']);
        $this->assertListed('/audio?decade[]=1940', ['Year1944']);
        $this->assertListed('/audio?decade[]=2000&decade[]=2020', ['Year2001', 'Year2004', 'Year2005', 'Year2024']);
        $this->assertListed('/audio?year_from=2001&year_to=2004', ['Year2001', 'Year2004']);
        $this->assertListed('/audio?year_from=1998', ['Year1998']);
        $this->assertListed('/audio?year_from=1998&decade[]=2010', ['Year1998'], 'a range replaces ticked decades');
        foreach (['/audio?decade[]=1930', '/audio?year_from=1899&year_to=1999', '/audio?year_from=1998&year_to=2030', '/audio?year_from=2004&year_to=2001', '/audio?year_from=98'] as $uri) {
            $ignored = $this->page($uri)->assertOk()->assertSee('Showing 1–11 of 11 releases');
            $this->assertFalse($ignored->viewData('filters')->anyYear(), $uri);
        }
        $this->assertSame('Year: 1998', $this->cellText($this->page('/audio?year_from=1998&decade[]=2010'), 'year'));
        $this->page('/audio?decade[]=1980&decade[]=1990&q=zzz')->assertSee('No releases match 1990s or 1980s · release names, artists or albums containing “zzz”.', false);
        $this->page('/audio?year_from=1950&year_to=1959&q=zzz')->assertSee('No releases match 1950–1959 · release names, artists or albums containing “zzz”.', false);
        $this->page('/audio?year_from=2024&q=zzz')->assertSee('No releases match 2024 · release names, artists or albums containing “zzz”.', false);
    }

    public function test_the_genre_menu_lists_the_band_genres_a_to_z_ignoring_case_then_unknown_when_a_band_release_has_no_genre(): void
    {
        $this->genre(21, 'rock');
        $this->genre(20, 'Rock');
        $this->genre(22, 'ambient');
        $this->genre(23, 'Bossa Nova');
        $this->genre(24, 'Only On A Book');
        $this->genre(25, 'Never Linked');
        $this->tag($this->audio('First'), ['genre' => 'Bossa Nova; rock'], [23, 21]);
        $this->tag($this->audio('Second'), ['genre' => 'Rock / ambient / Bossa Nova'], [20, 22, 23]);
        $this->tag($this->release('A.Book', ['categories_id' => self::EBOOK]), ['genre' => 'Only On A Book'], [24]);
        $list = app(AudioReleaseList::class);

        $genres = [22 => 'ambient', 23 => 'Bossa Nova', 20 => 'Rock', 21 => 'rock'];
        $this->assertSame($genres, $list->genreMenu(), 'A to Z ignoring case, ties by id, each once, a genre only outside the band left out; no Unknown');
        $this->assertSame($genres, Cache::get('audio_releases_genre_menu'));
        foreach ([
            'a band release with no tag row' => fn () => $this->audio('No.Tag'),
            'a band release whose tag has no genre' => fn () => $this->tag($this->audio('No.Genre'), ['genre' => null]),
            'a band release whose tag reads only Unknown' => fn () => $this->tag($this->audio('Only.Unknown'), ['genre' => 'Unknown']),
        ] as $case => $make) {
            DB::table('releases')->whereIn('name', ['No.Tag', 'No.Genre', 'Only.Unknown'])->delete();
            Cache::flush();
            $this->assertArrayNotHasKey(AudioReleaseFilters::GENRE_UNKNOWN, $list->genreMenu(), $case.': reset');
            $make();
            Cache::flush();
            $this->assertSame($genres + [AudioReleaseFilters::GENRE_UNKNOWN => 'Unknown'], $list->genreMenu(), $case);
        }
        DB::table('releases')->whereIn('name', ['No.Tag', 'No.Genre', 'Only.Unknown'])->delete();
        $this->release('A.Book.Without.Genre', ['categories_id' => self::EBOOK]);
        Cache::flush();
        $this->assertArrayNotHasKey(AudioReleaseFilters::GENRE_UNKNOWN, $list->genreMenu(), 'a release outside the band does not count');

        $this->audio('No.Tag');
        Cache::flush();
        $first = $this->page('/audio')->assertOk();
        $this->assertSame($genres + [AudioReleaseFilters::GENRE_UNKNOWN => 'Unknown'], $first->viewData('genreMenu'));
        $this->assertSame($first->viewData('genreMenu'), Cache::get('audio_releases_genre_menu'), 'one cached menu, no user in its key');
        $first->assertSeeInOrder(['data-name="genre"', 'Any genre', 'ambient', 'Bossa Nova', 'Rock', 'rock', 'Unknown', 'data-name="year"'], false);
        $html = (string) $first->getContent();
        $this->assertMatchesRegularExpression('/<div class="checkbox-menu is-cell[^"]*" x-data="checkboxMenu" data-name="genre" data-label="Genre"/', $html);
        $this->assertStringContainsString('data-first="1900" data-last="2026"', $html);
        preg_match('/<div class="year-menu-decades".*?<\/div>\s*<\/div>/s', $html, $decades);
        preg_match_all('/data-value="(\d+)"[^>]*>.*?(\d{4}s)</s', $decades[0] ?? $html, $offered);
        $this->assertSame(['2020s', '2010s', '2000s', '1990s', '1980s', '1970s', '1960s', '1950s', '1940s'], $offered[2]);
        $this->assertSame(['2020', '2010', '2000', '1990', '1980', '1970', '1960', '1950', '1940'], $offered[1]);
    }

    public function test_an_accepted_albums_musicbrainz_genres_replace_its_tag_genres_in_the_column_menu_and_filter(): void
    {
        $group = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->genre(self::ROCK, 'Rock');
        $this->genre(30, 'Alternatif et Indé');
        $this->genre(31, 'indie rock');
        $this->tag($this->audio('Band.Rock'), ['genre' => 'Rock'], [self::ROCK]);
        $this->tag($indie = $this->audio('Tagged.Indie'), ['genre' => 'Alternatif et Indé'], [30]);
        $this->tag($unknown = $this->audio('Tagged.Unknown'), ['genre' => 'Unknown']);
        $this->cover($indie, $group, stored: false);
        $this->cover($unknown, $group, stored: false);
        DB::table('musicbrainz_release_group_genres')->insert([
            ['musicbrainz_release_group_id' => $group, 'audio_genres_id' => 31, 'position' => 0],
            ['musicbrainz_release_group_id' => $group, 'audio_genres_id' => self::ROCK, 'position' => 1],
        ]);
        // What the decision store does once an album decision has committed.
        (new AudioGenres)->rederive($indie);
        (new AudioGenres)->rederive($unknown);
        Cache::flush();

        $response = $this->page('/audio')->assertOk();
        $this->assertStringContainsString('<td class="tv-genre" title="indie rock, Rock"><span>indie rock, Rock</span></td>', $this->rowOf($response, 'Tagged.Indie'));
        $this->assertStringContainsString('<td class="tv-genre" title="indie rock, Rock"><span>indie rock, Rock</span></td>', $this->rowOf($response, 'Tagged.Unknown'));
        $this->assertSame([31 => 'indie rock', self::ROCK => 'Rock'], app(AudioReleaseList::class)->genreMenu(),
            'the tag-only genre leaves the menu, and no band release is Unknown');
        $this->assertListed('/audio?genre[]=31', ['Tagged.Indie', 'Tagged.Unknown']);
    }

    public function test_the_genre_menu_searches_inside_itself_over_ten_genres(): void
    {
        $ids = [];
        foreach (range(1, 11) as $index) {
            $this->genre(300 + $index, 'Genre '.chr(64 + $index));
            $ids[] = 300 + $index;
        }
        $this->tag($this->audio('Everything'), ['genre' => 'many'], $ids);

        $this->page('/audio')->assertOk()->assertSee('placeholder="Search genres"', false);
    }

    public function test_a_year_or_genres_without_unknown_read_the_primary_key_and_otherwise_the_release_indexes(): void
    {
        foreach ([self::LOSSLESS, self::LOSSLESS, self::LOSSLESS, self::MP3] as $index => $category) {
            $this->audio('Release '.$index, ['categories_id' => $category]);
        }
        $list = app(AudioReleaseList::class);
        $index = static fn (array $filters): string => $list->readIndex(new AudioReleaseFilters(...$filters), []);
        $count = fn (array $filters): string => (fn (AudioReleaseFilters $filters): string => $this->countIndex($filters))->call($list, new AudioReleaseFilters(...$filters));
        $added = ['sort' => ReleaseSort::AddedNewest];
        $unknown = AudioReleaseFilters::GENRE_UNKNOWN;

        foreach ([[], ['genres' => [$unknown]], ['genres' => [self::ROCK, $unknown]]] as $releaseLed) {
            $label = json_encode($releaseLed, JSON_THROW_ON_ERROR);
            $this->assertSame('ix_releases_band_posted', $index($releaseLed), $label);
            $this->assertSame('ix_releases_band_added', $index([...$releaseLed, ...$added]), $label);
            $this->assertSame('ix_releases_band_cat_posted', $index([...$releaseLed, 'categories' => [self::MP3]]), $label);
            $this->assertSame('ix_releases_band_count', $count($releaseLed), $label);
            $this->assertSame('ix_releases_band_cat_posted', $count([...$releaseLed, 'categories' => [self::MP3], 'search' => 'x']), $label);
        }
        foreach ([['genres' => [self::ROCK]], ['genres' => [self::ROCK, self::METAL]], ['decades' => [1990]], ['yearFrom' => 2024], ['genres' => [$unknown], 'decades' => [1990]],
            ['genres' => [self::ROCK, $unknown], 'yearFrom' => 1998, 'yearTo' => 2001], ['genres' => [self::ROCK], 'decades' => [2000]]] as $tagLed) {
            $label = json_encode($tagLed, JSON_THROW_ON_ERROR);
            $this->assertSame('PRIMARY', $index($tagLed), $label);
            $this->assertSame('PRIMARY', $index(['categories' => [self::MP3], 'completion' => 95, 'search' => 'x', ...$added, ...$tagLed]), $label);
            $this->assertSame('PRIMARY', $count($tagLed), $label);
            $this->assertSame('PRIMARY', $count(['categories' => [self::MP3], 'completion' => 95, 'search' => 'x', ...$added, ...$tagLed]), $label);
        }
    }

    public function test_the_name_search_matches_the_release_name_or_the_tags_album_album_artist_or_performer_with_wildcards_literal(): void
    {
        $this->genre(self::ROCK, 'Rock');
        $this->tag($this->audio('xq7.scrambled', ['postdate' => '2026-09-01 00:00:00']), ['album' => 'Blades of Varn', 'recorded_year' => 2010, 'genre' => 'Rock'], [self::ROCK]);
        $this->audio('Blades.Fan.Mix', ['display_name' => 'Blades Fan Mix']);
        $this->audio('raw.one', ['display_name' => 'Shown Words', 'searchname' => 'hidden words']);
        $this->tag($this->audio('raw.two', ['categories_id' => self::LOSSLESS]), ['album_performer' => '100%_Real!Deal', 'recorded_year' => 2012, 'genre' => 'Rock'], [self::ROCK]);
        $this->tag($this->audio('raw.three', ['categories_id' => self::MP3]), ['performer' => '100 Real Deal', 'recorded_year' => 2012]);
        $this->tag($this->release('Blades.Of.Varn.Ebook', ['categories_id' => self::EBOOK]), ['album' => 'Blades']);

        $this->assertListed('/audio?q=blades', ['Blades.Fan.Mix', 'xq7.scrambled']);
        $this->assertListed('/audio?q=hidden', []);
        $this->assertListed('/audio?q=shown', ['raw.one']);
        $this->assertListed('/audio?q=%25_Real', ['raw.two']);
        $this->assertListed('/audio?q=l!D', ['raw.two']);
        $this->assertListed('/audio?q=100', ['raw.three', 'raw.two']);
        $this->assertListed('/audio?q=100&category[]='.self::MP3, ['raw.three']);
        $this->assertListed('/audio?q=100&genre[]='.self::ROCK, ['raw.two'], 'read 4');
        $this->assertListed('/audio?q=100&genre[]=unknown', ['raw.three'], 'read 2');
        $this->assertListed('/audio?q=100&genre[]=unknown&genre[]='.self::ROCK, ['raw.three', 'raw.two'], 'read 3');
        $this->assertListed('/audio?q=blades&decade[]=2010', ['xq7.scrambled'], 'read 5');
        $this->assertListed('/audio?q=real&year_from=2012&genre[]=unknown', ['raw.three'], 'read 6');
        $this->assertListed('/audio?q=real&year_from=2012&genre[]=unknown&genre[]='.self::ROCK, ['raw.three', 'raw.two'], 'read 7');
        $this->assertListed('/audio?q=real&year_from=2012&genre[]='.self::ROCK, ['raw.two'], 'read 4 with a year');
        $this->page('/audio?_fragment=list&q=100')->assertSee('Showing 1–2 of 2 releases');
        $this->page('/audio?q=zzqqxx')->assertSee('No releases match release names, artists or albums containing “zzqqxx”.', false);
        $this->page('/audio?q=zzqqxx&category[]='.self::LOSSLESS)->assertSee('No releases match Lossless · release names, artists or albums containing “zzqqxx”.', false)
            ->assertDontSee('<table', false);
    }

    public function test_the_empty_result_names_every_set_filter_in_the_prototypes_order(): void
    {
        $this->page('/audio')->assertOk()->assertSee('There are no audio releases yet.')->assertDontSee('<table', false);
        $this->genre(self::ROCK, 'Rock');
        $this->genre(self::SYNTH, self::SYNTH_NAME);
        $this->tag($this->audio('Album.MP3', ['categories_id' => self::MP3, 'completion' => 90]), ['genre' => 'Rock; '.self::SYNTH_NAME], [self::ROCK, self::SYNTH]);
        $this->audio('No.Tag', ['categories_id' => self::LOSSLESS]);
        Cache::flush();

        $this->page('/audio?category[]='.self::LOSSLESS.'&category[]='.self::MP3.'&genre[]='.self::SYNTH.'&genre[]=unknown&genre[]='.self::ROCK.'&decade[]=1980&decade[]=1990&completion=100&q=%20Lost%20')
            ->assertSee('No releases match MP3 or Lossless · Rock or Synth-pop, Disco or Unknown · 1990s or 1980s · 100% complete · release names, artists or albums containing “Lost”.', false);
        $this->page('/audio?category=exclude-other&genre[]='.self::ROCK.'&year_from=1950&year_to=1959')->assertSee('No releases match Rock · 1950–1959.', false);
        $this->audio('Other.Upload', ['categories_id' => self::AUDIO_OTHER]);
        Cache::flush();
        $this->page('/audio?category=exclude-other&genre[]='.self::ROCK.'&year_from=1950&year_to=1959')->assertSee('No releases match excluding Other · Rock · 1950–1959.', false);
    }

    public function test_each_row_shows_the_no_cover_tile_the_music_line_and_the_genres(): void
    {
        $this->genre(self::ROCK, 'Rock');
        $this->genre(self::SYNTH, self::SYNTH_NAME);
        $this->genre(self::METAL, 'Metal');
        $this->tag($this->audio('Full.Tags', ['categories_id' => self::LOSSLESS, 'size' => 2.5 * 1073741824]),
            ['album' => 'Night Drive', 'album_performer' => 'The Midnight', 'performer' => 'Track Singer', 'recorded_year' => 1999, 'genre' => self::SYNTH_NAME.' / Rock; Metal'],
            [self::SYNTH, self::ROCK, self::METAL]);
        $this->tag($this->audio('Performer.Only'), ['performer' => 'Solo Act', 'album_performer' => '', 'recorded_year' => 1984, 'genre' => 'Unknown']);
        $this->tag($this->audio('Album.Only'), ['album' => 'Untitled Album', 'recorded_year' => 2001, 'genre' => 'Rock'], [self::ROCK]);
        $this->tag($this->audio('No.Year'), ['album' => 'Timeless', 'album_performer' => 'Band', 'genre' => 'rock / UNKNOWN'], [self::ROCK]);
        $this->tag($this->audio('Year.Only'), ['recorded_year' => 2020, 'genre' => '']);
        $this->audio('No.Tag');
        $fullTags = (int) DB::table('releases')->where('name', 'Full.Tags')->value('id');
        $cover = $this->cover($fullTags, '11111111-1111-4111-8111-111111111111');
        $this->cover((int) DB::table('releases')->where('name', 'Album.Only')->value('id'), '22222222-2222-4222-8222-222222222222', stored: false);

        $response = $this->page('/audio')->assertOk()
            ->assertSee('<table class="tv-feed is-shelf"', false)
            ->assertSee('<colgroup><col class="tv-col-select"><col class="tv-col-cover"><col><col class="tv-col-category"><col class="tv-col-genre"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>', false)
            ->assertSeeInOrder(['<th colspan="2">Release</th>', '<th class="tv-category">Category</th>', '<th class="tv-genre">Genre</th>', '<th class="tv-num">Size</th>', '<th class="tv-num">Posted</th>'], false);
        $this->assertSame(7, substr_count(strstr((string) $response->getContent(), '</thead>', true), '<th') - 1, 'seven headers over eight columns');

        $noCover = static fn (string $name): string => '/<td class="tv-art is-square">\s*<a class="tv-placeholder is-no-picture" href="'.preg_quote(e(route('details', md5($name))), '/')
            .'" tabindex="-1" aria-hidden="true">\s*<i class="fas fa-compact-disc" aria-hidden="true"><\/i>\s*<span class="tv-placeholder-label">No cover<\/span>\s*<\/a>\s*<\/td>/';
        $full = $this->rowOf($response, 'Full.Tags');
        $this->assertSame(8, substr_count($full, '<td'));
        $details = e(route('details', md5('Full.Tags')));
        $this->assertMatchesRegularExpression('/<td class="tv-art is-square">\s*<a href="'.preg_quote($details, '/').'" tabindex="-1" aria-hidden="true"><img src="'.preg_quote(e($cover), '/')
            .'" alt="" loading="eager"><\/a>\s*<\/td>/', $full, 'the stored cover of the accepted album');
        $this->assertMatchesRegularExpression('/<a class="tv-release-name" href="'.preg_quote($details, '/').'" title="Full.Tags" data-part="release name">Full.Tags<\/a>\s*<span class="tv-game-line">The Midnight – Night Drive · 1999<\/span>/', $full);
        $joined = e(self::SYNTH_NAME.', Rock, Metal');
        $this->assertStringContainsString('<td class="tv-genre" title="'.$joined.'"><span>'.$joined.'</span></td>', $full, 'position order, the comma name intact');
        $this->assertStringContainsString('<td class="tv-category" title="Audio &gt; Lossless">Lossless</td>', $full);
        $this->assertStringContainsString('<td class="tv-num tv-size">2.50 GB</td>', $full);
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($full));

        $performer = $this->rowOf($response, 'Performer.Only');
        $this->assertStringContainsString('<span class="tv-game-line">Solo Act · 1984</span>', $performer, 'an empty album artist counts as missing');
        $this->assertStringContainsString('<td class="tv-genre" title="Unknown"><span>Unknown</span></td>', $performer, 'a tag genre reading only Unknown');
        $album = $this->rowOf($response, 'Album.Only');
        $this->assertMatchesRegularExpression($noCover('Album.Only'), $album, 'an accepted album with no stored cover');
        $this->assertStringContainsString('<span class="tv-game-line">Untitled Album · 2001</span>', $album);
        $this->assertStringContainsString('<td class="tv-genre" title="Rock"><span>Rock</span></td>', $album);
        $noYear = $this->rowOf($response, 'No.Year');
        $this->assertStringContainsString('<span class="tv-game-line">Band – Timeless</span>', $noYear);
        $this->assertStringContainsString('<td class="tv-genre" title="Rock"><span>Rock</span></td>', $noYear, 'a genre row wins over the Unknown part');
        foreach (['Year.Only', 'No.Tag'] as $name) {
            $row = $this->rowOf($response, $name);
            $this->assertMatchesRegularExpression($noCover($name), $row, $name);
            $this->assertStringNotContainsString('tv-game-line', $row, $name.': no album and no artist, no line');
            $this->assertStringContainsString('<td class="tv-genre"><span>—</span></td>', $row, $name);
        }
        $table = (string) strstr((string) strstr((string) $response->getContent(), '<tbody>'), '</tbody>', true);
        $this->assertSame(1, substr_count($table, '<img'), 'only the stored cover');
        $this->assertStringNotContainsString('tv-show-line', $table);
        $this->assertSame(6, substr_count($table, 'class="tv-art is-square"'), 'every row has the cover slot');
        $this->assertSame(5, substr_count($table, '>No cover<'), 'every row without a stored cover shows the No cover tile');

        // what the release details issue reads from a row: the file count, the category path, no picture
        [$withCount, $withoutCount] = app(AudioReleaseRows::class)->load([$this->audio('Counted', ['totalpart' => 37]), $this->audio('Uncounted', ['totalpart' => 0])], false);
        $this->assertSame(['37', '—', 'Audio > MP3', null, null], [$withCount->filesShown(), $withoutCount->filesShown(), $withCount->categoryPath, $withCount->preview, $withCount->sample]);
    }

    public function test_a_release_with_a_playable_preview_shows_the_listen_chip_last_before_the_group_and_poster(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.sounds.mp3']);
        $preview = ['has_preview' => 1, 'preview_extension' => 'mp3', 'preview_mime' => 'audio/mpeg', 'preview_seconds' => 30];
        $this->tag($this->audio('Timed.Preview', ['nfostatus' => 1, 'groups_id' => 1, 'completion' => 90]), [...$preview, 'track_name' => 'Opening Song', 'performer' => 'Singer',
            'album' => 'The Album', 'album_performer' => 'Album Artist']);
        $this->tag($this->audio('Album.Title'), [...$preview, 'preview_extension' => 'flac', 'preview_mime' => 'audio/flac', 'preview_seconds' => null, 'album' => 'Only Album',
            'album_performer' => 'Only Album Artist']);
        $this->tag($this->audio('No.Track'), [...$preview, 'album' => null, 'performer' => 'Lonely Artist']);
        $this->tag($this->audio('Unserved.Extension'), [...$preview, 'preview_extension' => 'wma']);
        $this->tag($this->audio('Not.Previewed'), [...$preview, 'has_preview' => 0]);
        $this->audio('No.Tag', ['haspreview' => 1, 'jpgstatus' => 1, 'videostatus' => 1]);
        $cover = $this->cover((int) DB::table('releases')->where('name', 'Timed.Preview')->value('id'), '11111111-1111-4111-8111-111111111111');

        $response = $this->page('/audio')->assertOk();
        $timed = $this->rowOf($response, 'Timed.Preview');
        $this->assertStringContainsString('data-audio-seconds="30" data-audio-cover="'.e($cover).'"', $timed, 'the Listen dialog shows the row\'s cover');
        $this->assertMatchesRegularExpression('/<button [^>]*data-chip-variant="clip"[^>]*>\s*Listen\s*<\/button>/', $timed, 'the word Listen, no icon');
        $this->assertStringContainsString('chip-tone-clip', $timed);
        $this->assertStringContainsString('class="release-chip chip-tone-clip listen-badge"', $timed);
        $this->assertStringContainsString('data-audio-url="'.route('preview.audio', md5('Timed.Preview')).'" data-audio-type="audio/mpeg" data-audio-title="Opening Song" data-audio-artist="Singer" data-audio-seconds="30"', $timed);
        $this->assertStringContainsString('data-release-display-name="Timed.Preview"', $timed);
        $this->assertStringContainsString('title="Play the 30-second preview"', $timed);
        $this->assertMatchesRegularExpression('/completion chip.*nfo-badge.*data-part="Listen chip"[^>]*>\s*Listen\s*<\/button>\s*<span class="tv-origin-pair">/s', $timed);

        $album = $this->rowOf($response, 'Album.Title');
        $this->assertStringContainsString('data-audio-type="audio/flac" data-audio-title="Only Album" data-audio-artist="Only Album Artist"', $album, 'the album without a track title');
        $this->assertStringContainsString('title="Play the preview"', $album, 'no stored length');
        $this->assertStringNotContainsString('data-audio-cover', $album, 'no cover, no attribute');
        $this->assertStringNotContainsString('data-audio-seconds', $album);
        $noTrack = $this->rowOf($response, 'No.Track');
        $this->assertStringNotContainsString('data-audio-title', $noTrack, 'no track title and no album');
        $this->assertStringContainsString('data-audio-artist="Lonely Artist"', $noTrack);
        foreach (['Unserved.Extension', 'Not.Previewed', 'No.Tag'] as $name) {
            $this->assertStringNotContainsString('listen-badge', $this->rowOf($response, $name), $name);
        }
        $table = (string) strstr((string) strstr((string) $response->getContent(), '<tbody>'), '</tbody>', true);
        foreach (['preview-badge', 'sample-badge', 'clip-badge', '>Preview<', '>Sample<', '>Clip<'] as $chip) {
            $this->assertStringNotContainsString($chip, $table, 'no row shows '.$chip);
        }
        $shared = (string) file_get_contents(resource_path('views/tv/partials/release-chip-list.blade.php'));
        $this->assertStringNotContainsString('Listen', $shared, 'the release page\'s own chip line never shows Listen');
        $this->assertStringNotContainsString('listen-badge', $shared);

        // the Listen dialog's markup: titled Listen, the release name under it, 560 px by class, no footer
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<div x-data="tvListenDialog">\s*<div data-modal-dialog[^>]*>\s*<div class="tv-dialog is-listen" role="dialog" aria-modal="true" aria-labelledby="listen-dialog-title"/', $html);
        preg_match('/<div x-data="tvListenDialog">.*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $dialog);
        $this->assertStringContainsString('<h2 id="listen-dialog-title" x-bind:data-part="partWhenOpen(\'dialog title\')">Listen</h2>', $dialog[0] ?? '');
        $this->assertStringContainsString('<p><span x-text="releaseName"></span></p>', $dialog[0] ?? '');
        $this->assertStringContainsString('x-ref="player"', $dialog[0] ?? '');
        $this->assertStringNotContainsString('tv-dialog-footer', $dialog[0] ?? '');
    }

    public function test_a_page_of_fifty_releases_with_tags_genres_and_previews_runs_as_many_queries_as_a_page_of_one(): void
    {
        $this->genre(self::ROCK, 'Rock');
        $this->genre(self::METAL, 'Metal');
        $tag = static fn (int $index): array => ['album' => 'Album '.$index, 'album_performer' => 'Artist '.$index, 'recorded_year' => 1990 + $index % 30, 'genre' => 'Rock; Metal',
            'has_preview' => 1, 'preview_extension' => 'mp3', 'preview_mime' => 'audio/mpeg', 'preview_seconds' => 30];
        $this->tag($first = $this->audio('Release 1'), $tag(1), [self::ROCK, self::METAL]);
        $this->cover($first, sprintf('11111111-1111-4111-8111-%012d', 1));
        $one = $this->queriesOf('/audio');
        foreach (range(2, 60) as $index) {
            $this->tag($id = $this->audio('Release '.$index), $tag($index), $index % 2 === 0 ? [self::ROCK] : [self::METAL, self::ROCK]);
            $this->cover($id, sprintf('11111111-1111-4111-8111-%012d', $index));
        }
        Cache::flush();
        $fifty = $this->queriesOf('/audio');

        $listed = $this->page('/audio');
        $this->assertSame(50, count($this->listedIds($listed)));
        $this->assertSame(50, substr_count((string) $listed->getContent(), 'loading="eager"'), 'every row shows its cover');
        $this->assertSame($one, $fifty);
    }

    public function test_the_music_menus_category_and_sort_are_remembered_under_audio_and_the_name_search_never_is(): void
    {
        $this->genre(self::ROCK, 'Rock');
        $this->tag($this->audio('Posted early added late', ['postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']), ['genre' => 'Rock', 'recorded_year' => 2010], [self::ROCK]);
        $this->tag($this->audio('Posted late added early', ['postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']), ['genre' => 'Rock', 'recorded_year' => 2010], [self::ROCK]);
        $user = $this->user = $this->browserUser();

        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'audio', 'sort' => 'newest'])->assertOk();
        $this->assertSame('newest', User::query()->findOrFail($user->id)->releaseViewPreferences('audio')['sort']);
        $this->page('/audio', User::query()->findOrFail($user->id))->assertSeeInOrder(['Posted early added late', 'Posted late added early'])
            ->assertSee('<th class="tv-num">Added</th>', false);
        $this->postJson('/profile/update-view', ['root' => 'audio', 'sort' => 'grabs'])->assertUnprocessable();
        foreach (['other', 'all'] as $root) {
            $this->postJson('/profile/update-view', ['root' => $root, 'sort' => 'posted'])->assertUnprocessable();
        }

        $this->page('/audio?_fragment=list&category[]='.self::LOSSLESS.'&genre[]='.self::ROCK.'&decade[]=2010&completion=95&q=Posted', User::query()->findOrFail($user->id))->assertOk();
        $this->assertSame(['category' => [self::LOSSLESS], 'completion' => 95, 'genre' => [self::ROCK], 'decade' => [2010]], $this->remembered($user, 'audio'));
        $remembered = ['category' => [self::LOSSLESS], 'completion' => 95, 'genre' => [self::ROCK], 'decade' => [2010]];
        $this->page('/audio', User::query()->findOrFail($user->id))->assertRedirect(route('audio.releases', $remembered));
        $this->page('/audio?q=late', User::query()->findOrFail($user->id))->assertRedirect(route('audio.releases', [...$remembered, 'q' => 'late']));
        $this->page('/audio?_fragment=list&year_from=2009&year_to=2011', User::query()->findOrFail($user->id))->assertOk();
        $this->assertSame(['year_from' => 2009, 'year_to' => 2011], $this->remembered($user, 'audio'));
        $this->page('/audio?clear=1', User::query()->findOrFail($user->id))->assertRedirect(route('audio.releases'));
        $this->assertSame([], $this->remembered($user, 'audio') ?? []);
    }

    public function test_the_list_needs_the_audio_permission_and_the_old_audio_pages_are_not_found(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view audio');
        $this->page('/audio', $user)->assertForbidden()->assertSee('Audio is hidden in your account preferences.');
        $this->page('/browse/audio', $user)->assertForbidden()->assertSee('Audio is hidden in your account preferences.');

        $this->flushSession();
        $this->page('/Audio')->assertNotFound();
        $this->page('/browse/audio')->assertNotFound();
        $this->assertSame('browse', app('router')->getRoutes()->match(request()->create('/browse/audio'))->getName());
        $this->assertSame('browse', app('router')->getRoutes()->match(request()->create('/browse/music'))->getName());
        $this->assertSame('audio.releases', app('router')->getRoutes()->match(request()->create('/audio'))->getName());
    }

    public function test_the_audio_list_renders_no_retired_address(): void
    {
        ProductionTables::fromAuthority()->create('musicinfo');
        DB::table('musicinfo')->insert(['id' => 12, 'title' => 'An Album', 'artist' => 'The Artist', 'year' => '2021', 'cover' => 1]);
        $this->genre(self::ROCK, 'Rock');
        $this->tag($this->audio('Tagged.Album', ['musicinfo_id' => 12]), ['album' => 'Night Drive', 'album_performer' => 'The Midnight', 'recorded_year' => 1999, 'genre' => 'Rock'], [self::ROCK]);

        $html = (string) $this->page('/audio')->assertOk()->assertSee('Tagged.Album')->getContent();
        $this->assertNoRetiredAddress($html, 'The Audio releases list');
    }

    public function test_the_header_sends_audio_to_the_new_list_and_marks_it_current(): void
    {
        $this->audio('An audio release');
        $response = $this->page('/audio')->assertOk()
            ->assertSee('href="'.route('audio.releases').'" class="public-menu-root">All Audio</a>', false)
            ->assertDontSee('href="'.url('/browse/audio').'"', false);
        foreach ([self::MP3, self::VIDEO, self::AUDIOBOOK, self::LOSSLESS, self::PODCAST, self::FOREIGN, self::AUDIO_OTHER] as $category) {
            $response->assertSee('href="'.e(route('audio.releases', ['category' => [$category]])).'"', false);
        }
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-audio"\s+aria-current="true"/', (string) $response->getContent());
        $this->assertSame(1, substr_count((string) $response->getContent(), 'aria-current="true"'));
    }

    /**
     * A catalogue of 300 audio releases over twelve tag profiles: no tag row, tags with no genre,
     * one genre, several genres, a tag of only Unknown, a genre name holding a comma, years from
     * the 1940s to the 2020s and none; three categories and four completions; dates collide so the
     * id breaks ties. A Rock release outside the band is left out of every list.
     *
     * @return array<int, array{name: string, category: int, completion: int, posted: string, added: string, tag: ?array{album: ?string, album_performer: ?string, performer: ?string, year: ?int, genres: list<int>}}>
     */
    private function catalogue(): array
    {
        $this->genre(self::ROCK, 'Rock');
        $this->genre(self::METAL, 'Metal');
        $this->genre(self::SYNTH, self::SYNTH_NAME);
        $this->genre(self::JAZZ, 'Jazz');
        $profiles = [
            null,
            ['album' => 'Night Drive', 'album_performer' => null, 'performer' => 'The Blades', 'year' => 1998, 'genre' => null, 'genres' => []],
            ['album' => 'Stone', 'album_performer' => 'Granite', 'performer' => null, 'year' => 1987, 'genre' => 'Rock', 'genres' => [self::ROCK]],
            ['album' => 'Forge', 'album_performer' => 'Iron Choir', 'performer' => 'Smith', 'year' => 2004, 'genre' => 'Rock; Metal', 'genres' => [self::ROCK, self::METAL]],
            ['album' => 'Undated', 'album_performer' => null, 'performer' => null, 'year' => null, 'genre' => 'Metal; Rock', 'genres' => [self::METAL, self::ROCK]],
            ['album' => 'Mystery', 'album_performer' => null, 'performer' => 'Nobody', 'year' => 2015, 'genre' => 'Unknown', 'genres' => []],
            ['album' => 'Neon', 'album_performer' => 'Retro Choir', 'performer' => null, 'year' => 1999, 'genre' => self::SYNTH_NAME.' / Rock', 'genres' => [self::SYNTH, self::ROCK]],
            ['album' => 'Blades of Brass', 'album_performer' => 'Big Band', 'performer' => null, 'year' => 1944, 'genre' => 'Jazz', 'genres' => [self::JAZZ]],
            ['album' => null, 'album_performer' => null, 'performer' => 'Duke', 'year' => 2024, 'genre' => '', 'genres' => []],
            ['album' => 'Mixed', 'album_performer' => null, 'performer' => 'Various', 'year' => 2010, 'genre' => 'Metal / Unknown', 'genres' => [self::METAL]],
            null,
            ['album' => 'Quiet', 'album_performer' => null, 'performer' => 'Hush', 'year' => 2001, 'genre' => 'Rock', 'genres' => [self::ROCK]],
        ];
        $releases = [];
        foreach (range(1, 300) as $index) {
            $profile = $profiles[$index % 12];
            $release = [
                'name' => 'Audio Release '.$index.($index % 17 === 0 ? ' Blades' : ''),
                'category' => [self::LOSSLESS, self::MP3, self::AUDIO_OTHER][$index % 3],
                'completion' => [100, 96, 80, 99][$index % 4],
                'posted' => Carbon::parse('2026-01-01')->addHours(intdiv($index, 2))->toDateTimeString(),
                'added' => Carbon::parse('2026-03-01')->subHours(intdiv($index, 3))->toDateTimeString(),
                'tag' => $profile === null ? null : ['album' => $profile['album'], 'album_performer' => $profile['album_performer'], 'performer' => $profile['performer'],
                    'year' => $profile['year'], 'genres' => $profile['genres']],
            ];
            $id = $this->audio($release['name'], ['categories_id' => $release['category'], 'completion' => $release['completion'], 'postdate' => $release['posted'],
                'adddate' => $release['added']]);
            if ($profile !== null) {
                $this->tag($id, ['album' => $profile['album'], 'album_performer' => $profile['album_performer'], 'performer' => $profile['performer'],
                    'recorded_year' => $profile['year'], 'genre' => $profile['genre']], $profile['genres']);
            }
            $releases[$id] = $release;
        }
        $this->tag($this->release('Rock.Ebook', ['categories_id' => self::EBOOK, 'postdate' => '2026-02-01 00:00:00']), ['album' => 'Blades', 'recorded_year' => 1998, 'genre' => 'Rock'], [self::ROCK]);

        return $releases;
    }

    /**
     * The ids the filters keep, in the sort, read straight from the catalogue.
     *
     * @param  array<int, array{name: string, category: int, completion: int, posted: string, added: string, tag: ?array{album: ?string, album_performer: ?string, performer: ?string, year: ?int, genres: list<int>}}>  $releases
     * @return list<int>
     */
    private function expected(array $releases, AudioReleaseFilters $filters): array
    {
        $kept = array_filter($releases, static function (array $release) use ($filters): bool {
            $tag = $release['tag'];
            $contains = static fn (?string $value): bool => $value !== null && stripos($value, $filters->search) !== false;
            if (($filters->categories !== [] && ! in_array($release['category'], $filters->categories, true))
                || ($filters->completion !== null && $release['completion'] < $filters->completion)
                || ($filters->search !== '' && ! $contains($release['name']) && ($tag === null || (! $contains($tag['album']) && ! $contains($tag['album_performer']) && ! $contains($tag['performer']))))) {
                return false;
            }
            $genres = $tag['genres'] ?? [];
            if ($filters->genres !== [] && array_intersect($genres, $filters->genreIds()) === [] && ! ($filters->genreUnknown() && $genres === [])) {
                return false;
            }
            if ($filters->anyYear()) {
                $year = $tag['year'] ?? null;
                if ($year === null) {
                    return false;
                }
                $bounds = $filters->yearBounds();

                return $bounds !== null ? $year >= $bounds[0] && $year <= $bounds[1]
                    : array_filter($filters->decades, static fn (int $decade): bool => $year >= $decade && $year < $decade + 10) !== [];
            }

            return true;
        });
        $date = $filters->sortsByAdded() ? 'added' : 'posted';
        $ids = array_keys($kept);
        usort($ids, static fn (int $a, int $b): int => $filters->ascending() ? [$kept[$a][$date], $a] <=> [$kept[$b][$date], $b] : [$kept[$b][$date], $b] <=> [$kept[$a][$date], $a]);

        return $ids;
    }

    /** The queries of the second open of a page, its menus and counts already cached. */
    private function queriesOf(string $uri): int
    {
        $this->page($uri)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function remembered(?User $user, string $root): mixed
    {
        return User::query()->findOrFail($user?->id)->releaseViewPreferences($root)['filters'] ?? null;
    }

    /** @param list<string> $names */
    private function assertListed(string $uri, array $names, string $message = ''): void
    {
        $this->assertSame($names, $this->listedNames($this->page($uri)->assertOk()), $uri.' '.$message);
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

    /** A filter cell's text as check.mjs reads it (textContent): "Genre: 2 chosen". */
    private function cellText(TestResponse $response, string $name): string
    {
        $this->assertMatchesRegularExpression('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent());
        preg_match('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent(), $match);

        return html_entity_decode(trim((string) preg_replace('/\s+/', ' ', strip_tags($match[1]))), ENT_QUOTES);
    }

    /**
     * The release's current accepted release group decision and, when stored, its cover file and
     * stored lookup; returns the cover's URL.
     */
    private function cover(int $releasesId, string $groupId, bool $stored = true): string
    {
        $hash = hash('sha256', 'evidence '.$releasesId);
        $evidence = DB::table('release_audio_evidence')->insertGetId(['releases_id' => $releasesId, 'revision' => 1, 'evidence_hash' => $hash, 'schema_version' => 1,
            'provenance' => 'test', 'release_snapshot' => '{}', 'nzb_manifest' => '[]', 'archive_manifest' => '[]', 'sidecar_manifest' => '[]', 'captured_at' => now()]);
        DB::table('release_music_identifications')->insert(['releases_id' => $releasesId, 'release_audio_evidence_id' => $evidence, 'evidence_hash' => $hash,
            'state' => 'accepted_release_group', 'band' => 'strong', 'musicbrainz_release_group_id' => $groupId, 'reasons' => '[]', 'feature_contributions' => '[]',
            'algorithm_version' => (string) config('music-identity.algorithm_version'), 'resolver_version' => 'r1', 'normalizer_version' => 'n1', 'scorer_version' => 's1',
            'policy_version' => 'p1', 'decided_at' => now()]);
        if (! $stored) {
            return '';
        }
        DB::table('music_cover_art_lookups')->insert(['kind' => 'release-group', 'musicbrainz_id' => $groupId, 'outcome' => 'stored', 'image_musicbrainz_id' => $groupId,
            'attempt_count' => 1, 'checked_at' => now()]);
        $directory = config('nntmux_settings.covers_path').'/audio';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/'.$groupId.'.jpg', 'jpg');

        return url('/covers/audio/'.$groupId.'.jpg');
    }

    private function genre(int $id, string $name): void
    {
        DB::table('audio_genres')->insert(['id' => $id, 'name' => $name]);
    }

    /**
     * The release's tag row and its genre rows in order, as the audio processor writes them through AudioGenres.
     *
     * @param  array<string, mixed>  $tag
     * @param  list<int>  $genres
     */
    private function tag(int $releasesId, array $tag = [], array $genres = []): void
    {
        DB::table('release_audio_tags')->insert(['releases_id' => $releasesId, ...$tag]);
        foreach ($genres as $position => $genre) {
            DB::table('release_audio_genres')->insert(['releases_id' => $releasesId, 'audio_genres_id' => $genre, 'position' => $position]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function audio(string $name, array $attributes = []): int
    {
        return $this->release($name, ['categories_id' => self::MP3, 'passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0,
            ...$attributes]);
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }
}
