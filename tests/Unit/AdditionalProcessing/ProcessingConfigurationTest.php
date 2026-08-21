<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\AudioProcessing\AudioProcessingConfigurationTest;

/**
 * The shared path's file classification. Its audio half moved to
 * {@see AudioProcessingConfigurationTest} with the
 * rest of the audio work; what stays here is what the video path still selects
 * on.
 */
class ProcessingConfigurationTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function videoFiles(): array
    {
        return [['show.mkv'], ['movie.mp4'], ['clip.avi'], ['disc.vob'], ['stream.ts']];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonVideoFiles(): array
    {
        return [['song.mp3'], ['cover.jpg'], ['group.nfo'], ['archive.rar']];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function supportFiles(): array
    {
        return [['release.par2'], ['release.vol01+02'], ['release.sfv'], ['release.srs'], ['release.nzb']];
    }

    #[Test]
    #[DataProvider('videoFiles')]
    public function video_file_regex_matches_playable_containers(string $fileName): void
    {
        $this->assertSame(1, preg_match($this->pattern('videoFileRegex'), $fileName));
    }

    #[Test]
    #[DataProvider('nonVideoFiles')]
    public function video_file_regex_ignores_everything_else(string $fileName): void
    {
        $this->assertSame(0, preg_match($this->pattern('videoFileRegex'), $fileName));
    }

    #[Test]
    #[DataProvider('supportFiles')]
    public function support_file_regex_matches_the_files_that_travel_with_a_release(string $fileName): void
    {
        $this->assertSame(1, preg_match($this->pattern('supportFileRegex'), $fileName));
    }

    #[Test]
    public function support_file_regex_does_not_swallow_the_release_itself(): void
    {
        $this->assertSame(0, preg_match($this->pattern('supportFileRegex'), 'show.mkv'));
    }

    #[Test]
    public function the_audio_file_list_no_longer_lives_here(): void
    {
        $this->assertFalse(
            (new ReflectionClass(ProcessingConfiguration::class))->hasConstant('AUDIO_FILE_REGEX'),
            'Audio selection belongs to AudioProcessingConfiguration.'
        );
    }

    private function pattern(string $property): string
    {
        $reflection = new ReflectionClass(ProcessingConfiguration::class);
        /** @var ProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        $value = match ($property) {
            'videoFileRegex' => '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)',
            'supportFileRegex' => '\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)',
            default => throw new \InvalidArgumentException($property),
        };

        $reflectionProperty = new \ReflectionProperty(ProcessingConfiguration::class, $property);
        $reflectionProperty->setValue($config, $value);

        return '/(.*)'.$config->{$property}.'$/i';
    }
}
