<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AudioProcessing\ChromaprintCapabilityProbe;
use App\Services\AudioProcessing\FfmpegChromaprintGenerator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Exercises the real FFmpeg Chromaprint muxer on generated local audio. It
 * skips itself where FFmpeg or its Chromaprint support is absent, and runs
 * wherever both are installed.
 */
final class AcousticFingerprintFfmpegTest extends TestCase
{
    private string $ffmpeg = '';

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $finder = new Process(['sh', '-c', 'command -v ffmpeg']);
        $finder->run();
        $ffmpeg = trim($finder->getOutput());
        if (! $finder->isSuccessful() || $ffmpeg === '') {
            $this->markTestSkipped('ffmpeg is required for this test.');
        }
        if (! (new ChromaprintCapabilityProbe($ffmpeg, 30))->probe()->available) {
            $this->markTestSkipped('ffmpeg was built without the Chromaprint muxer.');
        }

        $this->ffmpeg = $ffmpeg;
        $this->directory = $this->makeTempDirectory('acoustic-fingerprint');
    }

    #[Test]
    public function a_complete_track_is_fingerprinted_from_its_first_120_seconds_only(): void
    {
        $complete = $this->audio('complete.flac', 150);
        $firstWindow = $this->audio('first-window.flac', 150, ['-t', '120']);
        $previewLike = $this->audio('preview-like.flac', 150, ['-ss', '10']);

        $fingerprint = $this->generator()->generate($complete);

        $this->assertNotNull($fingerprint);
        $this->assertSame(2, $fingerprint->algorithm);
        $this->assertStringStartsWith('AQ', $fingerprint->fingerprint);
        $this->assertSame(hash('sha256', $fingerprint->fingerprint), $fingerprint->hash);
        $this->assertStringStartsWith('ffmpeg-chromaprint-120s-v1 ffmpeg/', $fingerprint->generatorVersion);
        // Audio after the first 120 seconds does not change the fingerprint,
        // while audio that does not start at time zero does.
        $this->assertSame($fingerprint->hash, $this->generator()->generate($firstWindow)?->hash);
        $this->assertNotSame($fingerprint->hash, $this->generator()->generate($previewLike)?->hash);
    }

    #[Test]
    public function a_multichannel_track_is_downmixed_rather_than_rejected(): void
    {
        $fingerprint = $this->generator()->generate($this->audio('surround.flac', 30, ['-ac', '6']));

        $this->assertNotNull($fingerprint);
        $this->assertStringStartsWith('AQ', $fingerprint->fingerprint);
    }

    #[Test]
    public function an_undecodable_file_yields_no_fingerprint(): void
    {
        $path = $this->directory.'/damaged.flac';
        file_put_contents($path, str_repeat("\x00\xFFnot audio", 500));

        $this->assertNull($this->generator()->generate($path));
    }

    private function generator(): FfmpegChromaprintGenerator
    {
        return new FfmpegChromaprintGenerator(new ChromaprintCapabilityProbe($this->ffmpeg, 30), 60);
    }

    /**
     * Two gliding tones give Chromaprint changing pitch content to work with.
     *
     * @param  list<string>  $outputOptions
     */
    private function audio(string $name, int $seconds, array $outputOptions = []): string
    {
        $source = $this->directory.'/source-'.$seconds.'.flac';
        if (! is_file($source)) {
            $this->ffmpegRun([
                '-f', 'lavfi',
                '-i', 'aevalsrc=sin(2*PI*(220+4*t)*t)+0.5*sin(2*PI*(660-2*t)*t):s=44100:d='.$seconds,
                '-ac', '2', '-c:a', 'flac', $source,
            ]);
        }

        $path = $this->directory.'/'.$name;
        $seek = array_search('-ss', $outputOptions, true);
        $input = $seek === false ? ['-i', $source] : ['-ss', $outputOptions[$seek + 1], '-i', $source];
        if ($seek !== false) {
            array_splice($outputOptions, $seek, 2);
        }
        $this->ffmpegRun([...$input, ...$outputOptions, '-c:a', 'flac', $path]);

        return $path;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function ffmpegRun(array $arguments): void
    {
        $process = new Process([$this->ffmpeg, '-hide_banner', '-nostdin', '-v', 'error', '-y', ...$arguments]);
        $process->setTimeout(120);
        $process->mustRun();
    }
}
