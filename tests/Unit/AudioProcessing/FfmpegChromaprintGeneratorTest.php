<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Services\AudioProcessing\ChromaprintCapabilityProbe;
use App\Services\AudioProcessing\FfmpegChromaprintGenerator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Controlled process outputs for the FFmpeg Chromaprint generator. The real
 * binary is exercised by AcousticFingerprintFfmpegTest where it is installed.
 */
final class FfmpegChromaprintGeneratorTest extends TestCase
{
    // Compressed fingerprints start with their algorithm byte: 0x01 is
    // Chromaprint algorithm 2, the default fpcalc and AcoustID use.
    private const string FINGERPRINT = 'AQADtEmUaEkSZSoAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const string MUXERS = "  E chromaprint     Chromaprint\n";

    private const string BANNER = "ffmpeg version 6.1.1-3ubuntu5 Copyright (c) 2000-2023 the FFmpeg developers\n";

    private string $ffmpeg;

    private string $source;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ffmpeg = $this->makeTempPath('fake-ffmpeg');
        file_put_contents($this->ffmpeg, "#!/bin/sh\nexit 0\n");
        chmod($this->ffmpeg, 0755);
        $this->source = $this->makeTempPath('fingerprint-source', '.flac');
        file_put_contents($this->source, 'flac');
    }

    #[Test]
    public function it_fingerprints_the_first_120_seconds_from_time_zero_with_algorithm_two(): void
    {
        $this->fakeFfmpeg(Process::result(self::FINGERPRINT."\n"));

        $fingerprint = $this->generator()->generate($this->source);

        $this->assertNotNull($fingerprint);
        $this->assertSame(self::FINGERPRINT, $fingerprint->fingerprint);
        $this->assertSame(hash('sha256', self::FINGERPRINT), $fingerprint->hash);
        $this->assertSame(2, $fingerprint->algorithm);
        $this->assertSame('ffmpeg-chromaprint-120s-v1 ffmpeg/6.1.1-3ubuntu5', $fingerprint->generatorVersion);

        $command = $this->commands[1];
        $this->assertSame($this->ffmpeg, $command[0]);
        $this->assertNotContains('-ss', $command);
        $this->assertSame($this->source, $command[array_search('-i', $command, true) + 1]);
        $this->assertSame('120', $command[array_search('-t', $command, true) + 1]);
        $this->assertSame('0:a:0', $command[array_search('-map', $command, true) + 1]);
        $this->assertSame('chromaprint', $command[array_search('-f', $command, true) + 1]);
        // FFmpeg passes Chromaprint's zero-based enum through: 1 is algorithm 2.
        $this->assertSame('1', $command[array_search('-algorithm', $command, true) + 1]);
        $this->assertSame('base64', $command[array_search('-fp_format', $command, true) + 1]);
        $this->assertSame('-', $command[array_key_last($command)]);
    }

    #[Test]
    public function a_failed_process_that_emitted_a_fingerprint_yields_none(): void
    {
        $this->fakeFfmpeg(Process::result(self::FINGERPRINT, 'Error while decoding stream #0:0', 3));

        $this->assertNull($this->generator()->generate($this->source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidOutputs(): array
    {
        return [
            'empty' => [''],
            'not base64' => ["Fingerprint: AQAD tEmU\n"],
            'standard alphabet' => ['AQADtEmU+aEkS/ZSoAAAA'],
            'too short to hold a header' => ['AQA'],
            'wrong algorithm byte' => ['AgADtEmUaEkSZSoAAAAA'],
        ];
    }

    #[Test]
    #[DataProvider('invalidOutputs')]
    public function a_successful_process_with_invalid_output_yields_none(string $output): void
    {
        $this->fakeFfmpeg(Process::result($output));

        $this->assertNull($this->generator()->generate($this->source));
    }

    #[Test]
    public function a_build_without_chromaprint_never_runs_the_generator(): void
    {
        $this->fakeFfmpeg(Process::result(self::FINGERPRINT), muxers: "  E flac            raw FLAC\n");
        $generator = $this->generator();

        $this->assertNull($generator->generate($this->source));
        $this->assertNull($generator->generate($this->source));

        $this->assertCount(1, $this->commands);
        $this->assertContains('-muxers', $this->commands[0]);
    }

    #[Test]
    public function a_missing_source_file_yields_none_without_running_ffmpeg(): void
    {
        $this->fakeFfmpeg(Process::result(self::FINGERPRINT));

        $this->assertNull($this->generator()->generate($this->source.'-missing'));
        $this->assertSame([], $this->commands);
    }

    private function fakeFfmpeg(mixed $fingerprintResult, string $muxers = self::MUXERS): void
    {
        Process::fake(function (PendingProcess $process) use ($fingerprintResult, $muxers) {
            /** @var list<string> $command */
            $command = $process->command;
            $this->commands[] = $command;

            return in_array('-muxers', $command, true)
                ? Process::result($muxers, self::BANNER)
                : $fingerprintResult;
        });
    }

    private function generator(): FfmpegChromaprintGenerator
    {
        return new FfmpegChromaprintGenerator(new ChromaprintCapabilityProbe($this->ffmpeg, 5), 5);
    }
}
