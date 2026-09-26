<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\TvShowDetails;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** Show details come from TMDB on a show's first match and on later matches at most once a day. */
final class TvShowDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tables = ProductionTables::fromAuthority();
        $tables->create('videos', ['id', 'type', 'title', 'tmdb', 'tvdb', 'imdb']);
        $tables->create('tv_info');
        $tables->create('networks');
        $tables->create('people');
        $tables->create('genres');
        $tables->create('video_genres');
        $tables->create('video_people');

        config(['tmdb.api_key' => 'test-key', 'tmdb.retry_times' => 1, 'tmdb.retry_delay' => 0]);
        Http::preventStrayRequests();
        Sleep::fake();
        Carbon::setTestNow('2026-09-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_first_refresh_stores_every_detail(): void
    {
        $this->insertShow(1, tmdb: 200, publisher: 'Home Box Office');
        Http::fake(['*tv/200?*' => Http::response($this->show())]);

        $this->details()->refreshIfDue(1);

        $info = DB::table('tv_info')->where('videos_id', 1)->first();
        $this->assertSame('en', $info->original_language);
        $this->assertSame(TvShowDetails::STATUS_ENDED, (int) $info->status);
        $this->assertSame('TV-MA', $info->content_rating_us);
        $this->assertSame('2008-01-20', $info->premiered);
        $this->assertSame('HBO', DB::table('networks')->where('id', $info->networks_id)->value('name'));
        $this->assertSame('2026-09-25 12:00:00', $info->details_refreshed_at);
        $this->assertSame('Home Box Office', $info->publisher);
        $this->assertSame(['Children', 'Drama', 'Fantasy', 'Sci-Fi'], $this->genreTitles(1));
        $this->assertSame(['Bryan', 'Aaron'], $this->castNames(1));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'append_to_response=content_ratings%2Ccredits'));
        Sleep::assertSleptTimes(1);
    }

    public function test_a_refresh_within_24_hours_does_not_fetch_and_one_after_does(): void
    {
        $this->insertShow(1, tmdb: 200);
        Http::fake(['*tv/200?*' => Http::response($this->show())]);

        $this->details()->refreshIfDue(1);
        Carbon::setTestNow('2026-09-26 11:59:59');
        $this->details()->refreshIfDue(1);
        Http::assertSentCount(1);

        Carbon::setTestNow('2026-09-26 12:00:01');
        $this->details()->refreshIfDue(1);
        Http::assertSentCount(2);
        $this->assertSame('2026-09-26 12:00:01', DB::table('tv_info')->where('videos_id', 1)->value('details_refreshed_at'));
    }

    public function test_a_tmdb_failure_changes_nothing(): void
    {
        $this->insertShow(1, tmdb: 200, publisher: 'HBO');
        Http::fake(['*tv/200?*' => Http::sequence()
            ->push($this->show())
            ->push(['status_message' => 'down'], 500)
            ->push(['status_message' => 'not found'], 404)]);
        $this->details()->refreshIfDue(1);
        $before = $this->snapshot(1);

        Carbon::setTestNow('2026-09-28 12:00:00');
        $this->details()->refreshIfDue(1);
        $this->details()->refreshIfDue(1);

        Http::assertSentCount(3);
        $this->assertSame($before, $this->snapshot(1));
        $this->assertSame('2026-09-25 12:00:00', DB::table('tv_info')->where('videos_id', 1)->value('details_refreshed_at'));
    }

    public function test_a_show_with_no_resolvable_id_is_stamped_and_not_retried_within_a_day(): void
    {
        $this->insertShow(1, tvdb: 81189, imdb: '0903747');
        Http::fake(['*find/*' => Http::response(['tv_results' => []])]);

        $this->details()->refreshIfDue(1);
        $this->details()->refreshIfDue(1);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'find/81189') && $request['external_source'] === 'tvdb_id');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'find/tt0903747') && $request['external_source'] === 'imdb_id');
        $this->assertSame('2026-09-25 12:00:00', DB::table('tv_info')->where('videos_id', 1)->value('details_refreshed_at'));
        $this->assertSame(0, DB::table('video_genres')->count());
    }

    public function test_absent_ids_are_not_looked_up(): void
    {
        $this->insertShow(1, tmdb: 0, tvdb: 0, imdb: '0');
        Http::fake();

        $this->details()->refreshIfDue(1);

        Http::assertNothingSent();
        $this->assertSame('2026-09-25 12:00:00', DB::table('tv_info')->where('videos_id', 1)->value('details_refreshed_at'));
    }

    public function test_the_id_resolves_through_tvdb_then_imdb(): void
    {
        $this->insertShow(1, tvdb: 81189, imdb: 'tt0903747');
        Http::fake([
            '*find/81189*' => Http::response(['tv_results' => []]),
            '*find/tt0903747*' => Http::response(['tv_results' => [['id' => 1396]]]),
            '*tv/1396?*' => Http::response($this->show()),
        ]);

        $this->details()->refreshIfDue(1);

        $this->assertSame(['find/81189', 'find/tt0903747', 'tv/1396'], $this->sentPaths());
        $this->assertSame('en', DB::table('tv_info')->where('videos_id', 1)->value('original_language'));
        Sleep::assertSleptTimes(3);
    }

    public function test_nothing_is_read_or_written_without_an_api_key(): void
    {
        config(['tmdb.api_key' => '']);
        $this->insertShow(1, tmdb: 200);
        Http::fake();

        $this->details()->refreshIfDue(1);

        Http::assertNothingSent();
        $this->assertNull(DB::table('tv_info')->where('videos_id', 1)->value('details_refreshed_at'));
    }

    public function test_cast_is_the_first_twelve_distinct_people_in_tmdb_order(): void
    {
        $this->insertShow(1, tmdb: 200);
        DB::table('people')->insert(['id' => 50, 'name' => 'Existing Spelling', 'tmdb_id' => 103]);
        $cast = [['name' => 'No TMDB id']];
        foreach ([101, 102, 101, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113] as $id) {
            $cast[] = ['id' => $id, 'name' => 'Actor '.$id];
        }
        Http::fake(['*tv/200?*' => Http::response($this->show(['credits' => ['cast' => $cast]]))]);

        $this->details()->refreshIfDue(1);

        $rows = DB::table('video_people')->join('people', 'people.id', '=', 'video_people.people_id')
            ->where('videos_id', 1)->orderBy('position')->get(['position', 'tmdb_id', 'people.id']);
        $this->assertSame(range(0, 11), $rows->pluck('position')->map(intval(...))->all());
        $this->assertSame([101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112], $rows->pluck('tmdb_id')->map(intval(...))->all());
        $this->assertSame(50, (int) $rows[2]->id);
        $this->assertSame('Existing Spelling', DB::table('people')->where('id', 50)->value('name'));
    }

    public function test_a_second_refresh_replaces_genres_and_cast(): void
    {
        $this->insertShow(1, tmdb: 200);
        Http::fake(['*tv/200?*' => Http::sequence()
            ->push($this->show())
            ->push($this->show(['genres' => [['id' => 35, 'name' => 'Comedy']], 'credits' => ['cast' => [['id' => 9, 'name' => 'Solo']]]]))]);

        $this->details()->refreshIfDue(1);
        Carbon::setTestNow('2026-09-27 12:00:00');
        $this->details()->refreshIfDue(1);

        $this->assertSame(['Comedy'], $this->genreTitles(1));
        $this->assertSame(['Solo'], $this->castNames(1));
    }

    public function test_two_spellings_of_one_network_are_one_row_and_publisher_is_the_fallback(): void
    {
        $this->insertShow(1, tmdb: 201);
        $this->insertShow(2, tmdb: 202);
        $this->insertShow(3, tmdb: 203, publisher: '  hbo ');
        Http::fake([
            '*tv/201?*' => Http::response($this->show(['networks' => [['name' => 'HBO']]])),
            '*tv/202?*' => Http::response($this->show(['networks' => [['name' => ' Hbo'], ['name' => 'Max']]])),
            '*tv/203?*' => Http::response($this->show(['networks' => []])),
        ]);

        foreach ([1, 2, 3] as $videosId) {
            $this->details()->refreshIfDue($videosId);
        }

        $this->assertSame(['HBO'], DB::table('networks')->pluck('name')->all());
        $this->assertSame(1, DB::table('tv_info')->distinct()->count('networks_id'));
        $this->assertSame(3, DB::table('tv_info')->whereNotNull('networks_id')->count());
    }

    public function test_genres_are_tv_rows_and_unknown_names_are_stored_unchanged(): void
    {
        $this->insertShow(1, tmdb: 200);
        DB::table('genres')->insert(['id' => 7, 'title' => 'Drama', 'type' => Category::MUSIC_ROOT, 'disabled' => 0]);
        Http::fake(['*tv/200?*' => Http::response($this->show(['genres' => [
            ['name' => 'Drama'], ['name' => 'Action & Adventure'], ['name' => 'War & Politics'], ['name' => 'Telenovela'],
        ]]))]);

        $this->details()->refreshIfDue(1);

        $this->assertSame(['Action', 'Adventure', 'Drama', 'Telenovela', 'War'], $this->genreTitles(1));
        $this->assertSame(0, DB::table('video_genres')->where('genres_id', 7)->count());
        $this->assertSame(5, DB::table('genres')->where('type', Category::TV_ROOT)->count());
    }

    public function test_status_and_missing_values_fall_back_to_unknown(): void
    {
        $this->insertShow(1, tmdb: 200);
        Http::fake(['*tv/200?*' => Http::response(['id' => 200, 'status' => 'Rumored', 'first_air_date' => ''])]);

        $this->details()->refreshIfDue(1);

        $info = DB::table('tv_info')->where('videos_id', 1)->first();
        $this->assertSame(TvShowDetails::STATUS_UNKNOWN, (int) $info->status);
        $this->assertSame('', $info->original_language);
        $this->assertSame('', $info->content_rating_us);
        $this->assertNull($info->premiered);
        $this->assertNull($info->networks_id);
        $this->assertSame('2026-09-25 12:00:00', $info->details_refreshed_at);
    }

    public function test_a_show_without_a_tv_info_row_gets_one(): void
    {
        $this->insertShow(1, tmdb: 200, withInfo: false);
        Http::fake(['*tv/200?*' => Http::response($this->show())]);

        $this->details()->refreshIfDue(1);

        $info = DB::table('tv_info')->where('videos_id', 1)->first();
        $this->assertSame('', $info->summary);
        $this->assertSame('', $info->publisher);
        $this->assertSame('en', $info->original_language);
    }

    public function test_a_match_refreshes_outside_the_match_transaction(): void
    {
        ProductionTables::fromAuthority()->create('releases', ['id', 'guid', 'searchname', 'categories_id', 'videos_id', 'tv_episodes_id', 'tv_episode_lookup_attempted_at']);
        DB::table('releases')->insert(['id' => 9, 'guid' => 'guid-9', 'searchname' => 'Show.S01E01', 'categories_id' => Category::TV_HD, 'videos_id' => 0, 'tv_episodes_id' => 0]);
        $this->insertShow(1, tmdb: 200);
        Event::fake();
        $levels = [];
        Http::fake(['*tv/200?*' => function () use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response($this->show());
        }]);

        (new TmdbProvider)->setVideoIdFound(1, 9, 0);

        $this->assertSame([0], $levels);
        $this->assertSame(1, (int) DB::table('releases')->where('id', 9)->value('videos_id'));
        $this->assertSame('en', DB::table('tv_info')->where('videos_id', 1)->value('original_language'));
    }

    public function test_a_match_that_saves_nothing_does_not_refresh(): void
    {
        ProductionTables::fromAuthority()->create('releases', ['id', 'guid', 'videos_id', 'tv_episodes_id']);
        $this->insertShow(1, tmdb: 200);
        Http::fake();

        (new TmdbProvider)->setVideoIdFound(1, 404, 0);

        Http::assertNothingSent();
    }

    public function test_the_migration_fills_networks_from_publisher_and_reverses(): void
    {
        foreach (['networks', 'people', 'video_genres', 'video_people', 'tv_info'] as $table) {
            DB::statement('DROP TABLE '.$table);
        }
        ProductionTables::fromAuthority()->create('tv_info', ['videos_id', 'summary', 'publisher', 'localzone', 'image', 'banner']);
        DB::table('genres')->insert(['id' => 1, 'title' => 'Rock', 'type' => Category::MUSIC_ROOT, 'disabled' => 0]);
        DB::table('tv_info')->insert([
            ['videos_id' => 4, 'summary' => '', 'publisher' => ' hbo '],
            ['videos_id' => 2, 'summary' => '', 'publisher' => 'HBO'],
            ['videos_id' => 3, 'summary' => '', 'publisher' => '   '],
            ['videos_id' => 5, 'summary' => '', 'publisher' => 'Netflix'],
        ]);
        $migration = require database_path('migrations/2026_09_25_000000_add_show_details_to_tv_info.php');

        $migration->up();

        $this->assertSame(['HBO', 'Netflix'], DB::table('networks')->orderBy('id')->pluck('name')->all());
        $networks = DB::table('tv_info')->orderBy('videos_id')->pluck('networks_id', 'videos_id')->map(fn ($id) => $id === null ? null : (int) $id)->all();
        $this->assertSame([2 => 1, 3 => null, 4 => 1, 5 => 2], $networks);
        $this->assertSame(TvShowDetails::tvGenreTitles(), DB::table('genres')->where('type', Category::TV_ROOT)->orderBy('id')->pluck('title')->all());
        $this->assertCount(18, TvShowDetails::tvGenreTitles());

        $migration->down();

        $this->assertSame(['Rock'], DB::table('genres')->pluck('title')->all());
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('networks'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('tv_info', 'networks_id'));
    }

    private function details(): TvShowDetails
    {
        return app(TvShowDetails::class);
    }

    private function insertShow(int $id, int $tmdb = 0, int $tvdb = 0, string $imdb = '', string $publisher = '', bool $withInfo = true): void
    {
        DB::table('videos')->insert(['id' => $id, 'type' => 0, 'title' => 'Show '.$id, 'tmdb' => $tmdb, 'tvdb' => $tvdb, 'imdb' => $imdb]);
        if ($withInfo) {
            DB::table('tv_info')->insert(['videos_id' => $id, 'summary' => 'A show', 'publisher' => $publisher, 'localzone' => '']);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function show(array $overrides = []): array
    {
        return array_merge([
            'id' => 200,
            'name' => 'Breaking Bad',
            'original_language' => 'en',
            'status' => 'Ended',
            'first_air_date' => '2008-01-20',
            'networks' => [['id' => 49, 'name' => 'HBO']],
            'genres' => [['id' => 18, 'name' => 'Drama'], ['id' => 10765, 'name' => 'Sci-Fi & Fantasy'], ['id' => 10762, 'name' => 'Kids']],
            'content_ratings' => ['results' => [['iso_3166_1' => 'DE', 'rating' => '16'], ['iso_3166_1' => 'US', 'rating' => 'TV-MA']]],
            'credits' => ['cast' => [['id' => 17419, 'name' => 'Bryan'], ['id' => 84497, 'name' => 'Aaron']]],
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    private function genreTitles(int $videosId): array
    {
        return DB::table('video_genres')->join('genres', 'genres.id', '=', 'video_genres.genres_id')
            ->where('videos_id', $videosId)->where('genres.type', Category::TV_ROOT)
            ->orderBy('title')->pluck('title')->all();
    }

    /**
     * @return list<string>
     */
    private function castNames(int $videosId): array
    {
        return DB::table('video_people')->join('people', 'people.id', '=', 'video_people.people_id')
            ->where('videos_id', $videosId)->orderBy('position')->pluck('name')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $videosId): array
    {
        return [
            'info' => (array) DB::table('tv_info')->where('videos_id', $videosId)->first(),
            'genres' => $this->genreTitles($videosId),
            'cast' => $this->castNames($videosId),
        ];
    }

    /**
     * @return list<string>
     */
    private function sentPaths(): array
    {
        return Http::recorded()->map(fn (array $pair): string => ltrim(str_replace('/3/', '', (string) parse_url($pair[0]->url(), PHP_URL_PATH)), '/'))->values()->all();
    }
}
