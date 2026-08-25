<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Services\Runners\PostProcessRunner;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\TvEpisodeRevisitService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Services\TvProcessor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class TvEpisodeRevisitTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'lookuptv' => '1',
            'maxrageprocessed' => '75',
            'postthreadsnon' => '1',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
        Carbon::setTestNow('2026-08-25 12:00:00');
        config([
            'nntmux.tv_episode_revisit_window_days' => 14,
            'nntmux.tv_episode_revisit_interval_hours' => 6,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function candidate_selection_includes_due_and_expired_episode_missing_rows(): void
    {
        $this->insertRelease(1);
        $this->insertRelease(2, [
            'videos_id' => 20,
            'tv_episode_lookup_attempted_at' => now()->subHours(7),
        ]);
        $this->insertRelease(3, [
            'videos_id' => 30,
            'tv_episode_lookup_attempted_at' => now()->subHour(),
        ]);
        $this->insertRelease(4, [
            'videos_id' => 40,
            'postdate' => now()->subDays(15),
            'tv_episode_lookup_attempted_at' => now()->subHour(),
        ]);
        $this->insertRelease(5, ['videos_id' => 50, 'tv_episodes_id' => -6]);
        $this->insertRelease(6, ['videos_id' => 60, 'tv_episodes_id' => 600]);

        $this->assertSame([1, 2, 4], TvProcessingCandidateQuery::query()->pluck('id')->all());
    }

    #[Test]
    public function worker_gate_and_monitor_count_use_the_shared_revisit_predicate(): void
    {
        Log::spy();
        $this->insertRelease(1, [
            'videos_id' => 10,
            'tv_episode_lookup_attempted_at' => now()->subHour(),
        ]);

        $runner = new PostProcessRunner;

        $this->assertFalse($runner->hasTvWork(false));
        $this->assertSame(0, TvProcessingCandidateQuery::count());
        $this->assertSame(0, $this->collectedTvCount());

        DB::table('releases')->where('id', 1)->update([
            'tv_episode_lookup_attempted_at' => now()->subHours(7),
        ]);

        $this->assertTrue($runner->hasTvWork(false));
        $this->assertSame(1, TvProcessingCandidateQuery::count());
        $this->assertSame(1, $this->collectedTvCount());
    }

    #[Test]
    public function a_due_revisit_uses_the_known_show_and_matches_an_episode_now_in_the_database(): void
    {
        Event::fake();
        DB::table('videos')->insert([
            'id' => 10,
            'type' => 0,
            'title' => 'Known Show',
            'source' => 1,
        ]);
        DB::table('tv_episodes')->insert([
            'id' => 101,
            'videos_id' => 10,
            'series' => 1,
            'episode' => 2,
            'firstaired' => '2026-08-20 00:00:00',
        ]);
        $this->insertRelease(1, [
            'searchname' => 'A.Different.Title.S01E02.1080p.WEB-DL',
            'videos_id' => 10,
            'tv_episode_lookup_attempted_at' => now()->subHours(7),
        ]);

        (new TvProcessor(false))->process('', 'a', 1);

        $release = DB::table('releases')->find(1);
        $this->assertSame(10, (int) $release->videos_id);
        $this->assertSame(101, (int) $release->tv_episodes_id);
    }

    #[Test]
    public function an_expired_revisit_is_finalized_without_invoking_a_provider(): void
    {
        $this->insertRelease(1, [
            'videos_id' => 10,
            'postdate' => now()->subDays(15),
        ]);

        $finalized = (new TvEpisodeRevisitService)->finalizeExpired('', 'a', 1);

        $this->assertSame(1, $finalized);
        $this->assertSame(-6, (int) DB::table('releases')->where('id', 1)->value('tv_episodes_id'));
    }

    #[Test]
    public function a_recent_final_episode_miss_is_paced_for_a_later_revisit(): void
    {
        $this->insertRelease(1, ['videos_id' => 10, 'tv_episodes_id' => -3]);

        (new TvEpisodeRevisitService)->settleFinalFailure(1);

        $release = DB::table('releases')->find(1);
        $this->assertSame(0, (int) $release->tv_episodes_id);
        $this->assertSame('2026-08-25 12:00:00', $release->tv_episode_lookup_attempted_at);
        $this->assertSame(0, TvProcessingCandidateQuery::count());
    }

    #[Test]
    public function an_old_backfilled_release_becomes_terminal_on_its_first_episode_miss(): void
    {
        $this->insertRelease(1, [
            'videos_id' => 10,
            'tv_episodes_id' => -3,
            'postdate' => now()->subDays(20),
        ]);

        (new TvEpisodeRevisitService)->settleFinalFailure(1);

        $this->assertSame(-6, (int) DB::table('releases')->where('id', 1)->value('tv_episodes_id'));
    }

    #[Test]
    public function the_final_provider_writes_the_designed_terminal_after_a_full_failure(): void
    {
        $provider = new class extends TraktProvider
        {
            public mixed $writtenStatus = null;

            public function __construct()
            {
                $this->echooutput = false;
                $this->titleCache = [];
            }

            public function getTvReleases(string $groupID = '', string $guidChar = '', int $lookupSetting = 1, int $status = 0): array|\Illuminate\Database\Eloquent\Collection|int|Collection
            {
                return collect([['id' => 1, 'searchname' => 'not parseable']]);
            }

            public function parseInfo(string $relname): bool|array
            {
                return false;
            }

            public function setVideoNotFound(mixed $status, mixed $Id): void
            {
                $this->writtenStatus = $status;
            }
        };

        $provider->processSite('', '', 1);

        $this->assertSame(-6, $provider->writtenStatus);
    }

    #[Test]
    public function a_final_provider_revisit_never_rederives_the_known_show(): void
    {
        $provider = new class extends TraktProvider
        {
            public int $showLookupCount = 0;

            /** @var array{video: int, release: int, episode: int}|null */
            public ?array $match = null;

            public function __construct()
            {
                $this->echooutput = false;
                $this->titleCache = [];
            }

            public function getTvReleases(string $groupID = '', string $guidChar = '', int $lookupSetting = 1, int $status = 0): array|\Illuminate\Database\Eloquent\Collection|int|Collection
            {
                return collect([[
                    'id' => 1,
                    'searchname' => 'Unrelated.Title.S01E02',
                    'videos_id' => 10,
                ]]);
            }

            public function parseInfo(string $relname): bool|array
            {
                return [
                    'name' => 'Unrelated Title',
                    'cleanname' => 'Unrelated Title',
                    'season' => 1,
                    'episode' => 2,
                    'airdate' => '',
                ];
            }

            public function getByTitle(string $title, int $type, int $source = 0): mixed
            {
                $this->showLookupCount++;

                return 0;
            }

            public function getShowInfo(string $name): bool|array
            {
                $this->showLookupCount++;

                return false;
            }

            public function getSiteIDFromVideoID(string $siteColumn, int $videoID): mixed
            {
                return 1000;
            }

            public function getLocalZoneFromVideoID(int $videoID): string
            {
                return 'UTC';
            }

            public function getBySeasonEp(int|string $id, int|string $series, int|string $episode, string $airdate = ''): bool|int
            {
                return 101;
            }

            public function setVideoIdFound(int $videoId, int $releaseId, int $episodeId): void
            {
                $this->match = ['video' => $videoId, 'release' => $releaseId, 'episode' => $episodeId];
            }
        };

        $provider->processSite('', '', 1);

        $this->assertSame(0, $provider->showLookupCount);
        $this->assertSame(['video' => 10, 'release' => 1, 'episode' => 101], $provider->match);
    }

    #[Test]
    public function tv_reset_covers_fully_matched_and_episode_missing_rows(): void
    {
        $this->app->instance('env', 'local');
        Search::shouldReceive('updateRelease')->twice();
        $this->insertRelease(1, [
            'videos_id' => 10,
            'tv_episodes_id' => 101,
            'tv_episode_lookup_attempted_at' => now(),
        ]);
        $this->insertRelease(2, [
            'videos_id' => 20,
            'tv_episode_lookup_attempted_at' => now(),
        ]);
        $this->insertRelease(3, ['tv_episodes_id' => -6]);

        $this->artisan('nntmux:resetpp', ['--category' => ['tv']])->assertSuccessful();

        $reset = DB::table('releases')->whereIn('id', [1, 2])->get();
        foreach ($reset as $release) {
            $this->assertSame(0, (int) $release->videos_id);
            $this->assertSame(0, (int) $release->tv_episodes_id);
            $this->assertNull($release->tv_episode_lookup_attempted_at);
        }

        $this->assertSame(-6, (int) DB::table('releases')->where('id', 3)->value('tv_episodes_id'));
    }

    private function collectedTvCount(): int
    {
        $monitor = new TmuxMonitorService;
        $runVar = new ReflectionProperty(TmuxMonitorService::class, 'runVar');
        $runVar->setValue($monitor, [
            'counts' => ['now' => []],
            'settings' => ['processtvrage' => 1],
            'timers' => ['query' => []],
        ]);

        (new ReflectionMethod(TmuxMonitorService::class, 'getProcessCounts'))->invoke($monitor);

        return (int) $runVar->getValue($monitor)['counts']['now']['processtv'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertRelease(int $id, array $overrides = []): void
    {
        DB::table('releases')->insert(array_merge([
            'id' => $id,
            'name' => 'Example.Show.S01E02',
            'searchname' => 'Example.Show.S01E02',
            'groups_id' => 1,
            'size' => 2_000_000,
            'postdate' => now()->subDay(),
            'adddate' => now()->subDay(),
            'guid' => 'a'.str_pad((string) $id, 39, '0'),
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
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('searchname');
            $table->unsignedInteger('groups_id');
            $table->unsignedBigInteger('size');
            $table->dateTime('postdate');
            $table->dateTime('adddate');
            $table->string('guid', 40);
            $table->char('leftguid', 1);
            $table->integer('categories_id');
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->timestamp('tv_episode_lookup_attempted_at')->nullable();
            $table->tinyInteger('isrenamed')->default(0);
            $table->tinyInteger('passwordstatus')->default(0);
            $table->tinyInteger('nfostatus')->default(0);
            $table->tinyInteger('nzbstatus')->default(1);
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->tinyInteger('type')->default(0);
            $table->string('title');
            $table->tinyInteger('source')->default(0);
        });

        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->unsignedInteger('series')->default(0);
            $table->unsignedInteger('episode')->default(0);
            $table->dateTime('firstaired')->nullable();
        });
    }
}
