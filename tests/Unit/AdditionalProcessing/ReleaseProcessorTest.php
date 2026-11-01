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
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\DynamicPreviewBudgetPolicy;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;
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
        $downloadService->shouldReceive('download')->once()->with(
            DownloadKind::MediaInfo,
            ['<video-second>'],
            '',
            1,
        )->andReturn(['success' => true, 'data' => str_repeat('v', 60), 'groupUnavailable' => false, 'error' => null]);
        $downloadService->shouldReceive('meetsMinimumSize')->once()->andReturnTrue();
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
        $mediaService->shouldReceive('getMediaInfo')->once()->with(
            Mockery::on(static fn (string $path): bool => File::get($path) === "\x1A\x45\xDF\xA3video".str_repeat('v', 60)),
            1,
            Mockery::on(static fn (MediaInfoProbeContext $probe): bool => $probe->sourceCompleteness === MediaInfoSourceCompleteness::Partial),
        )->andReturnTrue();

        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldReceive('echoReleaseStart')->once()->andReturnNull();
        $output->shouldReceive('setProcessTitle')->once()->andReturnNull();
        $output->shouldReceive('echoNfoFound')->once()->andReturnNull();
        $output->shouldReceive('echoMediaInfoDownload')->once()->andReturnNull();
        $output->shouldReceive('echoMediaInfoAdded')->once()->andReturnNull();

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

    /**
     * @return array<string, array{string}>
     */
    public static function sniffedVideoFirstArticles(): array
    {
        return [
            'fast-start MP4' => [pack('N', 16).'ftypisom0000'.pack('N', 24).'moov'.str_repeat('m', 16).str_repeat('v', 40)],
            'Matroska' => ["\x1A\x45\xDF\xA3".str_repeat('k', 60)],
        ];
    }

    #[Test]
    #[DataProvider('sniffedVideoFirstArticles')]
    public function a_sniffed_video_continues_into_the_named_flow_without_refetching_its_first_article(string $firstArticle): void
    {
        $rest = str_repeat('r', 100);

        [$downloadCalls, $mediaCalls] = $this->processSniffedVideoCandidates(
            [$this->sniffedFile('obfuscated.bin', ['<a1>', '<a2>', '<a3>', '<a4>'])],
            [$this->successfulDownload($firstArticle), $this->successfulDownload($rest)],
        );

        $this->assertSame(
            [DownloadKind::PayloadSniff, DownloadKind::MediaInfo],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<a2>', '<a3>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame($firstArticle.$rest, $mediaCalls->mediaInfoFiles[0]['data']);
        $this->assertSame($firstArticle.$rest, $mediaCalls->sampleFiles[0]['data']);
        $this->assertSame($firstArticle.$rest, $mediaCalls->videoFiles[0]['data']);
        $this->assertStringEndsWith('media.avi', $mediaCalls->mediaInfoFiles[0]['path']);
        $this->assertSame([], $mediaCalls->processVideoFiles);
    }

    #[Test]
    public function an_mdat_first_sniffed_mp4_requests_the_tail(): void
    {
        $firstArticle = $this->mp4Atom('ftyp', 'isom0000').pack('N', 4096).'mdat'.str_repeat('v', 48);
        $rest = str_repeat('r', 72);

        [$downloadCalls, $mediaCalls] = $this->processSniffedVideoCandidates(
            [$this->sniffedFile('obfuscated.bin', ['<a1>', '<a2>', '<a3>', '<a4>', '<a5>', '<a6>'], partsTotal: 6)],
            [
                $this->successfulDownload($firstArticle),
                $this->successfulDownload($rest),
                $this->successfulDownload('tail-prefix'.$this->validMoovAtom()),
            ],
        );

        $this->assertSame(
            [DownloadKind::PayloadSniff, DownloadKind::MediaInfo, DownloadKind::MediaInfoTail],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertStringEndsWith('media.mp4', $mediaCalls->mediaInfoFiles[0]['path']);
        $this->assertStringEndsWith($this->validMoovAtom(), $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_sniffed_fast_start_mp4_gets_the_budget_with_the_first_article_counted_as_held(): void
    {
        // 3 held segments of 100 bytes; the moov ends at 450, two segments away.
        // Leaving the first article out of the count makes that one segment.
        $firstArticle = str_pad($this->fastStartHead(moovEnd: 450), 100, 'h');
        $rest = str_repeat('r', 200);
        $moovBytes = str_repeat('M', 200);

        [$downloadCalls, $mediaCalls] = $this->processSniffedVideoCandidates(
            [$this->sniffedFile('obfuscated.bin', ['<a1>', '<a2>', '<a3>', '<a4>', '<a5>', '<a6>'], sizeBytes: 600)],
            [
                $this->successfulDownload($firstArticle),
                $this->successfulDownload($rest),
                $this->successfulDownload($moovBytes),
            ],
            budgetEnabled: true,
        );

        $this->assertSame(
            [DownloadKind::PayloadSniff, DownloadKind::MediaInfo, DownloadKind::MediaInfoTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<a4>', '<a5>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertSame($firstArticle.$rest.$moovBytes, $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_one_segment_sniffed_video_makes_no_empty_request(): void
    {
        $firstArticle = "\x1A\x45\xDF\xA3".str_repeat('k', 60);

        [$downloadCalls, $mediaCalls] = $this->processSniffedVideoCandidates(
            [$this->sniffedFile('obfuscated.bin', ['<a1>'])],
            [$this->successfulDownload($firstArticle)],
        );

        $this->assertSame([DownloadKind::PayloadSniff], array_column($downloadCalls->calls, 'kind'));
        $this->assertSame([$firstArticle], array_column($mediaCalls->processVideoFiles, 'data'));
        $this->assertSame([], $mediaCalls->mediaInfoFiles);
    }

    #[Test]
    public function a_failed_sniffed_remainder_falls_back_to_the_held_article(): void
    {
        $firstArticle = "\x1A\x45\xDF\xA3".str_repeat('k', 60);

        [$downloadCalls, $mediaCalls] = $this->processSniffedVideoCandidates(
            [$this->sniffedFile('obfuscated.bin', ['<a1>', '<a2>', '<a3>', '<a4>'])],
            [$this->successfulDownload($firstArticle), $this->failedDownload()],
            budgetEnabled: true,
        );

        $this->assertSame(
            [DownloadKind::PayloadSniff, DownloadKind::MediaInfo],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame([$firstArticle], array_column($mediaCalls->processVideoFiles, 'data'));
        $this->assertSame([], $mediaCalls->mediaInfoFiles);
    }

    #[Test]
    public function an_unavailable_group_during_the_sniffed_remainder_stops_the_candidates(): void
    {
        [$downloadCalls, $mediaCalls, $context] = $this->processSniffedVideoCandidates(
            [
                $this->sniffedFile('obfuscated.bin', ['<a1>', '<a2>', '<a3>', '<a4>'], sizeBytes: 400),
                $this->sniffedFile('later.bin', ['<b1>', '<b2>'], sizeBytes: 100),
            ],
            [
                $this->successfulDownload("\x1A\x45\xDF\xA3".str_repeat('k', 60)),
                ['success' => false, 'data' => null, 'groupUnavailable' => true, 'error' => 'group unavailable'],
            ],
        );

        $this->assertSame(
            [DownloadKind::PayloadSniff, DownloadKind::MediaInfo],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertTrue($context->groupUnavailable);
        $this->assertSame([], $mediaCalls->processVideoFiles);
        $this->assertSame([], $mediaCalls->mediaInfoFiles);
    }

    #[Test]
    public function a_later_sniffed_video_keeps_the_held_article_handler(): void
    {
        $first = "\x1A\x45\xDF\xA3".str_repeat('k', 60);
        $second = "\x1A\x45\xDF\xA3".str_repeat('l', 60);
        $rest = str_repeat('r', 100);

        [$downloadCalls, $mediaCalls] = $this->processSniffedVideoCandidates(
            [
                $this->sniffedFile('obfuscated.bin', ['<a1>', '<a2>', '<a3>', '<a4>'], sizeBytes: 400),
                $this->sniffedFile('later.bin', ['<b1>', '<b2>'], sizeBytes: 100),
            ],
            [
                $this->successfulDownload($first),
                $this->successfulDownload($rest),
                $this->successfulDownload($second),
            ],
        );

        $this->assertSame(
            [DownloadKind::PayloadSniff, DownloadKind::MediaInfo, DownloadKind::PayloadSniff],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame([$first.$rest], array_column($mediaCalls->mediaInfoFiles, 'data'));
        $this->assertSame([$second], array_column($mediaCalls->processVideoFiles, 'data'));
    }

    #[Test]
    public function a_sniffed_title_with_no_extension_gets_the_window_and_previews_without_mediainfo(): void
    {
        $firstArticle = "\x1A\x45\xDF\xA3".str_repeat('k', 60);
        $rest = str_repeat('r', 100);

        [$downloadCalls, $mediaCalls] = $this->processSniffedVideoCandidates(
            [$this->sniffedFile('a1b2c3d4e5f60718', ['<a1>', '<a2>', '<a3>', '<a4>'])],
            [$this->successfulDownload($firstArticle), $this->successfulDownload($rest)],
            ['processMediaInfo' => false],
        );

        $this->assertSame(['<a2>', '<a3>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame([], $mediaCalls->mediaInfoFiles);
        $this->assertSame($firstArticle.$rest, $mediaCalls->sampleFiles[0]['data']);
        $this->assertSame($firstArticle.$rest, $mediaCalls->videoFiles[0]['data']);
    }

    #[Test]
    public function a_sniffed_video_with_nothing_left_to_produce_fetches_no_head_window(): void
    {
        $firstArticle = "\x1A\x45\xDF\xA3".str_repeat('k', 60);

        [$downloadCalls] = $this->processSniffedVideoCandidates(
            [$this->sniffedFile('obfuscated.bin', ['<a1>', '<a2>', '<a3>', '<a4>'])],
            [$this->successfulDownload($firstArticle)],
            ['processMediaInfo' => false, 'processThumbnails' => false, 'processVideo' => false],
        );

        $this->assertSame([DownloadKind::PayloadSniff], array_column($downloadCalls->calls, 'kind'));
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

    #[DataProvider('nestedArchivePaths')]
    public function test_nested_archive_pattern_only_treats_terminal_numbers_as_split_volumes(string $path, bool $expected): void
    {
        $pattern = (new \ReflectionClass(ReleaseProcessor::class))->getConstant('NESTED_ARCHIVE_PATTERN');

        $this->assertSame($expected, preg_match($pattern, $path) === 1);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function nestedArchivePaths(): array
    {
        return [
            'dated video' => ['unrar/ClubSweethearts 2023 10/ClubSweethearts.2023.10.01.Foo.1080p.mp4', false],
            'numbered directory' => ['unrar/Disc.01/movie.mkv', false],
            'numbered part' => ['unrar/Title.Part.1.Bar.mp4', false],
            'plain video' => ['foo.mp4', false],
            'season directory' => ['Season.1/episode.mkv', false],
            'chapter video' => ['Chapter.1.mp4', false],
            'three digit token' => ['Title.001-extra.mp4', false],
            'split 7z' => ['inner.7z.001', true],
            'split three digits' => ['inner.001', true],
            'split two digits' => ['inner.01', true],
            'split one digit' => ['inner.1', true],
            'nested split volume' => ['unrar/Disc.01/inner.001', true],
            'rar' => ['inner.rar', true],
            'rar volume' => ['inner.r00', true],
            'zip' => ['inner.zip', true],
            'zip volume' => ['inner.z01', true],
            'zipx' => ['inner.zipx', true],
            'uppercase archive' => ['inner.RAR', true],
            'tolerated rar suffix' => ['inner.rar-extra', true],
            'tolerated volume suffix' => ['inner.r00-extra', true],
            'tolerated zip suffix' => ['inner.zip-extra', true],
        ];
    }

    #[Test]
    public function it_preserves_videos_with_numeric_path_tokens_during_the_nested_archive_sweep(): void
    {
        $tmpPath = $this->makeTempDirectory('nntmux-nested-archives').'/';
        $videos = [
            'unrar/ClubSweethearts 2023 10/ClubSweethearts.2023.10.01.Foo.1080p.mp4',
            'unrar/Disc.01/movie.mkv',
            'unrar/Title.Part.1.Bar.mp4',
        ];
        $archives = ['inner.7z.001', 'inner.001', 'inner.01', 'inner.1', 'inner.rar', 'inner.r00', 'inner.zip', 'inner.z01', 'inner.zipx'];
        foreach (array_merge($videos, $archives) as $name) {
            File::ensureDirectoryExists(dirname($tmpPath.$name));
            File::put($tmpPath.$name, $name);
        }

        $context = $this->makeContext();
        $context->tmpPath = $tmpPath;
        $archiveService = Mockery::mock(ArchiveExtractionService::class);
        foreach ($archives as $name) {
            $archiveService->shouldReceive('processCompressedData')->once()
                ->with($name, $context, $tmpPath)
                ->andReturn(['success' => false, 'hasPassword' => false]);
        }

        $mediaService = Mockery::mock(MediaExtractionService::class);
        foreach ($videos as $name) {
            $mediaService->shouldReceive('processVideoFile')->once()
                ->with($tmpPath.$name, $context, $tmpPath, Mockery::type(MediaInfoProbeContext::class))
                ->andReturn([]);
        }

        $config = $this->makeConfig(['maxNestedLevels' => 2]);
        $processor = new ReleaseProcessor(
            $config,
            Mockery::mock(NzbContentParser::class),
            new AdditionalWorkPlanner($config),
            $archiveService,
            $mediaService,
            Mockery::mock(UsenetDownloadService::class),
            Mockery::mock(ReleaseFileManager::class),
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            new TempWorkspaceService,
            Mockery::mock(ConsoleOutputService::class),
        );

        (new \ReflectionMethod(ReleaseProcessor::class, 'processExtractedFiles'))->invoke($processor, $context);

        foreach ($videos as $name) {
            $this->assertFileExists($tmpPath.$name);
        }
        foreach ($archives as $name) {
            $this->assertFileDoesNotExist($tmpPath.$name);
        }
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
    public function a_head_ending_inside_the_moov_tops_up_to_the_moov_end_plus_the_target(): void
    {
        // The moov ends at 180: 80 missing bytes at 50 bytes/segment is 2
        // segments. At 200 bytes / 30 s the 30 s target is 200 playable bytes
        // from the moov end: 180 + 200 - 200 held = 180, so 4 more segments.
        $head = str_pad($this->fastStartHead(moovEnd: 180), 100, 'h');
        $moovBytes = str_repeat('M', 100);
        $playableBytes = str_repeat('P', 200);

        [, $downloadCalls, $mediaCalls, , $probes] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMp4File(sizeBytes: 200),
            $head,
            [$this->successfulDownload($moovBytes), $this->successfulDownload($playableBytes)],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
            probeReadableFromBytes: 180,
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTopUp, DownloadKind::MediaInfoTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<s3>', '<s4>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(['<s5>', '<s6>', '<s7>', '<s8>'], $downloadCalls->calls[2]['messageIds']);
        $requested = array_merge(...array_column($downloadCalls->calls, 'messageIds'));
        $this->assertSame(array_values(array_unique($requested)), $requested, 'No message id may be requested twice.');
        $this->assertSame([200], $probes->headProbeSizes, 'The probe must see the grown head.');
        $this->assertStringEndsWith('media.avi', $mediaCalls->mediaInfoFiles[0]['path']);
        $this->assertSame($head.$moovBytes.$playableBytes, $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_moov_inside_the_head_still_counts_playable_bytes_from_its_end(): void
    {
        // 10 B/s: 300 playable bytes from the moov end at 60, less the 100
        // held, is 260: 6 segments where the plain target would ask for 4.
        [, $downloadCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMp4File(sizeBytes: 300),
            str_pad($this->fastStartHead(moovEnd: 60), 100, 'h'),
            [$this->successfulDownload('TOPUP-DATA')],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame(['<s3>', '<s4>', '<s5>', '<s6>', '<s7>', '<s8>'], $downloadCalls->calls[1]['messageIds']);
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public static function bareMoovEndsThatFetchNothing(): array
    {
        return [
            'past the byte ceiling' => [400, ['previewMaxFetchBytes' => 150]],
            'past the file size' => [120, ['previewMaxFetchBytes' => 0]],
        ];
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    #[Test]
    #[DataProvider('bareMoovEndsThatFetchNothing')]
    public function a_bare_moov_end_past_a_limit_fetches_nothing(int $sizeBytes, array $configOverrides): void
    {
        $head = str_pad($this->fastStartHead(moovEnd: 180), 100, 'h');

        [, $downloadCalls, $mediaCalls, , $probes] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMp4File($sizeBytes),
            $head,
            configOverrides: $configOverrides,
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadCalls->calls, 'kind'));
        $this->assertSame([], $probes->headProbeSizes);
        $this->assertSame($head, $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_gap_inside_the_bare_moov_range_skips_with_the_existing_reason(): void
    {
        $file = $this->tenSegmentMp4File(sizeBytes: 400);
        // Contiguous through 3 segments; the 2 held plus the 2 the moov needs reach 4.
        $file['segmentNumbers'] = [1, 2, 3, 5, 6, 7, 8, 9, 10, 11];
        $file['partstotal'] = '11';

        [, $downloadCalls, , $result, $probes] = $this->processDynamicBudgetCandidate(
            $file,
            str_pad($this->fastStartHead(moovEnd: 180), 100, 'h'),
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame([DownloadKind::MediaInfo], array_column($downloadCalls->calls, 'kind'));
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
        $this->assertSame([], $probes->headProbeSizes);
    }

    #[Test]
    public function the_bare_gap_gate_counts_the_segments_the_moov_phase_holds(): void
    {
        $file = $this->tenSegmentMp4File(sizeBytes: 200);
        // Contiguous through 5: the moov phase holds 4, and the 4 the target wants reach 8.
        $file['segmentNumbers'] = [1, 2, 3, 4, 5, 7, 8, 9, 10, 11];
        $file['partstotal'] = '11';
        $head = str_pad($this->fastStartHead(moovEnd: 180), 100, 'h');
        $moovBytes = str_repeat('M', 100);

        [, $downloadCalls, $mediaCalls, $result] = $this->processDynamicBudgetCandidate(
            $file,
            $head,
            [$this->successfulDownload($moovBytes)],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame(
            [DownloadKind::MediaInfo, DownloadKind::MediaInfoTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
        $this->assertSame($head.$moovBytes, $mediaCalls->mediaInfoFiles[0]['data'], 'Bytes already fetched are kept.');
    }

    #[Test]
    public function the_byte_ceiling_stops_the_bare_moov_phase_before_any_probe(): void
    {
        // Under a 190-byte ceiling only one whole segment fits after the
        // 100-byte head, and 150 bytes do not reach the moov end at 180.
        $head = str_pad($this->fastStartHead(moovEnd: 180), 100, 'h');
        $moovBytes = str_repeat('M', 50);

        [, $downloadCalls, $mediaCalls, , $probes] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMp4File(sizeBytes: 400),
            $head,
            [$this->successfulDownload($moovBytes)],
            ['previewMaxFetchBytes' => 190],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame(['<s3>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertCount(2, $downloadCalls->calls);
        $this->assertSame([], $probes->headProbeSizes);
        $this->assertSame($head.$moovBytes, $mediaCalls->mediaInfoFiles[0]['data']);
    }

    #[Test]
    public function a_failed_duration_fetch_keeps_the_bytes_the_moov_phase_fetched(): void
    {
        $head = str_pad($this->fastStartHead(moovEnd: 180), 100, 'h');
        $moovBytes = str_repeat('M', 100);

        [, , $mediaCalls] = $this->processDynamicBudgetCandidate(
            $this->tenSegmentMp4File(sizeBytes: 200),
            $head,
            [$this->successfulDownload($moovBytes), $this->failedDownload()],
            probeResult: new VideoHeadProbeResult(durationSeconds: 30.0),
        );

        $this->assertSame($head.$moovBytes, $mediaCalls->mediaInfoFiles[0]['data']);
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
    public function a_compressed_moov_past_the_fragment_is_fetched_before_any_probe_then_the_duration_target(): void
    {
        // The moov ends at 550: 250 missing bytes at 100 bytes/segment is
        // exactly 3 segments. At 40 B/s the 20 s demuxed then need 400 more
        // bytes, which the same fetch takes from part02.
        [$downloadCalls, , $fragmentPath, $extraction] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>', '<p2s4>', '<p2s5>', '<p2s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [
                $this->successfulDownload(str_repeat('b', 300)),
                $this->successfulDownload(str_repeat('c', 400)),
            ],
            extractSizes: [300, 600, 1000],
            decodableDurations: [20.0, 30.0],
            fragmentHead: $this->fastStartHead(moovEnd: 550),
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p1s4>', '<p1s5>', '<p1s6>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(['<p2s1>', '<p2s2>', '<p2s3>', '<p2s4>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertSame([600, 1000], $extraction->decodableProbeSizes, 'Nothing may probe a fragment that ends inside the moov.');
        $this->assertSame([600], $extraction->headProbeSizes);
        $this->assertSame(1000, filesize($fragmentPath));
    }

    #[Test]
    public function a_compressed_moov_that_is_already_covered_adds_no_moov_fetch(): void
    {
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [],
            extractSizes: [300],
            decodableDurations: [30.0],
            fragmentHead: $this->fastStartHead(moovEnd: 250),
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string}>
     */
    public static function compressedMoovEndsThatFetchNothing(): array
    {
        return [
            'past the byte ceiling' => [5000, ['previewMaxFetchBytes' => 4000], 'moov past byte ceiling'],
            'past the byte ceiling and the file end names the ceiling' => [5000, ['previewMaxFetchBytes' => 1000], 'moov past byte ceiling'],
            'under the ceiling, past the entry size' => [1300, ['previewMaxFetchBytes' => 4000], 'moov past file end'],
            'no ceiling, past the entry size' => [1300, ['previewMaxFetchBytes' => 0], 'moov past file end'],
        ];
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    #[Test]
    #[DataProvider('compressedMoovEndsThatFetchNothing')]
    public function a_compressed_moov_end_past_a_limit_fetches_nothing(int $moovEnd, array $configOverrides, string $reason): void
    {
        Log::spy();

        [$downloadCalls, , , $extraction] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [],
            extractSizes: [300],
            configOverrides: $configOverrides,
            decodableDurations: [],
            fragmentHead: $this->fastStartHead($moovEnd),
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
        $this->assertSame([], $extraction->decodableProbeSizes);
        Log::shouldHaveReceived('debug')->once()->withArgs(
            static fn (string $message, array $details): bool => $message === 'Compressed preview top-up probe-failed'
                && $details['reason'] === $reason,
        );
    }

    #[Test]
    public function the_compressed_moov_and_duration_phases_are_one_fetch(): void
    {
        // A 800-byte ceiling: 300 head + 300 moov leaves two whole segments for
        // the duration phase, which would otherwise ask for four.
        [$downloadCalls, , , $extraction] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>', '<p2s4>', '<p2s5>', '<p2s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [
                $this->successfulDownload(str_repeat('b', 300)),
                $this->successfulDownload(str_repeat('c', 200)),
            ],
            extractSizes: [300, 600, 800],
            configOverrides: ['previewMaxFetchBytes' => 800],
            decodableDurations: [20.0, 26.0],
            fragmentHead: $this->fastStartHead(moovEnd: 550),
        );

        $requested = array_merge(...array_column($downloadCalls->calls, 'messageIds'));
        $this->assertSame(array_values(array_unique($requested)), $requested, 'No message id may be requested twice.');
        $this->assertSame(['<p1s4>', '<p1s5>', '<p1s6>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(['<p2s1>', '<p2s2>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertSame(
            [
                'preview-archive.part001.rar' => str_repeat('a', 300).str_repeat('b', 300),
                'preview-archive.part002.rar' => str_repeat('c', 200),
            ],
            $extraction->workingVolumes,
            'Each batch lands in its volume exactly once.',
        );
    }

    #[Test]
    public function the_anchor_gap_gate_counts_the_segments_the_moov_phase_already_holds(): void
    {
        // Contiguous through 7 segments. The moov phase holds 6 by then; the
        // duration phase wants 4 more and 2 are left, reaching 8: a gap.
        [$downloadCalls, $result] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                [
                    'title' => 'release.part01.rar',
                    'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>', '<p1s7>', '<p1s9>'],
                    'segmentNumbers' => [1, 2, 3, 4, 5, 6, 7, 9],
                    'partstotal' => '9',
                ],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [300, 600],
            decodableDurations: [20.0],
            fragmentHead: $this->fastStartHead(moovEnd: 550),
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
    }

    /**
     * Every case holds a moov the bound keeps from being covered.
     *
     * @return array<string, array{nzbFiles: array<int, array<string, mixed>>, moovEnd: int, configOverrides: array<string, mixed>, fetchSucceeds: bool, topUpFetches: int, extractSizes: list<int>, gapReason: bool}>
     */
    public static function compressedMoovPhaseBounds(): array
    {
        $part01 = ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']];
        $part02 = ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>']];

        return [
            'parts cap' => [
                'nzbFiles' => [$part01, $part02],
                'moovEnd' => 900,
                'configOverrides' => ['previewMaxRarParts' => 1],
                'fetchSucceeds' => true,
                'topUpFetches' => 1,
                'extractSizes' => [300, 600],
                'gapReason' => false,
            ],
            'gap in the anchor part' => [
                'nzbFiles' => [[
                    'title' => 'release.part01.rar',
                    'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s8>', '<p1s9>'],
                    'segmentNumbers' => [1, 2, 3, 4, 8, 9],
                    'partstotal' => '9',
                ]],
                'moovEnd' => 900,
                'configOverrides' => [],
                'fetchSucceeds' => true,
                'topUpFetches' => 0,
                'extractSizes' => [300],
                'gapReason' => true,
            ],
            'gap in the next part' => [
                'nzbFiles' => [$part01, [
                    'title' => 'release.part02.rar',
                    'segments' => ['<p2s1>', '<p2s2>', '<p2s4>', '<p2s5>'],
                    'segmentNumbers' => [1, 2, 4, 5],
                    'partstotal' => '5',
                ]],
                'moovEnd' => 1190,
                'configOverrides' => [],
                'fetchSucceeds' => true,
                'topUpFetches' => 1,
                'extractSizes' => [300, 600],
                'gapReason' => true,
            ],
            'byte ceiling' => [
                'nzbFiles' => [$part01, $part02],
                'moovEnd' => 490,
                'configOverrides' => ['previewMaxFetchBytes' => 500],
                'fetchSucceeds' => true,
                'topUpFetches' => 1,
                'extractSizes' => [200, 400],
                'gapReason' => false,
            ],
            'failed fetch' => [
                'nzbFiles' => [$part01, $part02],
                'moovEnd' => 900,
                'configOverrides' => [],
                'fetchSucceeds' => false,
                'topUpFetches' => 1,
                'extractSizes' => [300],
                'gapReason' => false,
            ],
            'no progress' => [
                'nzbFiles' => [$part01, $part02],
                'moovEnd' => 900,
                'configOverrides' => [],
                'fetchSucceeds' => true,
                'topUpFetches' => 1,
                'extractSizes' => [300, 300],
                'gapReason' => false,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $nzbFiles
     * @param  array<string, mixed>  $configOverrides
     * @param  list<int>  $extractSizes
     */
    #[Test]
    #[DataProvider('compressedMoovPhaseBounds')]
    public function a_bound_stops_the_compressed_moov_phase_and_leaves_the_fragment(
        array $nzbFiles,
        int $moovEnd,
        array $configOverrides,
        bool $fetchSucceeds,
        int $topUpFetches,
        array $extractSizes,
        bool $gapReason,
    ): void {
        $response = $fetchSucceeds ? $this->successfulDownload(str_repeat('b', 200)) : $this->failedDownload();

        [$downloadCalls, $result, $fragmentPath, $extraction] = $this->processCompressedTopUpCandidate(
            nzbFiles: $nzbFiles,
            initialData: str_repeat('a', 300),
            responses: array_fill(0, $topUpFetches, $response),
            extractSizes: $extractSizes,
            configOverrides: $configOverrides,
            decodableDurations: [],
            fragmentHead: $this->fastStartHead($moovEnd),
        );

        $this->assertCount(1 + $topUpFetches, $downloadCalls->calls);
        $this->assertSame([], $extraction->decodableProbeSizes, 'A moov that was never covered is not probed.');
        $this->assertSame([], $extraction->headProbeSizes, 'A moov that was never covered is not probed.');
        $this->assertFileExists($fragmentPath);
        $this->assertSame($extractSizes[array_key_last($extractSizes)], filesize($fragmentPath));
        if ($gapReason) {
            $this->assertContains('segment-gaps', $result->unsupportedReasons);
        } else {
            $this->assertNotContains('segment-gaps', $result->unsupportedReasons);
        }
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function firstVolumesAndTheirWorkingNames(): array
    {
        $rar4 = "Rar!\x1a\x07\x00\xcf\x90";
        $oldNames = ['preview-archive.r00', 'preview-archive.r01', 'preview-archive.rar'];
        $partNames = ['preview-archive.part001.rar', 'preview-archive.part002.rar', 'preview-archive.part003.rar'];

        return [
            'old-numbered RAR4' => [$rar4."\x73\x01\x01\x0d\x00", $oldNames],
            'new-numbered RAR4' => [$rar4."\x73\x11\x01\x0d\x00", $partNames],
            'RAR5' => ["Rar!\x1a\x07\x01\x00\x33\x92\xb5\xe5\x0a\x01\x05", $partNames],
            'RAR4 marker with no main header at byte 9' => [$rar4."\x74\x01\x01\x0d\x00", $partNames],
            'not a RAR' => ['just some bytes', $partNames],
        ];
    }

    /**
     * @param  list<string>  $expectedVolumes
     */
    #[Test]
    #[DataProvider('firstVolumesAndTheirWorkingNames')]
    public function working_volumes_follow_the_sets_numbering_and_do_not_survive(string $firstVolumeHead, array $expectedVolumes): void
    {
        $firstVolume = str_pad($firstVolumeHead, 300, 'a');

        [, , , $extraction] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.rar', 'segments' => ['<v1s1>', '<v1s2>', '<v1s3>']],
                ['title' => 'release.r00', 'segments' => ['<v2s1>', '<v2s2>', '<v2s3>']],
                ['title' => 'release.r01', 'segments' => ['<v3s1>', '<v3s2>', '<v3s3>']],
            ],
            initialData: $firstVolume,
            responses: [
                $this->successfulDownload(str_repeat('b', 300)),
                $this->successfulDownload(str_repeat('c', 100)),
            ],
            extractSizes: [300, 600, 700],
            decodableDurations: [30.0],
            fragmentHead: $this->fastStartHead(moovEnd: 650),
        );

        $firstVolumeName = in_array('preview-archive.rar', $expectedVolumes, true)
            ? 'preview-archive.rar'
            : 'preview-archive.part001.rar';
        $this->assertSame($expectedVolumes, array_keys($extraction->workingVolumes));
        $this->assertSame($firstVolume, $extraction->workingVolumes[$firstVolumeName]);
        foreach ($extraction->archivePaths as $archivePath) {
            $this->assertSame($firstVolumeName, basename($archivePath), 'unrar is always pointed at the first volume.');
        }
        $this->assertSame([], glob($this->releaseTempPath.'preview-archive.*') ?: []);
    }

    #[Test]
    public function a_stored_mdat_first_fragment_is_spliced_from_the_first_and_last_segments_of_the_final_part(): void
    {
        // part02 has 6 segments, 550 bytes: five of 100 then one of 50. The
        // video ends 20 bytes before that, where the end block starts.
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);
        $finalTail = str_repeat('t', 130 - strlen($moov)).$moov.str_repeat('e', 20);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 6)],
            responses: [$this->successfulDownload($finalFirst), $this->successfulDownload($finalTail)],
            listings: [$finalFirst => [$this->storedVideoEntry(530)]],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTail, DownloadKind::CompressedTail],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p2s1>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(['<p2s5>', '<p2s6>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
        $this->assertSame(1, $result->mp4TailMetrics->moovFoundCount);
        $this->assertSame(0, $result->mp4TailMetrics->moovMissingCount);
        $this->assertSame(2, $result->mp4TailMetrics->tailsFetched);
        $this->assertSame(250, $result->mp4TailMetrics->tailBytes);
    }

    #[Test]
    public function the_budget_toggle_off_still_splices_and_makes_no_top_up_request(): void
    {
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 3), $this->rarPart(3, 3)],
            responses: [$this->successfulDownload($finalFirst), $this->successfulDownload(str_repeat('t', 72).$moov)],
            listings: [$finalFirst => [$this->storedVideoEntry(200)]],
        );

        $this->assertNotContains(DownloadKind::CompressedTopUp, array_column($downloadCalls->calls, 'kind'));
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function the_budget_toggle_on_re_splices_after_its_first_extraction_and_after_each_top_up(): void
    {
        // 1200 bytes over 30 s is 40 B/s. At 7.5 s the target needs 900 bytes,
        // but the anchor only has 300 left; at 15 s it needs 600 from part02.
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls, , $fragmentPath, $extraction] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 6), $this->rarPart(3, 3)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload(str_repeat('t', 172).$moov),
                $this->successfulDownload(str_repeat('b', 300)),
                $this->successfulDownload(str_repeat('c', 600)),
            ],
            listings: [$finalFirst => [$this->storedVideoEntry(300, ['size' => 1200])]],
            extractSizes: [300, 300, 600, 1200],
            budgetEnabled: true,
            decodableDurations: [7.5, 15.0, 30.0],
            anchorEntryOverrides: ['size' => 1200],
        );

        $this->assertSame(
            [
                DownloadKind::Compressed,
                DownloadKind::CompressedTail,
                DownloadKind::CompressedTail,
                DownloadKind::CompressedTopUp,
                DownloadKind::CompressedTopUp,
            ],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p1s4>', '<p1s5>', '<p1s6>'], $downloadCalls->calls[3]['messageIds']);
        $this->assertSame(
            ['<p2s1>', '<p2s2>', '<p2s3>', '<p2s4>', '<p2s5>', '<p2s6>'],
            $downloadCalls->calls[4]['messageIds'],
            'Progress is measured on the raw extraction, whose size the moov never inflates.',
        );
        $this->assertSame(
            [
                $this->splicedFragment(300, $moov),
                $this->splicedFragment(600, $moov),
                $this->splicedFragment(1200, $moov),
            ],
            $extraction->decodableProbeFiles,
        );
        $this->assertSame([300 + strlen($moov)], $extraction->headProbeSizes);
        $this->assertSame($this->splicedFragment(1200, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function a_top_up_extraction_that_makes_no_progress_leaves_the_fragment_spliced(): void
    {
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 3)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload(str_repeat('t', 172).$moov),
                $this->successfulDownload(str_repeat('b', 300)),
            ],
            listings: [$finalFirst => [$this->storedVideoEntry(300, ['size' => 1200])]],
            extractSizes: [300, 300, 300],
            budgetEnabled: true,
            decodableDurations: [7.5],
            anchorEntryOverrides: ['size' => 1200],
        );

        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function tail_bytes_count_against_the_byte_ceiling(): void
    {
        // 300 head + 100 first segment + 200 tail leave 200 of the 800 ceiling.
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 9), $this->rarPart(2, 3)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload(str_repeat('t', 172).$moov),
                $this->successfulDownload(str_repeat('b', 200)),
            ],
            listings: [$finalFirst => [$this->storedVideoEntry(300, ['size' => 1200])]],
            extractSizes: [300, 300, 500],
            configOverrides: ['previewMaxFetchBytes' => 800],
            budgetEnabled: true,
            decodableDurations: [7.5, 12.5],
            anchorEntryOverrides: ['size' => 1200],
        );

        $this->assertSame(['<p1s4>', '<p1s5>'], $downloadCalls->calls[3]['messageIds']);
    }

    #[Test]
    public function the_top_up_stops_before_the_segments_fetched_for_the_tail(): void
    {
        // A single part: the tail took its last two segments, so the top-up
        // may only have the two between them and the head.
        $moov = $this->validMoovAtom();

        [$downloadCalls] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 7)],
            responses: [
                $this->successfulDownload(str_repeat('t', 172).$moov),
                $this->successfulDownload(str_repeat('b', 200)),
            ],
            listings: [],
            extractSizes: [300, 300, 500],
            budgetEnabled: true,
            decodableDurations: [7.5, 12.5],
            anchorEntryOverrides: ['size' => 1200, 'next_offset' => 700, 'range' => '10-299'],
        );

        $this->assertSame(['<p1s6>', '<p1s7>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(['<p1s4>', '<p1s5>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertCount(3, $downloadCalls->calls, 'No volume may be left with a hole where the tail segments were.');
    }

    #[Test]
    public function the_top_up_never_opens_a_part_the_tail_read(): void
    {
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 5), $this->rarPart(2, 3)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload(str_repeat('t', 172).$moov),
                $this->successfulDownload(str_repeat('b', 200)),
            ],
            listings: [$finalFirst => [$this->storedVideoEntry(300, ['size' => 1200])]],
            extractSizes: [300, 300, 500],
            budgetEnabled: true,
            decodableDurations: [7.5, 12.5],
            anchorEntryOverrides: ['size' => 1200],
        );

        $this->assertSame(['<p1s4>', '<p1s5>'], $downloadCalls->calls[3]['messageIds']);
        $this->assertCount(4, $downloadCalls->calls);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function tailFetchOutcomes(): array
    {
        return ['the tail is fetched' => [true], 'the tail fetch fails' => [false]];
    }

    #[Test]
    #[DataProvider('tailFetchOutcomes')]
    public function with_the_budget_toggle_off_no_working_volume_remains_when_the_sweep_runs(bool $tailFetched): void
    {
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [, , , $extraction] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 3)],
            responses: [
                $this->successfulDownload($finalFirst),
                $tailFetched ? $this->successfulDownload(str_repeat('t', 172).$moov) : $this->failedDownload(),
            ],
            listings: [$finalFirst => [$this->storedVideoEntry(300)]],
        );

        $this->assertSame(['preview-archive.part001.rar'], array_keys($extraction->workingVolumes));
        $this->assertSame([], $extraction->workingVolumesAtSweep);
    }

    #[Test]
    public function the_working_volume_follows_the_sets_numbering(): void
    {
        $anchorHead = substr(SyntheticPosting::rar([2000, 2000], 'movie.mp4', newNumbering: false)['volumes'][0], 0, 300);

        [, , , $extraction] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 3)],
            responses: [],
            listings: [],
            anchorHead: $anchorHead,
        );

        $this->assertSame(['preview-archive.rar'], array_keys($extraction->workingVolumes));
        $this->assertSame([], $extraction->workingVolumesAtSweep);
    }

    #[Test]
    public function no_entry_of_the_videos_size_in_the_last_two_parts_stops_after_their_first_segments(): void
    {
        // An entry with the video's name but another video's size.
        $finalFirst = str_repeat('F', 100);
        $previousFirst = str_repeat('P', 100);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 6), $this->rarPart(3, 6)],
            responses: [$this->successfulDownload($finalFirst), $this->successfulDownload($previousFirst)],
            listings: [
                $finalFirst => [$this->storedVideoEntry(300, ['size' => 4999])],
                $previousFirst => [$this->storedVideoEntry(300, ['name' => 'other.mp4', 'size' => 4998])],
            ],
        );

        $this->assertSame(['<p3s1>'], $downloadCalls->calls[1]['messageIds']);
        $this->assertSame(['<p2s1>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertCount(3, $downloadCalls->calls);
        $this->assertSame($this->rawFragment(300), file_get_contents($fragmentPath));
        $this->assertSame(1, $result->mp4TailMetrics->moovMissingCount);
        $this->assertSame(0, $result->mp4TailMetrics->moovFoundCount);
    }

    #[Test]
    public function a_final_part_listing_the_video_under_another_name_is_used(): void
    {
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 3)],
            responses: [$this->successfulDownload($finalFirst), $this->successfulDownload(str_repeat('t', 172).$moov)],
            listings: [$finalFirst => [$this->storedVideoEntry(300, ['name' => 'a8f3c1.bin'])]],
        );

        $this->assertSame(['<p2s2>', '<p2s3>'], $downloadCalls->calls[2]['messageIds']);
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function a_previous_part_alone_holding_the_video_is_spliced_with_no_tail_from_the_final_part(): void
    {
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);
        $previousFirst = str_repeat('P', 100);

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 4), $this->rarPart(3, 4)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload($previousFirst),
                $this->successfulDownload(str_repeat('t', 172).$moov),
            ],
            listings: [
                $finalFirst => [$this->storedVideoEntry(300, ['size' => 77])],
                $previousFirst => [$this->storedVideoEntry(400)],
            ],
        );

        $this->assertSame(
            [['<p3s1>'], ['<p2s1>'], ['<p2s3>', '<p2s4>']],
            array_column(array_slice($downloadCalls->calls, 1), 'messageIds'),
        );
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function the_moov_of_a_file_after_the_video_in_the_final_part_is_not_used(): void
    {
        // part02 is 400 bytes: the video ends at 300, and the file after it
        // has a moov of its own in the last two segments.
        $moov = $this->validMoovAtom();
        $otherMoov = $this->mp4Atom('moov', $this->mp4Atom('mvhd', 'other-header'));
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 4)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload(str_repeat('v', 100).str_repeat('o', 100 - strlen($otherMoov)).$otherMoov),
                $this->successfulDownload(str_repeat('v', 100 - strlen($moov)).$moov),
            ],
            listings: [$finalFirst => [$this->storedVideoEntry(300)]],
        );

        $this->assertSame(
            [['<p2s1>'], ['<p2s3>', '<p2s4>'], ['<p2s2>']],
            array_column(array_slice($downloadCalls->calls, 1), 'messageIds'),
            'With nothing of the video left in the first tail, the tail grows into the segment before it.',
        );
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string, int}>
     */
    public static function fragmentsThatFetchNoTail(): array
    {
        return [
            'compressed entry' => [['compressed' => 1], [], 'mdat-first', 0],
            'entry with a password' => [['pass' => 1], [], 'mdat-first', 0],
            'fast-start fragment' => [[], [], 'fast-start', 1],
            'non-MP4 fragment' => [[], [], 'matroska', 1],
            'tail fetch switched off' => [[], ['mp4TailFetch' => false], 'mdat-first', 0],
        ];
    }

    /**
     * @param  array<string, mixed>  $entryOverrides
     * @param  array<string, mixed>  $configOverrides
     */
    #[Test]
    #[DataProvider('fragmentsThatFetchNoTail')]
    public function an_ineligible_entry_or_fragment_makes_no_tail_request(
        array $entryOverrides,
        array $configOverrides,
        string $fragment,
        int $extractions,
    ): void {
        [$downloadCalls, $result] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 3)],
            responses: [],
            listings: [],
            extractSizes: array_fill(0, $extractions, 300),
            configOverrides: $configOverrides,
            fragmentHead: match ($fragment) {
                'fast-start' => $this->mp4Atom('ftyp', 'isom0000').$this->validMoovAtom().pack('N', 400).'mdat',
                'matroska' => "\x1A\x45\xDF\xA3matroska",
                default => $this->mdatFirstHead(),
            },
            anchorEntryOverrides: $entryOverrides,
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
        $this->assertSame(0, $result->mp4TailMetrics->tailsFetched);
        $this->assertSame(0, $result->mp4TailMetrics->moovMissingCount);
        $this->assertSame(0, $result->mp4TailMetrics->moovFoundCount);
    }

    #[Test]
    public function a_final_part_whose_tail_is_not_contiguous_makes_no_tail_request_and_records_the_gap(): void
    {
        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [
                $this->rarPart(1, 6),
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>'], 'segmentNumbers' => [1], 'partstotal' => '62'],
            ],
            responses: [],
            listings: [],
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
        $this->assertContains('segment-gaps', $result->unsupportedReasons);
        $this->assertSame(0, $result->mp4TailMetrics->moovMissingCount);
        $this->assertSame($this->rawFragment(300), file_get_contents($fragmentPath));
    }

    #[Test]
    public function a_par2_last_candidate_is_skipped_for_the_volume_before_it(): void
    {
        $moov = $this->validMoovAtom();
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [
                $this->rarPart(1, 6),
                $this->rarPart(2, 3),
                // The planner takes this for an archive part: .rar appears in its subject.
                ['title' => 'release.rar [3/3] "release.vol00+01.PAR2" yEnc', 'segments' => ['<par2s1>', '<par2s2>']],
            ],
            responses: [$this->successfulDownload($finalFirst), $this->successfulDownload(str_repeat('t', 172).$moov)],
            listings: [$finalFirst => [$this->storedVideoEntry(300)]],
        );

        $this->assertSame(
            [['<p2s1>'], ['<p2s2>', '<p2s3>']],
            array_column(array_slice($downloadCalls->calls, 1), 'messageIds'),
        );
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function a_single_part_set_reads_the_tail_from_the_anchor(): void
    {
        $moov = $this->validMoovAtom();

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 8)],
            responses: [$this->successfulDownload(str_repeat('t', 130 - strlen($moov)).$moov.str_repeat('e', 20))],
            listings: [],
            anchorEntryOverrides: ['next_offset' => 730, 'range' => '10-299'],
        );

        $this->assertSame(
            [['<p1s1>', '<p1s2>', '<p1s3>'], ['<p1s7>', '<p1s8>']],
            array_column($downloadCalls->calls, 'messageIds'),
        );
        $this->assertSame(DownloadKind::CompressedTail, $downloadCalls->calls[1]['kind']);
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function a_single_part_set_already_held_whole_requests_nothing_more(): void
    {
        // The moov at the end of the 300 bytes the anchor's fetch already holds.
        $moov = $this->validMoovAtom();
        $anchorHead = str_repeat('a', 300 - strlen($moov) - 20).$moov.str_repeat('e', 20);

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 3)],
            responses: [],
            listings: [],
            anchorHead: $anchorHead,
            anchorEntryOverrides: ['next_offset' => 280, 'range' => '10-279'],
        );

        $this->assertCount(1, $downloadCalls->calls);
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function previousPartTrailers(): array
    {
        return ['a 20-byte trailer' => [20], 'a trailer larger than the first tail' => [230]];
    }

    #[Test]
    #[DataProvider('previousPartTrailers')]
    public function a_moov_split_across_two_parts_splices_with_exact_bytes(int $trailerBytes): void
    {
        // The headers before the video in the final part and the trailer after
        // it in the previous part both sit in the middle of the moov's bytes:
        // the splice only finds a whole moov if the join drops both exactly.
        $moov = $this->mp4Atom('moov', $this->mp4Atom('mvhd', str_repeat('m', 300)));
        $previousPart = str_repeat('h', 40).str_repeat('v', 200).substr($moov, 0, 150).str_repeat('T', $trailerBytes);
        $finalPart = str_repeat('H', 70).substr($moov, 150).str_repeat('E', 20);
        $previousSegments = str_split($previousPart, 100);
        $finalSegments = str_split($finalPart, 100);
        $this->assertCount(3, $finalSegments);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, count($previousSegments)), $this->rarPart(3, 3)],
            responses: array_map($this->successfulDownload(...), [
                $finalSegments[0],
                $finalSegments[1].$finalSegments[2],
                $previousSegments[0],
                ...$this->batchesFromTheEnd(array_slice($previousSegments, 1), 2),
            ]),
            listings: [
                $finalSegments[0] => [$this->storedVideoEntry(strlen($finalPart) - 20, ['range' => '70-99'])],
                $previousSegments[0] => [$this->storedVideoEntry(strlen($previousPart) - $trailerBytes, ['range' => '40-99'])],
            ],
        );

        $this->assertSame(['<p2s1>'], $downloadCalls->calls[3]['messageIds']);
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
        $this->assertSame(1, $result->mp4TailMetrics->moovFoundCount);
    }

    #[Test]
    public function the_join_takes_the_final_parts_bytes_up_to_next_offset_not_the_end_of_range(): void
    {
        // RarInfo clamps range to its 1 MiB read window: a real entry read
        // range 112-1048575 with next_offset 3260986.
        $nextOffset = 3260986;
        $moovPayload = str_repeat('m', $nextOffset - 112 + 50 - 16);
        $moov = $this->mp4Atom('moov', $this->mp4Atom('mvhd', $moovPayload));
        $finalPart = str_repeat('H', 112).substr($moov, 50).str_repeat('E', 20);
        $finalFirst = substr($finalPart, 0, 2000000);
        $previousTail = str_repeat('v', 130).substr($moov, 0, 50).str_repeat('T', 20);
        $previousFirst = str_repeat('P', 2000000);

        [, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 3), $this->rarPart(3, 2)],
            responses: array_map($this->successfulDownload(...), [
                $finalFirst,
                substr($finalPart, 2000000),
                $previousFirst,
                $previousTail,
            ]),
            listings: [
                $finalFirst => [$this->storedVideoEntry($nextOffset, ['range' => '112-1048575'])],
                $previousFirst => [$this->storedVideoEntry(2000180, ['range' => '10-1048575'])],
            ],
        );

        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    /**
     * @return array<string, array{list<int>, list<int>}>
     */
    public static function incompleteParts(): array
    {
        // Starting at segment 2 keeps the tail contiguous but the head not.
        return [
            'incomplete final part' => [[2, 3, 4], [1, 2, 3, 4]],
            'incomplete previous part' => [[1, 2, 3], [2, 3, 4, 5]],
        ];
    }

    /**
     * @param  list<int>  $finalNumbers
     * @param  list<int>  $previousNumbers
     */
    #[Test]
    #[DataProvider('incompleteParts')]
    public function an_incomplete_part_never_joins(array $finalNumbers, array $previousNumbers): void
    {
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [
                $this->rarPart(1, 6),
                $this->rarPart(2, 4) + ['segmentNumbers' => $previousNumbers, 'partstotal' => (string) max($previousNumbers)],
                $this->rarPart(3, 3) + ['segmentNumbers' => $finalNumbers, 'partstotal' => (string) max($finalNumbers)],
            ],
            responses: [$this->successfulDownload($finalFirst), $this->successfulDownload(str_repeat('t', 180))],
            listings: [$finalFirst => [$this->storedVideoEntry(280)]],
        );

        $this->assertCount(3, $downloadCalls->calls, 'The final part is read whole; the previous part is never touched.');
        $this->assertSame($this->rawFragment(300), file_get_contents($fragmentPath));
        $this->assertSame(1, $result->mp4TailMetrics->moovMissingCount);
    }

    #[Test]
    public function an_incomplete_final_part_with_the_video_only_in_the_previous_part_never_joins(): void
    {
        $finalFirst = str_repeat('F', 100);
        $previousFirst = str_repeat('P', 100);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [
                $this->rarPart(1, 6),
                $this->rarPart(2, 4),
                $this->rarPart(3, 3) + ['segmentNumbers' => [2, 3, 4], 'partstotal' => '4'],
            ],
            responses: [$this->successfulDownload($finalFirst), $this->successfulDownload($previousFirst)],
            listings: [$previousFirst => [$this->storedVideoEntry(400)]],
        );

        $this->assertCount(3, $downloadCalls->calls);
        $this->assertSame($this->rawFragment(300), file_get_contents($fragmentPath));
        $this->assertSame(1, $result->mp4TailMetrics->moovMissingCount);
    }

    #[Test]
    public function a_tail_that_never_yields_a_moov_stops_at_the_segment_cap(): void
    {
        // The held first segment counts: 1 + 2 + 2 is the cap of 5.
        $finalFirst = str_repeat('F', 100);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 10)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload(str_repeat('t', 200)),
                $this->successfulDownload(str_repeat('t', 200)),
            ],
            listings: [$finalFirst => [$this->storedVideoEntry(1000)]],
            configOverrides: ['mp4TailMaxSegments' => 5],
        );

        $this->assertSame(
            [['<p2s1>'], ['<p2s9>', '<p2s10>'], ['<p2s7>', '<p2s8>']],
            array_column(array_slice($downloadCalls->calls, 1), 'messageIds'),
        );
        $this->assertSame($this->rawFragment(300), file_get_contents($fragmentPath));
        $this->assertSame(1, $result->mp4TailMetrics->moovMissingCount);
    }

    #[Test]
    public function the_segment_cap_covers_the_tail_segments_of_both_parts(): void
    {
        // Final part: 1 + 2 whole. Previous part: its first segment, then only
        // the 1 segment left of the cap of 5.
        $finalFirst = str_repeat('F', 100);
        $previousFirst = str_repeat('P', 100);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 6), $this->rarPart(3, 3)],
            responses: [
                $this->successfulDownload($finalFirst),
                $this->successfulDownload(str_repeat('t', 200)),
                $this->successfulDownload($previousFirst),
                $this->successfulDownload(str_repeat('t', 100)),
            ],
            listings: [
                $finalFirst => [$this->storedVideoEntry(300)],
                $previousFirst => [$this->storedVideoEntry(600)],
            ],
            configOverrides: ['mp4TailMaxSegments' => 5],
        );

        $this->assertSame(
            [['<p3s1>'], ['<p3s2>', '<p3s3>'], ['<p2s1>'], ['<p2s6>']],
            array_column(array_slice($downloadCalls->calls, 1), 'messageIds'),
        );
        $this->assertSame($this->rawFragment(300), file_get_contents($fragmentPath));
        $this->assertSame(1, $result->mp4TailMetrics->moovMissingCount);
    }

    #[Test]
    public function the_anchor_as_previous_part_requests_none_of_its_held_head_again(): void
    {
        // A two-part set: the part before the final one is the anchor, whose
        // first three segments are already held.
        $moov = $this->mp4Atom('moov', $this->mp4Atom('mvhd', str_repeat('m', 100)));
        $anchorHead = str_repeat('a', 300);
        $finalPart = str_repeat('H', 70).substr($moov, 40).str_repeat('E', 20);
        $finalFirst = substr($finalPart, 0, 100);

        [$downloadCalls, , $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 5), $this->rarPart(2, 2)],
            responses: array_map($this->successfulDownload(...), [
                $finalFirst,
                substr($finalPart, 100),
                str_repeat('v', 160).substr($moov, 0, 40).str_repeat('T', 20),
            ]),
            listings: [$finalFirst => [$this->storedVideoEntry(strlen($finalPart) - 20, ['range' => '70-99'])]],
            anchorHead: $anchorHead,
            anchorEntryOverrides: ['next_offset' => 500, 'range' => '10-299'],
        );

        $this->assertSame(
            [['<p2s1>'], ['<p2s2>'], ['<p1s4>', '<p1s5>']],
            array_column(array_slice($downloadCalls->calls, 1), 'messageIds'),
        );
        $this->assertSame($this->splicedFragment(300, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function an_unavailable_group_during_the_tail_stops_the_candidates_and_keeps_the_fragment(): void
    {
        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 3)],
            responses: [['success' => false, 'data' => null, 'groupUnavailable' => true, 'error' => 'gone']],
            listings: [],
        );

        $this->assertCount(2, $downloadCalls->calls);
        $this->assertSame(ProcessingOutcome::GroupUnavailable, $result->outcome);
        $this->assertSame($this->rawFragment(300), file_get_contents($fragmentPath));
    }

    #[Test]
    public function a_real_stored_rar_set_splits_its_moov_across_its_last_two_volumes(): void
    {
        // Real RAR4 headers and the real listing and extraction: three 3000-byte
        // volumes in 500-byte segments, the moov filling the third volume and
        // starting in the second. Volume payloads start 61 bytes in.
        $overhead = 59 + strlen('movie.mp4');
        $moov = $this->mp4Atom('moov', $this->mp4Atom('mvhd', str_repeat('m', 3084)));
        $padding = str_repeat('x', 3 * (3000 - $overhead) - strlen($this->mdatFirstHead()) - strlen($moov));
        $volumes = SyntheticPosting::rar(
            [3000, 3000, 3000],
            'movie.mp4',
            $this->mdatFirstHead().$padding.$moov,
        )['volumes'];
        $previousSegments = str_split($volumes[1], 500);
        $finalSegments = str_split($volumes[2], 500);

        [$downloadCalls, $result, $fragmentPath] = $this->processStoredRarVideo(
            parts: [$this->rarPart(1, 6), $this->rarPart(2, 6), $this->rarPart(3, 6)],
            responses: array_map($this->successfulDownload(...), [
                $finalSegments[0],
                ...$this->batchesFromTheEnd(array_slice($finalSegments, 1), 2),
                $previousSegments[0],
                implode('', array_slice($previousSegments, -2)),
            ]),
            listings: [],
            anchorHead: substr($volumes[0], 0, 1500),
            realArchiveService: new ArchiveExtractionService($this->makeConfig()),
        );

        $this->assertSame(
            [['<p3s1>'], ['<p3s5>', '<p3s6>'], ['<p3s3>', '<p3s4>'], ['<p3s2>'], ['<p2s1>'], ['<p2s5>', '<p2s6>']],
            array_column(array_slice($downloadCalls->calls, 1), 'messageIds'),
        );
        $this->assertSame(1, $result->mp4TailMetrics->moovFoundCount);
        $this->assertSame($this->splicedFragment(1500 - 61, $moov), file_get_contents($fragmentPath));
    }

    #[Test]
    public function an_unreadable_demux_with_a_known_bitrate_still_tops_up(): void
    {
        // 40 B/s and 0.0 s decodable: the whole 1200-byte target is wanted.
        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [300, 600],
            decodableDurations: [null, 30.0],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        $this->assertSame(['<p1s4>', '<p1s5>', '<p1s6>'], $downloadCalls->calls[1]['messageIds']);
    }

    #[Test]
    public function an_unreadable_demux_with_an_unknown_bitrate_makes_one_fetch(): void
    {
        Log::spy();

        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [],
            extractSizes: [300],
            probeResult: null,
            decodableDurations: [null],
        );

        $this->assertSame([DownloadKind::Compressed], array_column($downloadCalls->calls, 'kind'));
        Log::shouldHaveReceived('debug')->once()->withArgs(
            static fn (string $message, array $details): bool => $message === 'Compressed preview top-up probe-failed'
                && $details['reason'] === 'duration unavailable',
        );
    }

    #[Test]
    public function an_unreadable_demux_inside_the_loop_still_stops_it(): void
    {
        Log::spy();

        [$downloadCalls] = $this->processCompressedTopUpCandidate(
            nzbFiles: [
                ['title' => 'release.part01.rar', 'segments' => ['<p1s1>', '<p1s2>', '<p1s3>', '<p1s4>', '<p1s5>', '<p1s6>']],
                ['title' => 'release.part02.rar', 'segments' => ['<p2s1>', '<p2s2>', '<p2s3>']],
            ],
            initialData: str_repeat('a', 300),
            responses: [$this->successfulDownload(str_repeat('b', 300))],
            extractSizes: [300, 600],
            decodableDurations: [7.0, null],
        );

        $this->assertSame(
            [DownloadKind::Compressed, DownloadKind::CompressedTopUp],
            array_column($downloadCalls->calls, 'kind'),
        );
        Log::shouldHaveReceived('debug')->once()->withArgs(
            static fn (string $message, array $details): bool => $message === 'Compressed preview top-up probe-failed'
                && $details['reason'] === 'duration unavailable',
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
     * @param  int|null  $probeReadableFromBytes  The probe sees nothing until the file on disk reaches this size.
     * @return array{ReleaseProcessingContext, RecordingMp4DownloadCalls, RecordingMediaExtractionCalls, ReleaseProcessingResult, RecordingPreviewExtraction}
     */
    private function processDynamicBudgetCandidate(
        array $file,
        string $head,
        array $responses = [],
        array $configOverrides = [],
        ?VideoHeadProbeResult $probeResult = null,
        bool $budgetEnabled = true,
        ?int $probeReadableFromBytes = null,
    ): array {
        File::ensureDirectoryExists($this->releaseTempPath);
        $probes = new RecordingPreviewExtraction;
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
            headProbe: new StubVideoHeadProbe($probeResult, $probes, $probeReadableFromBytes),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        return [$context, $downloadCalls, $mediaCalls, $result, $probes];
    }

    /**
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function sniffedFile(string $title, array $segments, int $sizeBytes = 300, ?int $partsTotal = null): array
    {
        return [
            'title' => $title,
            'segments' => $segments,
            'size' => $sizeBytes,
            'partsactual' => count($segments),
            'partstotal' => (string) ($partsTotal ?? count($segments)),
        ];
    }

    /**
     * Drive a release whose NZB holds only obfuscated files. Every artifact
     * is wanted unless $configOverrides says otherwise; the head window is 3.
     *
     * @param  list<array<string, mixed>>  $files
     * @param  list<array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}>  $responses
     * @param  array<string, mixed>  $configOverrides
     * @return array{RecordingMp4DownloadCalls, RecordingMediaExtractionCalls, ReleaseProcessingContext}
     */
    private function processSniffedVideoCandidates(
        array $files,
        array $responses,
        array $configOverrides = [],
        bool $budgetEnabled = false,
    ): array {
        File::ensureDirectoryExists($this->releaseTempPath);
        $config = $this->makeConfig(array_merge([
            'processMediaInfo' => true,
            'processThumbnails' => true,
            'processVideo' => true,
            'segmentsToDownload' => 3,
            'mp4TailMaxSegments' => 2,
        ], $configOverrides));
        $nzbParser = Mockery::mock(NzbContentParser::class);
        $nzbParser->shouldReceive('parseNzb')->once()->andReturn(['error' => null, 'contents' => $files]);

        $downloadCalls = new RecordingMp4DownloadCalls($responses);
        $downloadService = $this->scopedDownloadService();
        $downloadService->shouldReceive('download')
            ->times(count($responses))
            ->andReturnUsing($downloadCalls->download(...));
        $downloadService->shouldReceive('meetsMinimumSize')
            ->zeroOrMoreTimes()
            ->andReturnUsing($downloadCalls->meetsMinimumSize(...));

        $mediaCalls = new RecordingMediaExtractionCalls;
        $mediaService = Mockery::mock(MediaExtractionService::class);
        $mediaService->shouldReceive('getMediaInfo')->zeroOrMoreTimes()->andReturnUsing($mediaCalls->recordMediaInfo(...));
        $mediaService->shouldReceive('getSample')->zeroOrMoreTimes()->andReturnUsing($mediaCalls->recordSample(...));
        $mediaService->shouldReceive('getVideo')->zeroOrMoreTimes()->andReturnUsing($mediaCalls->recordVideo(...));
        $mediaService->shouldReceive('processVideoFile')->zeroOrMoreTimes()->andReturnUsing($mediaCalls->recordProcessVideoFile(...));

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
            headProbe: new StubVideoHeadProbe(null),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $processor->process($context, $this->mainTempPath);

        return [$downloadCalls, $mediaCalls, $context];
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
     * @param  string  $fragmentHead  Real atom bytes every extracted fragment starts with; the rest is padding.
     * @param  array<string, list<array<string, mixed>>>  $listings  What listArchiveContents returns, by the exact bytes it is given; anything else lists nothing. Empty keeps the single movie.mkv listing.
     * @return array{RecordingMp4DownloadCalls, ReleaseProcessingResult, string, RecordingPreviewExtraction}
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
        string $fragmentHead = '',
        array $listings = [],
        string $fragmentName = 'movie.mkv',
        ?ArchiveExtractionService $realArchiveService = null,
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

        $fragmentPath = $this->releaseTempPath.'unrar/'.$fragmentName;
        $extractIndex = 0;
        $extraction = new RecordingPreviewExtraction;
        $declaredDuration = $probeResult?->durationSeconds ?? 30.0;
        $bytesPerSecond = $videoEntrySize > 0 && $declaredDuration > 0
            ? $videoEntrySize / $declaredDuration
            : 1.0;
        $decodableDurations ??= array_map(
            static fn (int $size): float => $size / $bytesPerSecond,
            $extractSizes,
        );
        $archiveService = $realArchiveService;
        if ($archiveService === null) {
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
                ->andReturnUsing(static fn (string $data): array => [
                    'files' => $listings === []
                        ? [['name' => 'movie.mkv', 'size' => $videoEntrySize]]
                        : ($listings[$data] ?? []),
                    'hasPassword' => false,
                ]);
            $archiveService->shouldReceive('extractSpecificFileToPath')
                ->times(count($extractSizes))
                ->andReturnUsing(function (string $archivePath) use (&$extractIndex, $extractSizes, $fragmentPath, $fragmentHead, $extraction): string {
                    $extraction->recordExtraction($archivePath);
                    File::ensureDirectoryExists(dirname($fragmentPath));
                    $size = $extractSizes[$extractIndex];
                    file_put_contents($fragmentPath, substr($fragmentHead.str_repeat('x', $size), 0, $size));
                    $extractIndex++;

                    return $fragmentPath;
                });
        }

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('processReleaseNameFromNzbContents')->once()->andReturnFalse();
        $releaseManager->shouldReceive('processReleaseNameFromRar')->zeroOrMoreTimes()->andReturnFalse();
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
        $tempWorkspace->shouldReceive('listFiles')->zeroOrMoreTimes()->andReturnUsing(function () use ($extraction): array {
            $extraction->workingVolumesAtSweep = glob($this->releaseTempPath.'preview-archive.*') ?: [];

            return [];
        });

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
            headProbe: new StubVideoHeadProbe($probeResult, $extraction),
            decodableLengthProbe: new StubVideoDecodableLengthProbe($decodableDurations, $extraction),
        );

        $context = $this->makeContext();
        $context->release->nfostatus = 1;
        $result = $processor->process($context, $this->mainTempPath);

        return [$downloadCalls, $result, $fragmentPath, $extraction];
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

    /**
     * The first bytes of a fast-start MP4 whose moov ends at $moovEnd.
     */
    private function fastStartHead(int $moovEnd): string
    {
        $ftyp = $this->mp4Atom('ftyp', 'isom0000');

        return $ftyp.pack('N', $moovEnd - strlen($ftyp)).'moov';
    }

    /**
     * Drive one release whose anchor part lists a stored mdat-first MP4 through
     * the compressed path, with the budget toggle off unless a test turns it on.
     * The anchor's head is listed unless $listings says otherwise.
     *
     * @param  list<array<string, mixed>>  $parts
     * @param  list<array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}>  $responses
     * @param  array<string, list<array<string, mixed>>>  $listings
     * @param  list<int>  $extractSizes
     * @param  array<string, mixed>  $configOverrides
     * @param  list<float|null>|null  $decodableDurations
     * @param  array<string, mixed>  $anchorEntryOverrides
     * @return array{RecordingMp4DownloadCalls, ReleaseProcessingResult, string, RecordingPreviewExtraction}
     */
    private function processStoredRarVideo(
        array $parts,
        array $responses,
        array $listings,
        array $extractSizes = [300],
        array $configOverrides = [],
        bool $budgetEnabled = false,
        ?array $decodableDurations = null,
        array $anchorEntryOverrides = [],
        ?string $anchorHead = null,
        ?string $fragmentHead = null,
        ?ArchiveExtractionService $realArchiveService = null,
    ): array {
        $anchorHead ??= str_repeat('a', 300);

        return $this->processCompressedTopUpCandidate(
            nzbFiles: $parts,
            initialData: $anchorHead,
            responses: $responses,
            extractSizes: $extractSizes,
            configOverrides: $configOverrides,
            budgetEnabled: $budgetEnabled,
            decodableDurations: $decodableDurations ?? [],
            fragmentHead: $fragmentHead ?? $this->mdatFirstHead(),
            listings: [$anchorHead => [$this->storedVideoEntry(600, $anchorEntryOverrides)], ...$listings],
            fragmentName: 'movie.mp4',
            realArchiveService: $realArchiveService,
        );
    }

    /**
     * @return array{title: string, segments: list<string>}
     */
    private function rarPart(int $part, int $segments): array
    {
        return [
            'title' => sprintf('release.part%02d.rar', $part),
            'segments' => array_map(static fn (int $segment): string => "<p{$part}s{$segment}>", range(1, $segments)),
        ];
    }

    /**
     * Segment bytes as a tail reads them: batches from the last segment back.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function batchesFromTheEnd(array $segments, int $batchSize): array
    {
        $batches = [];
        while ($segments !== []) {
            $batches[] = implode('', array_splice($segments, -$batchSize));
        }

        return $batches;
    }

    /**
     * What the extraction of a fragment of $bytes leaves when nothing splices it.
     */
    private function rawFragment(int $bytes): string
    {
        return $this->mdatFirstHead().str_repeat('x', $bytes - strlen($this->mdatFirstHead()));
    }

    /**
     * The first bytes of an MP4 written with its moov at the end of the file.
     */
    private function mdatFirstHead(): string
    {
        return $this->mp4Atom('ftyp', 'isom0000').pack('N', 1 << 30).'mdat';
    }

    /**
     * What a fragment of $rawBytes (mdatFirstHead() then padding) becomes with
     * the moov spliced on: its mdat is cut to the bytes held.
     */
    private function splicedFragment(int $rawBytes, string $moov): string
    {
        return $this->mp4Atom('ftyp', 'isom0000').pack('N', $rawBytes - 16).'mdat'.str_repeat('x', $rawBytes - 24).$moov;
    }

    /**
     * A listArchiveContents entry for a video stored in a part.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storedVideoEntry(int $nextOffset, array $overrides = []): array
    {
        return array_merge([
            'name' => 'movie.mp4',
            'size' => 5000,
            'compressed' => 0,
            'pass' => 0,
            'range' => '10-'.($nextOffset - 1),
            'next_offset' => $nextOffset,
        ], $overrides);
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
    /**
     * @param  int|null  $readableFromBytes  Like ffmpeg on a fast-start MP4: no result until the probed file reaches this size (the moov end).
     */
    public function __construct(
        private readonly ?VideoHeadProbeResult $result,
        private readonly ?RecordingPreviewExtraction $extraction = null,
        private readonly ?int $readableFromBytes = null,
    ) {
        parent::__construct(static fn (): string => '');
    }

    public function probe(string $videoPath, ProcessingConfiguration $config): ?VideoHeadProbeResult
    {
        $size = (int) filesize($videoPath);
        if ($this->extraction !== null) {
            $this->extraction->headProbeSizes[] = $size;
        }

        return $this->readableFromBytes !== null && $size < $this->readableFromBytes ? null : $this->result;
    }
}

final class StubVideoDecodableLengthProbe extends VideoDecodableLengthProbe
{
    /** @param list<float|null> $durations */
    public function __construct(
        private array $durations,
        private readonly ?RecordingPreviewExtraction $extraction = null,
    ) {}

    public function demuxedSeconds(string $path, ProcessingConfiguration $config): ?float
    {
        if ($this->extraction !== null) {
            $this->extraction->decodableProbeSizes[] = (int) filesize($path);
            $this->extraction->decodableProbeFiles[] = (string) file_get_contents($path);
        }

        return array_shift($this->durations);
    }
}

final class RecordingPreviewExtraction
{
    /** @var list<string> Archive path handed to each extraction. */
    public array $archivePaths = [];

    /** @var array<string, string> Working volumes on disk at the last extraction, by file name. */
    public array $workingVolumes = [];

    /** @var list<int> Fragment size at each decodable-length probe. */
    public array $decodableProbeSizes = [];

    /** @var list<int> Fragment size at each head probe. */
    public array $headProbeSizes = [];

    /** @var list<string> Fragment bytes at each decodable-length probe. */
    public array $decodableProbeFiles = [];

    /** @var list<string>|null The working volumes really on disk when the extracted-files sweep listed the directory. */
    public ?array $workingVolumesAtSweep = null;

    public function recordExtraction(string $archivePath): void
    {
        $this->archivePaths[] = $archivePath;
        $this->workingVolumes = [];
        foreach (glob(dirname($archivePath).'/preview-archive.*') ?: [] as $volume) {
            $this->workingVolumes[basename($volume)] = (string) file_get_contents($volume);
        }
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

    /** @var list<array{path: string, data: string}> */
    public array $videoFiles = [];

    /** @var list<array{path: string, data: string}> */
    public array $processVideoFiles = [];

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

    public function recordVideo(string $fileLocation, string $tmpPath, string $guid, int $categoriesId): bool
    {
        $this->videoFiles[] = ['path' => $fileLocation, 'data' => (string) file_get_contents($fileLocation)];

        return true;
    }

    /**
     * @return array{sample: bool, video: bool, mediaInfo: bool}
     */
    public function recordProcessVideoFile(string $fileLocation): array
    {
        $this->processVideoFiles[] = ['path' => $fileLocation, 'data' => (string) file_get_contents($fileLocation)];

        return ['sample' => false, 'video' => false, 'mediaInfo' => false];
    }
}
