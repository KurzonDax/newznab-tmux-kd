<?php

namespace Tests\Unit\AdditionalProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleaseProcessorTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $releaseTempPath;

    private string $mainTempPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Unique per test: these only ever reach mocked collaborators, but a shared
        // literal would still make two concurrent runs indistinguishable in failures.
        $this->releaseTempPath = $this->makeTempPath('nntmux-ap-release').'/';
        $this->mainTempPath = $this->makeTempPath('nntmux-ap-main').'/';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_deletes_release_when_nzb_parsing_fails(): void
    {
        $processor = $this->makeProcessor(
            nzbParser: Mockery::mock(NzbContentParser::class)
                ->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn(['error' => 'broken nzb', 'contents' => []])->getMock(),
            releaseManager: Mockery::mock(ReleaseFileManager::class)
                ->shouldReceive('deleteRelease')->once()->andReturnNull()->getMock(),
            tempWorkspace: Mockery::mock(TempWorkspaceService::class)
                ->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath)
                ->shouldReceive('clearDirectory')->once()->with($this->releaseTempPath, false)->andReturnNull()->getMock(),
            output: Mockery::mock(ConsoleOutputService::class)
                ->shouldReceive('echoReleaseStart')->once()->andReturnNull()
                ->shouldReceive('setProcessTitle')->once()->andReturnNull()
                ->shouldReceive('warning')->once()->with('broken nzb')->andReturnNull()->getMock()
        );

        $result = $processor->process($this->makeContext(), $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::DeletedBrokenNzb, $result->outcome);
        $this->assertTrue($result->outcome->isDeleted());
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('broken nzb', $result->reason);
        $this->assertGreaterThan(0.0, $result->elapsedSeconds);
        $this->assertArrayHasKey(ProcessingStage::WorkspacePreparation->value, $result->stageDurations);
        $this->assertArrayHasKey(ProcessingStage::NzbParsing->value, $result->stageDurations);
        $this->assertArrayHasKey(ProcessingStage::WorkspaceCleanup->value, $result->stageDurations);
    }

    #[Test]
    public function it_finalizes_a_release_after_basic_successful_processing(): void
    {
        $config = $this->makeConfig();
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
            'error' => null,
            'contents' => [['title' => 'file.nzb', 'segments' => []]],
        ]);
        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $persistenceMetrics = new PersistenceMetricsCollector;
        $synchronizedReleaseIds = [];
        $searchSyncCoordinator = new ReleaseSearchSyncCoordinator(
            $persistenceMetrics,
            static function (int $releaseId) use (&$synchronizedReleaseIds): void {
                $synchronizedReleaseIds[] = $releaseId;
            },
        );
        $releaseManager->shouldReceive('finalizeRelease')
            ->once()
            ->andReturnUsing(static function () use ($searchSyncCoordinator): void {
                $searchSyncCoordinator->request(1);
                $searchSyncCoordinator->request(1);
            });

        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath);
        $tempWorkspace->shouldReceive('clearDirectory')->once()->with($this->releaseTempPath, false)->andReturnNull();

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            Mockery::mock(MediaExtractionService::class),
            $this->scopedDownloadService(),
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            $searchSyncCoordinator,
            $persistenceMetrics,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->artifactsCreated);
        $this->assertSame('Processing completed without creating useful artifacts.', $result->reason);
        $this->assertSame(0, $result->releaseFilesAdded);
        $this->assertSame([1], $synchronizedReleaseIds);
        $this->assertSame(2, $result->persistenceMetrics?->searchSyncRequests);
        $this->assertSame(1, $result->persistenceMetrics?->searchSyncExecutions);
        $this->assertGreaterThan(0.0, $result->elapsedSeconds);
        $this->assertSame([
            ProcessingStage::WorkspacePreparation->value,
            ProcessingStage::NzbParsing->value,
            ProcessingStage::ReleaseInitialization->value,
            ProcessingStage::MessageIdSelection->value,
            ProcessingStage::Finalization->value,
            ProcessingStage::WorkspaceCleanup->value,
        ], array_keys($result->stageDurations));
    }

    #[Test]
    public function it_always_uses_the_v2_work_plan_for_message_id_selection(): void
    {
        $config = $this->makeConfig();
        $nzbContents = [['title' => 'file.nzb', 'segments' => ['<message>']]];
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
            'error' => null,
            'contents' => $nzbContents,
        ]);
        $nzbParser->shouldNotReceive('extractMessageIDs');

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            Mockery::mock(MediaExtractionService::class),
            $this->scopedDownloadService(),
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $this->successfulTempWorkspace(),
            Mockery::mock(ConsoleOutputService::class)
                ->shouldReceive('echoReleaseStart')->once()->andReturnNull()
                ->shouldReceive('setProcessTitle')->once()->andReturnNull()
                ->getMock(),
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertNotNull($context->workPlan);
        $this->assertSame(0, $result->duplicateMessageIdCount);
    }

    #[Test]
    public function it_marks_release_timeout_before_full_processing_continues(): void
    {
        $config = $this->makeConfig(['releaseProcessingTimeout' => 1, 'maxPpTimeoutCount' => 2]);

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
            'error' => null,
            'contents' => [],
        ]);

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('handleReleaseTimeout')->once()->andReturn(false);
        $releaseManager->shouldNotReceive('finalizeRelease');

        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath);
        $tempWorkspace->shouldReceive('clearDirectory')->twice()->with($this->releaseTempPath, false)->andReturnNull();

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();
        $output->shouldReceive('echoReleaseTimeout')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            Mockery::mock(MediaExtractionService::class),
            $this->scopedDownloadService(),
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->startTime = hrtime(true) - 2_000_000_000;

        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::TimedOut, $result->outcome);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('exceeded', $result->reason);
        $this->assertArrayHasKey(ProcessingStage::TimeoutHandling->value, $result->stageDurations);
    }

    #[Test]
    public function it_reports_a_deleted_timeout_separately(): void
    {
        $config = $this->makeConfig(['releaseProcessingTimeout' => 1, 'maxPpTimeoutCount' => 1]);
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn(['error' => null, 'contents' => []]);

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('handleReleaseTimeout')->once()->andReturn(true);
        $releaseManager->shouldNotReceive('finalizeRelease');

        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath);
        $tempWorkspace->shouldReceive('clearDirectory')->twice()->with($this->releaseTempPath, false)->andReturnNull();

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();
        $output->shouldReceive('echoReleaseTimeoutDeleted')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            Mockery::mock(MediaExtractionService::class),
            $this->scopedDownloadService(),
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->startTime = hrtime(true) - 2_000_000_000;

        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::DeletedAfterTimeout, $result->outcome);
        $this->assertTrue($result->outcome->isDeleted());
        $this->assertFalse($result->isSuccessful());
    }

    #[Test]
    public function it_reports_a_temporary_workspace_failure(): void
    {
        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')
            ->once()
            ->andThrow(new \RuntimeException('workspace is not writable'));

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();
        $output->shouldReceive('warning')->once()->with('Unable to prepare release temp directory: workspace is not writable')->andReturnNull();

        $processor = $this->makeProcessor(tempWorkspace: $tempWorkspace, output: $output);

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::TemporaryWorkspaceUnavailable, $result->outcome);
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('workspace is not writable', $result->reason);
    }

    #[Test]
    public function it_reports_password_detection_as_a_successful_outcome(): void
    {
        $config = $this->makeConfig(['processPasswords' => true]);
        $nzbParser = $this->compressedNzbParser();

        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        $archiveService->shouldReceive('processCompressedData')->once()->andReturn([
            'success' => false,
            'files' => [],
            'hasPassword' => true,
            'passwordStatus' => ReleaseBrowseService::PASSWD_RAR,
        ]);

        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $this->expectDownloadScope($downloadService);
        $downloadService->shouldReceive('download')
            ->once()
            ->with(DownloadKind::Compressed, ['<archive>'], '', 1, 'archive.part01.rar')
            ->andReturn(['success' => true, 'data' => 'ARCHIVE', 'groupUnavailable' => false, 'error' => null]);

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            $archiveService,
            Mockery::mock(MediaExtractionService::class),
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $this->successfulTempWorkspace(),
            $this->passwordOutput(),
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::Passworded, $result->outcome);
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->artifactsCreated);
    }

    #[Test]
    public function it_reports_group_unavailability_as_an_unsuccessful_outcome(): void
    {
        $config = $this->makeConfig(['processPasswords' => true]);
        $nzbParser = $this->compressedNzbParser();

        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $this->expectDownloadScope($downloadService);
        $downloadService->shouldReceive('download')->once()->andReturn([
            'success' => false,
            'data' => null,
            'groupUnavailable' => true,
            'error' => 'Group unavailable',
        ]);

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();
        $output->shouldReceive('echoGroupUnavailable')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            Mockery::mock(MediaExtractionService::class),
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $this->successfulTempWorkspace(),
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::GroupUnavailable, $result->outcome);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('unavailable', $result->reason);
    }

    #[Test]
    public function it_sniffs_only_the_first_segment_and_reuses_the_payload_for_archive_processing(): void
    {
        $rarPayload = "Rar!\x1A\x07\x00\x00\x00\x73".pack('v', 0x0101).'archive';
        $config = $this->makeConfig([
            'payloadSniffMaxCandidates' => 2,
            'payloadSniffByteBudget' => 1000,
        ]);
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [
                ['title' => 'large.bin', 'segments' => ['<large-first>', '<large-second>'], 'size' => 400, 'partsactual' => 2],
                ['title' => 'small.bin', 'segments' => ['<small-first>', '<small-second>'], 'size' => 200, 'partsactual' => 2],
            ],
        ]);

        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $this->expectDownloadScope($downloadService);
        $downloadService->shouldReceive('download')
            ->once()
            ->with(DownloadKind::PayloadSniff, ['<large-first>'], '', 1, 'large.bin')
            ->andReturn(['success' => true, 'data' => $rarPayload, 'groupUnavailable' => false, 'error' => null]);
        $downloadService->shouldReceive('download')
            ->once()
            ->with(DownloadKind::PayloadSniff, ['<small-first>'], '', 1, 'small.bin')
            ->andReturn(['success' => true, 'data' => "\x00\x01unknown", 'groupUnavailable' => false, 'error' => null]);

        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        $archiveService->shouldReceive('processCompressedData')
            ->once()
            ->with($rarPayload, Mockery::type(ReleaseProcessingContext::class), $this->releaseTempPath)
            ->andReturn([
                'success' => true,
                'files' => [[
                    'name' => 'video.mkv',
                    'size' => 123,
                    'date' => 0,
                    'pass' => false,
                    'crc32' => 'ABC123',
                ]],
                'hasPassword' => false,
                'passwordStatus' => ReleaseBrowseService::PASSWD_NONE,
            ]);

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('addFileInfo')->once()->with(
            Mockery::on(static fn (array $file): bool => $file['crc32'] === 'ABC123'),
            Mockery::type(ReleaseProcessingContext::class),
            $config->supportFileRegex,
        )->andReturnUsing(static function (array $file, ReleaseProcessingContext $context): bool {
            $context->totalFileInfo++;
            $context->addedFileInfo++;
            $context->releaseFilesChanged = true;

            return true;
        });
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();

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
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $this->successfulTempWorkspace(),
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(2, $result->payloadSniffMetrics->candidateCount);
        $this->assertSame(['rar' => 1, 'unknown' => 1], $result->payloadSniffMetrics->classificationCounts);
        $this->assertSame(['unknown-payload'], $result->unsupportedReasons);
        $this->assertSame(1, $result->releaseFilesAdded);
        $this->assertArrayHasKey(ProcessingStage::PayloadSniffing->value, $result->stageDurations);
    }

    #[Test]
    public function it_routes_sniffed_par2_media_and_text_payloads_to_existing_handlers(): void
    {
        $tmpPath = $this->makeTempDirectory('nntmux-payload-routing').'/';
        $config = $this->makeConfig([
            'payloadSniffMaxCandidates' => 3,
            'processMediaInfo' => true,
        ]);
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [
                ['title' => 'large.bin', 'segments' => ['<par2-first>', '<par2-second>'], 'size' => 300, 'partsactual' => 2],
                ['title' => 'medium.bin', 'segments' => ['<video-first>', '<video-second>'], 'size' => 200, 'partsactual' => 2],
                ['title' => 'small.bin', 'segments' => ['<text-first>', '<text-second>'], 'size' => 100, 'partsactual' => 2],
            ],
        ]);

        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $this->expectDownloadScope($downloadService);
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<par2-first>'],
            '',
            1,
            'large.bin',
        )->andReturn(['success' => true, 'data' => "PAR2\x00PKTdata", 'groupUnavailable' => false, 'error' => null]);
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<text-first>'],
            '',
            1,
            'small.bin',
        )->andReturn(['success' => true, 'data' => 'Release information', 'groupUnavailable' => false, 'error' => null]);
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<video-first>'],
            '',
            1,
            'medium.bin',
        )->andReturn(['success' => true, 'data' => "\x1A\x45\xDF\xA3video", 'groupUnavailable' => false, 'error' => null]);
        $nntp = Mockery::mock(NNTPService::class);
        $downloadService->shouldReceive('getNNTP')->once()->andReturn($nntp);

        $par2Info = Mockery::mock(Par2Info::class);
        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        $archiveService->shouldReceive('getPar2Info')->once()->andReturn($par2Info);

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('processPar2File')->once()->with(
            Mockery::on(static fn (string $path): bool => File::get($path) === "PAR2\x00PKTdata"),
            Mockery::type(ReleaseProcessingContext::class),
            $par2Info,
        )->andReturnUsing(static function (string $path, ReleaseProcessingContext $context): bool {
            $context->foundPAR2Info = true;
            $context->pendingParHashes['0123456789abcdef0123456789abcdef'] = [
                'releases_id' => 1,
                'hash' => '0123456789abcdef0123456789abcdef',
            ];
            $context->release->searchname = 'Recovered.From.PAR2';

            return true;
        });
        $releaseManager->shouldReceive('processNfoFile')->once()->with(
            Mockery::on(static fn (string $path): bool => File::get($path) === 'Release information'),
            Mockery::type(ReleaseProcessingContext::class),
            $nntp,
        )->andReturnUsing(static function (string $path, ReleaseProcessingContext $context): bool {
            $context->releaseHasNoNFO = false;

            return true;
        });
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();

        $mediaService = Mockery::mock(MediaExtractionService::class);
        $mediaService->shouldReceive('processVideoFile')->once()->with(
            Mockery::on(static fn (string $path): bool => File::get($path) === "\x1A\x45\xDF\xA3video"),
            Mockery::type(ReleaseProcessingContext::class),
            $tmpPath,
        )->andReturnUsing(static function (string $path, ReleaseProcessingContext $context): array {
            $context->foundMediaInfo = true;

            return ['sample' => false, 'video' => false, 'mediaInfo' => true];
        });

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();
        $output->shouldReceive('echoNfoFound')->once()->andReturnNull();

        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($tmpPath);
        $tempWorkspace->shouldReceive('clearDirectory')->once()->with($tmpPath, false)->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            $archiveService,
            $mediaService,
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertSame(3, $result->payloadSniffMetrics->candidateCount);
        $this->assertSame(['par2' => 1, 'text' => 1, 'matroska' => 1], $result->payloadSniffMetrics->classificationCounts);
        $this->assertTrue($context->foundPAR2Info);
        $this->assertArrayHasKey('0123456789abcdef0123456789abcdef', $context->pendingParHashes);
        $this->assertSame('Recovered.From.PAR2', $context->release->searchname);
        $this->assertTrue($context->foundMediaInfo);
        $this->assertFalse($context->releaseHasNoNFO);
    }

    #[Test]
    public function it_defers_later_rar_volumes_until_other_candidates_are_sniffed_then_preserves_source_order(): void
    {
        $events = [];
        $laterRarA = "Rar!\x1A\x07\x00\x00\x00\x73".pack('v', 0x0001).'archive-a';
        $laterRarB = "Rar!\x1A\x07\x00\x00\x00\x73".pack('v', 0x0001).'archive-b';
        $config = $this->makeConfig(['payloadSniffMaxCandidates' => 3]);
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [
                ['title' => 'later-a.bin', 'segments' => ['<later-a>', '<unused-a>'], 'size' => 300, 'partsactual' => 2],
                ['title' => 'later-b.bin', 'segments' => ['<later-b>', '<unused-b>'], 'size' => 200, 'partsactual' => 2],
                ['title' => 'unknown.bin', 'segments' => ['<unknown>', '<unused-unknown>'], 'size' => 100, 'partsactual' => 2],
            ],
        ]);

        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $this->expectDownloadScope($downloadService);
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<later-a>'],
            '',
            1,
            'later-a.bin',
        )->andReturnUsing(static function () use (&$events, $laterRarA): array {
            $events[] = 'download-a';

            return ['success' => true, 'data' => $laterRarA, 'groupUnavailable' => false, 'error' => null];
        });
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<unknown>'],
            '',
            1,
            'unknown.bin',
        )->andReturnUsing(static function () use (&$events): array {
            $events[] = 'download-unknown';

            return ['success' => true, 'data' => "\x00\x01unknown", 'groupUnavailable' => false, 'error' => null];
        });
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::PayloadSniff,
            ['<later-b>'],
            '',
            1,
            'later-b.bin',
        )->andReturnUsing(static function () use (&$events, $laterRarB): array {
            $events[] = 'download-b';

            return ['success' => true, 'data' => $laterRarB, 'groupUnavailable' => false, 'error' => null];
        });

        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        $archiveService->shouldReceive('processCompressedData')->twice()->andReturnUsing(
            static function (string $payload) use (&$events, $laterRarA): array {
                $events[] = $payload === $laterRarA ? 'archive-a' : 'archive-b';

                return [
                    'success' => false,
                    'files' => [],
                    'hasPassword' => false,
                    'passwordStatus' => ReleaseBrowseService::PASSWD_NONE,
                ];
            },
        );
        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();
        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            $archiveService,
            Mockery::mock(MediaExtractionService::class),
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $this->successfulTempWorkspace(),
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $processor->process($context, $this->mainTempPath);

        $this->assertSame(
            ['download-a', 'download-unknown', 'download-b', 'archive-a', 'archive-b'],
            $events,
        );
    }

    #[Test]
    public function it_processes_media_info_when_password_inspection_is_disabled(): void
    {
        $tmpPath = $this->makeTempDirectory('nntmux-media-info').'/';

        $config = $this->makeConfig([
            'processPasswords' => false,
            'processMediaInfo' => true,
        ]);
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [['title' => 'video.mkv" yEnc', 'segments' => ['media-message-id']]],
        ]);
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $this->expectDownloadScope($downloadService);
        $downloadService->shouldReceive('download')
            ->once()
            ->with(DownloadKind::MediaInfo, ['media-message-id'], '', 1)
            ->andReturn([
                'success' => true,
                'data' => str_repeat('x', 41),
                'groupUnavailable' => false,
                'error' => null,
            ]);
        $downloadService->shouldReceive('meetsMinimumSize')->once()->andReturnTrue();

        $mediaService = Mockery::mock(MediaExtractionService::class);
        $mediaService->shouldReceive('getMediaInfo')->once()->andReturnTrue();

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('finalizeRelease')->once()->with(Mockery::type(ReleaseProcessingContext::class), false);

        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($tmpPath);
        $tempWorkspace->shouldReceive('clearDirectory')->once()->andReturnNull();

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once();
        $output->shouldReceive('setProcessTitle')->once();
        $output->shouldReceive('echoMediaInfoDownload')->once();
        $output->shouldReceive('echoMediaInfoAdded')->once();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            $mediaService,
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;

        try {
            $processor->process($context, $tmpPath);

            $this->assertTrue($context->foundMediaInfo);
        } finally {
            @unlink($tmpPath.'media.avi');
            @rmdir($tmpPath);
        }
    }

    #[Test]
    public function an_mdat_first_mp4_fetches_one_tail_and_processes_the_spliced_file(): void
    {
        $head = $this->mp4Atom('ftyp', 'isom0000').pack('N', 4096).'mdat'.str_repeat('v', 48);
        $tail = 'tail-prefix'.$this->validMoovAtom();

        [$context, $downloadService, $mediaService] = $this->processDirectVideoCandidate(
            'feature.mp4" yEnc',
            $head,
            $this->successfulDownload($tail),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTail],
            array_column($downloadService->calls, 'kind'),
        );
        $this->assertTrue($context->foundMediaInfo);
        $this->assertTrue($context->foundSample);
        $this->assertCount(1, $mediaService->mediaInfoFiles);
        $this->assertCount(1, $mediaService->sampleFiles);
        $this->assertStringEndsWith('media.mp4', $mediaService->mediaInfoFiles[0]['path']);
        $this->assertSame($mediaService->mediaInfoFiles[0], $mediaService->sampleFiles[0]);
        $this->assertStringEndsWith($this->validMoovAtom(), $mediaService->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function an_mkv_media_candidate_never_fetches_an_mp4_tail(): void
    {
        $head = "\x1A\x45\xDF\xA3".str_repeat('m', 48);

        [, $downloadService, $mediaService] = $this->processDirectVideoCandidate(
            'feature.mkv" yEnc',
            $head,
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadService->calls, 'kind'));
        $this->assertStringEndsWith('media.avi', $mediaService->mediaInfoFiles[0]['path']);
        $this->assertSame($head, $mediaService->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function an_mp4_tail_download_failure_keeps_the_head_only_behavior(): void
    {
        $head = $this->mp4Atom('ftyp', 'isom0000').pack('N', 4096).'mdat'.str_repeat('v', 48);

        [, $downloadService, $mediaService] = $this->processDirectVideoCandidate(
            'feature.mp4" yEnc',
            $head,
            $this->failedDownload(),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTail],
            array_column($downloadService->calls, 'kind'),
        );
        $this->assertStringEndsWith('media.avi', $mediaService->mediaInfoFiles[0]['path']);
        $this->assertSame($head, $mediaService->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function it_skips_sample_downloads_and_ffmpeg_when_the_root_category_disables_preview_generation(): void
    {
        $config = $this->makeConfig([
            'processThumbnails' => true,
            'processVideo' => true,
        ]);

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [['title' => 'movie.sample.mkv" yEnc', 'segments' => ['sample-message-id']]],
        ]);

        // No download() expectation: a sample-article download attempt would
        // fail the test as an unexpected mock call.
        $downloadService = $this->scopedDownloadService();

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $finalizedContext = null;
        $releaseManager->shouldReceive('finalizeRelease')
            ->once()
            ->andReturnUsing(static function (ReleaseProcessingContext $context) use (&$finalizedContext): void {
                $finalizedContext = $context;
            });

        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath);
        $tempWorkspace->shouldReceive('clearDirectory')->once()->with($this->releaseTempPath, false)->andReturnNull();

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once();
        $output->shouldReceive('setProcessTitle')->once();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            Mockery::mock(MediaExtractionService::class),
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            previewPolicy: $this->stubPreviewPolicy(enabled: false),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertNotNull($finalizedContext);
        $this->assertTrue($finalizedContext->previewGenerationSkippedByPolicy);
        $this->assertTrue($finalizedContext->foundSample, 'Pre-marked so the sample pipeline never runs.');
        $this->assertTrue($finalizedContext->foundVideo, 'Pre-marked so the video pipeline never runs.');
        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertFalse($result->artifactsCreated, 'Pre-marked flags must not count as created artifacts.');
    }

    private function makeProcessor(
        ?NzbContentParser $nzbParser = null,
        ?ReleaseFileManager $releaseManager = null,
        ?TempWorkspaceService $tempWorkspace = null,
        ?ConsoleOutputService $output = null
    ): ReleaseProcessor {
        $config = $this->makeConfig();

        return new ReleaseProcessor(
            $config,
            $nzbParser ?? Mockery::mock(NzbContentParser::class),
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            Mockery::mock(MediaExtractionService::class),
            $this->scopedDownloadService(),
            $releaseManager ?? Mockery::mock(ReleaseFileManager::class),
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace ?? Mockery::mock(TempWorkspaceService::class),
            $output ?? Mockery::mock(ConsoleOutputService::class),
            previewPolicy: $this->stubPreviewPolicy(),
        );
    }

    /**
     * @param  array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}|null  $tailResponse
     * @return array{ReleaseProcessingContext, RecordingMp4DownloadService, RecordingMediaExtractionService}
     */
    private function processDirectVideoCandidate(
        string $title,
        string $head,
        ?array $tailResponse = null,
    ): array {
        File::ensureDirectoryExists($this->releaseTempPath);
        $config = $this->makeConfig([
            'processMediaInfo' => true,
            'processThumbnails' => true,
            'mp4TailFetch' => true,
            'segmentsToDownload' => 2,
            'mp4TailMaxSegments' => 4,
        ]);
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [[
                'title' => $title,
                'segments' => ['<head-1>', '<head-2>', '<tail-1>', '<tail-2>'],
            ]],
        ]);

        $responses = [$this->successfulDownload($head)];
        if ($tailResponse !== null) {
            $responses[] = $tailResponse;
        }
        $downloadService = new RecordingMp4DownloadService($responses);
        /** @var RecordingMediaExtractionService $mediaService */
        $mediaService = (new \ReflectionClass(RecordingMediaExtractionService::class))->newInstanceWithoutConstructor();
        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            Mockery::mock(ArchiveExtractionService::class),
            $mediaService,
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $this->successfulTempWorkspace(),
            new ConsoleOutputService,
            previewPolicy: $this->stubPreviewPolicy(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $processor->process($context, $this->mainTempPath);

        return [$context, $downloadService, $mediaService];
    }

    /**
     * @return array{success: true, data: string, groupUnavailable: false, error: null}
     */
    private function successfulDownload(string $data): array
    {
        return [
            'success' => true,
            'data' => $data,
            'groupUnavailable' => false,
            'error' => null,
        ];
    }

    /**
     * @return array{success: false, data: null, groupUnavailable: false, error: string}
     */
    private function failedDownload(): array
    {
        return [
            'success' => false,
            'data' => null,
            'groupUnavailable' => false,
            'error' => 'tail unavailable',
        ];
    }

    private function mp4Atom(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8).$type.$payload;
    }

    private function validMoovAtom(): string
    {
        return $this->mp4Atom('moov', $this->mp4Atom('mvhd', 'movie-header'));
    }

    private function makeContext(): ReleaseProcessingContext
    {
        return new ReleaseProcessingContext(new Release([
            'id' => 1,
            'guid' => 'guid-1',
            'size' => 1024,
            'groups_id' => 10,
            'nfostatus' => -1,
            'pp_timeout_count' => 0,
        ]));
    }

    /**
     * DB-free policy double: the real service resolves the leaf category's
     * root toggle from the database, which unit tests do not have.
     */
    private function stubPreviewPolicy(bool $enabled = true): PreviewGenerationPolicy
    {
        return new class($enabled) extends PreviewGenerationPolicy
        {
            public function __construct(private readonly bool $enabled) {}

            public function generationEnabledForCategory(int $categoriesId): bool
            {
                return $this->enabled;
            }
        };
    }

    private function compressedNzbParser(): NzbContentParser
    {
        $parser = Mockery::mock(NzbContentParser::class);
        $parser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [['title' => 'archive.part01.rar', 'segments' => ['<archive>']]],
        ]);

        return $parser;
    }

    private function scopedDownloadService(): UsenetDownloadService
    {
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $this->expectDownloadScope($downloadService);

        return $downloadService;
    }

    private function expectDownloadScope(UsenetDownloadService $downloadService): void
    {
        $downloadService->shouldReceive('beginReleaseScope')->once()->andReturnNull();
        $downloadService->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
    }

    private function successfulTempWorkspace(): TempWorkspaceService
    {
        return Mockery::mock(TempWorkspaceService::class)
            ->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath)
            ->shouldReceive('clearDirectory')->once()->with($this->releaseTempPath, false)->andReturnNull()
            ->getMock();
    }

    private function passwordOutput(): ConsoleOutputService
    {
        return Mockery::mock(ConsoleOutputService::class)
            ->shouldReceive('echoReleaseStart')->once()->andReturnNull()
            ->shouldReceive('setProcessTitle')->once()->andReturnNull()
            ->shouldReceive('echoCompressedDownload')->once()->andReturnNull()
            ->getMock();
    }
}

