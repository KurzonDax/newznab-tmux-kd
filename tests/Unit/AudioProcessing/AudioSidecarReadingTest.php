<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Services\AudioProcessing\Sidecars\CueSheet;
use App\Services\AudioProcessing\Sidecars\RipLog;
use App\Services\AudioProcessing\Sidecars\SidecarText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Issue #313, section C: CUE sheets and rip logs read as identification evidence. */
final class AudioSidecarReadingTest extends TestCase
{
    public const string CUE = <<<'CUE'
        PERFORMER "Example Artist"
        TITLE "Example Album"
        CATALOG 0123456789012
        FILE "Example Artist - Example Album.wav" WAVE
          TRACK 01 AUDIO
            TITLE "First Song"
            ISRC XXA111111111
            INDEX 01 00:00:00
          TRACK 02 AUDIO
            TITLE "Second Song"
            PERFORMER "Guest Artist"
            ISRC XXA222222222
            INDEX 00 03:58:00
            INDEX 01 04:00:00
          TRACK 03 AUDIO
            TITLE "Third Song"
            ISRC 000000000000
            INDEX 01 07:30:37
        CUE;

    /** The worked example of musicbrainz.org/doc/Disc_ID_Calculation. */
    public const string DISC_ID = '49HHV7Eb8UKF3aQiNmu1GR8vKTY-';

    #[Test]
    public function a_cue_sheet_gives_the_album_its_barcode_and_each_tracks_title_performer_isrc_and_start(): void
    {
        $sheet = CueSheet::parse(self::CUE);

        $this->assertNotNull($sheet);
        $this->assertSame('Example Album', $sheet->album);
        $this->assertSame('Example Artist', $sheet->albumArtist);
        $this->assertSame('0123456789012', $sheet->barcode);
        $this->assertNull($sheet->discNumber);
        $this->assertSame(3, $sheet->trackCount());
        $this->assertSame('Example Artist - Example Album.wav', $sheet->files[0]['name']);
        $tracks = $sheet->files[0]['tracks'];
        $this->assertSame([1, 2, 3], array_column($tracks, 'number'));
        $this->assertSame(['First Song', 'Second Song', 'Third Song'], array_column($tracks, 'title'));
        $this->assertSame([null, 'Guest Artist', null], array_column($tracks, 'performer'));
        $this->assertSame(['XXA111111111', 'XXA222222222', '000000000000'], array_column($tracks, 'isrc'));
        $this->assertEqualsWithDelta(450 + 37 / 75, $tracks[2]['start'], 0.0001);
        $this->assertSame(240.0, (float) $tracks[1]['start'], 'INDEX 00 is ignored');
    }

    #[Test]
    public function keywords_ignore_case_values_may_be_bare_and_other_track_types_are_skipped(): void
    {
        $sheet = CueSheet::parse("rem DISCNUMBER 2\ncatalog 000000000000\nfile image.bin BINARY\n track 01 MODE1/2352\n  index 01 00:00:00\n"
            ."FILE image.wav WAVE\n track 02 audio\n  title Bare Title\n  index 01 00:00:00\n");

        $this->assertNotNull($sheet);
        $this->assertSame(2, $sheet->discNumber);
        $this->assertNull($sheet->barcode, 'an all-zero catalog is not a barcode');
        $this->assertCount(1, $sheet->files, 'a file of data tracks only holds no audio');
        $this->assertSame('image.wav', $sheet->files[0]['name']);
        $this->assertSame('Bare Title', $sheet->files[0]['tracks'][0]['title']);
    }

    #[Test]
    public function an_invalid_sheet_reads_as_nothing(): void
    {
        $this->assertNull(CueSheet::parse("FILE \"a.wav\" WAVE\n TRACK 01 AUDIO\n TRACK 02 AUDIO\n  INDEX 01 00:00:00\n"), 'a track without INDEX 01');
        $this->assertNull(CueSheet::parse("FILE \"a.wav\" WAVE\n TRACK 01 AUDIO\n  INDEX 01 01:00:00\n TRACK 02 AUDIO\n  INDEX 01 00:30:00\n"), 'INDEX 01 must increase');
        $this->assertNull(CueSheet::parse('REM COMMENT "no tracks"'));
        $hundred = "FILE \"a.wav\" WAVE\n";
        foreach (range(1, 100) as $track) {
            $hundred .= sprintf(" TRACK %02d AUDIO\n  INDEX 01 %02d:00:00\n", $track % 100, $track);
        }
        $this->assertNull(CueSheet::parse($hundred), 'more than 99 audio tracks');
    }

