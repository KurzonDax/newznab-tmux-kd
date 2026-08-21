<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ReleaseImageService;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The preview clip's extension follows the source codec and the spectrogram is a
 * separate file, so deleting a release can no longer delete one fixed name.
 */
class AudioPreviewArtifactCleanupTest extends TestCase
{
    private ReleaseImageService $service;

    private string $audioPath;

    private string $previewPath;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->makeTempDirectory('nntmux-covers');
        $this->audioPath = $root.'/audiosample/';
        $this->previewPath = $root.'/preview/';

        foreach (['audiosample', 'preview', 'sample', 'video'] as $directory) {
            mkdir($root.'/'.$directory, 0775, true);
        }

        $this->service = new ReleaseImageService;
        foreach ([
            'audSavePath' => $this->audioPath,
            'imgSavePath' => $this->previewPath,
            'jpgSavePath' => $root.'/sample/',
            'vidSavePath' => $root.'/video/',
        ] as $property => $value) {
            (new ReflectionProperty(ReleaseImageService::class, $property))->setValue($this->service, $value);
        }
    }

    public function test_it_removes_every_audio_artifact_whatever_the_container(): void
    {
        $this->write($this->audioPath.'abc123.flac');
        $this->write($this->audioPath.'abc123_spectrum.png');
        // Written by the retired Vorbis path; installs upgraded in place still hold these.
        $this->write($this->audioPath.'abc123.ogg');

        $this->service->delete('abc123');

        $this->assertFileDoesNotExist($this->audioPath.'abc123.flac');
        $this->assertFileDoesNotExist($this->audioPath.'abc123_spectrum.png');
        $this->assertFileDoesNotExist($this->audioPath.'abc123.ogg');
    }

    public function test_it_leaves_another_release_alone(): void
    {
        $this->write($this->audioPath.'abc123.mp3');
        $this->write($this->audioPath.'def456.mp3');
        $this->write($this->previewPath.'def456_thumb.jpg');

        $this->service->delete('abc123');

        $this->assertFileDoesNotExist($this->audioPath.'abc123.mp3');
        $this->assertFileExists($this->audioPath.'def456.mp3');
        $this->assertFileExists($this->previewPath.'def456_thumb.jpg');
    }

    public function test_a_guid_carrying_glob_metacharacters_matches_nothing(): void
    {
        $this->write($this->audioPath.'abc123.mp3');

        $this->service->delete('*');

        $this->assertFileExists($this->audioPath.'abc123.mp3');
    }

    private function write(string $path): void
    {
        file_put_contents($path, 'artifact');
    }
}