final class RecordingMp4DownloadService extends UsenetDownloadService
{
    /**
     * @var list<array{kind: DownloadKind, messageIds: list<string>}>
     */
    public array $calls = [];

    /**
     * @param  list<array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}>  $responses
     */
    public function __construct(private array $responses) {}

    public function beginReleaseScope(): void {}

    public function finishReleaseScope(): DownloadMetrics
    {
        return new DownloadMetrics;
    }

    /**
     * @param  array<int|string, mixed>|string  $messageIDs
     * @return array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}
     */
    public function download(
        DownloadKind $kind,
        array|string $messageIDs,
        string $groupName = '',
        ?int $releaseId = null,
        ?string $fileTitle = null,
    ): array {
        $this->calls[] = [
            'kind' => $kind,
            'messageIds' => array_map('strval', is_array($messageIDs) ? array_values($messageIDs) : [$messageIDs]),
        ];

        return array_shift($this->responses) ?? [
            'success' => false,
            'data' => null,
            'groupUnavailable' => false,
            'error' => 'Unexpected download',
        ];
    }

    public function meetsMinimumSize(string $data, int $minimumBytes = 40): bool
    {
        return strlen($data) > $minimumBytes;
    }
}

final class RecordingMediaExtractionService extends MediaExtractionService
{
    /** @var list<array{path: string, data: string}> */
    public array $mediaInfoFiles = [];

    /** @var list<array{path: string, data: string}> */
    public array $sampleFiles = [];

    public function getMediaInfo(string $fileLocation, int $releaseId): bool
    {
        $this->mediaInfoFiles[] = ['path' => $fileLocation, 'data' => (string) file_get_contents($fileLocation)];

        return true;
    }

    public function getSample(string $fileLocation, string $tmpPath, string $guid): bool
    {
        $this->sampleFiles[] = ['path' => $fileLocation, 'data' => (string) file_get_contents($fileLocation)];

        return true;
    }
}