    #[Test]
    public function windows_1252_utf8_with_a_bom_and_utf16_decode_to_the_same_text(): void
    {
        $expected = 'TITLE "Café Song"';

        $this->assertSame($expected, SidecarText::decode("TITLE \"Caf\xE9 Song\""));
        $this->assertSame($expected, SidecarText::decode("\xEF\xBB\xBF".$expected));
        $this->assertSame($expected, SidecarText::decode("\xFF\xFE".mb_convert_encoding($expected, 'UTF-16LE', 'UTF-8')));
        $this->assertSame($expected, SidecarText::decode("\xFE\xFF".mb_convert_encoding($expected, 'UTF-16BE', 'UTF-8')));
        $this->assertSame($expected, SidecarText::decode($expected));
    }

    #[Test]
    public function an_oversized_or_binary_body_is_not_read(): void
    {
        $this->assertNull(SidecarText::decode(str_repeat('a', SidecarText::MAX_BYTES + 1)));
        $this->assertSame(SidecarText::MAX_BYTES, strlen((string) SidecarText::decode(str_repeat('a', SidecarText::MAX_BYTES))));
        $this->assertNull(SidecarText::decode("FILE\0binary"));
    }

    #[Test]
    public function an_eac_rip_logs_toc_gives_the_musicbrainz_disc_id(): void
    {
        $this->assertSame([self::DISC_ID], RipLog::discIds(self::eacLog()));
        $this->assertSame([self::DISC_ID], RipLog::discIds((string) SidecarText::decode(self::utf16Log(self::eacLog()))));
    }

    #[Test]
    public function a_data_session_after_the_audio_gives_the_same_disc_id(): void
    {
        $this->assertSame([self::DISC_ID], RipLog::discIds(self::eacLog(enhanced: true)));
    }

    #[Test]
    public function an_xld_toc_and_a_log_with_two_rips_read_each_disc_once(): void
    {
        $xld = "X Lossless Decoder version 20230627\n\nTOC of the extracted CD\n     Track |   Start  |  Length  | Start sector | End sector\n"
            ."    ---------------------------------------------------------\n";
        foreach (self::rows() as [$track, $start, $end]) {
            $xld .= sprintf("       %2d  | 00:00:00 | 00:00:00 | %9d    | %9d\n", $track, $start, $end);
        }
        $xld .= "\nAccurateRip Summary\n";

        $this->assertSame([self::DISC_ID], RipLog::discIds($xld));
        $this->assertSame([self::DISC_ID], RipLog::discIds(self::eacLog()."\n\n".self::eacLog()));
    }

    #[Test]
    public function a_log_without_a_toc_is_not_a_rip_log(): void
    {
        $checker = "Lossless Audio Checker 2.0.6\nFile: 01 - First Song.flac\nResult: Clean\n";

        $this->assertSame([], RipLog::discIds($checker));
    }

    public static function eacLog(bool $enhanced = false): string
    {
        $log = "Exact Audio Copy V1.6 from 23. October 2020\n\nEAC extraction logfile from 8. October 2026\n\nExample Artist / Example Album\n\n"
            ."TOC of the extracted CD\n\n     Track |   Start  |  Length  | Start sector | End sector \n    ---------------------------------------------------------\n";
        $rows = self::rows();
        if ($enhanced) {
            $rows[] = [7, 106_712, 120_000];
        }
        foreach ($rows as [$track, $start, $end]) {
            $log .= sprintf("       %2d  |  0:00.00 |  0:00.00 | %9d    | %9d   \n", $track, $start, $end);
        }

        return $log."\n\nRange status and errors\n";
    }

    public static function utf16Log(string $text): string
    {
        return "\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    }

    /** @return list<array{0: int, 1: int, 2: int}> */
    private static function rows(): array
    {
        return [[1, 0, 15_212], [2, 15_213, 32_163], [3, 32_164, 46_441], [4, 46_442, 63_263], [5, 63_264, 80_338], [6, 80_339, 95_311]];
    }
}
