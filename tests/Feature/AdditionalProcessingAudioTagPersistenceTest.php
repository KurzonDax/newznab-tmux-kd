<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Attribute\Mode;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\MediaInfo;
use Mhor\MediaInfo\Type\Audio;
use Mhor\MediaInfo\Type\General;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class AdditionalProcessingAudioTagPersistenceTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $tmpPath;

    /** @var list<int> */
    private array $synchronized = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->string('searchname')->default('');
            $table->string('fromname')->default('');
            $table->unsignedInteger('categories_id')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->integer('haspreview')->default(0);
            $table->integer('passwordstatus')->default(-1);
            $table->boolean('iscategorized')->default(false);
            $table->boolean('isrenamed')->default(false);
            $table->integer('proc_pp')->default(0);
        });

        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('videoformat')->nullable();
        });

        Schema::create('audio_data', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('audioid')->default(0);
            $table->string('audioformat')->nullable();
        });

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('generate_previews')->default(true);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('root_categories_id')->nullable();
        });

        DB::table('root_categories')->insert([['id' => 3000, 'generate_previews' => 1]]);
        DB::table('categories')->insert([
            ['id' => Category::MUSIC_MP3, 'root_categories_id' => 3000],
            ['id' => Category::MUSIC_OTHER, 'root_categories_id' => 3000],
        ]);

        $migration = require database_path('migrations/2026_08_21_090000_create_release_audio_tags_table.php');
        $migration->up();

        $this->tmpPath = $this->makeTempDirectory('nntmux-audio-tags').'/';
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_general_track_tags_are_persisted_for_the_release(): void
    {
        $release = $this->makeRelease();
        [$service, $context] = $this->makeService($release, $this->taggedContainer());

        $result = $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertTrue($result['audioInfo']);
        $this->assertTrue($context->foundAudioInfo);

        $tags = ReleaseAudioTag::query()->where('releases_id', $release->id)->firstOrFail();
        $this->assertSame('Test Album', $tags->album);
        $this->assertSame('Test Artist', $tags->performer);
        $this->assertSame('Album Artist', $tags->album_performer);
        $this->assertSame('Track One', $tags->track_name);
        $this->assertSame(3, $tags->track_position);
        $this->assertSame(12, $tags->track_position_total);
        $this->assertSame('Electronic', $tags->genre);
        $this->assertSame('2019-04-01', $tags->recorded_date);
        $this->assertSame(2019, $tags->recorded_year);
        $this->assertSame('d1b2c3d4-0000-4000-8000-abcdefabcdef', $tags->musicbrainz_album_id);
        $this->assertSame('MPEG Audio', $tags->audio_format);
        $this->assertSame('audiofile.MP3', $tags->source_file);
        $this->assertSame('ripped by someone', $tags->raw_tags['comment'] ?? null);
        $this->assertFalse($tags->has_preview);
        $this->assertFalse($tags->has_spectrogram);
        $this->assertNull($tags->preview_extension);
    }

    public function test_tags_are_persisted_even_when_the_release_is_predb_matched(): void
    {
        $release = $this->makeRelease(['predb_id' => 77]);
        [$service, $context] = $this->makeService($release, $this->taggedContainer(), ['renameMusicMediaInfo' => true]);

        $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertDatabaseHas('release_audio_tags', ['releases_id' => $release->id, 'album' => 'Test Album']);
        $this->assertSame('Original.Release.Name', Release::query()->findOrFail($release->id)->searchname);
    }

    public function test_an_untagged_file_writes_no_row(): void
    {
        $release = $this->makeRelease();
        $general = new General;
        $general->set('format', new Mode('MPEG Audio', 'MPEG Audio'));
        $container = new MediaInfoContainer;
        $container->setGeneral($general);

        [$service, $context] = $this->makeService($release, $container, [], expectsExtraXml: false);

        $result = $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertFalse($result['audioInfo']);
        $this->assertFalse($context->foundAudioInfo);
        $this->assertDatabaseCount('release_audio_tags', 0);
    }

    public function test_tags_carried_only_on_an_audio_track_are_not_mistaken_for_general_track_tags(): void
    {
        $release = $this->makeRelease();
        $container = new MediaInfoContainer;
        $container->setGeneral(new General);
        $audio = new Audio;
        $audio->set('album', 'Test Album');
        $audio->set('performer', 'Test Artist');
        $container->add($audio);

        [$service, $context] = $this->makeService($release, $container, [], expectsExtraXml: false);

        $result = $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertFalse($result['audioInfo']);
        $this->assertDatabaseCount('release_audio_tags', 0);
    }

    public function test_reprocessing_a_release_refreshes_the_single_row(): void
    {
        $release = $this->makeRelease();
        [$service, $context] = $this->makeService($release, $this->taggedContainer());
        $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $updated = $this->taggedContainer();
        $updated->getGeneral()?->set('album', 'Remastered Album');
        [$service, $context] = $this->makeService($release, $updated);
        $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertDatabaseCount('release_audio_tags', 1);
        $this->assertSame(
            'Remastered Album',
            ReleaseAudioTag::query()->where('releases_id', $release->id)->firstOrFail()->album
        );
    }

    public function test_a_tagged_release_without_a_predb_match_is_still_renamed_and_recategorised(): void
    {
        $release = $this->makeRelease(['categories_id' => Category::MUSIC_OTHER]);
        [$service, $context] = $this->makeService($release, $this->taggedContainer(), ['renameMusicMediaInfo' => true]);

        $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $renamed = Release::query()->findOrFail($release->id);
        $this->assertSame('Test Artist - Test Album (2019) MP3', $renamed->searchname);
        $this->assertSame(Category::MUSIC_MP3, (int) $renamed->categories_id);
        $this->assertSame(1, (int) $renamed->isrenamed);
        $this->assertSame(1, (int) $renamed->iscategorized);
        $this->assertSame(1, (int) $renamed->proc_pp);
        $this->assertSame([$release->id], $this->synchronized);
        $this->assertDatabaseHas('release_audio_tags', ['releases_id' => $release->id, 'album' => 'Test Album']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRelease(array $attributes = []): Release
    {
        $id = DB::table('releases')->insertGetId(array_merge([
            'guid' => 'audio-tags-guid',
            'searchname' => 'Original.Release.Name',
            'categories_id' => Category::MUSIC_MP3,
        ], $attributes));

        return Release::query()->findOrFail($id);
    }

    private function taggedContainer(): MediaInfoContainer
    {
        $general = new General;
        foreach ([
            'format' => new Mode('MPEG Audio', 'MPEG Audio'),
            'album' => 'Test Album',
            'album_performer' => 'Album Artist',
            'performer' => 'Test Artist',
            'track_name' => 'Track One',
            'track_name_position' => '3',
            'track_name_total' => '12',
            'genre' => 'Electronic',
            'recorded_date' => '2019-04-01',
            'comment' => 'ripped by someone',
            'musicbrainz_album_id' => 'd1b2c3d4-0000-4000-8000-abcdefabcdef',
            'complete_name' => '/tmp/nntmux/1234/audiofile.MP3',
        ] as $key => $value) {
            $general->set($key, $value);
        }

        $container = new MediaInfoContainer;
        $container->setGeneral($general);

        return $container;
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     * @return array{0: MediaExtractionService, 1: ReleaseProcessingContext}
     */
    private function makeService(
        Release $release,
        MediaInfoContainer $container,
        array $configOverrides = [],
        bool $expectsExtraXml = true,
    ): array {
        $config = $this->makeConfig(array_merge([
            'processAudioInfo' => true,
            'processAudioSample' => false,
        ], $configOverrides));

        $releaseExtra = Mockery::mock(ReleaseExtraService::class);
        if ($expectsExtraXml) {
            $releaseExtra->shouldReceive('addFromXml')->once();
        } else {
            $releaseExtra->shouldNotReceive('addFromXml');
        }

        $coordinator = new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            function (int $releaseId): void {
                $this->synchronized[] = $releaseId;
            },
        );

        $service = new MediaExtractionService(
            $config,
            Mockery::mock(ReleaseImageService::class),
            $releaseExtra,
            Mockery::mock(CategorizationService::class),
            new VideoFrameExtractor($config),
            $coordinator,
        );

        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->andReturn($container);
        (new ReflectionProperty(MediaExtractionService::class, 'mediaInfo'))->setValue($service, $mediaInfo);

        file_put_contents($this->tmpPath.'audiofile.MP3', 'pretend audio payload');

        return [$service, new ReleaseProcessingContext($release)];
    }
}
