<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProcessingConfigurationTest extends TestCase
{
    /**
     * Formats posted as side-files of video releases (external audio tracks,
     * Matroska audio/subtitle companions) rather than as music.
     *
     * @return list<array{0: string}>
     */
    public static function videoSideCarAudioFiles(): array
    {
        return [['track.ac3'], ['track.dts'], ['track.mka'], ['track.mks']];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function standaloneAudioFiles(): array
    {
        return [['song.mp3'], ['song.flac'], ['song.ogg'], ['song.wav'], ['song.ape'], ['song.aac']];
    }

    #[Test]
    #[DataProvider('videoSideCarAudioFiles')]
    public function audio_file_regex_ignores_video_side_car_streams(string $fileName): void
    {
        $this->assertSame(0, preg_match($this->pattern(), $fileName));
    }

    #[Test]
    #[DataProvider('standaloneAudioFiles')]
    public function audio_file_regex_matches_standalone_music_files(string $fileName): void
    {
        $this->assertSame(1, preg_match($this->pattern(), $fileName));
    }

    private function pattern(): string
    {
        return '/(.*)'.ProcessingConfiguration::AUDIO_FILE_REGEX.'$/i';
    }
}
