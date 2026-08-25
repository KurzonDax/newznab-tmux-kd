<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\MetadataProcessing\AnimeProcessingCandidateQuery;
use App\Services\MetadataProcessing\BookProcessingCandidateQuery;
use App\Services\MetadataProcessing\ConsoleProcessingCandidateQuery;
use App\Services\MetadataProcessing\GameProcessingCandidateQuery;
use App\Services\MetadataProcessing\MovieProcessingCandidateQuery;
use App\Services\MetadataProcessing\MusicProcessingCandidateQuery;
use App\Services\MetadataProcessing\NfoProcessingCandidateQuery;
use App\Services\Runners\PostProcessRunner;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class MetadataProcessingCandidateQueryTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'lookupanidb' => '0',
            'lookupbooks' => '0',
            'lookupgames' => '0',
            'lookupimdb' => '0',
            'lookupmusic' => '2',
            'lookupnfo' => '0',
            'lookuptv' => '0',
            'maxnforetries' => '3',
            'maxsizetoprocessnfo' => '0',
            'minsizetoprocessnfo' => '0',
            'postthreadsamazon' => '1',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
        Log::spy();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_music_renamed_only_mode_agrees_at_worker_runner_and_monitor_seams(): void
    {
        $this->insertRelease(1, Category::MUSIC_MP3, 'a', false);
        $this->insertRelease(2, Category::MUSIC_LOSSLESS, 'b', true);
        $this->insertRelease(3, Category::MUSIC_AUDIOBOOK, 'c', true);

        $this->assertSame([2], MusicProcessingCandidateQuery::query()->pluck('id')->all());

        $runner = $this->capturingRunner();
        $runner->processMusic();

        $this->assertCount(1, $runner->capturedCommands);
        $this->assertStringContainsString('artisan postprocess:guid music b', $runner->capturedCommands[0]);

        $monitor = new TmuxMonitorService;
        $monitor->initializeMonitor();
        $statistics = $monitor->collectStatistics();

        $this->assertSame(1, (int) $statistics['counts']['now']['processmusic']);
    }

    public function test_audiobook_and_wrapper_backlogs_agree_at_worker_runner_and_monitor_seams(): void
    {
        DB::table('settings')->where('name', 'lookupbooks')->update(['value' => '1']);
        $this->insertRelease(1, Category::MUSIC_AUDIOBOOK, 'a', false);
        $this->insertRelease(2, Category::BOOKS_MAGAZINES, 'b', false);
        DB::table('releases')->where('id', 2)->update([
            'name' => 'N_NZB_Wrapper_Book',
            'searchname' => 'N:/NZB Wrapper Book',
            'bookinfo_id' => 55,
        ]);

        $this->assertSame([1, 2], BookProcessingCandidateQuery::query()->pluck('id')->all());

        $runner = $this->capturingRunner();
        $runner->processBooks();

        $this->assertCount(2, $runner->capturedCommands);
        $this->assertStringContainsString('artisan postprocess:guid books a', $runner->capturedCommands[0]);
        $this->assertStringContainsString('artisan postprocess:guid books b', $runner->capturedCommands[1]);

        $monitor = new TmuxMonitorService;
        $monitor->initializeMonitor();
        $statistics = $monitor->collectStatistics();

        $this->assertSame(2, (int) $statistics['counts']['now']['processbooks']);
    }

    public function test_game_renamed_only_mode_agrees_at_worker_runner_and_monitor_seams(): void
    {
        DB::table('settings')->where('name', 'lookupgames')->update(['value' => '2']);
        $this->insertRelease(1, Category::GAME_XBOX360, 'a', false);
        $this->insertRelease(2, Category::GAME_PS4, 'b', true);
        $this->insertRelease(3, Category::PC_GAMES, 'c', false);
        $this->insertRelease(4, Category::PC_GAMES, 'd', true);

        $this->assertSame([2], ConsoleProcessingCandidateQuery::query()->pluck('id')->all());
        $this->assertSame([4], GameProcessingCandidateQuery::query()->pluck('id')->all());

        $runner = $this->capturingRunner();
        $runner->processConsoles();
        $runner->processGames();

        $this->assertCount(2, $runner->capturedCommands);
        $this->assertStringContainsString('artisan postprocess:guid console b', $runner->capturedCommands[0]);
        $this->assertStringContainsString('artisan postprocess:guid games d', $runner->capturedCommands[1]);

        $monitor = new TmuxMonitorService;
        $monitor->initializeMonitor();
        $statistics = $monitor->collectStatistics();

        $this->assertSame(1, (int) $statistics['counts']['now']['processconsole']);
        $this->assertSame(1, (int) $statistics['counts']['now']['processgames']);
    }

    public function test_movie_renamed_only_mode_agrees_at_worker_runner_and_monitor_seams(): void
    {
        DB::table('settings')->where('name', 'lookupimdb')->update(['value' => '2']);
        $this->insertRelease(1, Category::MOVIE_HD, 'a', false);
        $this->insertRelease(2, Category::MOVIE_SD, 'b', true);
        $this->insertRelease(3, Category::TV_HD, 'c', true);

        $this->assertSame([2], MovieProcessingCandidateQuery::query()->pluck('id')->all());

        $runner = $this->capturingRunner();
        $runner->processMovies(false);

        $this->assertCount(1, $runner->capturedCommands);
        $this->assertStringContainsString('artisan postprocess:guid movie b', $runner->capturedCommands[0]);

        $monitor = new TmuxMonitorService;
        $monitor->initializeMonitor();
        $statistics = $monitor->collectStatistics();

        $this->assertSame(1, (int) $statistics['counts']['now']['processmovies']);
    }

    public function test_disabled_tv_lookup_has_no_worker_runner_or_monitor_work(): void
    {
        $this->insertRelease(1, Category::TV_HD, 'a', true);

        $this->assertSame([], TvProcessingCandidateQuery::query()->pluck('id')->all());

        $runner = $this->capturingRunner();
        $runner->processTv(false);

        $this->assertSame([], $runner->capturedCommands);

        $monitor = new TmuxMonitorService;
        $monitor->initializeMonitor();
        $statistics = $monitor->collectStatistics();

        $this->assertSame(0, (int) $statistics['counts']['now']['processtv']);
    }

    public function test_anime_lookup_agrees_at_worker_runner_and_monitor_seams(): void
    {
        DB::table('settings')->where('name', 'lookupanidb')->update(['value' => '1']);
        $this->insertRelease(1, Category::TV_ANIME, 'a', false);
        $this->insertRelease(2, Category::TV_HD, 'b', true);

        $this->assertSame([1], AnimeProcessingCandidateQuery::query()->pluck('id')->all());

        $runner = $this->capturingRunner();
        $runner->processAnime();

        $this->assertCount(1, $runner->capturedCommands);
        $this->assertStringContainsString('artisan postprocess:guid anime a', $runner->capturedCommands[0]);

        $monitor = new TmuxMonitorService;
        $monitor->initializeMonitor();
        $statistics = $monitor->collectStatistics();

        $this->assertSame(1, (int) $statistics['counts']['now']['processanime']);
    }

    public function test_nfo_toggle_and_shared_predicate_agree_at_worker_runner_and_monitor_seams(): void
    {
        $this->insertRelease(1, Category::OTHER_MISC, 'a', false);
        $this->insertRelease(2, Category::OTHER_MISC, 'b', false);
        DB::table('releases')->where('id', 2)->update(['nzbstatus' => 0]);

        $this->assertSame([], NfoProcessingCandidateQuery::query()->pluck('id')->all());

        $disabledRunner = $this->capturingRunner();
        $disabledRunner->processNfo();
        $this->assertSame([], $disabledRunner->capturedCommands);

        $disabledStatistics = $this->collectStatistics();
        $this->assertSame(0, (int) $disabledStatistics['counts']['now']['processnfo']);

        DB::table('settings')->where('name', 'lookupnfo')->update(['value' => '1']);

        $this->assertSame([1], NfoProcessingCandidateQuery::query()->pluck('id')->all());

        $enabledRunner = $this->capturingRunner();
        $enabledRunner->processNfo();
        $this->assertCount(1, $enabledRunner->capturedCommands);
        $this->assertStringContainsString('artisan postprocess:guid nfo a', $enabledRunner->capturedCommands[0]);

        $enabledStatistics = $this->collectStatistics();
        $this->assertSame(1, (int) $enabledStatistics['counts']['now']['processnfo']);
    }

    /**
     * @param  class-string  $candidateQuery
     */
    #[DataProvider('metadataTypeProvider')]
    public function test_lookup_mode_matrix_agrees_at_worker_runner_and_monitor_seams(
        string $candidateQuery,
        string $setting,
        string $runnerMethod,
        string $commandFragment,
        string $countKey,
        int $enabledMode,
        int $categoryId,
        int $expectedEnabledCount,
    ): void {
        DB::table('settings')->where('name', $setting)->update(['value' => '0']);
        $this->insertRelease(1, $categoryId, 'a', true);

        $this->assertSame([], $candidateQuery::query()->pluck('id')->all());

        $disabledRunner = $this->capturingRunner();
        $this->runMetadataType($disabledRunner, $runnerMethod);
        $this->assertSame([], $disabledRunner->capturedCommands);
        $this->assertSame(0, (int) $this->collectStatistics()['counts']['now'][$countKey]);

        DB::table('releases')->delete();
        DB::table('settings')->where('name', $setting)->update(['value' => (string) $enabledMode]);
        $this->insertRelease(1, $categoryId, 'a', false);
        $this->insertRelease(2, $categoryId, 'b', true);

        $this->assertCount($expectedEnabledCount, $candidateQuery::query()->get());

        $enabledRunner = $this->capturingRunner();
        $this->runMetadataType($enabledRunner, $runnerMethod);
        $this->assertCount($expectedEnabledCount, $enabledRunner->capturedCommands);
        foreach ($enabledRunner->capturedCommands as $command) {
            $this->assertStringContainsString('artisan '.$commandFragment, $command);
        }
        $this->assertSame(
            $expectedEnabledCount,
            (int) $this->collectStatistics()['counts']['now'][$countKey],
        );
    }

    /**
     * @return array<string, array{class-string, string, string, string, string, int, int, int}>
     */
    public static function metadataTypeProvider(): array
    {
        return [
            'anime' => [AnimeProcessingCandidateQuery::class, 'lookupanidb', 'processAnime', 'postprocess:guid anime', 'processanime', 1, Category::TV_ANIME, 2],
            'books' => [BookProcessingCandidateQuery::class, 'lookupbooks', 'processBooks', 'postprocess:guid books', 'processbooks', 2, Category::BOOKS_EBOOK, 1],
            'console' => [ConsoleProcessingCandidateQuery::class, 'lookupgames', 'processConsoles', 'postprocess:guid console', 'processconsole', 2, Category::GAME_PS4, 1],
            'PC games' => [GameProcessingCandidateQuery::class, 'lookupgames', 'processGames', 'postprocess:guid games', 'processgames', 2, Category::PC_GAMES, 1],
            'movies' => [MovieProcessingCandidateQuery::class, 'lookupimdb', 'processMovies', 'postprocess:guid movie', 'processmovies', 2, Category::MOVIE_HD, 1],
            'music' => [MusicProcessingCandidateQuery::class, 'lookupmusic', 'processMusic', 'postprocess:guid music', 'processmusic', 2, Category::MUSIC_MP3, 1],
            'NFO' => [NfoProcessingCandidateQuery::class, 'lookupnfo', 'processNfo', 'postprocess:guid nfo', 'processnfo', 1, Category::OTHER_MISC, 2],
            'TV' => [TvProcessingCandidateQuery::class, 'lookuptv', 'processTv', 'postprocess:tv-pipeline', 'processtv', 2, Category::TV_HD, 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectStatistics(): array
    {
        $monitor = new TmuxMonitorService;
        $monitor->initializeMonitor();

        return $monitor->collectStatistics();
    }

    private function runMetadataType(PostProcessRunner $runner, string $method): void
    {
        match ($method) {
            'processMovies' => $runner->processMovies(false),
            'processTv' => $runner->processTv(false),
            default => $runner->{$method}(),
        };
    }

    private function capturingRunner(): PostProcessRunner
    {
        config(['nntmux.stream_fork_output' => true]);

        return new class extends PostProcessRunner
        {
            /** @var list<string> */
            public array $capturedCommands = [];

            protected function executeCommand(string $command): string
            {
                $this->capturedCommands[] = $command;

                return '';
            }

            protected function runStreamingCommands(
                array $commands,
                int $maxProcesses,
                string $desc,
                ?callable $onComplete = null,
            ): void {
                array_push($this->capturedCommands, ...$commands);
            }

            protected function headerStart(string $workType, int $count, int $maxProcesses): void {}

            protected function headerNone(): void {}
        };
    }

    private function insertRelease(int $id, int $categoryId, string $leftGuid, bool $renamed): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => 'Metadata Candidate '.$id,
            'searchname' => 'Metadata Candidate '.$id,
            'groups_id' => 1,
            'size' => 2_000_000,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => $leftGuid.str_repeat('0', 39),
            'leftguid' => $leftGuid,
            'categories_id' => $categoryId,
            'isrenamed' => $renamed,
        ]);
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
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('bookinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->tinyInteger('isrenamed')->default(0);
            $table->tinyInteger('nfostatus')->default(-1);
            $table->tinyInteger('nzbstatus')->default(1);
            $table->tinyInteger('passwordstatus')->default(0);
        });

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->tinyInteger('active')->default(1);
        });
    }
}
