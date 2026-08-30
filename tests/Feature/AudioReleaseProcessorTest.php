<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseAudioTag;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\AudioTagExtractor;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AudioProcessing\AudioDecodableLengthProbe;
use App\Services\AudioProcessing\AudioEvidenceRecorder;
use App\Services\AudioProcessing\AudioFetcher;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\AudioReleaseProcessor;
use App\Services\AudioProcessing\AudioRouting;
use App\Services\AudioProcessing\AudioSourceSelector;
use App\Services\AudioProcessing\AudioTagRenamer;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\ReleaseExtraService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Attribute\Duration;
use Mhor\MediaInfo\Attribute\Mode;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\MediaInfo;
use Mhor\MediaInfo\Type\Audio;
use Mhor\MediaInfo\Type\General;
use Mhor\MediaInfo\Type\Video;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class AudioReleaseProcessorTest extends TestCase
{
    private string $tmpPath;

    private string $savePath;

    /** @var list<list<string>> */
    private array $downloads = [];

    /** @var list<int> */
    private array $timeoutCountsAtDownload = [];

    /** @var list<int> */
    private array $synchronized = [];

    /** @var list<list<string>> */
    private array $encoderCommands = [];

    private bool $renamesFromTags = false;

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
            $table->char('leftguid', 1)->default('a');
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->string('searchname_normalized')->nullable();
            $table->string('display_name')->nullable();
            $table->unsignedInteger('categories_id')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->string('fromname')->default('');
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->integer('movieinfo_id')->nullable();
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('bookinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->integer('proc_pp')->default(0);
            $table->boolean('iscategorized')->default(false);
            $table->boolean('isrenamed')->default(false);
            $table->boolean('is_trusted_name')->default(false);
            $table->integer('haspreview')->default(-1);
            $table->integer('passwordstatus')->default(-1);
            $table->integer('nzbstatus')->default(1);
            $table->unsignedBigInteger('size')->default(5 * 1024 * 1024);
            $table->double('completion')->default(100);
            $table->dateTime('postdate')->nullable();
            $table->unsignedInteger('pp_timeout_count')->default(0);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });

        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
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

        DB::table('root_categories')->insert([['id' => Category::MUSIC_ROOT, 'generate_previews' => 1]]);
        DB::table('categories')->insert([
            ['id' => Category::MUSIC_MP3, 'root_categories_id' => Category::MUSIC_ROOT],
            ['id' => Category::MUSIC_OTHER, 'root_categories_id' => Category::MUSIC_ROOT],
        ]);

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.sounds.lossless', 'forced_root_categories_id' => null],
        ]);

        $migration = require database_path('migrations/2026_08_21_090000_create_release_audio_tags_table.php');
        $migration->up();
        $evidenceMigrations = glob(database_path('migrations/*_create_release_audio_evidence_tables.php')) ?: [];
        $this->assertCount(1, $evidenceMigrations);
        (require $evidenceMigrations[0])->up();

        // The search driver is unreachable in tests and the refinement/rename
        // paths sync through it; swap it out rather than log a page of failures.
        Search::swap(Mockery::mock()->shouldIgnoreMissing());

        $this->tmpPath = $this->makeTempDirectory('nntmux-audio-processor').'/';
        $this->savePath = $this->makeTempDirectory('nntmux-audio-store').'/';
        $this->downloads = [];
        $this->timeoutCountsAtDownload = [];
        $this->synchronized = [];
        $this->encoderCommands = [];
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_tags_are_persisted_from_the_probe_article_before_the_rest_is_fetched(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            nzbContents: [
                ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>', '<seg-3>']],
                ['title' => '"02 - another track.mp3" yEnc', 'segments' => ['<second-1>', '<second-2>']],
                ['title' => '"Album.cue" yEnc', 'segments' => ['<cue>']],
                ['title' => '"Album.m3u" yEnc', 'segments' => ['<playlist>']],
                ['title' => '"Album.log" yEnc', 'segments' => ['<log>']],
            ],
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        // The probe article on its own, then the remaining head in one request.
        $this->assertSame([['<seg-1>'], ['<seg-2>', '<seg-3>']], $this->downloads);

        $tags = ReleaseAudioTag::query()->where('releases_id', $release->id)->firstOrFail();
        $this->assertSame('Test Album', $tags->album);
        $this->assertSame('Test Artist', $tags->performer);
        $this->assertSame('MPEG Audio', $tags->audio_format);

        $evidence = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole();
        $this->assertCount(2, $evidence->nzb_manifest);
        $this->assertSame(['cue', 'playlist', 'eac_log'], array_column($evidence->sidecar_manifest, 'kind'));
        $this->assertTrue($evidence->source_file_complete);
        $this->assertTrue($evidence->source_starts_at_zero);
        $this->assertFalse($evidence->whole_duration_reliable);
        $this->assertTrue($evidence->only_one_track_probed);
        $this->assertNull($evidence->archive_manifest_complete);
        $this->assertSame('USRC17607839', $evidence->tracks()->where('source_ordinal', 1)->value('isrc'));
        $this->assertSame(
            '11111111-1111-4111-8111-111111111111',
            $evidence->tracks()->where('source_ordinal', 1)->value('musicbrainz_recording_id'),
        );
    }

    public function test_a_probe_that_finds_video_declines_without_fetching_anything_else(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor($this->videoContainer(), expectsPreview: false, expectsExtraXml: false);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.multimedia');

        $this->assertSame(ProcessingOutcome::DeclinedToVideoPath, $result->outcome);
        $this->assertSame([['<seg-1>']], $this->downloads, 'Nothing past the probe article may be fetched.');

        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(AudioRouting::DECLINED_TOKEN, $row->additional_pp_claim_token);
        $this->assertNull($row->additional_pp_claimed_at);
        // haspreview stays pending: the video path still owes this release a run.
        $this->assertSame(-1, (int) $row->haspreview);
        $this->assertDatabaseMissing('release_audio_tags', ['releases_id' => $release->id]);
    }

    public function test_a_probe_without_any_audio_stream_also_declines(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(new MediaInfoContainer, expectsPreview: false, expectsExtraXml: false);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::DeclinedToVideoPath, $result->outcome);
        $this->assertSame([['<seg-1>']], $this->downloads);
    }

    public function test_a_successful_run_records_the_preview_and_clears_the_pending_sentinels(): void
    {
        $release = $this->makeRelease(['pp_timeout_count' => 2]);
        $processor = $this->makeProcessor($this->taggedContainer());

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertTrue($result->previewCreated);

        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(1, (int) $row->haspreview);
        $this->assertSame(0, (int) $row->passwordstatus);
        $this->assertSame(0, (int) $row->pp_timeout_count);
        $this->assertNull($row->additional_pp_claim_token);
        $this->assertSame([$release->id], $this->synchronized);

        $tags = ReleaseAudioTag::query()->where('releases_id', $release->id)->firstOrFail();
        $this->assertTrue($tags->has_preview);
        $this->assertSame('mp3', $tags->preview_extension);
        $this->assertSame('audio/mpeg', $tags->preview_mime);
        $this->assertSame(30, $tags->preview_seconds);
        $this->assertSame(4096, $tags->preview_bytes);
        $this->assertFileExists($this->savePath.'audio-guid.mp3');
        $this->assertFileExists($this->savePath.'audio-guid_spectrum.png');
        $this->assertTrue($tags->has_spectrogram);
    }

    public function test_a_root_with_preview_generation_disabled_keeps_the_tags_and_skips_the_encode(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor($this->taggedContainer(), expectsPreview: false, previewsEnabled: false);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame(
            PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY,
            (int) DB::table('releases')->where('id', $release->id)->value('haspreview'),
        );
        $this->assertDatabaseHas('release_audio_tags', ['releases_id' => $release->id, 'album' => 'Test Album']);
        $this->assertDatabaseHas('release_audio_evidence', ['releases_id' => $release->id, 'revision' => 1]);
        $this->assertSame([], $this->encoderCommands, 'No ffmpeg work may be started for a disabled root.');
    }

    public function test_an_evidence_persistence_failure_does_not_fail_preview_generation(): void
    {
        Schema::dropIfExists('release_audio_evidence_tracks');
        Schema::dropIfExists('release_audio_evidence');
        $release = $this->makeRelease();

        $result = $this->makeProcessor($this->taggedContainer())
            ->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertTrue($result->previewCreated);
        $this->assertFileExists($this->savePath.'audio-guid.mp3');
    }

    public function test_an_unidentified_release_is_renamed_from_its_own_tags(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        $this->renamesFromTags = true;
        $release = $this->makeRelease([
            'categories_id' => Category::MUSIC_OTHER,
            'videos_id' => 41,
            'tv_episodes_id' => 42,
            'movieinfo_id' => 47,
            'imdbid' => 'tt1234567',
            'musicinfo_id' => 43,
            'consoleinfo_id' => 44,
            'bookinfo_id' => 45,
            'anidbid' => 46,
            'gamesinfo_id' => 48,
        ]);

        $this->makeProcessor($this->taggedContainer())->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $renamed = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame('Test Artist - Test Album (2019) MP3', $renamed->searchname);
        $this->assertSame(Category::MUSIC_MP3, (int) $renamed->categories_id);
        $this->assertSame(1, (int) $renamed->isrenamed);
        $this->assertSame(1, (int) $renamed->iscategorized);
        $this->assertSame(1, (int) $renamed->is_trusted_name);
        $this->assertSame(1, (int) $renamed->proc_pp);
        $this->assertSame(0, (int) $renamed->videos_id);
        $this->assertSame(0, (int) $renamed->tv_episodes_id);
        $this->assertNull($renamed->movieinfo_id);
        $this->assertNull($renamed->imdbid);
        $this->assertNull($renamed->musicinfo_id);
        $this->assertNull($renamed->consoleinfo_id);
        $this->assertNull($renamed->bookinfo_id);
        $this->assertNull($renamed->anidbid);
        $this->assertSame(0, (int) $renamed->gamesinfo_id);
        Event::assertDispatched(
            ReleaseNameFixed::class,
            fn (ReleaseNameFixed $event): bool => $event->releaseId === (int) $release->id
                && $event->newName === 'Test Artist - Test Album (2019) MP3'
                && $event->categoryOverride === Category::MUSIC_MP3,
        );
    }

    public function test_a_predb_matched_release_keeps_its_scene_name(): void
    {
        $this->renamesFromTags = true;
        $release = $this->makeRelease(['categories_id' => Category::MUSIC_OTHER, 'predb_id' => 7]);

        $this->makeProcessor($this->taggedContainer())->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(
            'Some.Album-GROUP',
            DB::table('releases')->where('id', $release->id)->value('searchname'),
        );
        // The tags are still recorded; only the name is left alone.
        $this->assertDatabaseHas('release_audio_tags', ['releases_id' => $release->id, 'album' => 'Test Album']);
        $this->assertSame(
            'Some.Album-GROUP',
            ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()->release_snapshot['searchname'],
        );
    }

    public function test_renaming_is_off_by_default(): void
    {
        $release = $this->makeRelease(['categories_id' => Category::MUSIC_OTHER]);

        $this->makeProcessor($this->taggedContainer())->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(
            'Some.Album-GROUP',
            DB::table('releases')->where('id', $release->id)->value('searchname'),
        );
    }

    public function test_an_nzb_with_nothing_playable_is_settled_rather_than_left_pending(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => '"group.nfo" yEnc', 'segments' => ['<nfo>']]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame([], $this->downloads);
        $this->assertSame(0, (int) DB::table('releases')->where('id', $release->id)->value('haspreview'));
    }

    public function test_an_archive_source_bumps_pp_timeout_count_before_fetching_and_resets_it_on_settlement(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
            maxArchiveBytes: 1,
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame([1], $this->timeoutCountsAtDownload);
        $this->assertSame(0, (int) DB::table('releases')->where('id', $release->id)->value('pp_timeout_count'));
    }

    #[DataProvider('archiveCrashGuardCounts')]
    public function test_an_archive_attempt_at_the_crash_guard_cap_settles_before_fetching(int $initialCount): void
    {
        $release = $this->makeRelease([
            'pp_timeout_count' => $initialCount,
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'audio-worker',
        ]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertStringContainsString('crash guard', $result->reason);
        $this->assertSame([], $this->downloads);

        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(0, (int) $row->haspreview);
        $this->assertSame(0, (int) $row->pp_timeout_count);
        $this->assertNull($row->additional_pp_claimed_at);
        $this->assertNull($row->additional_pp_claim_token);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function archiveCrashGuardCounts(): array
    {
        return [
            'attempt reaches cap' => [2],
            'legacy pending row is already at cap' => [3],
        ];
    }

    public function test_an_archive_fetch_that_hits_the_ceiling_settles_the_release(): void
    {
        $release = $this->makeRelease([
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'audio-worker',
        ]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
            maxArchiveBytes: 1,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertStringContainsString('fetch ceiling', $result->reason);
        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(0, (int) $row->haspreview);
        $this->assertNull($row->additional_pp_claimed_at);
        $this->assertNull($row->additional_pp_claim_token);
    }

    public function test_a_bare_wavpack_decode_failure_reports_the_missing_fallback_tool(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            nzbContents: [['title' => '01-track.wv', 'segments' => ['<wv-1>']]],
            wavPackFallbackAvailable: false,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame('WavPack file requires wvunpack, which is not installed.', $result->reason);
        $this->assertSame(0, (int) DB::table('releases')->where('id', $release->id)->value('haspreview'));
        $this->assertTrue(
            ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()->whole_duration_reliable,
        );
    }

    public function test_an_encrypted_archive_head_settles_passworded_without_an_extra_download(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [[
                'title' => 'Album.part01.rar',
                'segments' => ['<rar-1>', '<rar-2>', '<rar-3>'],
            ]],
            archivePassworded: true,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertStringContainsString('password protected', $result->reason);
        $this->assertSame(
            [['<rar-1>', '<rar-2>', '<rar-3>']],
            $this->downloads,
            'The encryption verdict must use the archive bytes already fetched.',
        );
        $this->assertSame(
            ReleaseBrowseService::PASSWD_RAR,
            (int) DB::table('releases')->where('id', $release->id)->value('passwordstatus'),
        );
    }

    public function test_an_unencrypted_archive_without_audio_still_settles_not_passworded(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
            archivePassworded: false,
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame([['<rar-1>']], $this->downloads);
        $this->assertSame(
            ReleaseBrowseService::PASSWD_NONE,
            (int) DB::table('releases')->where('id', $release->id)->value('passwordstatus'),
        );
    }

    public function test_an_incomplete_release_fails_before_downloading_any_audio_articles(): void
    {
        $release = $this->makeRelease(['completion' => 7]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame('Source is only 7% complete.', $result->reason);
        $this->assertSame([], $this->downloads);
    }

    public function test_disabling_the_completion_threshold_preserves_audio_fetching(): void
    {
        $release = $this->makeRelease(['completion' => 7]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            minimumCompletionPercent: 0,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertSame([['<seg-1>'], ['<seg-2>', '<seg-3>']], $this->downloads);
    }

    public function test_an_archive_starting_on_a_later_volume_fails_after_one_download(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [
                ['title' => 'Album.part08.rar', 'segments' => ['<rar-8>']],
                ['title' => 'Album.part09.rar', 'segments' => ['<rar-9>']],
            ],
            archiveListings: [[
                'files' => [['name' => 'track.flac', 'size' => 100]],
                'hasPassword' => false,
                'isFirstVolume' => false,
            ]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame(
            'Archive set starts mid-volume; the first volume is not in this release.',
            $result->reason,
        );
        $this->assertSame([['<rar-8>']], $this->downloads);
    }

    public function test_an_archive_with_a_crc_failed_article_reports_the_distinct_source_damage_reason(): void
    {
        $release = $this->makeRelease();
        $segments = array_map(static fn (int $segment): string => '<damaged-'.$segment.'>', range(1, 65));
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => $segments]],
            downloadResult: [
                'success' => false,
                'data' => null,
                'groupUnavailable' => false,
                'error' => 'Source articles failed CRC verification.',
                'crcFailures' => 1,
                'crcFailed' => true,
            ],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame('Source articles failed CRC verification.', $result->reason);
        $this->assertSame(1, $result->crcFailures);
        $this->assertSame([array_slice($segments, 0, 64)], $this->downloads);
    }

    public function test_a_crc_failure_recovered_by_provider_fallback_does_not_label_clean_bytes_damaged(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<recovered>']]],
            archivePassworded: false,
            downloadResult: [
                'success' => true,
                'data' => str_repeat('x', 2048),
                'groupUnavailable' => false,
                'error' => null,
                'crcFailures' => 1,
                'crcFailed' => false,
            ],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame('No usable audio file was found within 1 fetched archive volume(s).', $result->reason);
        $this->assertSame(1, $result->crcFailures);
    }

    public function test_a_video_only_archive_declines_to_the_general_path_after_two_listed_volumes(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [
                ['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']],
                ['title' => 'Album.part02.rar', 'segments' => ['<rar-2>']],
                ['title' => 'Album.part03.rar', 'segments' => ['<rar-3>']],
            ],
            archiveListings: [
                ['files' => [['name' => 'release.nfo', 'size' => 12]], 'hasPassword' => false],
                ['files' => [['name' => 'feature.mkv', 'size' => 500]], 'hasPassword' => false],
            ],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::DeclinedToVideoPath, $result->outcome);
        $this->assertSame('The archive holds no audio files (found: mkv, nfo).', $result->reason);
        $this->assertSame([['<rar-1>'], ['<rar-2>']], $this->downloads);
        $this->assertSame(
            AudioRouting::DECLINED_TOKEN,
            DB::table('releases')->where('id', $release->id)->value('additional_pp_claim_token'),
        );
        $this->assertSame(
            [$release->id],
            AdditionalCandidateQuery::baseBuilder(minSizeBytes: 0, maxSizeBytes: 0)
                ->pluck('r.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRelease(array $attributes = []): Release
    {
        $id = DB::table('releases')->insertGetId(array_merge([
            'guid' => 'audio-guid',
            'searchname' => 'Some.Album-GROUP',
            'categories_id' => Category::MUSIC_MP3,
            'groups_id' => 1,
        ], $attributes));

        return Release::query()->findOrFail($id);
    }

    /**
     * @param  list<array<string, mixed>>|null  $nzbContents
     */
    private function makeProcessor(
        MediaInfoContainer $container,
        bool $expectsPreview = true,
        bool $expectsExtraXml = true,
        bool $previewsEnabled = true,
        ?array $nzbContents = null,
        ?int $maxArchiveBytes = null,
        ?bool $archivePassworded = null,
        bool $wavPackFallbackAvailable = true,
        float $minimumCompletionPercent = 95,
        ?array $archiveListings = null,
        ?array $downloadResult = null,
    ): AudioReleaseProcessor {
        $config = $this->config($maxArchiveBytes, $minimumCompletionPercent);

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->andReturn([
            'contents' => $nzbContents ?? [
                ['title' => '"cover.jpg" yEnc', 'segments' => ['<jpg>']],
                ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>', '<seg-3>']],
            ],
            'error' => null,
        ]);

        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('download')->andReturnUsing(
            function (mixed $kind, array $messageIds) use ($downloadResult): array {
                $this->downloads[] = array_values($messageIds);
                $this->timeoutCountsAtDownload[] = (int) DB::table('releases')
                    ->where('id', 1)
                    ->value('pp_timeout_count');

                return $downloadResult ?? [
                    'success' => true,
                    'data' => str_repeat('x', 2048),
                    'groupUnavailable' => false,
                    'error' => null,
                    'crcFailures' => 0,
                    'crcFailed' => false,
                ];
            }
        );

        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->andReturn($container);
        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'mediaInfo'))->setValue($tools, $mediaInfo);

        $encoder = new AudioPreviewEncoder(
            $config,
            $wavPackFallbackAvailable
                ? $this->encoderTools()
                : $this->encoderTools(codec: 'wavpack', commandFails: true, wavPackFallbackAvailable: false),
        );

        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        if ($archiveListings !== null) {
            $archiveService->shouldReceive('listArchiveContentsAtPath')
                ->times(count($archiveListings))
                ->andReturn(...$archiveListings);
        } elseif ($archivePassworded !== null) {
            $archiveService->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
                'hasPassword' => $archivePassworded,
                'files' => [],
            ]);
        }

        $releaseExtra = Mockery::mock(ReleaseExtraService::class);
        $expectsExtraXml
            ? $releaseExtra->shouldReceive('addFromXml')->once()
            : $releaseExtra->shouldNotReceive('addFromXml');

        $previewPolicy = Mockery::mock(PreviewGenerationPolicy::class);
        $previewPolicy->shouldReceive('generationEnabledForCategory')->andReturn($previewsEnabled);

        return new AudioReleaseProcessor(
            $nzbParser,
            new AudioSourceSelector,
            new AudioFetcher(
                $config,
                $downloadService,
                $archiveService,
                $tools,
                Mockery::mock(AudioDecodableLengthProbe::class)->shouldIgnoreMissing(0.0),
            ),
            $encoder,
            new AudioTagExtractor,
            $this->renamer(),
            $releaseExtra,
            new MediaInfoRefinementService,
            new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                function (int $releaseId): void {
                    $this->synchronized[] = $releaseId;
                },
            ),
            $previewPolicy,
            new AudioEvidenceRecorder,
        );
    }

    /**
     * ffmpeg is not installed on CI, so the encoder is driven by a recording
     * driver that writes a plausible artifact. What ffmpeg itself produces is
     * covered where the binary exists; what matters here is that the processor
     * records it.
     */
    private function encoderTools(
        string $codec = 'mp3',
        bool $commandFails = false,
        bool $wavPackFallbackAvailable = true,
    ): MediaTools {
        $driver = Mockery::mock(FFMpegDriver::class);
        $driver->shouldReceive('command')->andReturnUsing(function (array $command) use ($commandFails): string {
            $this->encoderCommands[] = array_values(array_map('strval', $command));
            if ($commandFails) {
                throw new \RuntimeException('Forced ffmpeg WavPack decode failure.');
            }
            file_put_contents((string) end($command), str_repeat('p', 4096));

            return '';
        });

        $ffmpeg = Mockery::mock(FFMpeg::class);
        $ffmpeg->shouldReceive('getFFMpegDriver')->andReturn($driver);

        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('streams')->andReturn(
            new StreamCollection([new Stream(['codec_type' => 'audio', 'codec_name' => $codec])])
        );
        // A full-length source, so the clip is the configured 30 seconds.
        $ffprobe->shouldReceive('format')->andReturn(new Format(['duration' => '300.0']));

        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'ffmpeg'))->setValue($tools, $ffmpeg);
        (new ReflectionProperty(MediaTools::class, 'ffprobe'))->setValue($tools, $ffprobe);
        if (! $wavPackFallbackAvailable) {
            (new ReflectionProperty(MediaTools::class, 'wvunpackPath'))->setValue($tools, false);
        }

        return $tools;
    }

    private function renamer(): AudioTagRenamer
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();
        (new ReflectionProperty(AudioProcessingConfiguration::class, 'renameMusicMediaInfo'))
            ->setValue($config, $this->renamesFromTags);
        (new ReflectionProperty(AudioProcessingConfiguration::class, 'echoCLI'))->setValue($config, false);

        return new AudioTagRenamer(
            $config,
            new CategorizationService,
            new ReleaseUpdateService,
            new PreviewGenerationPolicy,
        );
    }

    private function config(
        ?int $maxArchiveBytes = null,
        float $minimumCompletionPercent = 95,
    ): AudioProcessingConfiguration {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'segmentsToDownload' => 12,
            'maxRarParts' => 6,
            'maxArchiveBytes' => $maxArchiveBytes,
            'minimumCompletionPercent' => $minimumCompletionPercent,
            'previewSeconds' => 30,
            'previewStartSeconds' => 10,
            'spectrogram' => true,
            'savePath' => $this->savePath,
            'debugMode' => false,
            'echoCLI' => false,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }

    private function taggedContainer(): MediaInfoContainer
    {
        $general = new General;
        foreach ([
            'format' => new Mode('MPEG Audio', 'MPEG Audio'),
            'album' => 'Test Album',
            'performer' => 'Test Artist',
            'recorded_date' => '2019-04-01',
            'duration' => new Duration(241250),
            'ISRC' => 'USRC17607839',
            'MusicBrainz Recording Id' => '11111111-1111-4111-8111-111111111111',
        ] as $key => $value) {
            $general->set($key, $value);
        }

        $container = new MediaInfoContainer;
        $container->setGeneral($general);
        $container->add(new Audio);

        return $container;
    }

    private function videoContainer(): MediaInfoContainer
    {
        $container = $this->taggedContainer();
        $container->add(new Video);

        return $container;
    }
}
