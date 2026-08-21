<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\AudioSourceSelector;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

class AudioSourceSelectorTest extends TestCase
{
    #[Test]
    public function a_posted_audio_file_beats_an_archive_set(): void
    {
        $source = $this->selector()->select([
            ['title' => 'Album.part01.rar" yEnc', 'segments' => ['<part-1>']],
            ['title' => '"01 - track.flac" yEnc', 'segments' => ['<flac-1>', '<flac-2>']],
        ]);

        $this->assertNotNull($source);
        $this->assertSame(AudioSourceKind::BareFile, $source->kind);
        $this->assertSame('FLAC', $source->extension);
        $this->assertSame([['<flac-1>', '<flac-2>']], $source->parts);
    }

    #[Test]
    public function wavpack_and_m4a_are_recognised_as_audio(): void
    {
        foreach (['wv' => 'WV', 'm4a' => 'M4A', 'opus' => 'OPUS', 'tta' => 'TTA', 'oga' => 'OGA'] as $extension => $expected) {
            $source = $this->selector()->select([
                ['title' => '"01 - track.'.$extension.'" yEnc', 'segments' => ['<a>']],
            ]);

            $this->assertNotNull($source, $extension.' should be selectable');
            $this->assertSame($expected, $source->extension);
        }
    }

    #[Test]
    public function side_car_files_are_never_a_source(): void
    {
        $source = $this->selector()->select([
            ['title' => '"Album.cue" yEnc', 'segments' => ['<cue>']],
            ['title' => '"cover.jpg" yEnc', 'segments' => ['<jpg>']],
            ['title' => '"group.nfo" yEnc', 'segments' => ['<nfo>']],
            ['title' => '"Album.sfv" yEnc', 'segments' => ['<sfv>']],
            ['title' => '"Album.log" yEnc', 'segments' => ['<log>']],
        ]);

        $this->assertNull($source);
    }

    #[Test]
    public function a_cd_image_rip_is_selected_as_the_big_audio_file_beside_its_cue(): void
    {
        $source = $this->selector()->select([
            ['title' => '"Album.cue" yEnc', 'segments' => ['<cue>']],
            ['title' => '"Album.wv" yEnc', 'segments' => ['<wv-1>', '<wv-2>', '<wv-3>']],
        ]);

        $this->assertNotNull($source);
        $this->assertSame(AudioSourceKind::BareFile, $source->kind);
        $this->assertSame('WV', $source->extension);
    }

    #[Test]
    public function archive_volumes_are_kept_in_posted_order_and_capped(): void
    {
        $files = [];
        foreach (range(1, 9) as $volume) {
            $files[] = [
                'title' => sprintf('Album.part%02d.rar" yEnc', $volume),
                'segments' => ['<vol-'.$volume.'>'],
            ];
        }

        $source = $this->selector(maxRarParts: 3)->select($files);

        $this->assertNotNull($source);
        $this->assertSame(AudioSourceKind::Archive, $source->kind);
        $this->assertSame('', $source->extension);
        $this->assertSame([['<vol-1>'], ['<vol-2>'], ['<vol-3>']], $source->parts);
    }

    #[Test]
    public function a_file_without_segments_is_skipped(): void
    {
        $source = $this->selector()->select([
            ['title' => '"01 - track.flac" yEnc', 'segments' => []],
            ['title' => '"02 - track.flac" yEnc', 'segments' => ['<flac-2>']],
        ]);

        $this->assertNotNull($source);
        $this->assertSame([['<flac-2>']], $source->parts);
    }

    private function selector(int $maxRarParts = 6): AudioSourceSelector
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();
        (new ReflectionProperty(AudioProcessingConfiguration::class, 'maxRarParts'))->setValue($config, $maxRarParts);

        return new AudioSourceSelector($config);
    }
}
