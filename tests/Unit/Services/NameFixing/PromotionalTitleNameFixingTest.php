<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Facades\Search;
use App\Models\Category;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\ReleaseUpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Download-site advertising carried as an embedded title or a filename is
 * never accepted as a release name, whatever source presents it.
 */
class PromotionalTitleNameFixingTest extends TestCase
{
    private const HASHED_NAME = '5da7b5393d4f4445ac4db1ee8e95f567';

    private const READABLE_NAME = 'Studio - Example Feature';

    #[DataProvider('promotionalMediaTitles')]
    public function test_the_media_movie_name_check_declines_a_promotional_title(string $title): void
    {
        foreach ([[self::READABLE_NAME, Category::XXX_OTHER], [self::HASHED_NAME, Category::OTHER_HASHED]] as [$currentName, $category]) {
            [$service, $updater] = $this->mediaService();

            $accepted = (new ReflectionClass(NameFixingService::class))->getMethod('mediaMovieNameCheck')
                ->invoke($service, $this->release($currentName, $category, $title), false, 'Mediainfo, ', true, false);

            $this->assertFalse($accepted, "{$title} renamed {$currentName}");
            $this->assertFalse($updater->matched);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function promotionalMediaTitles(): array
    {
        return [
            'download attribution' => ['Downloaded from example.org'],
            'video branding' => ['example.org - HEVC x265 Video Downloads'],
            'porn branding' => ['example.org - HEVC x265 Porn Downloads'],
            'quoted and spaced' => [' "downloaded  FROM example.org" '],
            'https with a trailing slash' => ['Downloaded from https://example.org/'],
            'attribution to a name' => ['Downloaded From ExampleCinemas'],
            'encoded by a name' => ['Encoded By SomeName'],
            'ripped by a name and team' => ['Ripped By SomeName & Team'],
        ];
    }

    public function test_a_scene_name_after_a_site_prefix_is_still_extracted(): void
    {
        [$service, $updater] = $this->mediaService();

        $accepted = (new ReflectionClass(NameFixingService::class))->getMethod('mediaMovieNameCheck')->invoke(
            $service,
            $this->release(self::HASHED_NAME, Category::OTHER_HASHED, 'Example.site | Visible.Release.2026.1080p.10bit.WEBRip.6CH.x265.HEVC-GROUP'),
            false,
            'Mediainfo, ',
            true,
            false,
        );

        $this->assertTrue($accepted);
        $this->assertTrue($updater->matched);
    }

    #[DataProvider('promotionalCandidates')]
    public function test_the_updater_rejects_a_promotional_candidate_from_any_source(
        string $candidate,
        string $method,
        string $type,
        int $preId,
    ): void {
        foreach ([[self::READABLE_NAME, Category::XXX_OTHER], [self::HASHED_NAME, Category::OTHER_HASHED]] as [$currentName, $category]) {
            $updater = $this->updater();

            $updater->updateRelease($this->release($currentName, $category), $candidate, $method, false, $type, true, false, $preId);

            $this->assertFalse($updater->matched, "{$type}{$method} accepted {$candidate} over {$currentName}");
            $this->assertTrue($updater->done);
            $this->assertSame(0, $updater->fixed);
        }
    }

    /**
     * @return array<string, array{string, string, string, int}>
     */
    public static function promotionalCandidates(): array
    {
        return [
            'embedded title' => ['Downloaded from example.org', 'MediaInfo: Movie Name', 'Mediainfo, ', 0],
            'embedded title with a PreDB id' => ['example.org - HEVC x265 Video Downloads', 'MediaInfo: Movie Name', 'Mediainfo, ', 77],
            'UID donor' => ['example.org - HEVC x265 Porn Downloads', 'uidCheck: Unique_ID group election', 'UID, ', 77],
            'PAR2 hash donor' => ['Downloaded from example 1080p WEB-DL.org', 'hashCheck: PAR2 hash_16K', 'PAR2 hash, ', 0],
            'CRC32 donor' => ['example.org - HEVC x265 Video Downloads 1080p WEB-DL', 'crcCheck: CRC32', 'CRC32, ', 0],
            'PreDB title match' => ['Downloaded from https://example.org/', 'preDB: Title Match', 'PreDB FT Exact, ', 77],
            'filename' => ['example.org - HEVC x265 Porn Downloads.mp4', 'fileCheck: Filename', 'Filenames, ', 0],
        ];
    }

    public function test_an_uncorroborated_media_title_does_not_make_a_trusted_donor(): void
    {
        $policy = (new ReflectionClass(ReleaseUpdateService::class))->getMethod('sourceTrustPolicy');
        $updater = $this->updater();

        $this->assertFalse($policy->invoke($updater, 'Mediainfo, ', 'MediaInfo: Movie Name', 0)['trusted_donor']);
        $this->assertTrue($policy->invoke($updater, 'Mediainfo, ', 'MediaInfo: Movie Name', 77)['trusted_donor']);
    }

    public function test_a_promotional_video_filename_is_not_a_descriptive_title(): void
    {
        Search::shouldReceive('searchPredb')->andReturn([]);
        $updater = $this->updater();
        $service = $this->serviceWith($updater);
        $release = $this->release('5da7b5393d4f4445ac4db1ee8e95f567 1080p WEB-DL', Category::OTHER_HASHED);
        $release->name = 'Example.Upload.1080p.WEB-DL.x264';

        (new ReflectionClass(NameFixingService::class))->getMethod('processFileCandidates')->invoke(
            $service,
            $release,
            [(object) ['textstring' => 'example.org - HEVC x265 Porn Downloads.mp4']],
            false,
            true,
            false,
            false,
            false,
        );

        $this->assertFalse($updater->matched);
        $this->assertSame(0, $updater->fixed);
    }

    /**
     * @return array{NameFixingService, ReleaseUpdateService}
     */
    private function mediaService(): array
    {
        $updater = $this->updater();

        return [$this->serviceWith($updater), $updater];
    }

    private function serviceWith(ReleaseUpdateService $updater): NameFixingService
    {
        $cleaner = new FileNameCleaner;
        $reflection = new ReflectionClass(NameFixingService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('updateService')->setValue($service, $updater);
        $reflection->getProperty('fileNameCleaner')->setValue($service, $cleaner);
        $reflection->getProperty('fileExtractor')->setValue($service, new FileNameExtractor);
        $reflection->getProperty('filePrioritizer')->setValue($service, new FilePrioritizer);
        $reflection->getProperty('predbMatchSelector')->setValue($service, new PredbMatchSelector($cleaner));
        $reflection->getProperty('descriptiveTitleRenameEnabled')->setValue($service, true);

        return $service;
    }

    private function updater(): ReleaseUpdateService
    {
        $reflection = new ReflectionClass(ReleaseUpdateService::class);
        $updater = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('fileNameCleaner')->setValue($updater, new FileNameCleaner);
        $reflection->getProperty('echoOutput')->setValue($updater, false);

        return $updater;
    }

    private function release(string $searchName, int $categoryId, ?string $movieName = null): object
    {
        return (object) [
            'releases_id' => 1,
            'predb_id' => 0,
            'categories_id' => $categoryId,
            'searchname' => $searchName,
            'name' => '"'.$searchName.'.nzb" yEnc',
            'file_name' => $searchName,
            'movie_name' => $movieName,
            'relsize' => 1_000_000,
        ];
    }
}
