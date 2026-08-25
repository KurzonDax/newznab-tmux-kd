<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\MetadataProcessing\AnimeProcessingCandidateQuery;
use App\Services\MetadataProcessing\BookProcessingCandidateQuery;
use App\Services\MetadataProcessing\ConsoleProcessingCandidateQuery;
use App\Services\MetadataProcessing\MusicProcessingCandidateQuery;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Runners\PostProcessRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseRenameMetadataEligibilityTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '1',
            'lookupanidb' => '1',
            'lookupbooks' => '1',
            'lookupgames' => '1',
            'lookupmusic' => '1',
            'postthreadsamazon' => '1',
            'postthreadsnon' => '1',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_central_renames_remain_eligible_for_metadata_processing(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        $synchronizedReleaseIds = [];
        $updateService = new ReleaseUpdateService(
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                static function (int $releaseId) use (&$synchronizedReleaseIds): void {
                    $synchronizedReleaseIds[] = $releaseId;
                },
            ),
        );

        $categories = [
            1 => Category::MUSIC_MP3,
            2 => Category::GAME_XBOX360,
            3 => Category::BOOKS_EBOOK,
            4 => Category::TV_ANIME,
        ];

        foreach ($categories as $releaseId => $categoryId) {
            $release = Release::withoutEvents(
                fn (): Release => Release::factory()->create($this->releaseRow($releaseId, $categoryId)),
            );

            $updateService->updateRelease(
                $release,
                'Renamed.Metadata.Candidate.'.$releaseId,
                'nfoCheck: Title Match',
                true,
                'NFO, ',
                true,
                false,
            );
        }

        $this->assertSame([1, 2, 3, 4], $synchronizedReleaseIds);

        foreach (DB::table('releases')->orderBy('id')->get() as $release) {
            $this->assertNull($release->musicinfo_id);
            $this->assertNull($release->consoleinfo_id);
            $this->assertNull($release->bookinfo_id);
            $this->assertNull($release->anidbid);
            $this->assertSame(0, (int) $release->gamesinfo_id);
            $this->assertNull($release->movieinfo_id);
        }

        $runner = new class extends PostProcessRunner
        {
            /** @var list<string> */
            public array $capturedCommands = [];

            protected function executeCommand(string $command): string
            {
                $this->capturedCommands[] = $command;

                return '';
            }

            protected function headerStart(string $workType, int $count, int $maxProcesses): void {}

            protected function headerNone(): void {}
        };

        $runner->processMusic();
        $runner->processConsoles();
        $runner->processBooks();
        $runner->processAnime();

        $this->assertCount(4, $runner->capturedCommands);
        $this->assertStringContainsString('artisan postprocess:guid music 1', $runner->capturedCommands[0]);
        $this->assertStringContainsString('artisan postprocess:guid console 2', $runner->capturedCommands[1]);
        $this->assertStringContainsString('artisan postprocess:guid books 3', $runner->capturedCommands[2]);
        $this->assertStringContainsString('artisan postprocess:guid anime 4', $runner->capturedCommands[3]);

        $this->assertSame(1, MusicProcessingCandidateQuery::query()->count());
        $this->assertSame(1, ConsoleProcessingCandidateQuery::query()->count());
        $this->assertSame(1, BookProcessingCandidateQuery::query()->count());
        $this->assertSame(1, AnimeProcessingCandidateQuery::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(int $releaseId, int $categoryId): array
    {
        return [
            'id' => $releaseId,
            'name' => 'Original.Metadata.Candidate.'.$releaseId,
            'searchname' => 'Original.Metadata.Candidate.'.$releaseId,
            'groups_id' => 1,
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => str_repeat((string) $releaseId, 40),
            'leftguid' => (string) $releaseId,
            'fromname' => 'poster@example.com',
            'categories_id' => $categoryId,
            'isrenamed' => 0,
            'musicinfo_id' => 101,
            'consoleinfo_id' => 102,
            'bookinfo_id' => 103,
            'anidbid' => 104,
            'gamesinfo_id' => 105,
            'movieinfo_id' => 106,
        ];
    }

    private function createSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('searchname');
            $table->string('searchname_normalized')->nullable();
            $table->unsignedInteger('groups_id');
            $table->unsignedBigInteger('size');
            $table->dateTime('postdate');
            $table->dateTime('adddate');
            $table->string('guid', 40);
            $table->char('leftguid', 1);
            $table->string('fromname')->nullable();
            $table->integer('categories_id');
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('bookinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->integer('movieinfo_id')->nullable();
            $table->unsignedInteger('predb_id')->default(0);
            $table->tinyInteger('iscategorized')->default(0);
            $table->tinyInteger('isrenamed')->default(0);
            $table->tinyInteger('is_trusted_name')->default(0);
            $table->tinyInteger('proc_nfo')->default(0);
            $table->tinyInteger('proc_files')->default(0);
            $table->tinyInteger('proc_par2')->default(0);
            $table->tinyInteger('proc_uid')->default(0);
            $table->tinyInteger('proc_hash16k')->default(0);
            $table->tinyInteger('proc_srr')->default(0);
            $table->tinyInteger('proc_crc32')->default(0);
            $table->tinyInteger('nfostatus')->default(0);
            $table->tinyInteger('nzbstatus')->default(1);
            $table->tinyInteger('passwordstatus')->default(0);
        });
    }
}
