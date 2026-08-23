<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Services\AudioProcessing\AudioProcessingConfiguration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Moved here from Tests\Unit\AdditionalProcessing\ProcessingConfigurationTest when
 * the audio file list left the shared pipeline with the rest of the audio path.
 */
class AudioProcessingConfigurationTest extends TestCase
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
        return [
            ['song.mp3'], ['song.flac'], ['song.ogg'], ['song.wav'], ['song.ape'], ['song.aac'],
            // Added with the dedicated audio path: WV alone accounts for 52 inner
            // files in alt.binaries.sounds.lossless.
            ['song.wv'], ['song.m4a'], ['song.oga'], ['song.opus'], ['song.tta'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function sideCarFiles(): array
    {
        return [['album.cue'], ['album.m3u'], ['rip.log'], ['cover.jpg'], ['group.nfo'], ['album.sfv'], ['album.par2']];
    }

    #[Test]
    #[DataProvider('videoSideCarAudioFiles')]
    public function audio_file_regex_ignores_video_side_car_streams(string $fileName): void
    {
        $this->assertSame(0, preg_match($this->audioPattern(), $fileName));
    }

    #[Test]
    #[DataProvider('standaloneAudioFiles')]
    public function audio_file_regex_matches_standalone_music_files(string $fileName): void
    {
        $this->assertSame(1, preg_match($this->audioPattern(), $fileName));
    }

    #[Test]
    #[DataProvider('sideCarFiles')]
    public function ignored_file_regex_matches_the_side_cars_that_travel_with_a_rip(string $fileName): void
    {
        $this->assertSame(1, preg_match($this->ignoredPattern(), $fileName));
    }

    #[Test]
    #[DataProvider('standaloneAudioFiles')]
    public function ignored_file_regex_never_swallows_an_audio_file(string $fileName): void
    {
        $this->assertSame(0, preg_match($this->ignoredPattern(), $fileName));
    }

    #[Test]
    public function configured_preview_start_seconds_are_used(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        DB::table('settings')->insert([
            'name' => 'audio_preview_start_seconds',
            'value' => '10',
        ]);

        $this->assertSame(10, (new AudioProcessingConfiguration)->previewStartSeconds);
    }

    private function audioPattern(): string
    {
        return '/(.*)'.AudioProcessingConfiguration::AUDIO_FILE_REGEX.'$/i';
    }

    private function ignoredPattern(): string
    {
        return '/(.*)'.AudioProcessingConfiguration::IGNORED_FILE_REGEX.'$/i';
    }
}
