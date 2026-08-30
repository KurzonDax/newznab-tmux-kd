<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Services\AudioProcessing\AudioSourceSelector;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function it_keeps_every_audio_filename_and_supported_sidecar_in_posted_order(): void
    {
        $source = $this->selector()->select([
            ['title' => '"release.rar" - "01 - first.flac" yEnc', 'segments' => ['<first-1>', '<first-2>']],
            ['title' => '"Album.cue" yEnc', 'segments' => ['<cue>']],
            ['title' => '"02 - second.flac" yEnc', 'segments' => ['<second>']],
            ['title' => '"Album.m3u8" yEnc', 'segments' => ['<playlist>']],
            ['title' => '"Album.log" yEnc', 'segments' => ['<log>']],
        ]);

        $this->assertNotNull($source);
        $this->assertSame(['01 - first.flac', '02 - second.flac'], array_column($source->nzbAudioFiles, 'filename'));
        $this->assertSame([1, 3], array_column($source->nzbAudioFiles, 'ordinal'));
        $this->assertSame([2, 1], array_column($source->nzbAudioFiles, 'segmentCount'));
        $this->assertSame(['cue', 'playlist', 'eac_log'], array_column($source->sidecars, 'kind'));
        $this->assertSame([2, 4, 5], array_column($source->sidecars, 'ordinal'));
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
    public function the_dsd_fixture_is_selected_as_bare_audio(): void
    {
        $fixture = dirname(__DIR__, 2).'/Fixtures/Audio/dsd-tone.dsf';
        $this->assertFileExists($fixture);

        $source = $this->selector()->select([
            ['title' => '"'.basename($fixture).'" yEnc', 'segments' => ['<dsd-1>']],
        ]);

        $this->assertNotNull($source);
        $this->assertSame(AudioSourceKind::BareFile, $source->kind);
        $this->assertSame('DSF', $source->extension);
        $this->assertSame([['<dsd-1>']], $source->parts);
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
    public function archive_volumes_are_kept_whole_and_in_posted_order(): void
    {
        // How many volumes are worth fetching is AudioFetcher's call; the
        // selector hands over everything it found.
        $files = [];
        foreach (range(1, 9) as $volume) {
            $files[] = [
                'title' => sprintf('Album.part%02d.rar" yEnc', $volume),
                'segments' => ['<vol-'.$volume.'>'],
            ];
        }

        $source = $this->selector()->select($files);

        $this->assertNotNull($source);
        $this->assertSame(AudioSourceKind::Archive, $source->kind);
        $this->assertSame('', $source->extension);
        $this->assertCount(9, $source->parts);
        $this->assertSame([['<vol-1>'], ['<vol-2>']], array_slice($source->parts, 0, 2));
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
        $this->assertSame([0, 1], array_column($source->nzbAudioFiles, 'segmentCount'));
    }

    private function selector(): AudioSourceSelector
    {
        return new AudioSourceSelector;
    }
}
