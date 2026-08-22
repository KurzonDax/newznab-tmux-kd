<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AudioProcessing\AudioFetcher;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\MediaInfo;
use Mhor\MediaInfo\Type\Audio;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Scene music is store-mode RAR, so the first track is usually whole after one
 * or two volumes. The fetcher has to notice that and stop, rather than pulling
 * the part count it is allowed.
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
        $this->downloads = [];
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
    public function it_streams_each_volume_in_segment_chunks_and_appends_to_one_file(): void
    {
        $segments = array_map(
            static fn (int $segment): string => '<seg-'.$segment.'>',
            range(1, 150),
        );
        $archiveContents = null;
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')
            ->times(3)
            ->andReturnUsing(function (string $archivePath) use (&$archiveContents): array {
                $archiveContents = file_get_contents($archivePath);

                return [
                    'files' => [['name' => '00-group.nfo', 'size' => 12]],
                    'hasPassword' => false,
                ];
            });
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $source = new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: [$segments],
        );
        $result = $this->fetcher(
            $archive,
            maxRarParts: 6,
            downloadData: static fn (array $messageIds): string => implode(',', $messageIds).'|',
        )->fetch(
            $this->release(),
            $source,
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame([64, 64, 22], array_map('count', $this->downloads));
        $this->assertSame(
            implode(',', array_slice($segments, 0, 64)).'|'
                .implode(',', array_slice($segments, 64, 64)).'|'
                .implode(',', array_slice($segments, 128)).'|',
            $archiveContents,
        );
        $this->assertFileDoesNotExist($this->tmpPath.'audio-archive.rar');
    }

    #[Test]
    public function it_stops_mid_volume_once_the_first_track_is_whole(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->twice()->andReturn([
            'files' => [['name' => '01-track.flac', 'size' => 8]],
            'hasPassword' => false,
        ]);
        $extraction = 0;
        $archive->shouldReceive('extractSpecificFileToPath')
            ->twice()
            ->andReturnUsing(function () use (&$extraction): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, ++$extraction === 1 ? 'abc' : 'abcdefgh');

                return $path;
            });

        $source = new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: [
                array_map(static fn (int $segment): string => '<seg-'.$segment.'>', range(1, 150)),
                ['<vol-2>'],
            ],
        );
        $result = $this->fetcher($archive, 6)->fetch(
            $this->release(),
            $source,
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame([64, 64], array_map('count', $this->downloads));
        $this->assertSame('abcdefgh', file_get_contents((string) $result->path));
        $this->assertFileDoesNotExist($this->tmpPath.'audio-archive.rar');
    }

    #[Test]
    public function it_gives_up_at_the_byte_ceiling_before_appending(): void
    {
        $archiveBytes = [];
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')
            ->once()
            ->andReturnUsing(function (string $archivePath) use (&$archiveBytes): array {
                $archiveBytes[] = file_get_contents($archivePath);

                return ['files' => [], 'hasPassword' => false];
            });

        $source = new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: [[...range(1, 65)]],
        );
        $result = $this->fetcher(
            $archive,
            maxRarParts: 6,
            downloadData: static fn (): string => '123456',
            maxArchiveBytes: 10,
        )->fetch(
            $this->release(),
            $source,
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertFalse($result->declined);
        $this->assertStringContainsString('fetch ceiling', $result->reason);
        $this->assertSame(['123456'], $archiveBytes);
        $this->assertSame([64, 1], array_map('count', $this->downloads));
        $this->assertFileDoesNotExist($this->tmpPath.'audio-archive.rar');
    }

    #[Test]
    public function a_zero_ceiling_is_unlimited(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->twice()->andReturn([
            'files' => [],
            'hasPassword' => false,
        ]);

        $source = new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: [[...range(1, 65)]],
        );
        $result = $this->fetcher(
            $archive,
            maxRarParts: 6,
            downloadData: static fn (): string => '123456',
            maxArchiveBytes: null,
        )->fetch(
            $this->release(),
            $source,
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertStringNotContainsString('fetch ceiling', $result->reason);
        $this->assertSame([64, 1], array_map('count', $this->downloads));
    }

    #[Test]
    public function it_never_holds_more_than_one_chunk_in_memory(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->times(12)->andReturn([
            'files' => [],
            'hasPassword' => false,
        ]);
        $source = new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: [[...range(1, 64 * 12)]],
        );

        gc_collect_cycles();
        $peakBefore = memory_get_peak_usage(true);
        $this->fetcher(
            $archive,
            maxRarParts: 6,
            downloadData: static fn (): string => str_repeat('x', 1024 * 1024),
        )->fetch(
            $this->release(),
            $source,
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $peakBefore);
    }

    #[Test]
    public function it_stops_at_the_volume_that_completes_the_first_track(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn([
            'files' => [
                ['name' => '00-group.nfo', 'size' => 12],
                ['name' => '01-track.flac', 'size' => 8],
            ],
            'hasPassword' => false,
        ]);
        // The first volume yields a truncated track; the second completes it.
        $extraction = 0;
        $archive->shouldReceive('extractSpecificFileToPath')->twice()->andReturnUsing(
            function () use (&$extraction): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, ++$extraction === 1 ? 'abc' : 'abcdefgh');

                return $path;
            },
        );

        $result = $this->fetch($archive, volumes: 4);

        $this->assertTrue($result->succeeded());
        $this->assertSame('flac', $result->extension);
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertStringEqualsFile((string) $result->path, 'abcdefgh');
        $this->assertFileDoesNotExist($this->tmpPath.'audio-archive.rar');
    }

    #[Test]
    public function it_gives_up_at_the_configured_volume_ceiling(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn([
            'files' => [['name' => '01-track.flac', 'size' => 8]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')->andReturnUsing(function (): string {
            $path = $this->tmpPath.'01-track.flac';
            file_put_contents($path, 'abc');

            return $path;
        });

        $result = $this->fetch($archive, volumes: 6, maxRarParts: 2);

        $this->assertFalse($result->succeeded());
        $this->assertFalse($result->declined, 'Running out of volumes is a failure, not a routing decision.');
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertFileDoesNotExist($this->tmpPath.'audio-archive.rar');
    }

    #[Test]
    public function a_password_protected_archive_stops_immediately(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn(['files' => [], 'hasPassword' => true]);
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $result = $this->fetch($archive, volumes: 4);

        $this->assertFalse($result->succeeded());
        $this->assertSame([['<vol-1>']], $this->downloads);
        $this->assertFileDoesNotExist($this->tmpPath.'audio-archive.rar');
    }

    #[Test]
    public function an_archive_holding_only_side_cars_never_extracts_anything(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn([
            'files' => [['name' => 'album.cue', 'size' => 4], ['name' => '00-group.nfo', 'size' => 4]],
            'hasPassword' => false,
        ]);
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $this->assertFalse($this->fetch($archive, volumes: 2)->succeeded());
    }

    private function fetch(ArchiveExtractionService $archive, int $volumes, int $maxRarParts = 6): AudioFetchResult
    {
        $parts = [];
        foreach (range(1, $volumes) as $volume) {
            $parts[] = ['<vol-'.$volume.'>'];
        }

        $source = new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: $parts,
        );

        return $this->fetcher($archive, $maxRarParts)->fetch(
            $this->release(),
            $source,
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );
    }

    /**
     * @param  (callable(list<string>): string)|null  $downloadData
     */
    private function fetcher(
        ArchiveExtractionService $archive,
        int $maxRarParts,
        ?callable $downloadData = null,
        ?int $maxArchiveBytes = null,
    ): AudioFetcher {
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

        $audioContainer = new MediaInfoContainer;
        $audioContainer->add(new Audio);
        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->andReturn($audioContainer);
        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'mediaInfo'))->setValue($tools, $mediaInfo);

        return new AudioFetcher(
            $this->config($maxRarParts, $maxArchiveBytes),
            $downloadService,
            $archive,
            $tools,
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
            'debugMode' => false,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }

    private function release(): Release
    {
        $release = new Release;
        $release->id = 42;
        $release->guid = 'audio-guid';

        return $release;
    }
}
