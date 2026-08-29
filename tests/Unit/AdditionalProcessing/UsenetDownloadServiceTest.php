<?php

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\NNTP\DTO\ArticleDownloadResult;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsenetDownloadServiceTest extends TestCase
{
    use CreatesProcessingConfiguration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_downloads_binary_payloads_through_the_enum_driven_api(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')
            ->once()
            ->with(['<abc>'])
            ->andReturn(new ArticleDownloadResult('BINARY-DATA'));

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);

        $result = $service->download(DownloadKind::Compressed, ['<abc>'], 'alt.binaries', 55, 'archive.rar');

        $this->assertTrue($result['success']);
        $this->assertSame('BINARY-DATA', $result['data']);
    }

    #[Test]
    public function it_surfaces_crc_failed_articles_and_counts_them_in_release_metrics(): void
    {
        Log::spy();
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')
            ->once()
            ->with(['<damaged>'])
            ->andReturn(new ArticleDownloadResult(
                data: 'CORRUPT-DATA',
                crcFailedMessageIds: ['<damaged>'],
                damaged: true,
            ));

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $result = $service->download(DownloadKind::Audio, ['<damaged>'], 'alt.binaries', 55, 'archive.rar');
        $metrics = $service->finishReleaseScope();

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['crcFailures']);
        $this->assertTrue($result['crcFailed']);
        $this->assertSame('Source articles failed CRC verification.', $result['error']);
        $this->assertSame(1, $metrics->crcFailures);
        Log::shouldHaveReceived('debug')->once()->with(
            'yEnc article failed CRC verification',
            [
                'release_id' => 55,
                'message_id' => '<damaged>',
                'group' => 'alt.binaries',
            ],
        );
    }

    #[Test]
    public function it_marks_group_unavailable_errors_explicitly(): void
    {
        $error = new class
        {
            public function getMessage(): string
            {
                return 'No such news group';
            }
        };

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')
            ->once()
            ->with(['<missing>'])
            ->andReturn(new ArticleDownloadResult($error));

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);

        $result = $service->download(DownloadKind::Sample, ['<missing>']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['groupUnavailable']);
        $this->assertStringContainsString('Group unavailable', (string) $result['error']);
    }

    #[Test]
    public function it_reuses_exact_successful_requests_only_within_the_current_release(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')
            ->twice()
            ->with(['<shared>'])
            ->andReturn(new ArticleDownloadResult('SHARED-DATA'));

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $first = $service->download(DownloadKind::Compressed, ['<shared>'], 'alt.binaries.test', 10);
        $second = $service->download(DownloadKind::Jpg, ['<shared>'], 'alt.binaries.test', 10);
        $firstReleaseMetrics = $service->finishReleaseScope();

        $this->assertSame($first, $second);
        $this->assertSame(2, $firstReleaseMetrics->logicalRequests);
        $this->assertSame(1, $firstReleaseMetrics->networkRequests);
        $this->assertSame(1, $firstReleaseMetrics->cacheHits);
        $this->assertSame(strlen('SHARED-DATA'), $firstReleaseMetrics->bytesDownloaded);
        $this->assertSame(strlen('SHARED-DATA'), $firstReleaseMetrics->bytesReused);

        $service->beginReleaseScope();
        $service->download(DownloadKind::Compressed, ['<shared>'], 'alt.binaries.test', 11);
        $secondReleaseMetrics = $service->finishReleaseScope();

        $this->assertSame(1, $secondReleaseMetrics->logicalRequests);
        $this->assertSame(1, $secondReleaseMetrics->networkRequests);
        $this->assertSame(0, $secondReleaseMetrics->cacheHits);
    }

    #[Test]
    public function it_does_not_cache_failed_downloads(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')
            ->twice()
            ->with(['<missing>'])
            ->andReturn(new ArticleDownloadResult(''));

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $service->download(DownloadKind::Compressed, ['<missing>']);
        $service->download(DownloadKind::Compressed, ['<missing>']);
        $metrics = $service->finishReleaseScope();

        $this->assertSame(2, $metrics->networkRequests);
        $this->assertSame(0, $metrics->cacheHits);
    }

    #[Test]
    public function it_does_not_reuse_successful_downloads_outside_a_release_scope(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')
            ->twice()
            ->with(['<shared>'])
            ->andReturn(new ArticleDownloadResult('SHARED-DATA'));

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);

        $first = $service->download(DownloadKind::Compressed, ['<shared>'], 'alt.binaries.test', 10);
        $second = $service->download(DownloadKind::Jpg, ['<shared>'], 'alt.binaries.test', 10);

        $this->assertSame('SHARED-DATA', $first['data']);
        $this->assertSame('SHARED-DATA', $second['data']);
    }

    #[Test]
    public function it_retries_an_exact_request_after_a_partial_provider_failure(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')
            ->twice()
            ->with(['<retry>'])
            ->andReturn(new ArticleDownloadResult(''), new ArticleDownloadResult('RECOVERED-DATA'));

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $failed = $service->download(DownloadKind::Compressed, ['<retry>']);
        $recovered = $service->download(DownloadKind::Compressed, ['<retry>']);
        $metrics = $service->finishReleaseScope();

        $this->assertFalse($failed['success']);
        $this->assertTrue($recovered['success']);
        $this->assertSame('RECOVERED-DATA', $recovered['data']);
        $this->assertSame(2, $metrics->networkRequests);
        $this->assertSame(0, $metrics->cacheHits);
    }

    #[Test]
    public function it_checks_minimum_download_sizes(): void
    {
        $service = new UsenetDownloadService($this->makeConfig(), Mockery::mock(NNTPService::class));

        $this->assertFalse($service->meetsMinimumSize(str_repeat('a', 40)));
        $this->assertTrue($service->meetsMinimumSize(str_repeat('a', 41)));
    }
}
