<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use GdImage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * An Extracted Sample Image is stored twice (ADR 0012): the display thumb the
 * pipeline has always written, plus the Full-size copy under the bare guid.
 */
class ExtractedSampleImageStorageTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $coversRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'image.default' => 'gd',
            'image.output_format' => 'webp',
            'image.output_quality' => 82,
            'image.full_max_width' => 1920,
            'image.full_max_height' => 1920,
            'image.full_output_quality' => 90,
            'image.extracted_max_source_bytes' => 100 * 1024 * 1024,
            'image.extracted_max_source_pixels' => 120_000_000,
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->integer('jpgstatus')->default(0);
        });
        DB::table('releases')->insert(['id' => 1, 'guid' => 'sampleguid', 'jpgstatus' => 0]);

        $this->coversRoot = $this->makeTempDirectory('nntmux-extracted-covers');
        config(['nntmux_settings.covers_path' => $this->coversRoot]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_jpg_sample_is_stored_as_a_thumb_and_a_full_size_copy(): void
    {
        $source = $this->createPng(2600, 1300);

        $this->assertTrue($this->makeService()->getJPGSample($source, 'sampleguid'));

        $this->assertImageDimensions($this->coversRoot.'/sample/sampleguid_thumb.webp', 650, 325);
        $this->assertImageDimensions($this->coversRoot.'/sample/sampleguid.webp', 1920, 960);
        $this->assertSame(1, (int) DB::table('releases')->where('guid', 'sampleguid')->value('jpgstatus'));
    }

    public function test_a_source_the_old_limits_rejected_now_yields_both_files(): void
    {
        // The pre-ADR-0012 rejection lines, which no longer apply to imagery
        // extracted from a release.
        config([
            'image.max_source_bytes' => 1024,
            'image.max_source_pixels' => 100_000,
        ]);
        $source = $this->createPng(3000, 1500);

        $this->assertTrue($this->makeService()->getJPGSample($source, 'sampleguid'));

        $this->assertFileExists($this->coversRoot.'/sample/sampleguid_thumb.webp');
        $this->assertImageDimensions($this->coversRoot.'/sample/sampleguid.webp', 1920, 960);
    }

    public function test_a_source_over_the_hard_ceiling_is_refused_outright(): void
    {
        config(['image.extracted_max_source_pixels' => 10_000]);
        $source = $this->createPng(2000, 1000);

        $this->assertFalse($this->makeService()->getJPGSample($source, 'sampleguid'));

        $this->assertFileDoesNotExist($this->coversRoot.'/sample/sampleguid_thumb.webp');
        $this->assertFileDoesNotExist($this->coversRoot.'/sample/sampleguid.webp');
        $this->assertSame(0, (int) DB::table('releases')->where('guid', 'sampleguid')->value('jpgstatus'));
    }

    public function test_deleting_a_release_removes_both_stored_copies(): void
    {
        $service = new ReleaseImageService;
        $this->assertTrue((new MediaExtractionService(
            $this->makeConfig(),
            $service,
            Mockery::mock(ReleaseExtraService::class),
            new VideoFrameExtractor($this->makeConfig()),
        ))->getJPGSample($this->createPng(800, 400), 'sampleguid'));

        $service->delete('sampleguid');

        $this->assertFileDoesNotExist($this->coversRoot.'/sample/sampleguid_thumb.webp');
        $this->assertFileDoesNotExist($this->coversRoot.'/sample/sampleguid.webp');
    }

    private function makeService(): MediaExtractionService
    {
        $config = $this->makeConfig();

        return new MediaExtractionService(
            $config,
            new ReleaseImageService,
            Mockery::mock(ReleaseExtraService::class),
            new VideoFrameExtractor($config),
        );
    }

    private function createPng(int $width, int $height): string
    {
        $path = $this->makeTempPath('nntmux-extracted-source').'.png';
        File::ensureDirectoryExists(dirname($path));
        $image = imagecreatetruecolor($width, $height);

        $this->assertInstanceOf(GdImage::class, $image);

        imagefill($image, 0, 0, imagecolorallocate($image, 50, 100, 150));
        imagepng($image, $path);

        return $path;
    }

    private function assertImageDimensions(string $path, int $width, int $height): void
    {
        $this->assertFileExists($path);
        $dimensions = getimagesize($path);

        $this->assertIsArray($dimensions);
        $this->assertSame($width, $dimensions[0]);
        $this->assertSame($height, $dimensions[1]);
    }
}
