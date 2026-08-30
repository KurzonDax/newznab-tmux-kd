<?php

namespace Tests\Unit\AdditionalProcessing;

use App\Enums\ImagerySkipArtifact;
use App\Enums\NzbParseFailure;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\DTO\VideoHeadProbeResult;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\FreeDiskGuard;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AdditionalProcessing\VideoDecodableLengthProbe;
use App\Services\AdditionalProcessing\VideoHeadProbe;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\DynamicPreviewBudgetPolicy;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
    public function it_deletes_release_when_nzb_is_missing_from_healthy_storage(): void
    {
        $processor = $this->makeProcessor(
            nzbParser: Mockery::mock(NzbContentParser::class)
                ->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
                    'error' => 'NZB not found',
                    'contents' => [],
                    'failure' => NzbParseFailure::Missing,
                ])->getMock(),
            releaseManager: Mockery::mock(ReleaseFileManager::class)
                ->shouldReceive('deleteRelease')->once()->andReturnNull()->getMock(),
            tempWorkspace: Mockery::mock(TempWorkspaceService::class)
                ->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath)
                ->shouldReceive('clearDirectory')->once()->with($this->releaseTempPath, false)->andReturnNull()->getMock(),
            output: Mockery::mock(ConsoleOutputService::class)
                ->shouldReceive('echoReleaseStart')->once()->andReturnNull()
                ->shouldReceive('setProcessTitle')->once()->andReturnNull()
                ->shouldReceive('warning')->once()->with('NZB not found')->andReturnNull()->getMock()
        );

        $result = $processor->process($this->makeContext(), $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::DeletedBrokenNzb, $result->outcome);
        $this->assertTrue($result->outcome->isDeleted());
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('NZB not found', $result->reason);
        $this->assertGreaterThan(0.0, $result->elapsedSeconds);
        $this->assertArrayHasKey(ProcessingStage::WorkspacePreparation->value, $result->stageDurations);
        $this->assertArrayHasKey(ProcessingStage::NzbParsing->value, $result->stageDurations);
        $this->assertArrayHasKey(ProcessingStage::WorkspaceCleanup->value, $result->stageDurations);
    }

    #[Test]
    public function it_preserves_the_release_when_nzb_storage_is_unavailable(): void
    {
        $processor = $this->makeProcessor(
            nzbParser: Mockery::mock(NzbContentParser::class)
                ->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
                    'error' => 'NZB storage is unavailable',
                    'contents' => [],
                    'failure' => NzbParseFailure::StorageUnavailable,
                ])->getMock(),
            releaseManager: Mockery::mock(ReleaseFileManager::class)
                ->shouldNotReceive('deleteRelease')->getMock(),
            tempWorkspace: Mockery::mock(TempWorkspaceService::class)
                ->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath)
                ->shouldReceive('clearDirectory')->once()->with($this->releaseTempPath, false)->andReturnNull()->getMock(),
            output: Mockery::mock(ConsoleOutputService::class)
                ->shouldReceive('echoReleaseStart')->once()->andReturnNull()
                ->shouldReceive('setProcessTitle')->once()->andReturnNull()
                ->shouldReceive('warning')->once()->with('NZB storage is unavailable')->andReturnNull()->getMock(),
        );

        $result = $processor->process($this->makeContext(), $this->mainTempPath);

        $this->assertSame(ProcessingOutcome::StorageUnavailable, $result->outcome);
        $this->assertFalse($result->outcome->isDeleted());
        $this->assertFalse($result->isSuccessful());
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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

        [$context, $downloadCalls, $mediaCalls] = $this->processDirectVideoCandidate(
            'feature.mp4" yEnc',
            $head,
            $this->successfulDownload($tail),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTail],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertTrue($context->foundMediaInfo);
        $this->assertTrue($context->foundSample);
        $this->assertCount(1, $mediaCalls->mediaInfoFiles);
        $this->assertCount(1, $mediaCalls->sampleFiles);
        $this->assertStringEndsWith('media.mp4', $mediaCalls->mediaInfoFiles[0]['path']);
        $this->assertSame($mediaCalls->mediaInfoFiles[0], $mediaCalls->sampleFiles[0]);
        $this->assertStringEndsWith($this->validMoovAtom(), $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function an_mkv_media_candidate_never_fetches_an_mp4_tail(): void
    {
        $head = "\x1A\x45\xDF\xA3".str_repeat('m', 48);

        [, $downloadCalls, $mediaCalls] = $this->processDirectVideoCandidate(
            'feature.mkv" yEnc',
            $head,
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadCalls->calls, 'kind'));
        $this->assertStringEndsWith('media.avi', $mediaCalls->mediaInfoFiles[0]['path']);
        $this->assertSame($head, $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function an_mp4_tail_download_failure_keeps_the_head_only_behavior(): void
    {
        $head = $this->mp4Atom('ftyp', 'isom0000').pack('N', 4096).'mdat'.str_repeat('v', 48);

        [, $downloadCalls, $mediaCalls] = $this->processDirectVideoCandidate(
            'feature.mp4" yEnc',
            $head,
            $this->failedDownload(),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTail],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertStringEndsWith('media.avi', $mediaCalls->mediaInfoFiles[0]['path']);
        $this->assertSame($head, $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function the_dynamic_budget_tops_up_the_head_to_the_target_duration(): void
    {
        // 300 total bytes / 30s = 10 B/s; 30s target = 300 bytes; the 100-byte
        // head (50 bytes/segment) needs 4 more segments.
        [, $downloadCalls, $mediaCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMkvFile(sizeBytes: 300),
            str_repeat('h', 100),
            [$this->successfulDownload('TOPUP-DATA')],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<s3>', '<s4>', '<s5>', '<s6>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(str_repeat('h', 100).'TOPUP-DATA', $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_higher_bitrate_raises_the_top_up_until_the_ceiling_caps_it(): void
    {
        // 450 bytes / 30s = 15 B/s -> 450 target bytes -> 7 more segments.
        [, $downloadCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMkvFile(sizeBytes: 450),
            str_repeat('h', 100),
            [$this->successfulDownload('TOPUP-DATA')],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );
        $this->assertCount(7, $downloadCalls->calls[1]['messageIds']);

        // Same bitrate under a 250-byte ceiling: 150 bytes remain after the
        // 100-byte head, so only 3 whole segments fit.
        [, $cappedCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMkvFile(sizeBytes: 450),
            str_repeat('h', 100),
            [$this->successfulDownload('TOPUP-DATA')],
            ['previewMaxFetchBytes' => 250],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );
        $this->assertSame(['<s3>', '<s4>', '<s5>'], $cappedCalls->calls[1]['messageIds']);
    }

    #[Test]
    public function a_zero_ceiling_means_unlimited(): void
    {
        // 600 bytes / 30s -> 10 more segments wanted; only 8 expansion
        // segments exist, and the 0 ceiling never caps them.
        [, $downloadCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMkvFile(sizeBytes: 600),
            str_repeat('h', 100),
            [$this->successfulDownload('TOPUP-DATA')],
            ['previewMaxFetchBytes' => 0],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertCount(8, $downloadCalls->calls[1]['messageIds']);
    }

    #[Test]
    public function an_undeterminable_bitrate_keeps_the_fixed_count_behavior(): void
    {
        [, $downloadCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMkvFile(sizeBytes: 300),
            str_repeat('h', 100),
            probeResult: null,
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadCalls->calls, 'kind'));
    }

    #[Test]
    public function a_disabled_root_toggle_never_tops_up(): void
    {
        [, $downloadCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMkvFile(sizeBytes: 300),
            str_repeat('h', 100),
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
            budgetEnabled: false,
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadCalls->calls, 'kind'));
    }

    #[Test]
    public function a_gap_inside_the_needed_head_range_skips_the_top_up_with_a_distinct_reason(): void
    {
        $file = $this->tenSegmentMkvFile(sizeBytes: 300);
        // Segment 6 is missing: the head is only contiguous through 5
        // segments, and the needed range (2 head + 4 top-up) reaches 6.
        $file['segmentNumbers'] = [1, 2, 3, 4, 5, 7, 8, 9, 10, 11];
        $file['partstotal'] = '11';

        [, $downloadCalls, , $result] = $this->processDynamicBudgetCandidate(
            $file,
            str_repeat('h', 100),
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadCalls->calls, 'kind'));
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
    }

    #[Test]
    public function a_bare_mp4_only_tops_up_after_a_successful_moov_splice(): void
    {
        $head = $this->mp4Atom('ftyp', 'isom0000').pack('N', 4096).'mdat'.str_repeat('v', 48);
        $tail = 'tail-prefix'.$this->validMoovAtom();

        [, $downloadCalls, $mediaCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMp4File(sizeBytes: 400),
            $head,
            [$this->successfulDownload($tail), $this->successfulDownload('TOPUP-DATA')],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTail, DownloadKind::MediaInfoTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(
            ['<s3>', '<s4>', '<s5>', '<s6>'],
            $downloadCalls->calls[2]['messageIds'],
            'The top-up must stop before the already-fetched tail window: no byte is fetched twice.'
        );
        $this->assertStringEndsWith('media.mp4', $mediaCalls->mediaInfoFiles[0]['path']);
        $this->assertStringEndsWith($this->validMoovAtom(), $mediaCalls->mediaInfoFiles[0]['data']);
        $this->assertStringContainsString('TOPUP-DATA', $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_failed_moov_splice_means_no_top_up(): void
    {
        $head = $this->mp4Atom('ftyp', 'isom0000').pack('N', 4096).'mdat'.str_repeat('v', 48);

        [, $downloadCalls, $mediaCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMp4File(sizeBytes: 400),
            $head,
            [$this->failedDownload()],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTail],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame($head, $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_gapped_mp4_tail_window_spends_no_tail_fetches_and_records_the_reason(): void
    {
        $head = $this->mp4Atom('ftyp', 'isom0000').pack('N', 4096).'mdat'.str_repeat('v', 48);
        $file = $this->tenSegmentMp4File(sizeBytes: 400);
        // The post is truncated: the declared total (12) is never reached, so
        // the moov at the real end of the file was never posted.
        $file['partstotal'] = '12';

        [, $downloadCalls, $mediaCalls, $result] = $this->processDynamicBudgetCandidate(
            $file,
            $head,
            probeResult: null,
            budgetEnabled: false,
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadCalls->calls, 'kind'));
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
        $this->assertStringEndsWith('media.avi', $mediaCalls->mediaInfoFiles[0]['path']);
    }

    #[Test]
    public function the_compressed_budget_fetches_sequential_parts_until_the_target_is_covered(): void
    {
        // movie.mkv is 1200 bytes at 30s declared duration = 40 B/s; the 30s
        // target needs 1200 bytes. The 300-byte head fetch (100 bytes/segment)
        // extracts 300, the anchor's 3 expansion segments extract to 600, and
        // part02's 6 segments reach 1200. part03 is never touched.
        [$downloadCalls, $result, $fragmentPath] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>', '<p2s4>', '<p2s5>', '<p2s6>']],
                ['title' => 'release.part03.rar', 'segments' => ['<p3s1>', '<p3s2>', '<p3s3>', '<p3s4>', '<p3s5>', '<p3s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [
                $this->successfulDownload(str_repeat('b', 300)),
                $this->successfulDownload(str_repeat('c', 600)),
            ],
            extractSizes: [300, 600, 1200],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p1s4>', '<p1s5>', '<p1s6>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(
            ['<p2s1>', '<p2s2>', '<p2s3>', '<p2s4>', '<p2s5>', '<p2s6>'],
            $downloadCalls->calls[2]['messageIds'],
        );
        $this->assertSame(1200, filesize($fragmentPath), 'The extended fragment is what the extracted-files sweep will clip.');
        $this->assertSame(
            [],
            glob($this->releaseTempPath.'preview-archive.part*.rar') ?: [],
            'Working volume files must not survive into the extracted-files sweep.',
        );
    }

    #[Test]
    public function a_large_compressed_fragment_with_only_seven_playable_seconds_is_topped_up(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [1500, 1800],
            probeResult: new VideoHeadProbeResult(durationSeconds: 120.0),
            videoEntrySize: 4800,
            decodableDurations: [7.0, 35.0],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
            'A metadata-heavy fragment must not satisfy the preview target until its playable duration does.',
        );
    }

    #[Test]
    public function a_compressed_fragment_meeting_the_duration_target_skips_top_up(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [],
            extractSizes: [300],
            decodableDurations: [30.0],
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
    }

    #[Test]
    public function the_compressed_top_up_pool_excludes_already_fetched_message_ids(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s1>', '<p1s4>', '<p1s5>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 200))],
            extractSizes: [300, 500],
            decodableDurations: [7.0, 30.0],
        );

        $this->assertSame(['<p1s4>', '<p1s5>'], $downloadCalls->calls[1]['messageIds']);
    }

    #[Test]
    public function the_compressed_top_up_pool_excludes_message_ids_tried_before_the_video_anchor(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'earlier.part01.rar', 'segments' => ['<shared>', '<old2>', '<old3>']],
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<shared>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [
                $this->successfulDownload(str_repeat('a', 300)),
                $this->successfulDownload(str_repeat('b', 200)),
            ],
            extractSizes: [300, 500],
            configOverrides: ['maximumRarPasswordChecks' => 2],
            decodableDurations: [7.0, 30.0],
            initialResponse: $this->failedDownload(),
        );

        $this->assertSame(['<p1s5>', '<p1s6>'], $downloadCalls->calls[2]['messageIds']);
    }

    #[Test]
    public function the_compressed_budget_stops_at_the_byte_ceiling(): void
    {
        // A 500-byte ceiling minus the 300-byte head leaves room for exactly
        // two whole 100-byte segments; after they arrive no further segment fits.
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 200))],
            extractSizes: [300, 500],
            configOverrides: ['previewMaxFetchBytes' => 500],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p1s4>', '<p1s5>'], $downloadCalls->calls[1]['messageIds']);
    }

    #[Test]
    public function the_compressed_budget_stops_at_the_part_ceiling(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [300, 600],
            configOverrides: ['previewMaxRarParts' => 1],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
            'With the part ceiling at 1 the anchor part is exhausted and part02 is never fetched.',
        );
    }

    #[Test]
    public function the_compressed_budget_stops_when_no_further_part_exists(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [300, 600],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
    }

    #[Test]
    public function the_compressed_budget_stops_when_extraction_stops_progressing(): void
    {
        // A compressed-mode archive: appended bytes extract nothing further.
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [300, 300],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
    }

    #[Test]
    public function a_disabled_root_toggle_keeps_the_single_fixed_compressed_fetch(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [],
            extractSizes: [],
            budgetEnabled: false,
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
    }

    #[Test]
    public function a_gap_in_the_anchor_part_skips_the_compressed_top_up_with_a_distinct_reason(): void
    {
        // Segments 5-7 of part01 are missing: the head is contiguous through 4
        // segments and the needed range (3 fetched + 3 expansion) reaches 6.
        [$downloadCalls, $result] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                [
                    'title' => 'release.part01.rar',
                    'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s8>', '<p1s9>'],
                    'segmentNumbers' => [1, 2, 3, 4, 8, 9],
                    'partstotal' => '9',
                ],
            ],
            initialData: str_repeat('a', 300),
            responses: [],
            extractSizes: [300],
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
    }

    #[Test]
    public function a_gap_in_the_next_part_stops_the_compressed_top_up_before_fetching_it(): void
    {
        // part02 is missing its third segment: bytes past the hole can never
        // be contiguous, so the top-up keeps the anchor's yield and stops.
        [$downloadCalls, $result] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                [
                    'title' => 'release.part02.rar',
                    'segments' => ['<p2s1>', '<p2s2>', '<p2s4>', '<p2s5>'],
                    'segmentNumbers' => [1, 2, 4, 5],
                    'partstotal' => '5',
                ],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [300, 600],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p1s4>', '<p1s5>', '<p1s6>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
    }

    #[Test]
    public function a_gap_past_the_needed_range_still_uses_the_next_parts_contiguous_head(): void
    {
        // movie.mkv is 900 bytes at 30s = 30 B/s -> 900 bytes needed. part02
        // has a numbering gap only after its 4-segment contiguous head, and
        // the needed range fits inside that head - so the head is fetched,
        // while part03 is never touched: nothing past part02's gap can be
        // contiguous with it.
        [$downloadCalls, $result] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                [
                    'title' => 'release.part02.rar',
                    'segments' => ['<p2s1>', '<p2s2>', '<p2s3>', '<p2s4>', '<p2s6>', '<p2s8>'],
                    'segmentNumbers' => [1, 2, 3, 4, 6, 8],
                    'partstotal' => '8',
                ],
                ['title' => 'release.part03.rar', 'segments' => ['<p3s1>', '<p3s2>', '<p3s3>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [
                $this->successfulDownload(str_repeat('b', 300)),
                $this->successfulDownload(str_repeat('c', 300)),
                $this->successfulDownload(str_repeat('d', 100)),
            ],
            extractSizes: [300, 600, 700, 800],
            videoEntrySize: 900,
        );

        $this->assertSame(
            [
                DownloadKind::Compressed,
                DownloadKind::CompressedTopUp,
                DownloadKind::CompressedTopUp,
                DownloadKind::CompressedTopUp,
            ],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p2s1>', '<p2s2>', '<p2s3>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertSame(['<p2s4>'], $downloadCalls->calls[3]['messageIds']);
        $this->assertNotContains('segment-gaps', $result->unsupportedReasons);
    }

    #[Test]
    public function an_unknown_bitrate_keeps_the_fixed_compressed_fetch(): void
    {
        Log::spy();

        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [],
            extractSizes: [300],
            probeResult: null,
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
        Log::shouldHaveReceived('debug')->once()->withArgs(
            static fn (string $message, array $details): bool => $message === 'Compressed preview top-up probe-failed'
                && $details['reason'] === 'bitrate unavailable',
        );
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
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

    #[Test]
    public function it_skips_all_imagery_work_when_the_free_disk_guard_refuses(): void
    {
        $config = $this->makeConfig([
            'processThumbnails' => true,
            'processJPGSample' => true,
        ]);

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [
                ['title' => 'movie.sample.mkv" yEnc', 'segments' => ['sample-message-id']],
                ['title' => 'movie.jpg" yEnc', 'segments' => ['jpg-message-id']],
            ],
        ]);

        // No download() expectation: any sample or JPG article fetch would fail
        // the test as an unexpected mock call.
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
            previewPolicy: $this->stubPreviewPolicy(),
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->refusingDiskGuard(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        $this->assertNotNull($finalizedContext);
        $this->assertSame(
            [ImagerySkipArtifact::Sample, ImagerySkipArtifact::Preview],
            $finalizedContext->imagerySkippedByDiskGuard,
        );
        $this->assertTrue($finalizedContext->foundJPGSample, 'Pre-marked so no JPG article is fetched.');
        $this->assertTrue($finalizedContext->foundSample, 'Pre-marked so no ffmpeg frame is produced.');
        $this->assertFalse(
            $finalizedContext->previewGenerationSkippedByPolicy,
            'A disk skip must not be confused with the per-root policy skip and its sentinel.',
        );
        $this->assertFalse($result->artifactsCreated);
    }

    #[Test]
    public function a_disk_squeeze_records_only_the_imagery_the_release_would_have_been_given(): void
    {
        $config = $this->makeConfig([
            'processThumbnails' => true,
            'processJPGSample' => false,
        ]);

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [['title' => 'movie.sample.mkv" yEnc', 'segments' => ['sample-message-id']]],
        ]);

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
            $this->scopedDownloadService(),
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->refusingDiskGuard(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $processor->process($context, $this->mainTempPath);

        $this->assertNotNull($finalizedContext);
        $this->assertSame([ImagerySkipArtifact::Preview], $finalizedContext->imagerySkippedByDiskGuard);
    }

    #[Test]
    public function a_disk_squeeze_records_nothing_for_a_release_that_was_never_owed_imagery(): void
    {
        $config = $this->makeConfig();

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [['title' => 'movie.mkv" yEnc', 'segments' => ['message-id']]],
        ]);

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
            $this->scopedDownloadService(),
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            $output,
            previewPolicy: $this->stubPreviewPolicy(),
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->refusingDiskGuard(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $processor->process($context, $this->mainTempPath);

        $this->assertNotNull($finalizedContext);
        $this->assertSame([], $finalizedContext->imagerySkippedByDiskGuard);
    }

    private function refusingDiskGuard(): FreeDiskGuard
    {
        return new FreeDiskGuard(
            static fn (string $path): float => 50.0,
            static fn (string $path): float => 1000.0,
        );
    }

    /**
     * The Free-disk guard measures the real covers volume; pin it open so a
     * nearly-full disk under the test run cannot suppress imagery mid-assertion.
     */
    private function permissiveDiskGuard(): FreeDiskGuard
    {
        return new FreeDiskGuard(
            static fn (string $path): float => 900.0,
            static fn (string $path): float => 1000.0,
        );
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
        );
    }

    /**
     * @param  array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}|null  $tailResponse
     * @return array{ReleaseProcessingContext, RecordingMp4DownloadCalls, RecordingMediaExtractionCalls}
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
        $downloadCalls = new RecordingMp4DownloadCalls($responses);
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('beginReleaseScope')->once()->andReturnNull();
        $downloadService->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        $downloadService->shouldReceive('download')
            ->times(count($responses))
            ->andReturnUsing($downloadCalls->download(...));
        $downloadService->shouldReceive('meetsMinimumSize')
            ->zeroOrMoreTimes()
            ->andReturnUsing($downloadCalls->meetsMinimumSize(...));
        $mediaCalls = new RecordingMediaExtractionCalls;
        $mediaService = Mockery::mock(MediaExtractionService::class);
        $mediaService->shouldReceive('getMediaInfo')
            ->once()
            ->andReturnUsing($mediaCalls->recordMediaInfo(...));
        $mediaService->shouldReceive('getSample')
            ->once()
            ->andReturnUsing($mediaCalls->recordSample(...));
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy(),
            freeDiskGuard: $this->permissiveDiskGuard(),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $processor->process($context, $this->mainTempPath);

        return [$context, $downloadCalls, $mediaCalls];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenSegmentMkvFile(int $sizeBytes): array
    {
        return [
            'title' => 'feature.mkv" yEnc',
            'segments' => ['<s1>', '<s2>', '<s3>', '<s4>', '<s5>', '<s6>', '<s7>', '<s8>', '<s9>', '<s10>'],
            'segmentNumbers' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'size' => $sizeBytes,
            'partstotal' => '10',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenSegmentMp4File(int $sizeBytes): array
    {
        return ['title' => 'feature.mp4" yEnc'] + $this->tenSegmentMkvFile($sizeBytes);
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  list<array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}>  $responses  Download responses after the head download.
     * @param  array<string, mixed>  $configOverrides
     * @return array{ReleaseProcessingContext, RecordingMp4DownloadCalls, RecordingMediaExtractionCalls, ReleaseProcessingResult}
     */
    private function processDynamicBudgetCandidate(
        array $file,
        string $head,
        array $responses = [],
        array $configOverrides = [],
        ?VideoHeadProbeResult $probeResult = null,
        bool $budgetEnabled = true,
    ): array {
        File::ensureDirectoryExists($this->releaseTempPath);
        $config = $this->makeConfig(array_merge([
            'processMediaInfo' => true,
            'processThumbnails' => true,
            'mp4TailFetch' => true,
            'segmentsToDownload' => 2,
            'mp4TailMaxSegments' => 4,
        ], $configOverrides));
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => [$file],
        ]);

        $downloadCalls = new RecordingMp4DownloadCalls([$this->successfulDownload($head), ...$responses]);
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('beginReleaseScope')->once()->andReturnNull();
        $downloadService->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        $downloadService->shouldReceive('download')
            ->times(1 + count($responses))
            ->andReturnUsing($downloadCalls->download(...));
        $downloadService->shouldReceive('meetsMinimumSize')
            ->zeroOrMoreTimes()
            ->andReturnUsing($downloadCalls->meetsMinimumSize(...));
        $mediaCalls = new RecordingMediaExtractionCalls;
        $mediaService = Mockery::mock(MediaExtractionService::class);
        $mediaService->shouldReceive('getMediaInfo')
            ->once()
            ->andReturnUsing($mediaCalls->recordMediaInfo(...));
        $mediaService->shouldReceive('getSample')
            ->once()
            ->andReturnUsing($mediaCalls->recordSample(...));
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
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy($budgetEnabled),
            freeDiskGuard: $this->permissiveDiskGuard(),
            headProbe: new StubVideoHeadProbe($probeResult),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        return [$context, $downloadCalls, $mediaCalls, $result];
    }

    /**
     * Drive one release whose NZB holds RAR parts through the compressed path,
     * scripting what each partial extraction yields. The archive lists a
     * single movie.mkv of $videoEntrySize bytes; with the default 1200 bytes,
     * 30s probe duration and 30s target, the top-up aims for 1200 fragment
     * bytes.
     *
     * @param  list<array<string, mixed>>  $nzbFiles
     * @param  list<array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}>  $responses  Download responses after the initial compressed fetch.
     * @param  list<int>  $extractSizes  Fragment bytes on disk after each extraction, the initial extraction first.
     * @param  list<float|null>|null  $decodableDurations  Playable seconds after each extraction; defaults to the same ratio as the declared duration.
     * @param  array<string, mixed>  $configOverrides
     * @param  array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}|null  $initialResponse
     * @return array{RecordingMp4DownloadCalls, ReleaseProcessingResult, string}
     */
    private function processCompressedTopUpCandidate(
        array $nzbFiles,
        string $initialData,
        array $responses,
        array $extractSizes,
        array $configOverrides = [],
        ?VideoHeadProbeResult $probeResult = new VideoHeadProbeResult(durationSeconds: 30.0),
        bool $budgetEnabled = true,
        int $videoEntrySize = 1200,
        ?array $decodableDurations = null,
        ?array $initialResponse = null,
    ): array {
        File::ensureDirectoryExists($this->releaseTempPath);
        $config = $this->makeConfig(array_merge([
            'processVideo' => true,
            'maximumRarSegments' => 3,
        ], $configOverrides));

        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn([
            'error' => null,
            'contents' => $nzbFiles,
        ]);

        $downloadCalls = new RecordingMp4DownloadCalls([
            $initialResponse ?? $this->successfulDownload($initialData),
            ...$responses,
        ]);
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('beginReleaseScope')->once()->andReturnNull();
        $downloadService->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        $downloadService->shouldReceive('download')
            ->times(1 + count($responses))
            ->andReturnUsing($downloadCalls->download(...));

        $fragmentPath = $this->releaseTempPath.'unrar/movie.mkv';
        $extractIndex = 0;
        $declaredDuration = $probeResult?->durationSeconds ?? 30.0;
        $bytesPerSecond = $videoEntrySize > 0 && $declaredDuration > 0
            ? $videoEntrySize / $declaredDuration
            : 1.0;
        $decodableDurations ??= array_map(
            static fn (int $size): float => $size / $bytesPerSecond,
            $extractSizes,
        );
        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        $archiveService->shouldReceive('processCompressedData')->once()->andReturn([
            'success' => true,
            'files' => [['name' => 'movie.mkv', 'size' => $videoEntrySize]],
            'hasPassword' => false,
            'passwordStatus' => ReleaseBrowseService::PASSWD_NONE,
            'archiveMarker' => 'r',
            'dataSummary' => [],
        ]);
        $archiveService->shouldReceive('listArchiveContents')
            ->zeroOrMoreTimes()
            ->andReturn(['files' => [['name' => 'movie.mkv', 'size' => $videoEntrySize]], 'hasPassword' => false]);
        $archiveService->shouldReceive('extractSpecificFileToPath')
            ->times(count($extractSizes))
            ->andReturnUsing(function () use (&$extractIndex, $extractSizes, $fragmentPath): string {
                File::ensureDirectoryExists(dirname($fragmentPath));
                file_put_contents($fragmentPath, str_repeat('x', $extractSizes[$extractIndex]));
                $extractIndex++;

                return $fragmentPath;
            });

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('addFileInfo')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static function (array $file, ReleaseProcessingContext $context): bool {
                $context->totalFileInfo++;

                return true;
            });
        $releaseManager->shouldReceive('finalizeRelease')->once()->andReturnNull();

        $tempWorkspace = Mockery::mock(TempWorkspaceService::class);
        $tempWorkspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($this->releaseTempPath);
        $tempWorkspace->shouldReceive('clearDirectory')->once()->with($this->releaseTempPath, false)->andReturnNull();
        $tempWorkspace->shouldReceive('listFiles')->zeroOrMoreTimes()->andReturn([]);

        $processor = new ReleaseProcessor(
            $config,
            $nzbParser,
            new AdditionalWorkPlanner($config),
            $archiveService,
            Mockery::mock(MediaExtractionService::class),
            $downloadService,
            $releaseManager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            $tempWorkspace,
            new ConsoleOutputService,
            previewPolicy: $this->stubPreviewPolicy(),
            dynamicBudgetPolicy: $this->stubDynamicBudgetPolicy($budgetEnabled),
            freeDiskGuard: $this->permissiveDiskGuard(),
            headProbe: new StubVideoHeadProbe($probeResult),
            decodableLengthProbe: new StubVideoDecodableLengthProbe($decodableDurations),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        return [$downloadCalls, $result, $fragmentPath];
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

    /**
     * DB-free policy double: the real service resolves the leaf category's
     * root toggle from the database, which unit tests do not have.
     */
    private function stubDynamicBudgetPolicy(bool $enabled = false): DynamicPreviewBudgetPolicy
    {
        return new class($enabled) extends DynamicPreviewBudgetPolicy
        {
            public function __construct(private readonly bool $enabled) {}

            public function enabledForCategory(int $categoriesId): bool
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

final class StubVideoHeadProbe extends VideoHeadProbe
{
    public function __construct(private readonly ?VideoHeadProbeResult $result)
    {
        parent::__construct(static fn (): string => '');
    }

    public function probe(string $videoPath, ProcessingConfiguration $config): ?VideoHeadProbeResult
    {
        return $this->result;
    }
}

final class StubVideoDecodableLengthProbe extends VideoDecodableLengthProbe
{
    /** @param list<float|null> $durations */
    public function __construct(private array $durations) {}

    public function demuxedSeconds(string $path, ProcessingConfiguration $config): ?float
    {
        return array_shift($this->durations);
    }
}

final class RecordingMp4DownloadCalls
{
    /**
     * @var list<array{kind: DownloadKind, messageIds: list<string>}>
     */
    public array $calls = [];

    /**
     * @param  list<array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}>  $responses
     */
    public function __construct(private array $responses) {}

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

final class RecordingMediaExtractionCalls
{
    /** @var list<array{path: string, data: string}> */
    public array $mediaInfoFiles = [];

    /** @var list<array{path: string, data: string}> */
    public array $sampleFiles = [];

    public function recordMediaInfo(string $fileLocation, int $releaseId): bool
    {
        $this->mediaInfoFiles[] = ['path' => $fileLocation, 'data' => (string) file_get_contents($fileLocation)];

        return true;
    }

    public function recordSample(string $fileLocation, string $tmpPath, string $guid): bool
    {
        $this->sampleFiles[] = ['path' => $fileLocation, 'data' => (string) file_get_contents($fileLocation)];

        return true;
    }
}
