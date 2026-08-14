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
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
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
