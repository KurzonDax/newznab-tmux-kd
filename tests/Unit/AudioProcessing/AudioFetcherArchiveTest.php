<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Models\Release;
use App\Models\ReleaseFile;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AudioProcessing\AudioDecodableLengthProbe;
use App\Services\AudioProcessing\AudioFetcher;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use FFMpeg\Driver\FFProbeDriver;
use FFMpeg\FFProbe;
use Illuminate\Database\Eloquent\Collection;
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
        $this->assertFalse($result->archiveManifestComplete);
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
            'files' => [['name' => 'CD2/01-track.flac', 'size' => 8]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->once()
            ->with($this->partPath(1), 'CD2/01-track.flac', $this->tmpPath, true)
            ->andReturnUsing(function (): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, 'abcdefgh');

                return $path;
            });

        $result = $this->fetch($archive, volumes: 3);

        $this->assertTrue($result->succeeded());
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertSame(['00-group.nfo', 'CD2/01-track.flac'], array_column($result->archiveMembers, 'name'));
        $this->assertFalse($result->archiveManifestComplete);
        $this->assertTrue($result->sourceFileComplete);
        $this->assertTrue($result->sourceStartsAtZero);
        $this->assertTrue($result->wholeDurationReliable);
        $this->assertTrue($result->onlyOneTrackProbed);
        $this->assertSame('CD2/01-track.flac', $result->sampledFilename);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_selects_the_dsd_fixture_from_an_archive_listing(): void
    {
        $fixture = dirname(__DIR__, 2).'/Fixtures/Audio/dsd-tone.dsf';
        $this->assertFileExists($fixture);
        $name = basename($fixture);
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [
                ['name' => 'cover.jpg', 'size' => 100],
                ['name' => $name, 'size' => filesize($fixture)],
            ],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->once()
            ->with($this->partPath(1), $name, $this->tmpPath, true)
            ->andReturnUsing(function () use ($fixture, $name): string {
                $path = $this->tmpPath.$name;
                copy($fixture, $path);

                return $path;
            });

        $result = $this->fetch($archive, volumes: 1);

        $this->assertTrue($result->succeeded());
        $this->assertSame('dsf', $result->extension);
        $this->assertTrue($result->archiveManifestComplete);
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
        $this->assertFalse($result->sourceFileComplete);
        $this->assertFalse($result->wholeDurationReliable);
        $this->assertSame(45.0, $result->decodedDurationSeconds);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_rejects_a_short_partial_then_accepts_a_complete_extraction(): void
    {
        $archive = $this->archiveWithTrack(declaredSize: 8, extractedBodies: ['abc', 'abcdefgh']);
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(20.0);

        $result = $this->fetch($archive, volumes: 2, lengthProbe: $lengthProbe);

        $this->assertTrue($result->succeeded());
        $this->assertSame('abcdefgh', file_get_contents((string) $result->path));
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_short_first_track_advances_to_a_long_second_track(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [
                ['name' => '01-intro.flac', 'size' => 8],
                ['name' => '02-song.flac', 'size' => 8],
            ],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->twice()
            ->andReturnUsing(function (string $archivePath, string $name): string {
                $path = $this->tmpPath.$name;
                file_put_contents($path, 'abcdefgh');

                return $path;
            });
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(13.0, 42.0);
        $probedFilenames = [];

        $result = $this->fetcher($archive, lengthProbe: $lengthProbe)->fetch(
            $this->release(),
            $this->archiveSource([['<vol-1>']]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (
                MediaInfoContainer $container,
                string $sourceFilename,
            ) use (&$probedFilenames): void {
                $probedFilenames[] = $sourceFilename;
            },
        );

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame('02-song.flac', $result->sampledFilename);
        $this->assertSame(42.0, $result->decodedDurationSeconds);
        $this->assertFalse($result->onlyOneTrackProbed);
        $this->assertSame(['01-intro.flac'], $probedFilenames, 'Tags remain sourced from the first probed entry.');
        $this->assertFileDoesNotExist($this->tmpPath.'01-intro.flac');
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function two_short_tracks_choose_the_longer_first_track(): void
    {
        $archive = $this->archiveWithNamedTracks(['01-intro.flac', '02-applause.flac']);
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(20.0, 10.0);
        $probedFilenames = [];

        $result = $this->fetcher($archive, lengthProbe: $lengthProbe)->fetch(
            $this->release(),
            $this->archiveSource([['<vol-1>']]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (
                MediaInfoContainer $container,
                string $sourceFilename,
            ) use (&$probedFilenames): void {
                $probedFilenames[] = $sourceFilename;
            },
        );

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame('01-intro.flac', $result->sampledFilename);
        $this->assertSame(20.0, $result->decodedDurationSeconds);
        $this->assertFalse($result->onlyOneTrackProbed);
        $this->assertSame(['01-intro.flac'], $probedFilenames);
        $this->assertFileDoesNotExist($this->tmpPath.'02-applause.flac');
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_single_short_track_is_previewed_at_full_length(): void
    {
        $archive = $this->archiveWithNamedTracks(['01-intro.flac']);
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->once()->andReturn(13.0);

        $result = $this->fetch($archive, volumes: 1, lengthProbe: $lengthProbe);

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame('01-intro.flac', $result->sampledFilename);
        $this->assertSame(13.0, $result->decodedDurationSeconds);
        $this->assertTrue($result->onlyOneTrackProbed);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_short_stored_entry_advances_to_the_next_stored_audio_entry(): void
    {
        $files = [
            ['name' => '01-intro.flac', 'size' => 8, 'compressed' => 0, 'range' => '0-7'],
            ['name' => '02-song.flac', 'size' => 8, 'compressed' => 0, 'range' => '8-15'],
        ];
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => $files,
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('carveStoredFileChunkToPath')
            ->twice()
            ->andReturnUsing(function (string $archivePath, array $entry, string $outputPath): bool {
                file_put_contents($outputPath, 'abcdefgh');

                return true;
            });
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(13.0, 42.0);

        $result = $this->fetch($archive, volumes: 1, lengthProbe: $lengthProbe);

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame('02-song.flac', $result->sampledFilename);
        $this->assertSame(42.0, $result->decodedDurationSeconds);
        $this->assertFalse($result->onlyOneTrackProbed);
        $this->assertFileDoesNotExist($this->tmpPath.'01-intro.flac');
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
    public function extraction_that_stops_gaining_decodable_audio_settles_before_another_chunk_is_fetched(): void
    {
        $extractedBodies = ['first fragment', 'larger second fragment'];
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn([
            'files' => [['name' => '01-track.flac', 'size' => 10_000, 'compressed' => 1]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->twice()
            ->andReturnUsing(function () use (&$extractedBodies): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, array_shift($extractedBodies));

                return $path;
            });
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(5.0, 5.0);

        $result = $this->fetcher($archive, lengthProbe: $lengthProbe)->fetch(
            $this->release(),
            $this->archiveSource([
                array_map(static fn (int $segment): string => '<vol-1-'.$segment.'>', range(1, 129)),
            ]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(
            'Archive extraction stopped progressing after 128 fetched segments.',
            $result->reason,
        );
        $this->assertSame([64, 64], array_map('count', $this->downloads));
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function each_chunk_logs_progress_while_a_known_entry_reuses_its_listing(): void
    {
        $diagnostics = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('debug')
            ->twice()
            ->with('Audio archive chunk inspected', Mockery::type('array'))
            ->andReturnUsing(function (string $message, array $context) use (&$diagnostics): void {
                $diagnostics[] = $context;
            });
        Log::swap($logger);

        $extractedBodies = ['first', 'longer second'];
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [['name' => '01-track.flac', 'size' => 10_000, 'compressed' => 1]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->twice()
            ->andReturnUsing(function () use (&$extractedBodies): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, array_shift($extractedBodies));

                return $path;
            });
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(5.0, 42.0);

        $result = $this->fetcher(
            $archive,
            downloadData: static fn (array $messageIds): string => str_repeat('x', count($messageIds)),
            lengthProbe: $lengthProbe,
            debugMode: true,
        )->fetch(
            $this->release(),
            $this->archiveSource([
                array_map(static fn (int $segment): string => '<vol-1-'.$segment.'>', range(1, 129)),
            ]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame([64, 64], array_map('count', $this->downloads));
        $this->assertSame([
            [
                'release_id' => 42,
                'segments_fetched' => 64,
                'bytes_appended' => 64,
                'listing_entry_count' => 1,
                'fragment_bytes' => 5,
                'decodable_seconds' => 5.0,
            ],
            [
                'release_id' => 42,
                'segments_fetched' => 128,
                'bytes_appended' => 64,
                'listing_entry_count' => 1,
                'fragment_bytes' => 13,
                'decodable_seconds' => 42.0,
            ],
        ], $diagnostics);
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
    public function incomplete_sources_fail_before_any_article_is_downloaded(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldNotReceive('listArchiveContentsAtPath');

        $result = $this->fetch(
            $archive,
            volumes: 3,
            release: $this->release(completion: 7),
        );

        $this->assertSame('Source is only 7% complete.', $result->reason);
        $this->assertSame([], $this->downloads);
    }

    #[Test]
    public function an_unmeasured_zero_completion_source_remains_fetchable(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [],
            'hasPassword' => false,
        ]);

        $result = $this->fetch(
            $archive,
            volumes: 1,
            maxRarParts: 1,
            release: $this->release(completion: 0),
        );

        $this->assertSame('No usable audio file was found within 1 fetched archive volume(s).', $result->reason);
        $this->assertSame([['<vol-1>']], $this->downloads);
    }

    #[Test]
    public function a_zero_completion_threshold_preserves_archive_fetching(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [],
            'hasPassword' => false,
        ]);

        $result = $this->fetch(
            $archive,
            volumes: 1,
            maxRarParts: 1,
            release: $this->release(completion: 7),
            minimumCompletionPercent: 0,
        );

        $this->assertSame('No usable audio file was found within 1 fetched archive volume(s).', $result->reason);
        $this->assertSame([['<vol-1>']], $this->downloads);
    }

    #[Test]
    public function a_later_rar_volume_fails_after_its_header_is_listed(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [['name' => 'track.flac', 'size' => 100]],
            'hasPassword' => false,
            'isFirstVolume' => false,
        ]);
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $result = $this->fetch($archive, volumes: 3);

        $this->assertSame(
            'Archive set starts mid-volume; the first volume is not in this release.',
            $result->reason,
        );
        $this->assertSame([['<vol-1>']], $this->downloads);
    }

    #[Test]
    public function two_listed_video_only_volumes_decline_without_fetching_the_rest(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->twice()->andReturn(
            ['files' => [['name' => 'sample.nfo', 'size' => 12]], 'hasPassword' => false],
            [
                'files' => [['name' => 'feature.m2ts', 'size' => 500, 'split_after' => true]],
                'hasPassword' => false,
            ],
        );

        $result = $this->fetch($archive, volumes: 5);

        $this->assertTrue($result->declined);
        $this->assertSame('The archive holds no audio files (found: m2ts, nfo).', $result->reason);
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
    }

    #[Test]
    public function support_only_volumes_do_not_advance_the_early_non_audio_cutoff(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->times(5)->andReturn(
            ['files' => [['name' => 'installer.exe', 'size' => 12]], 'hasPassword' => false],
            ['files' => [['name' => 'readme.txt', 'size' => 20]], 'hasPassword' => false],
        );

        $result = $this->fetch($archive, volumes: 5);

        $this->assertFalse($result->declined);
        $this->assertSame('The archive holds no audio files (found: exe, txt).', $result->reason);
        $this->assertSame(
            [['<vol-1>'], ['<vol-2>'], ['<vol-3>'], ['<vol-4>'], ['<vol-5>']],
            $this->downloads,
        );
    }

    #[Test]
    public function artwork_and_text_only_volumes_do_not_hide_audio_in_a_later_volume(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn(
            ['files' => [['name' => 'front-cover.jpg', 'size' => 12]], 'hasPassword' => false],
            ['files' => [['name' => 'release.nfo', 'size' => 20]], 'hasPassword' => false],
            ['files' => [['name' => '01-track.flac', 'size' => 8]], 'hasPassword' => false],
        );
        $archive->shouldReceive('extractSpecificFileToPath')
            ->once()
            ->andReturnUsing(function (): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, 'abcdefgh');

                return $path;
            });

        $result = $this->fetch($archive, volumes: 3);

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame([['<vol-1>'], ['<vol-2>'], ['<vol-3>']], $this->downloads);
        $this->assertSame('01-track.flac', $result->sampledFilename);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function audio_after_artwork_is_not_accepted_until_the_full_preview_window_decodes(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn(
            ['files' => [['name' => 'front-cover.jpg', 'size' => 12]], 'hasPassword' => false],
            ['files' => [['name' => 'release.nfo', 'size' => 20]], 'hasPassword' => false],
            ['files' => [['name' => '01-track.flac', 'size' => 100]], 'hasPassword' => false],
            ['files' => [['name' => '01-track.flac', 'size' => 100]], 'hasPassword' => false],
        );
        $extractedBodies = ['short', 'long enough fragment'];
        $archive->shouldReceive('extractSpecificFileToPath')
            ->twice()
            ->andReturnUsing(function () use (&$extractedBodies): string {
                $path = $this->tmpPath.'01-track.flac';
                file_put_contents($path, array_shift($extractedBodies));

                return $path;
            });
        $lengthProbe = Mockery::mock(AudioDecodableLengthProbe::class);
        $lengthProbe->shouldReceive('demuxedSeconds')->twice()->andReturn(5.0, 42.0);

        $result = $this->fetch($archive, volumes: 4, lengthProbe: $lengthProbe);

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame(42.0, $result->decodedDurationSeconds);
        $this->assertSame(
            [['<vol-1>'], ['<vol-2>'], ['<vol-3>'], ['<vol-4>']],
            $this->downloads,
        );
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function artwork_only_archives_settle_with_found_extensions_when_the_volume_budget_is_exhausted(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->twice()->andReturn(
            ['files' => [['name' => 'front-cover.jpg', 'size' => 12]], 'hasPassword' => false],
            ['files' => [['name' => 'booklet.pdf', 'size' => 20]], 'hasPassword' => false],
        );

        $result = $this->fetch($archive, volumes: 5, maxRarParts: 2);

        $this->assertFalse($result->succeeded());
        $this->assertSame('The archive holds no audio files (found: jpg, pdf).', $result->reason);
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function artwork_only_archives_settle_with_found_extensions_when_the_byte_budget_is_exhausted(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [['name' => 'front-cover.jpg', 'size' => 12]],
            'hasPassword' => false,
        ]);

        $result = $this->fetcher(
            $archive,
            downloadData: static fn (): string => '123456',
            maxArchiveBytes: 10,
        )->fetch(
            $this->release(),
            $this->archiveSource([['<vol-1>'], ['<vol-2>']]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('The archive holds no audio files (found: jpg).', $result->reason);
        $this->assertSame([['<vol-1>'], ['<vol-2>']], $this->downloads);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_single_volume_non_audio_archive_reports_its_contents_at_source_exhaustion(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [['name' => 'installer.exe', 'size' => 12]],
            'hasPassword' => false,
        ]);

        $result = $this->fetch($archive, volumes: 1);

        $this->assertSame('The archive holds no audio files (found: exe).', $result->reason);
        $this->assertSame([['<vol-1>']], $this->downloads);
    }

    #[Test]
    public function a_non_audio_listing_does_not_claim_source_exhaustion_when_the_part_cap_stops_fetching(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [['name' => 'installer.exe', 'size' => 12]],
            'hasPassword' => false,
        ]);

        $result = $this->fetch($archive, volumes: 5, maxRarParts: 1);

        $this->assertSame('No usable audio file was found within 1 fetched archive volume(s).', $result->reason);
        $this->assertSame([['<vol-1>']], $this->downloads);
    }

    #[Test]
    public function known_audio_metadata_prevents_support_files_from_triggering_the_no_audio_cutoff(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn(
            ['files' => [['name' => 'release.nfo', 'size' => 12]], 'hasPassword' => false],
            ['files' => [['name' => 'cover.jpg', 'size' => 20]], 'hasPassword' => false],
            ['files' => [['name' => 'track.flac', 'size' => 8]], 'hasPassword' => false],
        );
        $archive->shouldReceive('extractSpecificFileToPath')
            ->once()
            ->andReturnUsing(function (): string {
                $path = $this->tmpPath.'track.flac';
                file_put_contents($path, 'abcdefgh');

                return $path;
            });

        $result = $this->fetch(
            $archive,
            volumes: 3,
            release: $this->releaseWithKnownAudio('track.flac', 8),
        );

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame([['<vol-1>'], ['<vol-2>'], ['<vol-3>']], $this->downloads);
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
    public function a_compressed_entry_that_cannot_fit_the_byte_budget_settles_from_its_header(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [[
                'name' => '01-track.flac',
                'size' => 100,
                'compressed' => 1,
                'range' => '5-5',
                'next_offset' => 20,
            ]],
            'hasPassword' => false,
        ]);
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $result = $this->fetcher(
            $archive,
            downloadData: static fn (): string => '123456',
            maxArchiveBytes: 10,
        )->fetch(
            $this->release(),
            $this->archiveSource([
                array_map(static fn (int $segment): string => '<vol-1-'.$segment.'>', range(1, 129)),
            ]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(
            'The archive exceeded the 1 MB fetch ceiling before a whole audio file was found.',
            $result->reason,
        );
        $this->assertSame([64], array_map('count', $this->downloads));
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
    public function it_seeks_to_a_stored_audio_header_and_carves_a_real_multivolume_flac(): void
    {
        $result = $this->fetcher(
            $this->realArchiveService(),
            maxRarParts: 4,
            downloadData: fn (array $messageIds): string => $this->fixtureVolume($messageIds[0]),
        )->fetch(
            $this->releaseWithKnownAudio('01-track.flac'),
            $this->archiveSource(array_map(
                static fn (int $volume): array => ['<store-'.$volume.'>'],
                range(1, 6),
            )),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame([
            ['<store-1>'],
            ['<store-4>'],
            ['<store-5>'],
            ['<store-6>'],
        ], $this->downloads);
        $this->assertSame('fLaC', file_get_contents((string) $result->path, false, null, 0, 4));
        $preview = (new AudioPreviewEncoder($this->previewConfig(), new MediaTools))->encode(
            (string) $result->path,
            'carved-audio',
            $this->tmpPath,
        );
        $this->assertNotNull($preview);
        $this->assertSame('flac', $preview->extension);
        $this->assertFileExists($this->tmpPath.'carved-audio.flac');
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_reports_when_a_known_stored_audio_header_is_beyond_the_fetched_volume_budget(): void
    {
        $result = $this->fetcher(
            $this->realArchiveService(),
            maxRarParts: 1,
            downloadData: fn (array $messageIds): string => $this->fixtureVolume($messageIds[0]),
        )->fetch(
            $this->releaseWithKnownAudio('01-track.flac'),
            $this->archiveSource(array_map(
                static fn (int $volume): array => ['<store-'.$volume.'>'],
                range(1, 6),
            )),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame([
            ['<store-1>'],
        ], $this->downloads);
        $this->assertSame(
            'Release metadata identifies 01-track.flac, but its stored data starts beyond the 1 fetched volume budget.',
            $result->reason,
        );
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_compressed_multivolume_archive_keeps_the_sequential_extraction_path(): void
    {
        $result = $this->fetcher(
            $this->realArchiveService(externalUnrar: true),
            maxRarParts: 4,
            downloadData: fn (array $messageIds): string => $this->fixtureVolume($messageIds[0]),
        )->fetch(
            $this->releaseWithKnownAudio('01-track.flac'),
            $this->archiveSource(array_map(
                static fn (int $volume): array => ['<compressed-'.$volume.'>'],
                range(1, 4),
            )),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame([
            ['<compressed-1>'],
            ['<compressed-2>'],
            ['<compressed-3>'],
            ['<compressed-4>'],
        ], $this->downloads);
        $this->assertSame('fLaC', file_get_contents((string) $result->path, false, null, 0, 4));
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_backfills_skipped_volumes_when_a_later_audio_entry_is_compressed(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $storedArtwork = [[
            'name' => '00-artwork.png',
            'size' => 29,
            'compressed' => 0,
            'range' => '0-3',
            'split_after' => 1,
        ]];
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->with($this->partPath(1))->andReturn([
            'files' => $storedArtwork,
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->with($this->partPath(4))->andReturn([
            'files' => [['name' => '01-track.flac', 'size' => 8, 'compressed' => 1]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->with($this->partPath(2))->andReturn([
            'files' => $storedArtwork,
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->with($this->partPath(3))->andReturn([
            'files' => $storedArtwork,
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
        $archive->shouldNotReceive('carveStoredFileChunkToPath');

        $result = $this->fetcher($archive, maxRarParts: 4)->fetch(
            $this->releaseWithKnownAudio('01-track.flac', 8),
            $this->archiveSource([
                ['<vol-1>'],
                ['<vol-2>'],
                ['<vol-3>'],
                array_map(static fn (int $segment): string => '<vol-4-'.$segment.'>', range(1, 65)),
                ['<vol-5>'],
            ]),
            $this->tmpPath,
            'alt.binaries.sounds.lossless',
            static function (): void {},
        );

        $this->assertTrue($result->succeeded(), $result->reason);
        $this->assertSame([1, 64, 1, 1, 1], array_map('count', $this->downloads));
        $this->assertSame(['<vol-4-65>'], $this->downloads[2]);
        $this->assertSame(['<vol-2>'], $this->downloads[3]);
        $this->assertSame(['<vol-3>'], $this->downloads[4]);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function a_known_audio_filename_does_not_claim_archive_evidence_when_downloads_fail(): void
    {
        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('download')->once()->andReturn([
            'success' => false,
            'data' => false,
        ]);
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldNotReceive('listArchiveContentsAtPath');

        $result = $this->fetch(
            $archive,
            volumes: 1,
            maxRarParts: 1,
            downloadService: $downloadService,
            release: $this->releaseWithKnownAudio('01-track.flac', 8),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('No usable audio file was found within 1 fetched archive volume(s).', $result->reason);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function an_adjacent_computed_store_target_reports_when_the_fetched_cap_is_exhausted(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [[
                'name' => '00-artwork.png',
                'size' => 4,
                'compressed' => 0,
                'range' => '0-3',
                'split_after' => 1,
            ]],
            'hasPassword' => false,
        ]);

        $result = $this->fetch(
            $archive,
            volumes: 2,
            maxRarParts: 1,
            release: $this->releaseWithKnownAudio('01-track.flac', 8),
        );

        $this->assertSame(
            'Release metadata identifies 01-track.flac, but its stored data starts beyond the 1 fetched volume budget.',
            $result->reason,
        );
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function source_exhaustion_does_not_claim_that_the_fetched_volume_cap_was_exhausted(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [[
                'name' => '00-artwork.png',
                'size' => 29,
                'compressed' => 0,
                'range' => '0-3',
                'split_after' => 1,
            ]],
            'hasPassword' => false,
        ]);

        $result = $this->fetch(
            $archive,
            volumes: 1,
            maxRarParts: 6,
            release: $this->releaseWithKnownAudio('01-track.flac', 8),
        );

        $this->assertSame('No usable audio file was found within 1 fetched archive volume(s).', $result->reason);
        $this->assertNoArchivePartsRemain();
    }

    #[Test]
    public function it_reports_a_distinct_reason_when_a_stored_audio_payload_cannot_be_carved(): void
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => [[
                'name' => '01-track.flac',
                'size' => 8,
                'compressed' => 0,
                'range' => '0-7',
            ]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('carveStoredFileChunkToPath')->once()->andReturn(false);
        $archive->shouldNotReceive('extractSpecificFileToPath');

        $result = $this->fetch($archive, volumes: 1);

        $this->assertFalse($result->succeeded());
        $this->assertSame(
            'The stored audio entry 01-track.flac was found, but its payload could not be carved.',
            $result->reason,
        );
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
        ?UsenetDownloadService $downloadService = null,
        ?Release $release = null,
        float $minimumCompletionPercent = 95,
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
            downloadService: $downloadService,
            minimumCompletionPercent: $minimumCompletionPercent,
        )->fetch(
            $release ?? $this->release(),
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

    /**
     * @param  list<string>  $names
     */
    private function archiveWithNamedTracks(array $names): ArchiveExtractionService
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
            'files' => array_map(
                static fn (string $name): array => ['name' => $name, 'size' => 8],
                $names,
            ),
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')
            ->times(count($names))
            ->andReturnUsing(function (string $archivePath, string $name): string {
                $path = $this->tmpPath.$name;
                file_put_contents($path, 'abcdefgh');

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
        float $minimumCompletionPercent = 95,
        bool $debugMode = false,
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
            $this->config($maxRarParts, $maxArchiveBytes, $minimumCompletionPercent, $debugMode),
            $downloadService,
            $archive,
            $tools,
            $lengthProbe,
        );
    }

    private function config(
        int $maxRarParts,
        ?int $maxArchiveBytes,
        float $minimumCompletionPercent = 95,
        bool $debugMode = false,
    ): AudioProcessingConfiguration {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'segmentsToDownload' => 12,
            'maxRarParts' => $maxRarParts,
            'maxArchiveBytes' => $maxArchiveBytes,
            'minimumCompletionPercent' => $minimumCompletionPercent,
            'previewSeconds' => 30,
            'previewStartSeconds' => 10,
            'debugMode' => $debugMode,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }

    private function previewConfig(): AudioProcessingConfiguration
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'previewSeconds' => 30,
            'previewStartSeconds' => 10,
            'spectrogram' => false,
            'savePath' => $this->tmpPath,
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

    private function realArchiveService(bool $externalUnrar = false): ArchiveExtractionService
    {
        $reflection = new ReflectionClass(ProcessingConfiguration::class);
        /** @var ProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();
        (new ReflectionProperty(ProcessingConfiguration::class, 'unrarPath'))->setValue(
            $config,
            $externalUnrar ? '/usr/bin/unrar' : false,
        );
        (new ReflectionProperty(ProcessingConfiguration::class, 'unzipPath'))->setValue($config, false);
        (new ReflectionProperty(ProcessingConfiguration::class, 'timeoutPath'))->setValue($config, false);
        (new ReflectionProperty(ProcessingConfiguration::class, 'timeoutSeconds'))->setValue($config, 0);
        (new ReflectionProperty(ProcessingConfiguration::class, 'debugMode'))->setValue($config, false);

        return new ArchiveExtractionService($config);
    }

    private function fixtureVolume(string $messageId): string
    {
        preg_match('/<(store|compressed)-(\d+)>/', $messageId, $matches);
        $path = $matches[1] === 'store'
            ? dirname(__DIR__, 2).'/Fixtures/Audio/rar-seek/store-seek.part'.$matches[2].'.rar'
            : dirname(__DIR__, 2).'/Fixtures/Audio/rar-compressed/compressed.part'.$matches[2].'.rar';

        return (string) file_get_contents($path);
    }

    private function partPath(int $volume): string
    {
        return $this->tmpPath.'audio-archive.part'.sprintf('%03d', $volume).'.rar';
    }

    private function assertNoArchivePartsRemain(): void
    {
        $this->assertSame([], glob($this->tmpPath.'audio-archive.part*.rar') ?: []);
    }

    private function release(float $completion = 100): Release
    {
        $release = new Release;
        $release->id = 42;
        $release->guid = 'audio-guid';
        $release->completion = $completion;

        return $release;
    }

    private function releaseWithKnownAudio(string $name, int $size = 0): Release
    {
        $release = $this->release();
        $releaseFile = new ReleaseFile;
        $releaseFile->name = $name;
        $releaseFile->size = $size;
        $release->setRelation('file', new Collection([$releaseFile]));

        return $release;
    }
}
