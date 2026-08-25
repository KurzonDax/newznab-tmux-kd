<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
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
}
