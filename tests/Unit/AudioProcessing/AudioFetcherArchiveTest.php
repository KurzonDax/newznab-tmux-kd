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
    public function it_stops_at_the_volume_that_completes_the_first_track(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContents')->andReturn([
            'files' => [
                ['name' => '00-group.nfo', 'size' => 12],
                ['name' => '01-track.flac', 'size' => 8],
            ],
            'hasPassword' => false,
        ]);
        // The first volume yields a truncated track; the second completes it.
        $archive->shouldReceive('extractSpecificFile')->twice()->andReturn('abc', 'abcdefgh');

        $result = $this->fetch($archive, volumes: 4);

        $this->assertTrue($result->succeeded());
        $this->assertSame('flac', $result->extension);
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertStringEqualsFile((string) $result->path, 'abcdefgh');
    }

    #[Test]
    public function it_gives_up_at_the_configured_volume_ceiling(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContents')->andReturn([
            'files' => [['name' => '01-track.flac', 'size' => 8]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFile')->andReturn('abc');

        $result = $this->fetch($archive, volumes: 6, maxRarParts: 2);

        $this->assertFalse($result->succeeded());
        $this->assertFalse($result->declined, 'Running out of volumes is a failure, not a routing decision.');
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
    }

    #[Test]
    public function a_password_protected_archive_stops_immediately(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContents')->once()->andReturn(['files' => [], 'hasPassword' => true]);
        $archive->shouldNotReceive('extractSpecificFile');

        $result = $this->fetch($archive, volumes: 4);

        $this->assertFalse($result->succeeded());
        $this->assertSame([['<vol-1>']], $this->downloads);
    }

    #[Test]
    public function an_archive_holding_only_side_cars_never_extracts_anything(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContents')->andReturn([
            'files' => [['name' => 'album.cue', 'size' => 4], ['name' => '00-group.nfo', 'size' => 4]],
            'hasPassword' => false,
        ]);
        $archive->shouldNotReceive('extractSpecificFile');

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

    private function fetcher(ArchiveExtractionService $archive, int $maxRarParts): AudioFetcher
    {
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('download')->andReturnUsing(
            function (mixed $kind, array $messageIds): array {
                $this->downloads[] = array_values($messageIds);

                return ['success' => true, 'data' => 'volume-bytes', 'groupUnavailable' => false, 'error' => null];
            }
        );

        $audioContainer = new MediaInfoContainer;
        $audioContainer->add(new Audio);
        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->andReturn($audioContainer);
        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'mediaInfo'))->setValue($tools, $mediaInfo);

        return new AudioFetcher($this->config($maxRarParts), $downloadService, $archive, $tools);
    }

    private function config(int $maxRarParts): AudioProcessingConfiguration
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'segmentsToDownload' => 12,
            'maxRarParts' => $maxRarParts,
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
