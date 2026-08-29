<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AudioProcessing\AudioDecodableLengthProbe;
use App\Services\AudioProcessing\AudioFetcher;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use FFMpeg\Driver\FFProbeDriver;
use FFMpeg\FFProbe;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\MediaInfo;
use Mhor\MediaInfo\Type\Audio;
use Mhor\MediaInfo\Type\Video;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Scene music uses multi-volume RAR sets. Every volume must remain a separate
 * file so unrar can discover its siblings while the fetcher incrementally
 * decides whether the first audio entry is already usable.
 */
class AudioFetcherArchiveTest extends TestCase
{
    private string $tmpPath;

    /** @var list<list<string>> */
    private array $downloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Application(sys_get_temp_dir());
        $container->instance('files', new Filesystem);
        Facade::setFacadeApplication($container);
        Log::swap(Mockery::mock()->shouldIgnoreMissing());

        $this->tmpPath = sys_get_temp_dir().'/audio-fetcher-'.bin2hex(random_bytes(6)).'/';
        (new Filesystem)->makeDirectory($this->tmpPath, 0777, true, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tmpPath);
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[Test]
    public function it_keeps_each_volume_separate_while_appending_chunks_within_that_volume(): void
    {
        $paths = [];
        $contents = [];
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')
            ->times(4)
            ->andReturnUsing(function (string $archivePath) use (&$paths, &$contents): array {
                $paths[] = basename($archivePath);
                $contents[] = (string) file_get_contents($archivePath);

                return ['files' => [], 'hasPassword' => false];
            });
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $result = $this->fetcher(
            $archive,
            downloadData: static fn (array $messageIds): string => implode(',', $messageIds).'|',
        )->fetch(
            $this->release(),
            $this->archiveSource([
                array_map(static fn (int $segment): string => '<v1-'.$segment.'>', range(1, 65)),
                array_map(static fn (int $segment): string => '<v2-'.$segment.'>', range(1, 65)),
            ]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame([64, 1, 64, 1], array_map('count', $this->downloads));
        $this->assertSame([
            'audio-archive.part001.rar',
            'audio-archive.part001.rar',
            'audio-archive.part002.rar',
            'audio-archive.part002.rar',
        ], $paths);
        $this->assertSame(
            implode(',', array_map(static fn (int $segment): string => '<v1-'.$segment.'>', range(1, 64))).'|',
            $contents[0],
        );
        $this->assertSame($contents[0].'<v1-65>|', $contents[1]);
        $this->assertSame(
            implode(',', array_map(static fn (int $segment): string => '<v2-'.$segment.'>', range(1, 64))).'|',
            $contents[2],
        );
        $this->assertSame($contents[2].'<v2-65>|', $contents[3]);
        $this->assertFileDoesNotExist($this->tmpPath.'audio-archive.rar');
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_merges_later_volume_listings_and_extracts_from_part_one_with_keep_broken(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->with($this->partPath(1))->andReturn([
            'files' => [['name' => '00-group.nfo', 'size' => 12]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->with($this->partPath(2))->andReturn([
            'files' => [['name' => '01-track.flac', 'size' => 8]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->once()
            ->with($this->partPath(1), '01-track.flac', $this->tmpPath, true)
            ->andReturnUsing(function (): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, 'abcdefgh');

                return $path;
            });

        $result = $this->fetch($archive, volumes: 3);

        $this->assertTrue($result->succeeded());
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_accepts_a_long_enough_partial_extraction_without_fetching_another_volume(): void
    {
        $archive = $this->archiveWithTrack(declaredSize: 100, extractedBodies: ['partial']);
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->once()->andReturn(45.0);

        $result = $this->fetch($archive, volumes: 3, lengthProbe: $lengthProbe);

        $this->assertTrue($result->succeeded());
        $this->assertSame([['<vol-1>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_rejects_a_short_partial_then_accepts_a_complete_extraction_without_probing_it(): void
    {
        $archive = $this->archiveWithTrack(declaredSize: 8, extractedBodies: ['abc', 'abcdefgh']);
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->once()->andReturn(20.0);

        $result = $this->fetch($archive, volumes: 3, lengthProbe: $lengthProbe);

        $this->assertTrue($result->succeeded());
        $this->assertSame('abcdefgh', file_get_contents((string) $result->path));
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    /**
     * @return iterable<string, array{float, bool}>
     */
    public static function partialThresholds(): iterable
    {
        yield 'exact threshold' => [42.0, true];
        yield 'just below threshold' => [41.9, false];
    }

    #[Test]
    #[DataProvider('partialThresholds')]
    public function partial_extractions_must_cover_the_preview_window_and_margin(float $seconds, bool $accepted): void
    {
        $archive = $this->archiveWithTrack(declaredSize: 100, extractedBodies: ['partial']);
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->once()->andReturn($seconds);

        $result = $this->fetch($archive, volumes: 1, lengthProbe: $lengthProbe);

        $this->assertSame($accepted, $result->succeeded());
        if (! $accepted) {
            $this->assertStringContainsString('No usable audio file', $result->reason);
            $this->assertFileDoesNotExist($this->tmpPath.'01-track.flac');
        }
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_float_wavpack_partial_reports_the_missing_fallback_instead_of_exhausting_volumes(): void
    {
        $archive = $this->archiveWithTrack(
            declaredSize: 100,
            extractedBodies: ['partial'],
            name: '01-track.wv',
        );

        $result = $this->fetch(
            $archive,
            volumes: 1,
            lengthProbe: $this->unavailableWavPackLengthProbe(),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('WavPack file requires wvunpack, which is not installed.', $result->reason);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function the_first_declared_size_for_an_entry_wins_when_later_listings_disagree(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn(
            ['files' => [['name' => '01-track.flac', 'size' => 100]], 'hasPassword' => false],
            ['files' => [['name' => '01-track.flac', 'size' => 5]], 'hasPassword' => false],
        );
        $archive->shouldReceive('extractSpecificFileToPath')->twice()->andReturnUsing(function (): string {
            $path = $this->tmpPath.'01-track.flac';
            file_put_contents($path, 'small');

            return $path;
        });
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(20.0);

        $result = $this->fetch($archive, volumes: 2, lengthProbe: $lengthProbe);

        $this->assertFalse($result->succeeded());
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_password_in_the_first_volume_stops_the_fetch(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->with($this->partPath(1))->andReturn([
            'files' => [],
            'hasPassword' => true,
        ]);
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $result = $this->fetch($archive, volumes: 4);

        $this->assertFalse($result->succeeded());
        $this->assertSame('The archive is password protected.', $result->reason);
        $this->assertSame([['<vol-1>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_enforces_the_total_archive_byte_ceiling_before_appending_a_chunk(): void
    {
        $listedContents = [];
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')
            ->once()
            ->andReturnUsing(function (string $archivePath) use (&$listedContents): array {
                $listedContents[] = (string) file_get_contents($archivePath);

                return ['files' => [], 'hasPassword' => false];
            });

        $result = $this->fetcher(
            $archive,
            downloadData: static fn (): string => '123456',
            maxArchiveBytes: 10,
        )->fetch(
            $this->release(),
            $this->archiveSource([[...range(1, 65)]]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('fetch ceiling', $result->reason);
        $this->assertSame(['123456'], $listedContents);
        $this->assertSame([64, 1], array_map('count', $this->downloads));
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_stops_at_the_configured_volume_ceiling(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->twice()->andReturn([
            'files' => [],
            'hasPassword' => false,
        ]);

        $result = $this->fetch($archive, volumes: 6, maxRarParts: 2);

        $this->assertFalse($result->succeeded());
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertStringContainsString('No usable audio file', $result->reason);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function probe_declines_still_remove_every_archive_part(): void
    {
        $container = new MediaInfoContainer;
        $container->add(new Video);
        $archive = $this->archiveWithTrack(declaredSize: 8, extractedBodies: ['abcdefgh']);

        $result = $this->fetch($archive, volumes: 1, mediaContainer: $container);

        $this->assertTrue($result->declined);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function download_exceptions_still_remove_every_archive_part(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('download')->andThrow(new RuntimeException('download exploded'));
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldNotReceive('demuxedSeconds');

        try {
            $this->fetcher($archive, downloadService: $downloadService, lengthProbe: $lengthProbe)->fetch(
                $this->release(),
                $this->archiveSource([['<vol-1>']]),
                $this->tmpPath,
                'alt.binaries.sounds.lossless',
                static function (): void {},
            );
            $this->fail('The download exception should escape the fetcher.');
        } catch (RuntimeException $exception) {
            $this->assertSame('download exploded', $exception->getMessage());
        }

        $this->assertNoArchivePartsRemain();
    }

    private function fetch(
        ArchiveExtractionService $archive,
        int $volumes,
        int $maxRarParts = 6,
        ?AudioDecodableLengthProbe $lengthProbe = null,
        ?MediaInfoContainer $mediaContainer = null,
    ): AudioFetchResult {
        $parts = [];
        foreach (range(1, $volumes) as $volume) {
            $parts[] = ['<vol-'.$volume.'>'];
        }

        return $this->fetcher(
            $archive,
            maxRarParts: $maxRarParts,
            lengthProbe: $lengthProbe,
            mediaContainer: $mediaContainer,
        )->fetch(
            $this->release(),
            $this->archiveSource($parts),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );
    }

    /**
     * @param  list<string>  $extractedBodies
     */
    private function archiveWithTrack(
        int $declaredSize,
        array $extractedBodies,
        string $name = '01-track.flac',
    ): ArchiveExtractionService {
        $extractionCount = 0;
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->times(count($extractedBodies))->andReturn([
            'files' => [['name' => $name, 'size' => $declaredSize]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->times(count($extractedBodies))
            ->with($this->partPath(1), $name, $this->tmpPath, true)
            ->andReturnUsing(function () use (&$extractedBodies, &$extractionCount, $name): string {
                $path = $this->tmpPath.$name;
                if ($extractionCount > 0) {
                    $this->assertFileDoesNotExist($path, 'A rejected partial must be deleted before retrying extraction.');
                }
                file_put_contents($path, array_shift($extractedBodies));
                $extractionCount++;

                return $path;
            });

        return $archive;
    }

    private function unavailableWavPackLengthProbe(): AudioDecodableLengthProbe
    {
        $driver = Mockery::mock(FFProbeDriver::class);
        $driver->shouldReceive('command')->once()->andReturn("0.0\n2.5\n");
        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('getFFProbeDriver')->andReturn($driver);

        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'ffprobe'))->setValue($tools, $ffprobe);
        (new ReflectionProperty(MediaTools::class, 'wvunpackPath'))->setValue($tools, false);

        return new AudioDecodableLengthProbe($tools);
    }

    /**
     * @param  (callable(list<string>): string)|null  $downloadData
     */
    private function fetcher(
        ArchiveExtractionService $archive,
        int $maxRarParts = 6,
        ?callable $downloadData = null,
        ?int $maxArchiveBytes = null,
        ?AudioDecodableLengthProbe $lengthProbe = null,
        ?MediaInfoContainer $mediaContainer = null,
        ?UsenetDownloadService $downloadService = null,
    ): AudioFetcher {
        if ($downloadService === null) {
            $downloadService = Mockery::mock(UsenetDownloadService::class);
            $downloadService->shouldReceive('download')->andReturnUsing(
                function (mixed $kind, array $messageIds) use ($downloadData): array {
                    $this->downloads[] = array_values($messageIds);

                    return [
                        'success' => true,
                        'data' => $downloadData === null ? 'volume-bytes' : $downloadData($messageIds),
                        'groupUnavailable' => false,
                        'error' => null,
                    ];
                }
            );
        }

        $mediaContainer ??= $this->audioContainer();
        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->andReturn($mediaContainer);
        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'mediaInfo'))->setValue($tools, $mediaInfo);

        $lengthProbe ??= Mockery::mock(AudioDecodableLengthProbe::class)->shouldIgnoreMissing(0.0);

        return new AudioFetcher(
            $this->config($maxRarParts, $maxArchiveBytes),
            $downloadService,
            $archive,
            $tools,
            $lengthProbe,
        );
    }

    private function config(int $maxRarParts, ?int $maxArchiveBytes): AudioProcessingConfiguration
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'segmentsToDownload' => 12,
            'maxRarParts' => $maxRarParts,
            'maxArchiveBytes' => $maxArchiveBytes,
            'previewSeconds' => 30,
            'previewStartSeconds' => 10,
            'debugMode' => false,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }

    /**
     * @param  list<list<string>>  $parts
     */
    private function archiveSource(array $parts): AudioSource
    {
        return new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: $parts,
        );
    }

    private function audioContainer(): MediaInfoContainer
    {
        $container = new MediaInfoContainer;
        $container->add(new Audio);

        return $container;
    }

    private function partPath(int $volume): string
    {
        return $this->tmpPath.'audio-archive.part'.sprintf('%03d', $volume).'.rar';
    }

    private function assertNoArchivePartsRemain(): void
    {
        $this->assertSame([], glob($this->tmpPath.'audio-archive.part*.rar') ?: []);
    }

    private function release(): Release
    {
        $release = new Release;
        $release->id = 42;
        $release->guid = 'audio-guid';

        return $release;
    }
}
