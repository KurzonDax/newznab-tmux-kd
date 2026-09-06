<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseNameTransitionEquivalenceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('searchname');
            $table->string('searchname_normalized')->default('');
            $table->string('display_name')->nullable();
            $table->unsignedInteger('groups_id');
            $table->integer('categories_id');
            $table->string('fromname')->nullable();
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->integer('movieinfo_id')->nullable();
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('bookinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->tinyInteger('iscategorized')->default(0);
            $table->tinyInteger('isrenamed')->default(0);
            $table->tinyInteger('is_trusted_name')->default(0);
            $table->tinyInteger('proc_files')->default(0);
            $table->tinyInteger('proc_pp')->default(0);
        });

        Event::fake([ReleaseNameFixed::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_all_entry_points_apply_equivalent_canonical_side_effects(): void
    {
        $targetName = 'Canonical.Release.2026-GROUP';
        foreach (range(1, 4) as $releaseId) {
            DB::table('releases')->insert([
                'id' => $releaseId,
                'name' => 'Obfuscated.Release.'.$releaseId,
                'searchname' => $releaseId === 4 ? $targetName : 'Obfuscated.Release.'.$releaseId,
                'groups_id' => 1,
                'categories_id' => Category::OTHER_HASHED,
                'fromname' => 'poster@example.test',
                'videos_id' => 41,
                'tv_episodes_id' => 42,
                'movieinfo_id' => 43,
                'imdbid' => 'tt1234567',
                'musicinfo_id' => 44,
                'consoleinfo_id' => 45,
                'bookinfo_id' => 46,
                'anidbid' => 47,
                'gamesinfo_id' => 48,
            ]);
        }

        $synchronized = [];
        $updates = new ReleaseUpdateService(
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                static function (int $releaseId) use (&$synchronized): void {
                    $synchronized[] = $releaseId;
                },
            ),
        );
        $updates->updateRelease(
            Release::query()->findOrFail(1),
            $targetName,
            'Heuristic filename',
            true,
            'Filenames, ',
            true,
            false,
        );
        $updates->renameFromBookMetadata(2, $targetName);
        $updates->renameFromAudioTags(3, $targetName, Category::MUSIC_MP3);
        $updates->attachPredbId(4, 77);

        $releases = DB::table('releases')->orderBy('id')->get()->keyBy('id');
        foreach ($releases as $release) {
            $this->assertSame(0, (int) $release->videos_id);
            $this->assertSame(0, (int) $release->tv_episodes_id);
            $this->assertNull($release->movieinfo_id);
            $this->assertNull($release->imdbid);
            $this->assertNull($release->musicinfo_id);
            $this->assertNull($release->consoleinfo_id);
            $this->assertNull($release->anidbid);
            $this->assertSame(0, (int) $release->gamesinfo_id);
            $this->assertSame(1, (int) $release->isrenamed);
            $this->assertSame(1, (int) $release->iscategorized);
            $this->assertSame($targetName, $release->searchname);
            $this->assertSame($targetName, $release->searchname_normalized);
        }

        $this->assertNull($releases[1]->bookinfo_id);
        $this->assertSame(46, (int) $releases[2]->bookinfo_id);
        $this->assertNull($releases[3]->bookinfo_id);
        $this->assertNull($releases[4]->bookinfo_id);
        $this->assertSame([1 => 0, 2 => 0, 3 => 1, 4 => 1], $releases->mapWithKeys(
            static fn (object $release): array => [(int) $release->id => (int) $release->is_trusted_name],
        )->all());
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 77], $releases->mapWithKeys(
            static fn (object $release): array => [(int) $release->id => (int) $release->predb_id],
        )->all());
        $this->assertSame(Category::MUSIC_MP3, (int) $releases[3]->categories_id);
        $this->assertSame([1, 2, 3, 4], $synchronized);

        Event::assertDispatchedTimes(ReleaseNameFixed::class, 4);
        $events = Event::dispatched(ReleaseNameFixed::class)
            ->map(static fn (array $arguments): ReleaseNameFixed => $arguments[0])
            ->keyBy('releaseId');
        foreach ($events as $event) {
            $this->assertSame($targetName, $event->newName);
            $this->assertSame(Category::OTHER_HASHED, $event->oldCategoryId);
            $this->assertSame(1, $event->groupId);
            $this->assertSame('poster@example.test', $event->poster);
        }
        $this->assertNull($events[1]->categoryOverride);
        $this->assertNull($events[2]->categoryOverride);
        $this->assertSame(Category::MUSIC_MP3, $events[3]->categoryOverride);
        $this->assertNull($events[4]->categoryOverride);
    }

    public function test_output_category_event_and_storage_share_one_finalized_name_in_live_and_dry_runs(): void
    {
        config(['nntmux.echocli' => true]);
        $currentName = 'Original Show S01E01 1080p WEB-DL English AAC 2.0-GROUP';
        $candidate = 'Show S01E01 1080p WEB-DL-GROUP';
        $finalizedName = 'Show.S01E01.1080p.WEB-DL AAC 2.0 English-GROUP';
        DB::table('releases')->insert([
            'id' => 10,
            'name' => $currentName,
            'searchname' => $currentName,
            'groups_id' => 1,
            'categories_id' => Category::TV_HD,
            'fromname' => 'poster@example.test',
        ]);

        $category = Mockery::mock(CategorizationService::class);
        $category->shouldReceive('determineCategory')
            ->twice()
            ->with(1, $finalizedName, 'poster@example.test', false, [], 10)
            ->andReturn(['categories_id' => Category::TV_HD]);

        $synchronized = [];
        $coordinator = new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            static function (int $releaseId) use (&$synchronized): void {
                $synchronized[] = $releaseId;
            },
        );

        $live = $this->capturingUpdateService($category, $coordinator);
        $live->updateRelease(
            Release::query()->findOrFail(10),
            $candidate,
            'file matched source: title match',
            true,
            'Filenames, ',
            true,
            true,
        );

        $stored = Release::query()->findOrFail(10);
        $this->assertSame($finalizedName, $live->reportedName);
        $this->assertSame($finalizedName, $stored->searchname);
        $this->assertSame($finalizedName, Event::dispatched(ReleaseNameFixed::class)->last()[0]->newName);
        $this->assertSame([10], $synchronized);

        DB::table('releases')->where('id', 10)->update([
            'searchname' => $currentName,
            'searchname_normalized' => $currentName,
        ]);
        $dryRun = $this->capturingUpdateService($category, $coordinator);
        $dryRun->updateRelease(
            Release::query()->findOrFail(10),
            $candidate,
            'file matched source: title match',
            false,
            'Filenames, ',
            true,
            true,
        );

        $this->assertSame($finalizedName, $dryRun->reportedName);
        $this->assertSame($currentName, Release::query()->findOrFail(10)->searchname);
        $this->assertSame([10], $synchronized);
    }

    private function capturingUpdateService(
        CategorizationService $category,
        ReleaseSearchSyncCoordinator $coordinator,
    ): ReleaseUpdateService {
        return new class($category, $coordinator) extends ReleaseUpdateService
        {
            public ?string $reportedName = null;

            public function __construct(
                CategorizationService $category,
                ReleaseSearchSyncCoordinator $coordinator,
            ) {
                parent::__construct(category: $category, searchSyncCoordinator: $coordinator);
            }

            /**
             * @param  array<string, mixed>  $determinedCategory
             */
            public function echoReleaseInfo(
                object $release,
                string $newName,
                array $determinedCategory,
                string $type,
                string $method,
            ): void {
                $this->reportedName = $newName;
            }
        };
    }
}
