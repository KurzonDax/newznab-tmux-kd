<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Enums\IncidentImpactEnum;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AudioProcessing\ChromaprintCapabilityProbe;
use App\Services\StatusProbes\ChromaprintStatusProbe;
use App\Services\StatusProbes\ServiceProbeRegistry;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class ChromaprintCapabilityProbeTest extends TestCase
{
    private const string MUXERS = <<<'TXT'
File formats:
 D. = Demuxing supported
 .E = Muxing supported
 --
  E adts            ADTS AAC (Advanced Audio Coding)
  E chromaprint     Chromaprint
  E flac            raw FLAC
TXT;

    private const string BANNER = "ffmpeg version 6.1.1-3ubuntu5 Copyright (c) 2000-2023 the FFmpeg developers\n";

    private string $ffmpeg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ffmpeg = $this->makeTempPath('fake-ffmpeg');
        file_put_contents($this->ffmpeg, "#!/bin/sh\nexit 0\n");
        chmod($this->ffmpeg, 0755);
    }

    #[Test]
    public function a_build_listing_the_chromaprint_muxer_is_available_with_its_version(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(self::MUXERS, self::BANNER);
        });

        $capability = $this->probe()->probe();

        $this->assertTrue($capability->available);
        $this->assertSame('6.1.1-3ubuntu5', $capability->ffmpegVersion);
        $this->assertSame([[$this->ffmpeg, '-nostdin', '-muxers']], $commands);
    }

    #[Test]
    public function a_build_without_the_chromaprint_muxer_is_unavailable(): void
    {
        Process::fake(fn () => Process::result(str_replace('chromaprint', 'crc', self::MUXERS), self::BANNER));

        $capability = $this->probe()->probe();

        $this->assertFalse($capability->available);
        $this->assertSame('6.1.1-3ubuntu5', $capability->ffmpegVersion);
        $this->assertStringContainsString('without the Chromaprint muxer', $capability->reason);
    }

    #[Test]
    public function a_failing_ffmpeg_is_unavailable_even_when_it_printed_the_muxer_list(): void
    {
        Process::fake(fn () => Process::result(self::MUXERS, self::BANNER, 1));

        $capability = $this->probe()->probe();

        $this->assertFalse($capability->available);
        $this->assertStringContainsString('exited with status 1', $capability->reason);
    }

    #[Test]
    public function a_missing_executable_is_unavailable(): void
    {
        // A real process: the path does not exist, so nothing can start.
        $capability = (new ChromaprintCapabilityProbe($this->ffmpeg.'-missing', 5))->probe();

        $this->assertFalse($capability->available);
        $this->assertStringContainsString($this->ffmpeg.'-missing', $capability->reason);
    }

    #[Test]
    public function a_bare_executable_name_is_run_from_path(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(self::MUXERS, self::BANNER);
        });

        $this->assertTrue((new ChromaprintCapabilityProbe('ffmpeg', 5))->probe()->available);
        $this->assertSame([['ffmpeg', '-nostdin', '-muxers']], $commands);
    }

    /**
     * @return array<string, array{string|false, string}>
     */
    public static function configuredPaths(): array
    {
        return [
            'unset' => [false, 'ffmpeg'],
            'empty' => ['', 'ffmpeg'],
            'bare name' => ['ffmpeg', 'ffmpeg'],
            'absolute path' => ['/opt/ffmpeg/bin/ffmpeg', '/opt/ffmpeg/bin/ffmpeg'],
        ];
    }

    #[Test]
    #[DataProvider('configuredPaths')]
    public function the_configured_ffmpeg_path_resolves_like_the_other_ffmpeg_callers(string|false $path, string $binary): void
    {
        /** @var ProcessingConfiguration $config */
        $config = (new ReflectionClass(ProcessingConfiguration::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ProcessingConfiguration::class, 'ffmpegPath'))->setValue($config, $path);

        $this->assertSame($binary, $config->ffmpegBinary());
    }

    #[Test]
    public function the_worker_capability_is_probed_once_per_process(): void
    {
        Process::fake(fn () => Process::result(self::MUXERS, self::BANNER));
        $probe = $this->probe();

        $this->assertTrue($probe->capability()->available);
        $this->assertTrue($probe->capability()->available);

        Process::assertRanTimes(fn (): bool => true, 1);
    }

    #[Test]
    public function the_status_probe_reports_an_available_capability_as_operational(): void
    {
        Process::fake(fn () => Process::result(self::MUXERS, self::BANNER));
        $this->app->instance(ChromaprintCapabilityProbe::class, $this->probe());

        $result = app(ServiceProbeRegistry::class)->run('chromaprint');

        $this->assertTrue($result->ok);
        $this->assertNull($result->impact);
        $this->assertSame(['available' => true, 'ffmpegVersion' => '6.1.1-3ubuntu5'], $result->metadata);
    }

    #[Test]
    public function the_status_probe_reports_a_missing_capability_as_a_minor_degradation_on_every_check(): void
    {
        $probe = $this->probe();
        $this->app->instance(ChromaprintCapabilityProbe::class, $probe);
        Process::fake(fn () => Process::result(self::MUXERS, self::BANNER));
        $probe->capability();

        Process::fake(fn () => Process::result(str_replace('chromaprint', 'crc', self::MUXERS), self::BANNER));
        $result = app(ChromaprintStatusProbe::class)->probe();

        $this->assertFalse($result->ok);
        $this->assertSame(IncidentImpactEnum::Minor, $result->impact);
        $this->assertStringContainsString('without the Chromaprint muxer', $result->reason);
        $this->assertFalse($result->metadata['available']);
    }

    private function probe(): ChromaprintCapabilityProbe
    {
        return new ChromaprintCapabilityProbe($this->ffmpeg, 5);
    }
}
