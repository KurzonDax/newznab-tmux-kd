<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\CollectionCleanupService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Par2Processor;
use App\Services\ReleaseImageService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\TempWorkspaceService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class AdditionalProcessingReleaseFileManagerTest extends TestCase
{
    use CreatesProcessingConfiguration;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_release_file_rows_are_deduped_and_flushed_once_at_finalize(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $nameFixing = new CountingNameFixingService;

        $manager = $this->makeManager($nameFixing);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024,
            'date' => 1_788_600_000,
            'pass' => 0,
            'crc32' => 'ABC123',
        ], $context, '\\.(?:par2|sfv|nzb)'));
        $this->assertFalse($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024,
            'date' => 1_788_600_000,
            'pass' => 0,
            'crc32' => 'ABC123',
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, $nameFixing->matchPreDbFilesCalls);
        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('additional_pp_claimed_at'));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    public function test_release_file_names_are_scrubbed_before_archive_rows_are_written(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->addFileInfo([
            'name' => "Example\x00.Movie\xED\xBD\xBF.mkv\x7F",
            'size' => 1024,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $storedName = DB::table('release_files')->value('name');
        $this->assertSame('Example.Movie.mkv', $storedName);
        $this->assertTrue(mb_check_encoding($storedName, 'UTF-8'));
    }

    public function test_release_file_name_with_no_printable_characters_is_not_written(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertFalse($manager->addFileInfo([
            'name' => "\x00\x1F\x7F\xED\xBD\xBF",
            'size' => 1024,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertSame(0, DB::table('release_files')->count());
    }

    public function test_finalization_flushes_rows_and_synchronizes_search_immediately(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManagerWithImageService(new ReleaseImageService);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 2048,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        $this->assertSame([], $context->pendingReleaseFiles);
    }

    public function test_finalization_refines_from_media_info_restores_preview_and_syncs_once(): void
    {
        DB::table('releases')->insert(array_merge($this->releaseRow(Category::MOVIE_OTHER), [
            'haspreview' => -2,
            'iscategorized' => 0,
        ]));
        DB::table('video_data')->insert([
            'releases_id' => 1,
            'videowidth' => 1920,
            'videoheight' => 1080,
            'videoformat' => 'AVC',
        ]);

        Search::shouldReceive('updateRelease')->once()->with(1);
        $coordinator = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $coordinator->beginReleaseScope(1);

        $manager = $this->makeManagerWithImageService(
            new ReleaseImageService,
            searchSyncCoordinator: $coordinator,
        );
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $context->previewGenerationSkippedByPolicy = true;

        $manager->finalizeRelease($context, false);
        $coordinator->finishReleaseScope();

        $this->assertSame(Category::MOVIE_HD, (int) DB::table('releases')->where('id', 1)->value('categories_id'));
        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('iscategorized'));
        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
    }

    public function test_rar_inner_video_uses_the_longest_descriptive_title(): void
    {
        DB::table('releases')->insert(array_merge($this->releaseRow(Category::OTHER_HASHED), [
            'name' => '(Els1212) [02/23] - "CQPVTOVKUDJVGELG.part01.rar"',
            'searchname' => '(Els1212) [02/23] - "CQPVTOVKUDJVGELG.part01.rar"',
        ]));

        $updater = Mockery::mock(ReleaseUpdateService::class);
        $updater->shouldReceive('updateRelease')
            ->once()
            ->withArgs(function (
                object $release,
                string $name,
                string $method,
                bool $echo,
                string $type,
                bool $nameStatus,
                bool $show,
                ?int $preId,
                bool $descriptiveTitleCandidate,
            ): bool {
                return $release->id === 1
                    && $name === '2016-04-17 - Anita Bellini - Playful And Petite (4k).mp4'
                    && str_contains($method, 'Descriptive title')
                    && $echo
                    && $type === 'Filenames, '
                    && $nameStatus
                    && $show
                    && $preId === 0
                    && $descriptiveTitleCandidate;
            });

        $manager = new ReleaseFileManager(
            $this->makeConfig(),
            new ReleaseImageService,
            new NfoService,
            new TestNzbService,
            new CountingNameFixingService,
            releaseUpdateService: $updater,
            descriptiveTitleRenameEnabled: true,
        );
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $manager->processReleaseNameFromRar([
            'file_list' => [
                ['name' => '2016-04-16 - Solana A - Before The Party 2.mp4'],
                ['name' => '2016-04-17 - Anita Bellini - Playful And Petite (4k).mp4'],
            ],
        ], $context);

        $this->addToAssertionCount(1);
    }

    public function test_disabled_fallback_does_not_inspect_nested_archive_after_extractable_outer_name(): void
    {
        DB::table('releases')->insert($this->releaseRow(Category::OTHER_HASHED));

        $updater = Mockery::mock(ReleaseUpdateService::class);
        $updater->shouldNotReceive('updateRelease');
        $manager = new ReleaseFileManager(
            $this->makeConfig(),
            new ReleaseImageService,
            new NfoService,
            new TestNzbService,
            new CountingNameFixingService,
            releaseUpdateService: $updater,
            descriptiveTitleRenameEnabled: false,
        );
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $manager->processReleaseNameFromRar([
            'file_list' => [
                ['name' => 'Short-AB.mkv'],
            ],
            'archives' => [
                'Short-AB.mkv' => [
                    'file_list' => [
                        ['name' => 'Movie.2020.1080p-GRP.mkv'],
                    ],
                ],
            ],
        ], $context);

        $this->addToAssertionCount(1);
    }

    public function test_database_statements_are_measured_inside_an_active_release_scope(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        $collector = app(PersistenceMetricsCollector::class);

        $collector->beginReleaseScope(1);
        DB::table('releases')->where('id', 1)->value('guid');
        $metrics = $collector->finishReleaseScope();

        $this->assertSame(1, $metrics->databaseStatements);
        $this->assertGreaterThanOrEqual(0.0, $metrics->databaseMilliseconds);
    }

    public function test_queued_par_hashes_flush_with_release_files(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $queue = new ReflectionMethod(ReleaseFileManager::class, 'queueReleaseFile');
        $queue->invoke(
            $manager,
            $context,
            1,
            'Example.Movie.2026.par2',
            512,
            1_788_600_000,
            0,
            '1234567890abcdef1234567890abcdef'
        );

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('par_hashes')->count());
    }

    public function test_par2_hashes_are_persisted_when_par2_release_files_are_disabled(): void
    {
        DB::table('releases')->insert(array_merge($this->releaseRow(), [
            'postdate' => '2026-08-16 12:00:00',
            'proc_pp' => 0,
        ]));

        Search::shouldReceive('updateRelease')->once()->with(1);

        $par2Info = Mockery::mock(Par2Info::class);
        $par2Info->error = '';
        $par2Info->shouldReceive('open')->once()->andReturnTrue();
        $par2Info->shouldReceive('getFileList')->once()->andReturn([
            [
                'name' => 'Canonical.Release.2026.mkv',
                'size' => 1_024,
                'hash_16K' => '1234567890abcdef1234567890abcdef',
            ],
        ]);

        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: [
            'addPAR2Files' => false,
            'renamePar2' => false,
        ]);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->processPar2File($this->makeTempPath('example', '.par2'), $context, $par2Info));
        $manager->finalizeRelease($context, false);

        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertDatabaseHas('par_hashes', [
            'releases_id' => 1,
            'hash' => '1234567890abcdef1234567890abcdef',
        ]);
    }

    public function test_par2_hashes_and_release_files_are_persisted_when_listing_is_enabled(): void
    {
        DB::table('releases')->insert(array_merge($this->releaseRow(), [
            'postdate' => '2026-08-16 12:00:00',
            'proc_pp' => 0,
        ]));

        Search::shouldReceive('updateRelease')->once()->with(1);

        $par2Info = Mockery::mock(Par2Info::class);
        $par2Info->error = '';
        $par2Info->shouldReceive('open')->once()->andReturnTrue();
        $par2Info->shouldReceive('getFileList')->once()->andReturn([
            [
                'name' => 'Canonical.Release.2026.mkv',
                'size' => 1_024,
                'hash_16K' => 'abcdef1234567890abcdef1234567890',
            ],
        ]);

        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: [
            'addPAR2Files' => true,
            'renamePar2' => false,
        ]);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->processPar2File($this->makeTempPath('example', '.par2'), $context, $par2Info));
        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('par_hashes')->count());
    }

    public function test_sniffed_rar_and_par2_payloads_flow_through_real_finalization(): void
    {
        DB::table('releases')->insert(array_merge($this->releaseRow(), [
            'postdate' => '2026-08-16 12:00:00',
            'proc_pp' => 0,
            'nfostatus' => 1,
        ]));

        Search::shouldReceive('updateRelease')->once()->with(1);
        $config = $this->makeConfig([
            'addPAR2Files' => true,
            'renamePar2' => true,
            'payloadSniffing' => true,
            'payloadSniffMaxCandidates' => 2,
            'payloadSniffByteBudget' => 1024,
        ]);
        $persistenceMetrics = new PersistenceMetricsCollector;
        $searchSync = new ReleaseSearchSyncCoordinator($persistenceMetrics);
        $manager = new ReleaseFileManager(
            $config,
            new ReleaseImageService,
            new NfoService,
            new TestNzbService,
            new PersistingPar2NameFixingService,
            searchSyncCoordinator: $searchSync,
        );

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [
                ['title' => 'archive.bin', 'segments' => ['<rar-first>', '<rar-second>'], 'size' => 400, 'partsactual' => 2],
                ['title' => 'metadata.bin', 'segments' => ['<par2-first>', '<par2-second>'], 'size' => 200, 'partsactual' => 2],
            ],
        ]);

        $rarPayload = "Rar!\x1A\x07\x00\x00\x00\x73".pack('v', 0x0101).'archive';
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('beginReleaseScope')->once()->andReturnNull();
        $downloadService->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<rar-first>'],
            'alt.binaries.test',
            1,
            'archive.bin',
        )->andReturn(['success' => true, 'data' => $rarPayload, 'groupUnavailable' => false, 'error' => null]);
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<par2-first>'],
            'alt.binaries.test',
            1,
            'metadata.bin',
        )->andReturn(['success' => true, 'data' => "PAR2\x00PKTdata", 'groupUnavailable' => false, 'error' => null]);

        $par2Info = Mockery::mock(Par2Info::class);
        $par2Info->error = '';
        $par2Info->shouldReceive('open')->once()->andReturnTrue();
        $par2Info->shouldReceive('getFileList')->once()->andReturn([[
            'name' => 'Canonical.Release.2026.mkv',
            'size' => 1024,
            'hash_16K' => '1234567890abcdef1234567890abcdef',
        ]]);
        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        $archiveService->shouldReceive('processCompressedData')->once()->with(
            $rarPayload,
            Mockery::type(ReleaseProcessingContext::class),
            Mockery::type('string'),
        )->andReturn([
            'success' => true,
            'files' => [[
                'name' => 'Inside.Release.mkv',
                'size' => 2048,
                'date' => 1_788_600_000,
                'pass' => false,
                'crc32' => 'ABC123',
            ]],
            'hasPassword' => false,
            'passwordStatus' => 0,
        ]);
        $archiveService->shouldReceive('getPar2Info')->once()->andReturn($par2Info);

        $tmpPath = $this->makeTempDirectory('nntmux-sniff-persistence').'/';
        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($tmpPath);
        $tempWorkspace->shouldReceive('clearDirectory')->once()->with($tmpPath, false)->andReturnNull();
        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();
        $output->shouldReceive('echoFileInfoAdded')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            $archiveService,
            Mockery::mock(MediaExtractionService::class),
            $downloadService,
            $manager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            $searchSync,
            $persistenceMetrics,
            previewPolicy: new AlwaysEnabledPreviewPolicy,
        );

        $result = $processor->process(new ReleaseProcessingContext(Release::query()->findOrFail(1)), $tmpPath);

        $this->assertTrue($result->artifactsCreated);
        $this->assertDatabaseHas('release_files', [
            'releases_id' => 1,
            'name' => 'Inside.Release.mkv',
            'crc32' => 'ABC123',
        ]);
        $this->assertDatabaseHas('par_hashes', [
            'releases_id' => 1,
            'hash' => '1234567890abcdef1234567890abcdef',
        ]);
        $this->assertSame('Canonical.Release.2026', DB::table('releases')->where('id', 1)->value('searchname'));
    }

    public function test_legacy_par2_processor_persists_hashes_when_release_file_listing_is_disabled(): void
    {
        DB::table('releases')->insert(array_merge($this->releaseRow(), [
            'isrenamed' => 0,
            'postdate' => '2026-08-16 12:00:00',
        ]));

        $par2Info = Mockery::mock(Par2Info::class);
        $par2Info->error = '';
        $par2Info->shouldReceive('setData')->once()->with('par2-payload');
        $files = array_map(
            static fn (int $fileNumber): array => [
                'name' => "Canonical.Release.2026.part{$fileNumber}.mkv",
                'size' => 1_024,
                'hash_16K' => md5("part-{$fileNumber}"),
            ],
            range(1, 25),
        );
        $par2Info->shouldReceive('getFileList')->once()->andReturn($files);

        $nameFixing = Mockery::mock(NameFixingService::class);
        $nameFixing->shouldReceive('checkName')->once()->andReturnTrue();
        $nntp = Mockery::mock(NNTPService::class)->makePartial();
        $nntp->shouldReceive('getMessages')->once()->with('alt.binaries.test', '<message-id>')->andReturn('par2-payload');

        $processor = new Par2Processor($nameFixing, $par2Info, false);

        $this->assertTrue($processor->parseFromMessage('<message-id>', 1, 1, $nntp, 0));
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(25, DB::table('par_hashes')->where('releases_id', 1)->count());
    }

    public function test_failed_finalization_rolls_back_buffered_rows_and_keeps_them_for_retry(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->never();

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        DB::unprepared("CREATE TRIGGER fail_release_finalize BEFORE UPDATE ON releases BEGIN SELECT RAISE(ABORT, 'forced finalization failure'); END");

        try {
            $manager->finalizeRelease($context, false);
            $this->fail('Finalization should have failed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced finalization failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_release_finalize');
        }

        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertArrayHasKey('Example.Movie.2026.mkv', $context->pendingReleaseFiles);
    }

    public function test_float_release_file_size_is_normalized_before_queueing(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024.0,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertSame(1024, DB::table('release_files')->value('size'));
    }

    public function test_invalid_release_file_sizes_are_rejected(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Log::shouldReceive('warning')
            ->times(4)
            ->with('Skipping release file with invalid size metadata.', Mockery::type('array'));

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $invalidSizes = [-1, INF, PHP_INT_MAX + 1.0, 'not-numeric'];

        foreach ($invalidSizes as $index => $invalidSize) {
            $this->assertFalse($manager->addFileInfo([
                'name' => "Invalid.Size.{$index}.mkv",
                'size' => $invalidSize,
            ], $context, '\\.(?:par2|sfv|nzb)'));
        }

        $this->assertSame(0, $context->totalFileInfo);
        $this->assertSame(0, $context->addedFileInfo);
    }

    public function test_executable_file_discards_release_when_root_toggle_enabled(): void
    {
        DB::table('releases')->insert($this->releaseRow(2045)); // Movies root, discarding on

        Search::shouldReceive('deleteRelease')->once()->with(1);
        Log::shouldReceive('warning')->once()->with(
            'Discarding release containing executable file',
            Mockery::type('array')
        );

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $this->assertFalse($manager->addFileInfo([
            'name' => 'Fixer/Fixer.exe',
            'size' => 2048,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $this->assertTrue($context->releaseDiscarded);
        $this->assertSame(0, DB::table('releases')->count());

        // Files seen after the discard are ignored entirely.
        $this->assertFalse($manager->addFileInfo([
            'name' => 'Another.File.mkv',
            'size' => 512,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        // Finalization is a no-op: nothing is flushed or resurrected.
        $manager->finalizeRelease($context, false);
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(0, DB::table('releases')->count());
    }

    public function test_executable_file_records_normally_when_root_toggle_disabled(): void
    {
        DB::table('releases')->insert($this->releaseRow(4050)); // PC root, discarding off

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->addFileInfo([
            'name' => 'Legit.App.Installer.exe',
            'size' => 4096,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $this->assertFalse($context->releaseDiscarded);

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame('Legit.App.Installer.exe', DB::table('release_files')->value('name'));
    }

    public function test_finalize_recognizes_webp_preview_and_sample_without_moving_them(): void
    {
        DB::table('releases')->insert([...$this->releaseRow(), 'pp_timeout_count' => 2]);
        Search::shouldReceive('updateRelease')->once()->with(1);

        $imageService = new ReleaseImageService;
        $preview = $imageService->imgSavePath.'guid-1_thumb.webp';
        $sample = $imageService->jpgSavePath.'guid-1_thumb.webp';
        File::ensureDirectoryExists(dirname($preview));
        File::ensureDirectoryExists(dirname($sample));
        File::put($preview, 'preview');
        File::put($sample, 'sample');

        try {
            $manager = $this->makeManagerWithImageService($imageService);
            $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

            $manager->finalizeRelease($context, false);

            $this->assertSame(1, DB::table('releases')->where('id', 1)->value('haspreview'));
            $this->assertSame(1, DB::table('releases')->where('id', 1)->value('jpgstatus'));
            $this->assertSame(0, DB::table('releases')->where('id', 1)->value('pp_timeout_count'));
        } finally {
            File::delete([$preview, $sample]);
        }
    }

    public function test_release_exception_below_the_cap_is_counted_and_left_pending(): void
    {
        DB::table('releases')->insert(array_merge($this->releaseRow(), [
            'size' => 2 * 1048576,
            'passwordstatus' => PasswordInspectionMode::pendingReleaseStatus(),
        ]));
        Search::shouldReceive('updateRelease')->once()->with(1);

        $settled = $this->makeManager()->handleReleaseException(
            Release::query()->findOrFail(1),
            3,
            'token',
        );

        $release = Release::query()->findOrFail(1);

        $this->assertFalse($settled);
        $this->assertSame(1, (int) $release->pp_timeout_count);
        $this->assertSame(-1, (int) $release->haspreview);
        $this->assertSame(PasswordInspectionMode::pendingReleaseStatus(), (int) $release->passwordstatus);
        $this->assertNull($release->additional_pp_claimed_at);
        $this->assertNull($release->additional_pp_claim_token);
        $this->assertTrue(AdditionalCandidateQuery::hasAnyCandidate());
    }

    public function test_release_exception_at_the_cap_is_settled_without_deleting_the_release_or_nzb(): void
    {
        $nzbRoot = $this->makeTempDirectory('nntmux-exception-cap-nzb').'/';
        config(['nntmux_settings.path_to_nzbs' => $nzbRoot]);

        DB::table('releases')->insert(array_merge($this->releaseRow(), ['pp_timeout_count' => 2]));
        $nzbPath = $nzbRoot.'g/guid-1.nzb.gz';
        File::ensureDirectoryExists(dirname($nzbPath));
        File::put($nzbPath, 'kept');
        Search::shouldReceive('updateRelease')->once()->with(1);

        $settled = $this->makeManager()->handleReleaseException(
            Release::query()->findOrFail(1),
            3,
            'token',
        );

        $release = Release::query()->findOrFail(1);

        $this->assertTrue($settled);
        $this->assertSame(3, (int) $release->pp_timeout_count);
        $this->assertSame(0, (int) $release->haspreview);
        $this->assertSame(0, (int) $release->passwordstatus);
        $this->assertNull($release->additional_pp_claimed_at);
        $this->assertNull($release->additional_pp_claim_token);
        $this->assertFileExists($nzbPath);
        $this->assertFalse(AdditionalCandidateQuery::hasAnyCandidate());
    }

    public function test_release_exception_does_not_clear_a_newer_workers_claim(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        $release = Release::query()->findOrFail(1);
        Release::query()->whereKey(1)->update([
            'additional_pp_claim_token' => 'new-worker-token',
        ]);
        $coordinator = new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            static function (): never {
                throw new \RuntimeException('stale worker attempted search synchronization');
            },
        );

        $settled = $this->makeManagerWithImageService(
            new ReleaseImageService,
            searchSyncCoordinator: $coordinator,
        )->handleReleaseException(
            $release,
            3,
            'token',
        );

        $freshRelease = Release::query()->findOrFail(1);

        $this->assertFalse($settled);
        $this->assertSame(0, (int) $freshRelease->pp_timeout_count);
        $this->assertSame('new-worker-token', $freshRelease->additional_pp_claim_token);
        $this->assertNotNull($freshRelease->additional_pp_claimed_at);
    }

    public function test_release_exception_settlement_survives_search_sync_failure(): void
    {
        Log::spy();
        DB::table('releases')->insert($this->releaseRow());
        $coordinator = new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            static function (): never {
                throw new \RuntimeException('search unavailable');
            },
        );

        $settled = $this->makeManagerWithImageService(
            new ReleaseImageService,
            searchSyncCoordinator: $coordinator,
        )->handleReleaseException(
            Release::query()->findOrFail(1),
            3,
            'token',
        );

        $release = Release::query()->findOrFail(1);

        $this->assertFalse($settled);
        $this->assertSame(1, (int) $release->pp_timeout_count);
        $this->assertNull($release->additional_pp_claim_token);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_release_exception_is_counted_when_claim_columns_are_unavailable(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['additional_pp_claimed_at', 'additional_pp_claim_token']);
        });
        (new \ReflectionProperty(ReleaseClaimant::class, 'supportsClaims'))
            ->setValue(null, null);

        try {
            $releaseRow = $this->releaseRow();
            unset($releaseRow['additional_pp_claimed_at'], $releaseRow['additional_pp_claim_token']);
            DB::table('releases')->insert($releaseRow);
            Search::shouldReceive('updateRelease')->once()->with(1);

            $settled = $this->makeManager()->handleReleaseException(
                Release::query()->findOrFail(1),
                3,
                'worker-token',
            );

            $this->assertFalse($settled);
            $this->assertSame(1, (int) Release::query()->whereKey(1)->value('pp_timeout_count'));
        } finally {
            (new \ReflectionProperty(ReleaseClaimant::class, 'supportsClaims'))
                ->setValue(null, null);
        }
    }

    public function test_release_timeout_at_the_cap_still_deletes_the_release_and_nzb(): void
    {
        $nzbRoot = $this->makeTempDirectory('nntmux-timeout-cap-nzb').'/';
        $coversRoot = $this->makeTempDirectory('nntmux-timeout-cap-covers');
        config([
            'nntmux_settings.path_to_nzbs' => $nzbRoot,
            'nntmux_settings.covers_path' => $coversRoot,
        ]);

        DB::table('releases')->insert(array_merge($this->releaseRow(), ['pp_timeout_count' => 2]));
        $nzbPath = $nzbRoot.'g/guid-1.nzb.gz';
        $audioPreviewPath = $coversRoot.'/audiosample/guid-1.mp3';
        $audioSpectrogramPath = $coversRoot.'/audiosample/guid-1_spectrum.png';
        File::ensureDirectoryExists(dirname($nzbPath));
        File::ensureDirectoryExists(dirname($audioPreviewPath));
        File::put($nzbPath, 'deleted');
        File::put($audioPreviewPath, 'deleted');
        File::put($audioSpectrogramPath, 'deleted');
        Search::shouldReceive('deleteRelease')->once()->with(1);

        $deleted = $this->makeManager()->handleReleaseTimeout(
            Release::query()->findOrFail(1),
            3,
        );

        $this->assertTrue($deleted);
        $this->assertNull(Release::query()->find(1));
        $this->assertFileDoesNotExist($nzbPath);
        $this->assertFileDoesNotExist($audioPreviewPath);
        $this->assertFileDoesNotExist($audioSpectrogramPath);
    }

    private function makeManager(?NameFixingService $nameFixing = null): ReleaseFileManager
    {
        return $this->makeManagerWithImageService(new ReleaseImageService, $nameFixing);
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    private function makeManagerWithImageService(
        ReleaseImageService $imageService,
        ?NameFixingService $nameFixing = null,
        array $configOverrides = [],
        ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
    ): ReleaseFileManager {
        return new ReleaseFileManager(
            $this->makeConfig($configOverrides),
            $imageService,
            new NfoService,
            new TestNzbService,
            $nameFixing ?? new CountingNameFixingService,
            searchSyncCoordinator: $searchSyncCoordinator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(int $categoriesId = 10): array
    {
        return [
            'id' => 1,
            'guid' => 'guid-1',
            'name' => 'Example',
            'searchname' => 'Example',
            'size' => 1024,
            'groups_id' => 1,
            'nfostatus' => -1,
            'categories_id' => $categoriesId,
            'iscategorized' => 1,
            'passwordstatus' => -1,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'rarinnerfilecount' => 0,
            'pp_timeout_count' => 0,
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'token',
        ];
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        DB::table('settings')->upsert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ], ['name'], ['value']);

        Schema::dropIfExists('par_hashes');
        Schema::dropIfExists('audio_data');
        Schema::dropIfExists('video_data');
        Schema::dropIfExists('predb');
        Schema::dropIfExists('release_files');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('releases_groups');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('root_categories');
        Schema::dropIfExists('usenet_groups');

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->boolean('discard_executables')->default(false);
            $table->boolean('generate_previews')->default(true);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->unsignedInteger('root_categories_id');
        });

        DB::table('root_categories')->insert([
            ['id' => 1, 'title' => 'Other', 'discard_executables' => 0],
            ['id' => 2000, 'title' => 'Movies', 'discard_executables' => 1],
            ['id' => 4000, 'title' => 'PC', 'discard_executables' => 0],
        ]);

        DB::table('categories')->insert([
            ['id' => 10, 'title' => 'Other > Misc', 'root_categories_id' => 1],
            ['id' => Category::MOVIE_OTHER, 'title' => 'Movies > Other', 'root_categories_id' => 2000],
            ['id' => Category::MOVIE_HD, 'title' => 'Movies > HD', 'root_categories_id' => 2000],
            ['id' => 2045, 'title' => 'Movies > SD', 'root_categories_id' => 2000],
            ['id' => 4050, 'title' => 'PC > Games', 'root_categories_id' => 4000],
        ]);

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->integer('nfostatus')->default(0);
            $table->integer('categories_id')->default(10);
            $table->boolean('iscategorized')->default(true);
            $table->integer('passwordstatus')->default(-1);
            $table->integer('haspreview')->default(-1);
            $table->integer('jpgstatus')->default(0);
            $table->integer('videostatus')->default(0);
            $table->integer('nzbstatus')->default(1);
            $table->integer('rarinnerfilecount')->default(0);
            $table->integer('pp_timeout_count')->default(0);
            $table->dateTime('postdate')->nullable();
            $table->integer('proc_pp')->default(0);
            $table->boolean('isrenamed')->default(false);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('crc32')->default('');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('passworded')->default(false);
            $table->primary(['releases_id', 'name']);
        });

        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->unique();
        });

        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash', 32);
            $table->primary(['releases_id', 'hash']);
        });

        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('containerformat')->nullable();
            $table->string('videoformat')->nullable();
            $table->string('videocodec')->nullable();
            $table->integer('videowidth')->nullable();
            $table->integer('videoheight')->nullable();
        });

        Schema::create('audio_data', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('audioid');
            $table->string('audioformat')->nullable();
        });
    }
}

class CountingNameFixingService extends NameFixingService
{
    public int $matchPreDbFilesCalls = 0;

    public function matchPreDbFiles(object $release, bool $echo, bool $nameStatus, bool $show): int
    {
        $this->matchPreDbFilesCalls++;

        return 0;
    }
}

class PersistingPar2NameFixingService extends CountingNameFixingService
{
    public function checkName(object $release, bool $echo, string $type, bool $nameStatus, bool $show, bool $preId = false): bool
    {
        if ($type !== 'PAR2, ') {
            return false;
        }

        $release->searchname = 'Canonical.Release.2026';
        Release::query()->whereKey((int) $release->id)->update(['searchname' => $release->searchname]);

        return true;
    }
}

class AlwaysEnabledPreviewPolicy extends PreviewGenerationPolicy
{
    public function generationEnabledForCategory(int $categoriesId): bool
    {
        return true;
    }
}

class TestNzbService extends NzbService
{
    public function __construct()
    {
        parent::__construct(new CollectionCleanupService);
    }
}
