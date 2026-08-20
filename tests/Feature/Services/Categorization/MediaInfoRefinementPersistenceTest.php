<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Categorization;

use App\Models\AudioData;
use App\Models\Category;
use App\Models\Release;
use App\Models\VideoData;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\Releases\PreviewGenerationPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDO;
use Tests\TestCase;

class MediaInfoRefinementPersistenceTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->databasePath = $this->makeTempPath('nntmux-mediainfo-refinement-test', '.sqlite');
        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('categorizeforeign', '0'), ('catwebdl', '0')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);
        DB::purge();
        DB::reconnect();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_it_persists_category_policy_and_search_sync_for_an_eligible_release(): void
    {
        config(['nntmux.categorization.log' => true]);
        $releaseId = DB::table('releases')->insertGetId([
            'categories_id' => Category::MOVIE_OTHER,
            'iscategorized' => 0,
        ]);
        VideoData::query()->create([
            'releases_id' => $releaseId,
            'videowidth' => 1920,
            'videoheight' => 1080,
            'videoformat' => 'AVC',
        ]);

        $previewPolicy = Mockery::mock(PreviewGenerationPolicy::class);
        $previewPolicy->shouldReceive('restoreOwedPreviews')->once()->with([$releaseId], false)->andReturn(0);
        Log::shouldReceive('info')->once()->with('categorization.mediainfo_refined', [
            'release_id' => $releaseId,
            'old_category_id' => Category::MOVIE_OTHER,
            'new_category_id' => Category::MOVIE_HD,
            'rule' => 'video_hd',
        ]);

        $synchronized = [];
        $coordinator = new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            function (int $releaseId) use (&$synchronized): void {
                $synchronized[] = $releaseId;
            },
        );

        $decision = (new MediaInfoRefinementService($previewPolicy, $coordinator))->refine($releaseId);

        self::assertNotNull($decision);
        self::assertSame(Category::MOVIE_HD, $decision->categoryId);
        self::assertSame([$releaseId], $synchronized);
        $this->assertDatabaseHas('releases', [
            'id' => $releaseId,
            'categories_id' => Category::MOVIE_HD,
            'iscategorized' => 1,
        ]);
    }

    public function test_dry_run_and_ineligible_releases_are_not_written_or_synchronized(): void
    {
        $eligibleId = DB::table('releases')->insertGetId(['categories_id' => Category::TV_OTHER, 'iscategorized' => 1]);
        $ineligibleId = DB::table('releases')->insertGetId(['categories_id' => Category::MOVIE_HD, 'iscategorized' => 1]);
        foreach ([$eligibleId, $ineligibleId] as $releaseId) {
            VideoData::query()->create([
                'releases_id' => $releaseId,
                'videowidth' => 3840,
                'videoheight' => 2160,
            ]);
        }

        $previewPolicy = Mockery::mock(PreviewGenerationPolicy::class);
        $previewPolicy->shouldNotReceive('restoreOwedPreviews');
        $synchronized = [];
        $coordinator = new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            function (int $releaseId) use (&$synchronized): void {
                $synchronized[] = $releaseId;
            },
        );
        $service = new MediaInfoRefinementService($previewPolicy, $coordinator);

        self::assertNotNull($service->refine($eligibleId, true));
        self::assertNull($service->refine($ineligibleId));
        self::assertSame([], $synchronized);
        self::assertSame(Category::TV_OTHER, (int) Release::query()->findOrFail($eligibleId)->categories_id);
        self::assertSame(Category::MOVIE_HD, (int) Release::query()->findOrFail($ineligibleId)->categories_id);
    }

    public function test_audio_refinement_cannot_pull_a_release_out_of_its_group_forced_root(): void
    {
        $groupId = $this->createGroup('alt.binaries.ijsklontje', Category::XXX_ROOT);
        $releaseId = $this->createAudioOnlyRelease($groupId);

        $previewPolicy = Mockery::mock(PreviewGenerationPolicy::class);
        $previewPolicy->shouldNotReceive('restoreOwedPreviews');

        $service = new MediaInfoRefinementService($previewPolicy);

        self::assertNull($service->refine($releaseId));
        self::assertNull($service->refine($releaseId, true));
        self::assertSame(Category::XXX_OTHER, (int) Release::query()->findOrFail($releaseId)->categories_id);
    }

    public function test_audio_refinement_still_moves_a_release_from_a_group_without_a_forced_root(): void
    {
        $groupId = $this->createGroup('alt.binaries.multimedia', null);
        $releaseId = $this->createAudioOnlyRelease($groupId);

        $previewPolicy = Mockery::mock(PreviewGenerationPolicy::class);
        $previewPolicy->shouldReceive('restoreOwedPreviews')->once()->with([$releaseId], false);

        $decision = (new MediaInfoRefinementService($previewPolicy))->refine($releaseId);

        self::assertNotNull($decision);
        self::assertSame(Category::MUSIC_MP3, $decision->categoryId);
        self::assertSame(Category::MUSIC_MP3, (int) Release::query()->findOrFail($releaseId)->categories_id);
    }

    private function createGroup(string $name, ?int $forcedRootCategoryId): int
    {
        return (int) DB::table('usenet_groups')->insertGetId([
            'name' => $name,
            'forced_root_categories_id' => $forcedRootCategoryId,
        ]);
    }

    private function createAudioOnlyRelease(int $groupId): int
    {
        $releaseId = (int) DB::table('releases')->insertGetId([
            'categories_id' => Category::XXX_OTHER,
            'groups_id' => $groupId,
            'iscategorized' => 1,
        ]);

        AudioData::query()->create([
            'releases_id' => $releaseId,
            'audioid' => 1,
            'audioformat' => 'MPEG Audio',
        ]);

        return $releaseId;
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('releases')) {
            Schema::create('releases', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('categories_id');
                $table->unsignedInteger('groups_id')->default(0);
                $table->boolean('iscategorized')->default(false);
            });
        }

        if (! Schema::hasTable('usenet_groups')) {
            Schema::create('usenet_groups', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->default('');
                $table->unsignedInteger('forced_root_categories_id')->nullable();
            });
        }

        if (! Schema::hasTable('video_data')) {
            Schema::create('video_data', function (Blueprint $table): void {
                $table->unsignedInteger('releases_id')->primary();
                $table->string('containerformat')->nullable();
                $table->string('videoformat')->nullable();
                $table->string('videocodec')->nullable();
                $table->integer('videowidth')->nullable();
                $table->integer('videoheight')->nullable();
            });
        }

        if (! Schema::hasTable('audio_data')) {
            Schema::create('audio_data', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('releases_id');
                $table->unsignedInteger('audioid');
                $table->string('audioformat')->nullable();
            });
        }
    }
}
