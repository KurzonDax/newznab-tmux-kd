<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\FanartTvService;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use App\Services\TvProcessing\Pipes\AbstractTvProviderPipe;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Pipes\ParseInfoPipe;
use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Services\TvProcessing\Pipes\TraktPipe;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Services\TvProcessing\TagFreeShowLookup;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Services\TvProcessing\TvShowResolution;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * A release whose name starts with a group tag ("aaf-dexters.laboratory.s02e25") is looked up once
 * more without the tag, after every lookup of the full name found no show, and is bound only
 * together with its episode.
 */
final class TagFreeShowLookupTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private TagFreeRemote $remote;

    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'lookuptv' => '1',
            'maxrageprocessed' => '75',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
        Carbon::setTestNow('2026-11-20 12:00:00');
        Event::fake();
        config([
            'nntmux.echocli' => false,
            'nntmux_api.fanarttv_api_key' => '',
            'tmdb.api_key' => '',
        ]);
        $this->remote = new TagFreeRemote;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function the_pipeline_binds_a_local_show_through_the_tag_free_name(): void
    {
        $this->insertShow(10, "Dexter's Laboratory", [[2, 25]]);
        $this->insertRelease(1, 'aaf-dexters.laboratory.s02e25');

        $result = $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertTrue($result['matched']);
        $this->assertBound(1, 10, 2, 25);
    }

    #[Test]
    public function the_final_stage_binds_a_local_show_that_no_earlier_stage_looked_up(): void
    {
        $this->insertShow(10, "Dexter's Laboratory", [[2, 25]]);
        $this->insertRelease(1, 'aaf-dexters.laboratory.s02e25');

        (new TagFreeLocalDbProvider($this->remote))->processSite('', '', 1);
        (new TagFreeTvdbProvider($this->remote))->processSite('', '', 1);

        $this->assertSame(0, (int) DB::table('releases')->find(1)->videos_id);
        $this->assertSame([['local', 'aaf-dexters laboratory'], ['tvdb', 'aaf-dexters laboratory']], $this->remote->lookups);
        $this->assertSame([['tvdb', 'aaf-dexters laboratory']], $this->remote->searches);

        $this->runStages(new TagFreeTvMazeProvider($this->remote), new TagFreeTmdbProvider($this->remote), new TagFreeTraktProvider($this->remote));

        $this->assertBound(1, 10, 2, 25);
    }

    #[Test]
    public function a_full_name_found_locally_wins_over_the_tag_free_name(): void
    {
        $this->insertShow(10, '1000-lb Sisters', [[9, 10]]);
        $this->insertShow(20, 'Lb Sisters', [[9, 10]]);
        $this->insertRelease(1, '1000-lb.Sisters.S09E10.1080p.WEB.h264-EDITH');

        $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertBound(1, 10, 9, 10);
        $this->assertSame([['local', '1000-lb Sisters']], $this->remote->lookups);
    }

    #[Test]
    public function a_full_name_found_at_a_provider_wins_over_the_tag_free_name_in_the_staged_path(): void
    {
        $this->insertShow(10, 'Ed', [[1, 1]]);
        $this->remote->know('tvdb', 'Co-Ed', 'Co-Ed', 500, [[1, 1]]);
        $this->insertRelease(1, 'Co-Ed.S01E01.720p.WEB.h264-GRP');

        (new TagFreeLocalDbProvider($this->remote))->processSite('', '', 1);
        (new TagFreeTvdbProvider($this->remote))->processSite('', '', 1);

        $this->assertBound(1, $this->videoTitled('Co-Ed'), 1, 1);
    }

    #[Test]
    public function a_full_name_found_at_a_provider_wins_over_the_tag_free_name_in_the_pipeline(): void
    {
        $this->insertShow(10, 'Ed', [[1, 1]]);
        $this->remote->know('tvdb', 'Co-Ed', 'Co-Ed', 500, [[1, 1]]);
        $this->insertRelease(1, 'Co-Ed.S01E01.720p.WEB.h264-GRP');

        $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertBound(1, $this->videoTitled('Co-Ed'), 1, 1);
        $this->assertNotContains(['local', 'Ed'], $this->remote->lookups);
    }

    #[Test]
    public function the_final_stage_searches_the_providers_for_the_tag_free_name_after_every_stage_missed(): void
    {
        $this->remote->know('tvdb', 'monk', 'Monk', 600, [[3, 12]]);
        $this->insertRelease(1, 'tv4a-monk.s03e12-1080p.mkv');

        $this->runStages(
            new TagFreeTvdbProvider($this->remote),
            new TagFreeTvMazeProvider($this->remote),
            new TagFreeTmdbProvider($this->remote),
            new TagFreeTraktProvider($this->remote),
        );

        $this->assertBound(1, $this->videoTitled('Monk'), 3, 12);
        $this->assertSame([
            ['tvdb', 'tv4a-monk'], ['tvmaze', 'tv4a-monk'], ['tmdb', 'tv4a-monk'], ['trakt', 'tv4a-monk'], ['tvdb', 'monk'],
        ], $this->remote->searches);
    }

    #[Test]
    public function the_pipeline_searches_the_providers_for_the_tag_free_name_after_every_pipe_missed(): void
    {
        $this->remote->know('tvdb', 'monk', 'Monk', 600, [[3, 12]]);
        $this->insertRelease(1, 'tv4a-monk.s03e12-1080p.mkv');

        $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertBound(1, $this->videoTitled('Monk'), 3, 12);
        $this->assertSame([
            ['tvdb', 'tv4a-monk'], ['tvmaze', 'tv4a-monk'], ['tmdb', 'tv4a-monk'], ['trakt', 'tv4a-monk'], ['tvdb', 'monk'],
        ], $this->remote->searches);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesWithoutTheEpisode(): iterable
    {
        yield 'episode not found' => ['rgl-sop.s2.e01'];
        yield 'season-shaped' => ['Rgl-sop.s2 DVDRip XviD.e02'];
    }

    #[DataProvider('namesWithoutTheEpisode')]
    #[Test]
    public function the_final_stage_never_binds_a_tag_free_show_without_the_episode(string $searchname): void
    {
        $this->insertShow(10, 'SOP', [], '1997-01-01', tvdb: 6379);
        $this->insertRelease(1, $searchname, ['tv_episodes_id' => -3]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $this->assertUnmatched(1);
        $this->assertContains(['local', 'sop'], $this->remote->lookups, 'the tag-free name was looked up');
    }

    #[DataProvider('namesWithoutTheEpisode')]
    #[Test]
    public function the_pipeline_never_binds_a_tag_free_show_without_the_episode(string $searchname): void
    {
        $this->insertShow(10, 'SOP', [], '1997-01-01', tvdb: 6379);
        $this->insertRelease(1, $searchname);

        $result = $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertFalse($result['matched']);
        $this->assertUnmatched(1);
        $this->assertContains(['local', 'sop'], $this->remote->lookups, 'the tag-free name was looked up');
    }

    #[Test]
    public function an_ambiguous_full_name_gets_no_tag_free_pass_in_the_pipeline(): void
    {
        $this->insertAmbiguousFullNameShows();
        $this->insertRelease(1, 'aaf-castle.s01e01');

        $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $release = DB::table('releases')->find(1);
        $this->assertSame(0, (int) $release->videos_id);
        $this->assertNotNull($release->tv_episode_lookup_attempted_at, 'paced for a revisit as an ambiguous show');
        $this->assertNotContains(['local', 'castle'], $this->remote->lookups);
    }

    #[Test]
    public function an_ambiguous_full_name_gets_no_tag_free_pass_at_the_final_stage(): void
    {
        $this->insertAmbiguousFullNameShows();
        $this->insertRelease(1, 'aaf-castle.s01e01', ['tv_episodes_id' => -3]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $this->assertSame(0, (int) DB::table('releases')->find(1)->videos_id);
        $this->assertNotContains(['local', 'castle'], $this->remote->lookups);
    }

    #[Test]
    public function an_ambiguous_tag_free_name_ends_the_pass_at_the_final_stage(): void
    {
        $this->insertAmbiguousScrubs();
        $this->insertRelease(1, 'Wat-scrubs DVDRip XviD-s06e12', ['tv_episodes_id' => -3]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $this->assertUnmatched(1);
        $this->assertSame([['trakt', 'Wat-scrubs']], $this->remote->searches);
    }

    #[Test]
    public function an_ambiguous_tag_free_name_ends_the_pass_in_the_pipeline(): void
    {
        $this->insertAmbiguousScrubs();
        $this->insertRelease(1, 'Wat-scrubs DVDRip XviD-s06e12');

        $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertUnmatched(1);
        $this->assertNotContains('scrubs', array_column($this->remote->searches, 1));
    }

    #[Test]
    public function a_name_that_fails_to_parse_keeps_its_parse_failure(): void
    {
        $this->insertRelease(1, 'S02E08-Podsvt CZ dab [GreAndr]*');
        $this->insertRelease(2, 'S02E08-Podsvt CZ dab [GreAndr]*', ['guid' => str_repeat('b', 40)]);
        DB::table('releases')->where('id', 2)->update(['leftguid' => 'b']);

        (new TagFreeTvdbProvider($this->remote))->processSite('', 'a', 1);
        $this->pipeline()->processRelease(DB::table('releases')->find(2));

        $this->assertSame(-100, (int) DB::table('releases')->find(1)->tv_episodes_id);
        $this->assertSame(-6, (int) DB::table('releases')->find(2)->tv_episodes_id);
        $this->assertSame([], $this->remote->lookups);
        $this->assertSame([], $this->remote->searches);
    }

    #[Test]
    public function a_known_show_revisit_gets_no_tag_free_pass_in_the_pipeline(): void
    {
        $this->insertShow(10, "Dexter's Laboratory", [[2, 26]]);
        $this->insertShow(20, 'Unrelated Show', [[1, 1]]);
        $this->insertRelease(1, 'aaf-dexters.laboratory.s02e26', ['videos_id' => 20]);

        $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertSame(20, (int) DB::table('releases')->find(1)->videos_id);
        $this->assertNotContains(['local', 'dexters laboratory'], $this->remote->lookups);
    }

    #[Test]
    public function a_known_show_revisit_gets_no_tag_free_pass_at_the_final_stage(): void
    {
        $this->insertShow(10, "Dexter's Laboratory", [[2, 26]]);
        $this->insertShow(20, 'Unrelated Show', [[1, 1]]);
        $this->insertRelease(1, 'aaf-dexters.laboratory.s02e26', ['videos_id' => 20, 'tv_episodes_id' => -3]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $this->assertSame(20, (int) DB::table('releases')->find(1)->videos_id);
        $this->assertNotContains(['local', 'dexters laboratory'], $this->remote->lookups);
    }

    #[Test]
    public function one_final_stage_run_searches_a_tag_free_name_once_and_binds_both_releases(): void
    {
        $this->remote->know('tvdb', 'inspector gadget', 'Inspector Gadget', 700, [[1, 23], [1, 24]]);
        $this->insertRelease(1, 'aaf-inspector.gadget.s01e23', ['tv_episodes_id' => -3]);
        $this->insertRelease(2, 'aaf-inspector.gadget.s01e24', ['tv_episodes_id' => -3, 'guid' => str_repeat('b', 40)]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $videoId = $this->videoTitled('Inspector Gadget');
        $this->assertBound(1, $videoId, 1, 23);
        $this->assertBound(2, $videoId, 1, 24);
        $this->assertSame(['inspector gadget'], array_values(array_intersect($this->remote->searchesAt('tvdb'), ['inspector gadget'])));
    }

    #[Test]
    public function one_final_stage_run_searches_a_missed_tag_free_name_once(): void
    {
        $this->insertRelease(1, 'aaf-inspector.gadget.s01e23', ['tv_episodes_id' => -3]);
        $this->insertRelease(2, 'aaf-inspector.gadget.s01e24', ['tv_episodes_id' => -3, 'guid' => str_repeat('b', 40)]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $this->assertUnmatched(1);
        $this->assertUnmatched(2);
        $this->assertSame(['inspector gadget'], array_values(array_intersect($this->remote->searchesAt('tvdb'), ['inspector gadget'])));
    }

    #[Test]
    public function a_lookup_no_provider_could_answer_gets_no_tag_free_pass_and_stays_pending(): void
    {
        config([
            'tmdb.api_key' => 'tmdb-key',
            'nntmux_api.trakttv_api_key' => 'trakt-key',
            'tvdb.api_key' => 'tvdb-key',
            'tvdb.user_pin' => 'pin',
        ]);
        $gateway = app(ProviderGateway::class);
        foreach (['tvdb', 'tvmaze', 'tmdb', 'trakt'] as $provider) {
            foreach (range(1, 3) as $failure) {
                $gateway->report($provider, ProviderOutcome::Unavailable);
            }
        }
        $gateway->useHandler('tvdb', new MockHandler);
        Http::preventStrayRequests();
        Http::fake();
        $this->insertShow(10, "Dexter's Laboratory", [[2, 25]]);
        $this->insertRelease(1, 'aaf-dexters.laboratory.s02e25');

        $result = TvProcessingPipeline::createDefault(echoOutput: false)->processRelease(DB::table('releases')->find(1));

        $this->assertFalse($result['matched']);
        $release = DB::table('releases')->find(1);
        $this->assertSame(0, (int) $release->videos_id);
        $this->assertSame(0, (int) $release->tv_episodes_id, 'still pending');
        $this->assertNull($release->tv_episode_lookup_attempted_at);
    }

    #[Test]
    public function a_tag_free_lookup_a_provider_could_not_answer_stays_pending(): void
    {
        $this->remote->unavailable('tvdb', 'monk');
        $this->insertRelease(1, 'tv4a-monk.s03e12-1080p.mkv');

        $result = $this->pipeline()->processRelease(DB::table('releases')->find(1));

        $this->assertFalse($result['matched']);
        $this->assertContains(['tvdb', 'monk'], $this->remote->searches);
        $release = DB::table('releases')->find(1);
        $this->assertSame(0, (int) $release->videos_id);
        $this->assertSame(0, (int) $release->tv_episodes_id, 'still pending');
        $this->assertNull($release->tv_episode_lookup_attempted_at);
    }

    #[Test]
    public function a_title_cased_tag_is_removed(): void
    {
        $this->insertShow(10, 'Highlander: The Raven', [[1, 3]]);
        $this->insertRelease(1, 'Dim-Highlander.The.Raven.S01e03.Bloodlines.Dvdrip.Xvid', ['tv_episodes_id' => -3]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $this->assertBound(1, 10, 1, 3);
    }

    #[Test]
    public function a_numbered_title_behind_a_tag_binds_through_the_tag_free_parse(): void
    {
        $this->insertShow(10, 'Sealab 2021', [[1, 10]], '2000-12-21');
        $this->insertRelease(1, 'aaf-sealab.2021.s01e10', ['tv_episodes_id' => -3]);

        $this->runStages(new TagFreeTraktProvider($this->remote));

        $this->assertBound(1, 10, 1, 10);
    }

    private function insertAmbiguousFullNameShows(): void
    {
        $this->insertShow(10, 'Aaf-Castle', [[1, 1]], '2003-09-13');
        $this->insertShow(20, 'Aaf-Castle (2009)', [[1, 1]], '2009-03-09');
        $this->insertShow(30, 'Castle', [[1, 1]], '2009-03-09');
    }

    private function insertAmbiguousScrubs(): void
    {
        $this->insertShow(10, 'Scrubs', [[6, 12]], '2001-10-02');
        $this->insertShow(20, 'Scrubs (2026)', [[6, 12]], '2026-02-25');
    }

    private function videoTitled(string $title): int
    {
        return (int) DB::table('videos')->where('title', $title)->value('id');
    }

    /**
     * Runs the given staged providers in order, with the final stage's tag-free pass using faked pipes.
     */
    private function runStages(AbstractTvProvider ...$stages): void
    {
        $this->app->bind(TagFreeShowLookup::class, fn (): TagFreeShowLookup => new TagFreeShowLookup($this->lookupPipes()));
        foreach ($stages as $stage) {
            $stage->processSite('', '', 1);
        }
    }

    private function pipeline(): TvProcessingPipeline
    {
        return new TvProcessingPipeline([new ParseInfoPipe, ...$this->lookupPipes()], false);
    }

    /**
     * The five lookup pipes, each holding a faked provider that answers from the remote catalog.
     *
     * @return list<AbstractTvProviderPipe>
     */
    private function lookupPipes(): array
    {
        $pipes = [];
        foreach ([
            [LocalDbPipe::class, 'localDb', new TagFreeLocalDbProvider($this->remote)],
            [TvdbPipe::class, 'tvdb', new TagFreeTvdbProvider($this->remote)],
            [TvMazePipe::class, 'tvmaze', new TagFreeTvMazeProvider($this->remote)],
            [TmdbPipe::class, 'tmdb', new TagFreeTmdbProvider($this->remote)],
            [TraktPipe::class, 'trakt', new TagFreeTraktProvider($this->remote)],
        ] as [$pipeClass, $property, $provider]) {
            $pipe = (new $pipeClass)->setEchoOutput(false);
            (new \ReflectionProperty($pipeClass, $property))->setValue($pipe, $provider);
            $pipes[] = $pipe;
        }

        return $pipes;
    }

    private function assertBound(int $releaseId, int $videoId, int $series, int $episode): void
    {
        $release = DB::table('releases')->find($releaseId);
        $this->assertSame($videoId, (int) $release->videos_id);
        $this->assertSame(
            (int) DB::table('tv_episodes')->where(['videos_id' => $videoId, 'series' => $series, 'episode' => $episode])->value('id'),
            (int) $release->tv_episodes_id,
        );
        $this->assertGreaterThan(0, (int) $release->tv_episodes_id);
    }

    private function assertUnmatched(int $releaseId): void
    {
        $release = DB::table('releases')->find($releaseId);
        $this->assertSame(0, (int) $release->videos_id);
        $this->assertSame(-6, (int) $release->tv_episodes_id);
        $this->assertNull($release->tv_episode_lookup_attempted_at);
    }

    /**
     * @param  list<array{int, int}>  $episodes  series and episode numbers
     */
    private function insertShow(int $id, string $title, array $episodes, string $started = '2000-01-01', int $tvdb = 0): void
    {
        DB::table('videos')->insert([
            'id' => $id, 'type' => 0, 'title' => $title, 'countries_id' => '',
            'started' => $started, 'source' => 0, 'imdb' => '0', 'tvdb' => $tvdb,
            'tvmaze' => 0, 'tmdb' => 0, 'trakt' => 0, 'tvrage' => 0,
        ]);
        foreach ($episodes as [$series, $episode]) {
            DB::table('tv_episodes')->insert([
                'videos_id' => $id, 'series' => $series, 'episode' => $episode,
                'se_complete' => sprintf('S%02dE%02d', $series, $episode),
                'title' => sprintf('Episode %d', $episode), 'firstaired' => '2000-01-01', 'summary' => '',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertRelease(int $id, string $searchname, array $overrides = []): void
    {
        DB::table('releases')->insert(array_merge([
            'id' => $id,
            'name' => $searchname,
            'searchname' => $searchname,
            'groups_id' => 1,
            'size' => 2_000_000,
            'postdate' => now()->subDay(),
            'adddate' => now()->subDay(),
            'guid' => 'a'.str_pad((string) $id, 39, '0', STR_PAD_LEFT),
            'leftguid' => 'a',
            'categories_id' => Category::TV_HD,
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'tv_episode_lookup_attempted_at' => null,
            'isrenamed' => 1,
            'passwordstatus' => 0,
            'nfostatus' => 0,
            'nzbstatus' => 1,
        ], $overrides));
    }

    private function createSchema(): void
    {
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', [
            'id', 'name', 'searchname', 'groups_id', 'size', 'postdate', 'adddate', 'guid', 'leftguid',
            'categories_id', 'videos_id', 'tv_episodes_id', 'tv_episode_lookup_attempted_at', 'isrenamed',
            'passwordstatus', 'nfostatus', 'nzbstatus',
        ]);
        $tables->create('videos', [
            'id', 'type', 'title', 'countries_id', 'started', 'source', 'imdb', 'tmdb', 'trakt', 'tvdb', 'tvmaze', 'tvrage',
        ]);
        $tables->create('videos_aliases', ['videos_id', 'title']);
        $tables->create('tv_info');
        $tables->create('tv_episodes');
        $tables->create('release_files', ['releases_id', 'name']);
        $tables->create('media_infos', ['id', 'releases_id', 'movie_name']);
        $tables->create('release_nfos', ['releases_id', 'nfo']);
    }
}

/**
 * The shows each faked provider knows, and every show search and local lookup made.
 */
final class TagFreeRemote
{
    /** @var array<string, array<string, array{id: int, title: string, started: string, episodes: list<array{int, int}>}>> */
    private array $shows = [];

    /** @var list<array{string, string}> provider and searched name */
    public array $searches = [];

    /** @var list<array{string, string}> provider and looked-up clean name */
    public array $lookups = [];

    /** @var array<string, list<string>> names each provider cannot be asked about */
    private array $unavailable = [];

    public function unavailable(string $provider, string $name): void
    {
        $this->unavailable[$provider][] = mb_strtolower($name);
    }

    /**
     * @param  list<array{int, int}>  $episodes
     */
    public function know(string $provider, string $searchedAs, string $title, int $id, array $episodes, string $started = '2000-01-01'): void
    {
        $this->shows[$provider][mb_strtolower($searchedAs)] = ['id' => $id, 'title' => $title, 'started' => $started, 'episodes' => $episodes];
    }

    /**
     * @return array<string, mixed>|false
     */
    public function search(string $provider, string $name): array|false
    {
        $this->searches[] = [$provider, $name];
        if (\in_array(mb_strtolower($name), $this->unavailable[$provider] ?? [], true)) {
            app(ProviderGateway::class)->report($provider, ProviderOutcome::Unavailable);

            return false;
        }
        $show = $this->shows[$provider][mb_strtolower($name)] ?? null;
        if ($show === null) {
            return false;
        }

        return [
            'type' => 0, 'title' => $show['title'], 'summary' => '', 'started' => $show['started'],
            'publisher' => '', 'country' => '', 'source' => ['tvdb' => 1, 'tvmaze' => 2, 'tmdb' => 3, 'trakt' => 4][$provider],
            'imdb' => 0, 'tvdb' => 0, 'tvmaze' => 0, 'tmdb' => 0, 'trakt' => 0, 'tvrage' => 0,
            $provider => $show['id'], 'aliases' => [], 'localzone' => '', 'poster' => '',
        ];
    }

    /**
     * @return array<string, mixed>|false
     */
    public function episode(string $provider, int|string $siteId, int|string $series, int|string $episode): array|false
    {
        foreach ($this->shows[$provider] ?? [] as $show) {
            if ($show['id'] === (int) $siteId && \in_array([(int) $series, (int) $episode], $show['episodes'], true)) {
                return [
                    'series' => (int) $series, 'episode' => (int) $episode,
                    'se_complete' => sprintf('S%02dE%02d', $series, $episode),
                    'title' => sprintf('Episode %d', $episode), 'firstaired' => '2000-01-01', 'summary' => '',
                ];
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function searchesAt(string $provider): array
    {
        return array_values(array_map(
            static fn (array $search): string => $search[1],
            array_filter($this->searches, static fn (array $search): bool => $search[0] === $provider),
        ));
    }
}

/**
 * Answers show and episode requests from the remote catalog and records local lookups.
 */
trait AnswersFromTagFreeRemote
{
    private TagFreeRemote $remote;

    public function __construct(TagFreeRemote $remote)
    {
        AbstractTvProvider::__construct();
        $this->echooutput = false;
        $this->remote = $remote;
    }

    public function getShowInfo(string $name, ?int $releaseYear = null): bool|array
    {
        return $this->remote->search(self::REMOTE, $name);
    }

    public function getEpisodeInfo(int|string $siteId, int|string $series, int|string $episode, mixed ...$rest): bool|array
    {
        return (int) $series < 0 ? false : $this->remote->episode(self::REMOTE, $siteId, $series, $episode);
    }

    public function getPoster(int $videoId): int
    {
        return 0;
    }

    public function getBanner(mixed $videoID, mixed $siteId): bool
    {
        return false;
    }

    public function resolveByRelease(array $showInfo, int $type, int $source = 0, int $fallbackVideoId = 0, int $releaseId = 0): TvShowResolution
    {
        $this->remote->lookups[] = [self::REMOTE, (string) ($showInfo['cleanname'] ?? '')];

        return parent::resolveByRelease($showInfo, $type, $source, $fallbackVideoId, $releaseId);
    }
}

final class TagFreeLocalDbProvider extends LocalDbProvider
{
    use AnswersFromTagFreeRemote;

    private const REMOTE = 'local';
}

final class TagFreeTvdbProvider extends TvdbProvider
{
    use AnswersFromTagFreeRemote {
        AnswersFromTagFreeRemote::__construct as private answerFromRemote;
    }

    private const REMOTE = 'tvdb';

    public function __construct(TagFreeRemote $remote)
    {
        $this->answerFromRemote($remote);
        (new \ReflectionProperty(TvdbProvider::class, 'local'))->setValue($this, false);
        (new \ReflectionProperty(TvdbProvider::class, 'fanart'))->setValue($this, new FanartTvService(''));
    }
}

final class TagFreeTvMazeProvider extends TvMazeProvider
{
    use AnswersFromTagFreeRemote;

    private const REMOTE = 'tvmaze';
}

final class TagFreeTmdbProvider extends TmdbProvider
{
    use AnswersFromTagFreeRemote;

    private const REMOTE = 'tmdb';
}

final class TagFreeTraktProvider extends TraktProvider
{
    use AnswersFromTagFreeRemote;

    private const REMOTE = 'trakt';
}
