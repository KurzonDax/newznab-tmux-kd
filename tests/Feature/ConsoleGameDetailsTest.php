<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Http\Controllers\Api\RSS;
use App\Models\Category;
use App\Services\ConsoleService;
use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Services\IGDB\Models\Game;
use App\Services\IGDBService;
use App\Services\MetadataProcessing\ConsoleGameDetails;
use App\Services\MetadataProcessing\ConsoleGenres;
use App\Services\ReleaseImageService;
use App\Support\Data\ImageProcessingResult;
use App\Support\SecondaryIndexDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * A console game keeps IGDB's storyline, scores, official website, developers, publishers,
 * game modes and player perspectives, written by the lookup's one save, and a stored game is
 * refreshed from IGDB when a new release of it arrives, at most once every 24 hours.
 */
final class ConsoleGameDetailsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private const array TABLES = [
        'consoleinfo', 'genres', 'console_genres', 'companies', 'console_companies', 'game_modes',
        'console_game_modes', 'player_perspectives', 'console_player_perspectives', 'releases',
    ];

    private const array LINK_TABLES = ['console_companies', 'console_game_modes', 'console_player_perspectives'];

    private const array DETAIL_COLUMNS = ['storyline', 'critic_score', 'user_score', 'website', 'details_refreshed_at'];

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'lookupgames' => '1',
            'maxgamesprocessed' => '50',
            'amazonsleep' => '0',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        foreach (self::TABLES as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_new_game_stores_the_igdb_details_and_their_rows(): void
    {
        $this->fakeSearchIndex();

        $id = $this->lookUp($this->game());

        $row = DB::table('consoleinfo')->where('id', $id)->first();
        $this->assertSame('Master Chief returns.', $row->storyline);
        $this->assertSame(92, (int) $row->critic_score);
        $this->assertSame(78, (int) $row->user_score);
        $this->assertSame('https://www.halo.test/', $row->website);
        $this->assertSame('2026-10-02 12:00:00', Carbon::parse((string) $row->details_refreshed_at)->toDateTimeString());
        $this->assertSame('Label B,Studio C', $row->publisher);

        $this->assertSame([
            ['Studio A', 101, 0, 0], ['Studio C', 103, 0, 1],
            ['Label B', 102, 1, 0], ['Studio C', 103, 1, 1],
        ], $this->companies($id));
        $this->assertSame([['Single player', 1], ['Multiplayer', 2]], $this->lookups('game_modes', $id));
        $this->assertSame([['First person', 1]], $this->lookups('player_perspectives', $id));
    }

    public function test_a_second_game_reuses_the_company_mode_and_perspective_rows(): void
    {
        $this->fakeSearchIndex();

        $first = $this->lookUp($this->game());
        $second = $this->lookUp($this->game(['id' => 43, 'name' => 'Halo 4']));

        $this->assertNotSame($first, $second);
        $this->assertSame(3, DB::table('companies')->count());
        $this->assertSame(2, DB::table('game_modes')->count());
        $this->assertSame(1, DB::table('player_perspectives')->count());
        $this->assertSame($this->companies($first), $this->companies($second));
        $this->assertSame($this->lookups('game_modes', $first), $this->lookups('game_modes', $second));
    }

    public function test_a_mode_igdb_renamed_keeps_its_row_and_its_stored_name(): void
    {
        $this->fakeSearchIndex();
        $stored = (int) DB::table('game_modes')->insertGetId(['name' => 'Single player', 'igdb_id' => 1]);

        $id = $this->lookUp($this->game(['game_modes' => [['id' => 1, 'name' => 'Single-player']]]));

        $this->assertSame(1, DB::table('game_modes')->count());
        $this->assertSame([['Single player', 1]], $this->lookups('game_modes', $id));
        $this->assertSame($stored, (int) DB::table('console_game_modes')->where('consoleinfo_id', $id)->value('game_modes_id'));
    }

    public function test_a_mode_without_an_igdb_id_is_found_by_name_and_a_stored_mode_gets_its_id(): void
    {
        $this->fakeSearchIndex();
        DB::table('game_modes')->insert(['name' => 'Co-operative', 'igdb_id' => 3]);
        $unclaimed = (int) DB::table('player_perspectives')->insertGetId(['name' => 'First person', 'igdb_id' => null]);

        $id = $this->lookUp($this->game(['game_modes' => [['name' => 'Co-operative']]]));

        $this->assertSame([['Co-operative', 3]], $this->lookups('game_modes', $id));
        $this->assertSame(1, DB::table('game_modes')->count());
        $this->assertSame(1, (int) DB::table('player_perspectives')->where('id', $unclaimed)->value('igdb_id'));
        $this->assertSame(1, DB::table('player_perspectives')->count());
    }

    public function test_a_game_without_the_details_stores_nulls_and_no_rows(): void
    {
        $this->fakeSearchIndex();

        $id = $this->lookUp(new Game(['id' => 42, 'name' => 'Halo 3', 'platforms' => $this->platforms()]));

        $row = DB::table('consoleinfo')->where('id', $id)->first();
        foreach (['storyline', 'critic_score', 'user_score', 'website'] as $column) {
            $this->assertNull($row->{$column}, $column);
        }
        $this->assertSame('Unknown', $row->publisher);
        foreach (self::LINK_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_a_relookup_replaces_the_link_rows(): void
    {
        $this->fakeSearchIndex();
        $id = $this->lookUp($this->game());

        $this->assertSame($id, $this->lookUp($this->game([
            'involved_companies' => [['id' => 9, 'company' => ['id' => 104, 'name' => 'Studio D'], 'developer' => true, 'publisher' => true]],
            'game_modes' => [['id' => 2, 'name' => 'Multiplayer']],
            'player_perspectives' => [['id' => 4, 'name' => 'Third person']],
        ])));

        $this->assertSame([['Studio D', 104, 0, 0], ['Studio D', 104, 1, 0]], $this->companies($id));
        $this->assertSame([['Multiplayer', 2]], $this->lookups('game_modes', $id));
        $this->assertSame([['Third person', 4]], $this->lookups('player_perspectives', $id));
        $this->assertSame('Studio D', DB::table('consoleinfo')->where('id', $id)->value('publisher'));
    }

    public function test_a_failed_link_write_on_the_update_path_keeps_nothing_written_in_the_transaction(): void
    {
        $this->fakeSearchIndex();
        $id = $this->lookUp($this->game());
        DB::table('consoleinfo')->where('id', $id)->update(['details_refreshed_at' => '2026-09-01 00:00:00']);
        $before = $this->gameState($id);

        $service = $this->service($this->igdbFinding($this->game(['name' => 'Halo 3 Renamed', 'storyline' => 'Changed.', 'genres' => [['name' => 'Racing']]])), null, new DuplicatingConsoleGameDetails);

        try {
            $service->updateConsoleInfo(['title' => 'Halo 3', 'platform' => 'X360']);
            $this->fail('A duplicate link row must fail the write.');
        } catch (QueryException) {
        }

        $this->assertSame($before, $this->gameState($id));
        $this->assertSame('Halo 3', DB::table('consoleinfo')->where('id', $id)->value('title'));
    }

    public function test_a_failed_link_write_on_the_insert_path_leaves_the_new_row_unstamped(): void
    {
        $this->fakeSearchIndex();
        $service = $this->service($this->igdbFinding($this->game()), null, new DuplicatingConsoleGameDetails);

        try {
            $service->updateConsoleInfo(['title' => 'Halo 3', 'platform' => 'X360']);
            $this->fail('A duplicate link row must fail the write.');
        } catch (QueryException) {
        }

        $row = DB::table('consoleinfo')->where('asin', '42')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->details_refreshed_at);
        $this->assertNull($row->genres_id);
        $this->assertSame(0, DB::table('console_genres')->count());
        foreach (self::LINK_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_a_failed_save_on_a_refresh_still_links_the_release_and_the_pass_goes_on(): void
    {
        $failing = $this->storedGame();
        $fresh = $this->storedGame(['title' => 'Gears Of War', 'asin' => '43', 'details_refreshed_at' => '2026-10-02 11:00:00']);
        $before = $this->gameState($failing);
        $this->insertRelease(1, 'Halo 3 PAL XBOX360 -GAMERS');
        $this->insertRelease(2, 'Gears Of War PAL XBOX360 -GAMERS');
        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchSecondary')->andReturn(['id' => [$failing]], ['id' => [$fresh]]);

        $igdb = $this->igdb();
        $igdb->shouldReceive('findGame')->once()->with(42)->andReturn($this->game());

        $this->service($igdb, null, new DuplicatingConsoleGameDetails)->processConsoleReleases('', '', 1);

        $this->assertSame($failing, $this->releaseGame(1));
        $this->assertSame($fresh, $this->releaseGame(2));
        $this->assertSame($before, $this->gameState($failing));
    }

    public function test_a_release_of_a_game_never_refreshed_refreshes_it_and_links_the_release(): void
    {
        $id = $this->storedGame();

        $this->processReleaseOf($id, $this->igdbFinding(null, $this->game()));

        $row = DB::table('consoleinfo')->where('id', $id)->first();
        $this->assertSame('Master Chief returns.', $row->storyline);
        $this->assertSame(92, (int) $row->critic_score);
        $this->assertSame('Finish the fight.', $row->review);
        $this->assertSame('2026-10-02 12:00:00', Carbon::parse((string) $row->details_refreshed_at)->toDateTimeString());
        $this->assertCount(4, $this->companies($id));
        $this->assertSame($id, $this->releaseGame(1));
    }

    public function test_a_game_refreshed_25_hours_ago_is_refreshed(): void
    {
        $id = $this->storedGame(['details_refreshed_at' => '2026-10-01 11:00:00']);

        $this->processReleaseOf($id, $this->igdbFinding(null, $this->game()));

        $this->assertSame('2026-10-02 12:00:00', Carbon::parse((string) DB::table('consoleinfo')->where('id', $id)->value('details_refreshed_at'))->toDateTimeString());
        $this->assertSame('Master Chief returns.', DB::table('consoleinfo')->where('id', $id)->value('storyline'));
    }

    public function test_a_game_refreshed_an_hour_ago_is_not_asked_about(): void
    {
        $id = $this->storedGame(['details_refreshed_at' => '2026-10-02 11:00:00']);
        $before = $this->gameState($id);
        $igdb = $this->igdb();
        $igdb->shouldNotReceive('findGame');

        $this->processReleaseOf($id, $igdb);

        $this->assertSame($before, $this->gameState($id));
        $this->assertSame($id, $this->releaseGame(1));
    }

    public function test_a_game_igdb_no_longer_returns_only_gets_its_stamp(): void
    {
        $id = $this->storedGame();
        $before = $this->gameState($id);
        $igdb = $this->igdb();
        $igdb->shouldReceive('findGame')->once()->with(42)->andReturnNull();

        $this->processReleaseOf($id, $igdb);

        $after = $this->gameState($id);
        $this->assertSame('2026-10-02 12:00:00', Carbon::parse((string) $after['consoleinfo']['details_refreshed_at'])->toDateTimeString());
        $after['consoleinfo']['details_refreshed_at'] = null;
        $this->assertSame($before, $after);
    }

    #[TestWith([500])]
    #[TestWith([429])]
    public function test_a_failed_igdb_request_changes_nothing_and_still_links_the_release(int $status): void
    {
        $id = $this->storedGame();
        $before = $this->gameState($id);
        $igdb = $this->igdb();
        $igdb->shouldReceive('findGame')->once()->with(42)->andThrow(new IgdbHttpException('IGDB request failed', $status));

        $this->processReleaseOf($id, $igdb);

        $this->assertSame($before, $this->gameState($id));
        $this->assertSame($id, $this->releaseGame(1));
    }

    public function test_a_refresh_rewrites_admin_edits_and_keeps_the_cover_when_igdb_has_none(): void
    {
        $coverFile = $this->coverFile();
        $id = $this->adminEditedGame();
        file_put_contents($coverFile.$id.'.jpg', 'uploaded cover');
        $image = Mockery::mock(ReleaseImageService::class);
        $image->shouldNotReceive('saveRemoteImage');

        $this->processReleaseOf($id, $this->igdbFinding(null, $this->game()), $image);

        $row = DB::table('consoleinfo')->where('id', $id)->first();
        $this->assertSame('Halo 3', $row->title);
        $this->assertSame('Xbox 360', $row->platform);
        $this->assertSame('Label B,Studio C', $row->publisher);
        $this->assertSame('2007-09-25', Carbon::parse((string) $row->releasedate)->toDateString());
        $this->assertSame('M', $row->esrb);
        $shooter = (int) DB::table('genres')->where('title', 'Shooter')->value('id');
        $this->assertSame($shooter, (int) $row->genres_id);
        $this->assertSame([$shooter], (new ConsoleGenres)->stored($id));
        $this->assertSame(1, (int) $row->cover);
        $this->assertSame('uploaded cover', file_get_contents($coverFile.$id.'.jpg'));

        unlink($coverFile.$id.'.jpg');
        rmdir($coverFile);
        rmdir(dirname($coverFile));
    }

    public function test_a_refresh_whose_cover_is_saved_sets_the_cover(): void
    {
        $id = $this->adminEditedGame();
        DB::table('consoleinfo')->where('id', $id)->update(['cover' => 0]);

        $this->processReleaseOf($id, $this->igdbFinding(null, $this->game(['cover' => ['image_id' => 'co1abc']])),
            $this->imageServiceSaving(ImageProcessingResult::success('/covers/console/1.jpg', 264, 374, 'image/jpeg')));

        $this->assertSame(1, (int) DB::table('consoleinfo')->where('id', $id)->value('cover'));
    }

    public function test_a_refresh_whose_cover_download_fails_keeps_the_cover(): void
    {
        $id = $this->adminEditedGame();

        $this->processReleaseOf($id, $this->igdbFinding(null, $this->game(['cover' => ['image_id' => 'co1abc']])),
            $this->imageServiceSaving(ImageProcessingResult::failure('Remote image could not be fetched.')));

        $this->assertSame(1, (int) DB::table('consoleinfo')->where('id', $id)->value('cover'));
    }

    public function test_a_new_game_is_put_in_the_console_search_index(): void
    {
        $documents = [];
        Search::shouldReceive('insertSecondary')->once()->andReturnUsing(static function (SecondarySearchIndex $index, int $id, array $document) use (&$documents): void {
            $documents[] = [$index, $id, $document];
        });

        $id = $this->lookUp($this->game());

        $row = (array) DB::table('consoleinfo')->where('id', $id)->first();
        $this->assertSame([[SecondarySearchIndex::Console, $id, SecondaryIndexDocuments::consoleFromArray($row)]], $documents);
    }

    public function test_a_failing_search_index_still_saves_the_game_and_links_the_release(): void
    {
        $this->insertRelease(1, 'Halo 3 PAL XBOX360 -GAMERS');
        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchSecondary')->andReturn(['id' => []]);
        Search::shouldReceive('insertSecondary')->once()->andThrow(new \RuntimeException('search is down'));

        $this->service($this->igdbFinding($this->game()))->processConsoleReleases('', '', 1);

        $id = (int) DB::table('consoleinfo')->where('asin', '42')->value('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $this->releaseGame(1));
        $this->assertNotNull(DB::table('consoleinfo')->where('id', $id)->value('details_refreshed_at'));
    }

    public function test_a_stored_amazon_asin_only_gets_its_stamp(): void
    {
        $id = $this->storedGame(['asin' => 'B000TEST01']);
        $before = $this->gameState($id);
        $igdb = $this->igdb();
        $igdb->shouldNotReceive('findGame');

        $this->processReleaseOf($id, $igdb);

        $after = $this->gameState($id);
        $this->assertSame('2026-10-02 12:00:00', Carbon::parse((string) $after['consoleinfo']['details_refreshed_at'])->toDateTimeString());
        $after['consoleinfo']['details_refreshed_at'] = null;
        $this->assertSame($before, $after);
    }

    public function test_without_igdb_configured_a_release_of_a_stored_game_is_only_linked(): void
    {
        $id = $this->storedGame();
        $before = $this->gameState($id);
        $igdb = Mockery::mock(IGDBService::class)->makePartial();
        $igdb->shouldReceive('isConfigured')->andReturnFalse();
        $igdb->shouldNotReceive('findGame');

        $this->processReleaseOf($id, $igdb);

        $this->assertSame($before, $this->gameState($id));
        $this->assertSame($id, $this->releaseGame(1));
    }

    public function test_a_refreshed_game_waits_out_the_lookup_window(): void
    {
        DB::table('settings')->upsert([['name' => 'amazonsleep', 'value' => '400']], ['name'], ['value']);
        $id = $this->storedGame();
        $igdb = $this->igdb();
        $igdb->shouldReceive('findGame')->once()->with(42)->andReturnNull();

        $elapsed = $this->timeOf(fn () => $this->processReleaseOf($id, $igdb));

        $this->assertGreaterThanOrEqual(0.39, $elapsed);
    }

    public function test_a_game_refreshed_less_than_24_hours_ago_does_not_wait(): void
    {
        DB::table('settings')->upsert([['name' => 'amazonsleep', 'value' => '400']], ['name'], ['value']);
        $id = $this->storedGame(['details_refreshed_at' => '2026-10-02 11:00:00']);
        $igdb = $this->igdb();
        $igdb->shouldNotReceive('findGame');

        $elapsed = $this->timeOf(fn () => $this->processReleaseOf($id, $igdb));

        $this->assertLessThan(0.3, $elapsed);
    }

    public function test_the_admin_writes_leave_the_details_and_link_rows_unchanged(): void
    {
        $this->fakeSearchIndex();
        $id = $this->lookUp($this->game());
        $before = $this->details($id);
        $genreId = (int) DB::table('genres')->insertGetId(['title' => 'Puzzle', 'type' => Category::GAME_ROOT, 'disabled' => 0]);
        $service = $this->service($this->igdb());

        $save = static fn () => $service->update($id, 'Edited', '42', null, 5, 'PS3', 'Edited Label', '2008-01-01', 'T', 0, $genreId);
        (new ConsoleGenres)->replace($id, [$genreId], $save);

        $this->assertSame('Edited', DB::table('consoleinfo')->where('id', $id)->value('title'));
        $this->assertSame($before, $this->details($id));
    }

    public function test_update_writes_a_given_summary_cut_to_3000_characters_and_keeps_it_when_none_is_given(): void
    {
        $id = $this->storedGame();
        $service = $this->service($this->igdb());

        $service->update($id, 'Halo 3', '42', null, null, 'Xbox 360', 'Label', null, null, 0, null);
        $this->assertSame('Old summary', DB::table('consoleinfo')->where('id', $id)->value('review'));

        $service->update($id, 'Halo 3', '42', null, null, 'Xbox 360', 'Label', null, null, 0, null, str_repeat('s', 3500));
        $this->assertSame(str_repeat('s', 3000), DB::table('consoleinfo')->where('id', $id)->value('review'));
    }

    public function test_rss_output_is_unchanged_when_the_details_are_filled(): void
    {
        foreach (['categories', 'root_categories', 'usenet_groups', 'musicinfo', 'movieinfo', 'tv_episodes', 'bookinfo', 'registration_periods'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        Search::spy();
        DB::table('root_categories')->insert(['id' => Category::GAME_ROOT, 'title' => 'Console']);
        DB::table('categories')->insert(['id' => Category::GAME_XBOX360, 'title' => 'Xbox 360', 'root_categories_id' => Category::GAME_ROOT]);
        $id = $this->storedGame(['releasedate' => '2007-09-25 00:00:00', 'cover' => 1]);
        $shooter = (int) DB::table('genres')->insertGetId(['title' => 'Shooter', 'type' => Category::GAME_ROOT, 'disabled' => 0]);
        (new ConsoleGenres)->replace($id, [$shooter]);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'console-release', 'name' => 'Halo.3.PAL.XBOX360-GAMERS', 'searchname' => 'Halo.3.PAL.XBOX360-GAMERS',
            'categories_id' => Category::GAME_XBOX360, 'consoleinfo_id' => $id, 'passwordstatus' => 0, 'size' => 1000,
            'postdate' => '2026-10-01 00:00:00', 'adddate' => '2026-10-01 00:00:00']);

        [$rowBefore, $xmlBefore] = $this->rss();

        DB::table('consoleinfo')->where('id', $id)->update([
            'storyline' => 'Master Chief returns.', 'critic_score' => 92, 'user_score' => 78,
            'website' => 'https://www.halo.test/', 'details_refreshed_at' => now(),
        ]);
        $company = (int) DB::table('companies')->insertGetId(['name' => 'Studio A', 'igdb_id' => 101]);
        $mode = (int) DB::table('game_modes')->insertGetId(['name' => 'Single player', 'igdb_id' => 1]);
        $perspective = (int) DB::table('player_perspectives')->insertGetId(['name' => 'First person', 'igdb_id' => 1]);
        DB::table('console_companies')->insert(['consoleinfo_id' => $id, 'companies_id' => $company, 'role' => 0, 'position' => 0]);
        DB::table('console_game_modes')->insert(['consoleinfo_id' => $id, 'game_modes_id' => $mode, 'position' => 0]);
        DB::table('console_player_perspectives')->insert(['consoleinfo_id' => $id, 'player_perspectives_id' => $perspective, 'position' => 0]);

        [$rowAfter, $xmlAfter] = $this->rss();

        $this->assertSame('Old summary', $rowBefore['co_review']);
        $this->assertSame($xmlBefore, $xmlAfter);
        $this->assertSame(array_keys($rowBefore), array_keys($rowAfter));
    }

    /**
     * @return array{array<string, mixed>, string}
     */
    private function rss(): array
    {
        $rss = app(RSS::class);
        $rows = $rss->getRss([Category::GAME_XBOX360], 0, 0);
        $this->assertCount(1, $rows);

        $params = ['dl' => '0', 'del' => '0', 'extended' => 1, 'uid' => 1, 'token' => 'rss-token', 'apilimit' => 100,
            'requests' => 0, 'downloadlimit' => 100, 'grabs' => 0, 'oldestapi' => '', 'oldestgrab' => ''];

        $row = $rows[0] instanceof Model ? $rows[0]->getAttributes() : (array) $rows[0];

        return [$row, (string) $rss->output($rows, $params, true, 0, 'rss')->getContent()];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function game(array $overrides = []): Game
    {
        return new Game($overrides + [
            'id' => 42,
            'name' => 'Halo 3',
            'summary' => 'Finish the fight.',
            'url' => 'https://www.igdb.com/games/halo-3',
            'storyline' => 'Master Chief returns.',
            'aggregated_rating' => 91.73,
            'rating' => 78.4,
            'websites' => [['id' => 7, 'type' => 13, 'url' => 'https://store.halo.test/'], ['id' => 8, 'type' => 1, 'url' => 'https://www.halo.test/']],
            'involved_companies' => [
                ['id' => 1, 'company' => ['id' => 101, 'name' => 'Studio A'], 'developer' => true, 'publisher' => false],
                ['id' => 2, 'company' => ['id' => 102, 'name' => 'Label B'], 'developer' => false, 'publisher' => true],
                ['id' => 3, 'company' => ['id' => 103, 'name' => 'Studio C'], 'developer' => true, 'publisher' => true],
            ],
            'game_modes' => [['id' => 1, 'name' => 'Single player'], ['id' => 2, 'name' => 'Multiplayer']],
            'player_perspectives' => [['id' => 1, 'name' => 'First person']],
            'genres' => [['name' => 'Shooter']],
            'age_ratings' => [['id' => 1, 'organization' => ['id' => 1, 'name' => 'ESRB'], 'rating_category' => ['id' => 11, 'rating' => 'M']]],
            'release_dates' => [['date' => 1190678400, 'platform' => 12, 'human' => 'Sep 25, 2007']],
            'platforms' => $this->platforms(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function platforms(): array
    {
        return [['id' => 12, 'name' => 'Xbox 360', 'abbreviation' => 'X360']];
    }

    private function igdb(): IGDBService&MockInterface
    {
        $igdb = Mockery::mock(IGDBService::class)->makePartial();
        $igdb->shouldReceive('isConfigured')->andReturnTrue();

        return $igdb;
    }

    private function igdbFinding(?Game $searched, ?Game $found = null): IGDBService&MockInterface
    {
        $igdb = $this->igdb();
        if ($searched !== null) {
            $igdb->shouldReceive('searchConsole')->andReturn($searched);
        }
        if ($found !== null) {
            $igdb->shouldReceive('findGame')->once()->with((int) $found->id)->andReturn($found);
        }

        return $igdb;
    }

    private function service(IGDBService $igdb, ?ReleaseImageService $image = null, ?ConsoleGameDetails $details = null): ConsoleService
    {
        return new ConsoleService($image ?? Mockery::mock(ReleaseImageService::class), $igdb, null, $details);
    }

    private function imageServiceSaving(ImageProcessingResult $result): ReleaseImageService
    {
        $image = Mockery::mock(ReleaseImageService::class);
        $image->shouldReceive('saveRemoteImage')->once()->andReturn($result);

        return $image;
    }

    private function lookUp(Game $game): int
    {
        return $this->service($this->igdbFinding($game))->updateConsoleInfo(['title' => 'Halo 3', 'platform' => 'X360']);
    }

    private function processReleaseOf(int $consoleId, IGDBService $igdb, ?ReleaseImageService $image = null): void
    {
        $this->insertRelease(1, 'Halo 3 PAL XBOX360 -GAMERS');
        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchSecondary')->andReturn(['id' => [$consoleId]]);

        $this->service($igdb, $image)->processConsoleReleases('', '', 1);
    }

    private function fakeSearchIndex(): void
    {
        Search::shouldReceive('insertSecondary')->andReturnNull();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storedGame(array $overrides = []): int
    {
        return (int) DB::table('consoleinfo')->insertGetId($overrides + [
            'title' => 'Halo 3',
            'asin' => '42',
            'url' => 'https://www.igdb.com/games/halo-3',
            'platform' => 'Xbox 360',
            'publisher' => 'Old Label',
            'review' => 'Old summary',
            'cover' => 0,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
    }

    /** A game stored with a cover and then edited on the admin form. */
    private function adminEditedGame(): int
    {
        $puzzle = (int) DB::table('genres')->insertGetId(['title' => 'Puzzle', 'type' => Category::GAME_ROOT, 'disabled' => 0]);
        $id = $this->storedGame(['title' => 'Edited Title', 'platform' => 'Typed Platform', 'publisher' => 'Edited Label',
            'releasedate' => '1999-01-01 00:00:00', 'esrb' => 'E', 'cover' => 1]);
        (new ConsoleGenres)->replace($id, [$puzzle]);

        return $id;
    }

    private function coverFile(): string
    {
        $directory = sys_get_temp_dir().'/nntmux-console-covers-'.getmypid().'/';
        @mkdir($directory.'console', 0777, true);
        config(['nntmux_settings.covers_path' => rtrim($directory, '/')]);

        return $directory.'console/';
    }

    private function insertRelease(int $id, string $searchName): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => 'console-release-'.$id,
            'name' => $searchName,
            'searchname' => $searchName,
            'categories_id' => Category::GAME_XBOX360,
            'passwordstatus' => 0,
            'postdate' => '2026-10-01 00:00:00',
            'adddate' => '2026-10-01 00:00:00',
        ]);
    }

    private function releaseGame(int $releaseId): int
    {
        return (int) DB::table('releases')->where('id', $releaseId)->value('consoleinfo_id');
    }

    /**
     * @return list<array{string, int, int, int}>
     */
    private function companies(int $consoleId): array
    {
        return DB::table('console_companies as cc')->join('companies as c', 'c.id', '=', 'cc.companies_id')
            ->where('cc.consoleinfo_id', $consoleId)->orderBy('cc.role')->orderBy('cc.position')
            ->get(['c.name', 'c.igdb_id', 'cc.role', 'cc.position'])
            ->map(static fn (object $row): array => [(string) $row->name, (int) $row->igdb_id, (int) $row->role, (int) $row->position])->all();
    }

    /**
     * @return list<array{string, int}>
     */
    private function lookups(string $table, int $consoleId): array
    {
        return DB::table('console_'.$table.' as l')->join($table.' as t', 't.id', '=', 'l.'.$table.'_id')
            ->where('l.consoleinfo_id', $consoleId)->orderBy('l.position')
            ->get(['t.name', 't.igdb_id'])
            ->map(static fn (object $row): array => [(string) $row->name, (int) $row->igdb_id])->all();
    }

    /**
     * The game's five detail columns and its link rows.
     *
     * @return array<string, mixed>
     */
    private function details(int $consoleId): array
    {
        $state = ['columns' => (array) DB::table('consoleinfo')->where('id', $consoleId)->first(self::DETAIL_COLUMNS)];
        foreach (self::LINK_TABLES as $table) {
            $state[$table] = DB::table($table)->where('consoleinfo_id', $consoleId)->orderBy('position')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }

    /**
     * The game's row, genre rows and link rows.
     *
     * @return array<string, mixed>
     */
    private function gameState(int $consoleId): array
    {
        $state = ['consoleinfo' => (array) DB::table('consoleinfo')->where('id', $consoleId)->first()];
        foreach (['console_genres', ...self::LINK_TABLES] as $table) {
            $state[$table] = DB::table($table)->where('consoleinfo_id', $consoleId)->orderBy('position')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }

    /**
     * @param  callable(): void  $work
     */
    private function timeOf(callable $work): float
    {
        $startedAt = hrtime(true);
        $work();

        return (hrtime(true) - $startedAt) / 1_000_000_000;
    }
}

/** Returns one `console_game_modes` row twice, which breaks the table's primary key on insert. */
class DuplicatingConsoleGameDetails extends ConsoleGameDetails
{
    public function linkRows(array $con): array
    {
        $rows = parent::linkRows($con);
        $rows['console_game_modes'][] = $rows['console_game_modes'][0];

        return $rows;
    }
}
