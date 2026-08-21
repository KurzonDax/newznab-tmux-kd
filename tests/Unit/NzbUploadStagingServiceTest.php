<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\NzbUploadException;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\Nzb\NzbUploadStagingService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class NzbUploadStagingServiceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $uploadFolder;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'innerfileblacklist' => '',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->uploadFolder = sys_get_temp_dir().'/nntmux-nzb-upload-'.bin2hex(random_bytes(6));
        config(['nntmux.nzb_upload_folder' => $this->uploadFolder]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->uploadFolder);

        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_stages_an_nzb_and_arbitrarily_named_nfo_with_a_manifest(): void
    {
        $result = (new NzbUploadStagingService(new Filesystem))->stage(
            $this->nzb('Release.nzb'),
            UploadedFile::fake()->createWithContent('scene-info.nfo', 'release information'),
        );

        $uploadDirectory = $this->onlyUploadDirectory();
        $manifest = json_decode(
            (string) file_get_contents($uploadDirectory.'/'.NzbUploadManifestService::FILENAME),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('Release', $result['name']);
        $this->assertSame('Release.nzb', $result['files']['nzb']['filename']);
        $this->assertSame('scene-info.nfo', $result['files']['nfo']['filename']);
        $this->assertFileExists($uploadDirectory.'/Release.nzb');
        $this->assertFileExists($uploadDirectory.'/scene-info.nfo');
        $this->assertSame(NzbUploadManifestService::STATE_STAGED, $manifest['state']);
        $this->assertSame('Release.nzb', $manifest['nzb']['filename']);
        $this->assertSame('scene-info.nfo', $manifest['nfo']['filename']);
        $this->assertSame(basename($uploadDirectory), $manifest['upload_id']);
    }

    public function test_it_stages_an_nzb_without_an_nfo(): void
    {
        $result = (new NzbUploadStagingService(new Filesystem))->stage($this->nzb('Solo.nzb'));

        $uploadDirectory = $this->onlyUploadDirectory();

        $this->assertNull($result['files']['nfo']);
        $this->assertFileExists($uploadDirectory.'/Solo.nzb');
        $this->assertFileExists($uploadDirectory.'/'.NzbUploadManifestService::FILENAME);
    }

    public function test_it_isolates_repeated_generic_nfo_names(): void
    {
        $service = new NzbUploadStagingService(new Filesystem);
        $service->stage(
            $this->nzb('First.nzb'),
            UploadedFile::fake()->createWithContent('info.nfo', 'first release information'),
        );
        $service->stage(
            $this->nzb('Second.nzb'),
            UploadedFile::fake()->createWithContent('info.nfo', 'second release information'),
        );

        $directories = (new Filesystem)->directories($this->uploadFolder);
        $this->assertCount(2, $directories);
        foreach ($directories as $directory) {
            $this->assertFileExists($directory.'/info.nfo');
        }
    }

    public function test_it_rejects_an_invalid_nzb_payload(): void
    {
        $this->expectException(NzbUploadException::class);
        $this->expectExceptionMessage('Invalid NZB payload');

        (new NzbUploadStagingService(new Filesystem))->stage(
            UploadedFile::fake()->createWithContent('Release.nzb', 'not xml'),
        );
    }

    public function test_it_rejects_an_oversized_nfo(): void
    {
        $this->expectException(NzbUploadException::class);
        $this->expectExceptionMessage('NFO upload must not be empty or exceed 65535 bytes');

        (new NzbUploadStagingService(new Filesystem))->stage(
            $this->nzb('Release.nzb'),
            UploadedFile::fake()->createWithContent('Release.nfo', str_repeat('x', 65536)),
        );
    }

    public function test_it_rolls_back_the_nzb_when_the_nfo_write_fails(): void
    {
        $filesystem = new class extends Filesystem
        {
            public function put($path, $contents, $lock = false): int|bool
            {
                if (str_ends_with((string) $path, '.nfo')) {
                    return false;
                }

                return parent::put($path, $contents, $lock);
            }
        };

        $this->expectException(NzbUploadException::class);
        $this->expectExceptionMessage('Failed to write Release.nfo to disk');

        (new NzbUploadStagingService($filesystem))->stage(
            $this->nzb('Release.nzb'),
            UploadedFile::fake()->createWithContent('Release.nfo', 'release information'),
        );
    }

    public function test_rollback_removes_the_partial_upload_directory(): void
    {
        $filesystem = new class extends Filesystem
        {
            public function put($path, $contents, $lock = false): int|bool
            {
                return str_ends_with((string) $path, '.nfo')
                    ? false
                    : parent::put($path, $contents, $lock);
            }
        };

        try {
            (new NzbUploadStagingService($filesystem))->stage(
                $this->nzb('Release.nzb'),
                UploadedFile::fake()->createWithContent('Release.nfo', 'release information'),
            );
            $this->fail('Expected staged pair write to fail.');
        } catch (NzbUploadException) {
            $this->assertSame([], $filesystem->directories($this->uploadFolder));
        }
    }

    private function nzb(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            '<?xml version="1.0"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"></nzb>',
        );
    }

    private function onlyUploadDirectory(): string
    {
        $directories = (new Filesystem)->directories($this->uploadFolder);
        $this->assertCount(1, $directories);

        return $directories[0];
    }
}
