<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\MoovlessH264Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoovlessH264StreamTest extends TestCase
{
    private const string START_CODE = "\x00\x00\x00\x01";

    private const string X264_OPTIONS = 'cabac=1 ref=3 deblock=1:0:0 analyse=0x3:0x113 me=hex subme=7 chroma_qp_offset=-2 '
        .'threads=12 8x8dct=1 cqm=0 bframes=3 b_pyramid=2 weightb=1 weightp=2 interlaced=0 rc=crf crf=23.0';

    #[Test]
    public function the_walker_keeps_units_on_both_sides_of_a_junk_gap(): void
    {
        $before = $this->keyframe('before');
        $after = $this->keyframe('after');

        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->inBandSettings().$before.str_repeat("\xFF", 700).$after,
        ));

        $this->assertNotNull($stream);
        $this->assertSame([$this->annexB($before), $this->annexB($after)], $stream->keyframeGroups());
    }

    #[Test]
    public function the_walker_stops_at_a_unit_past_the_end_of_the_head_while_in_sync_and_close_to_it(): void
    {
        $hiddenKeyframe = $this->keyframe('inside the cut unit');
        $cutUnit = pack('N', 2_000_000)."\x41\x80".str_repeat("\xFF", 64).$hiddenKeyframe;
        $kept = $this->keyframe('kept');

        $stream = MoovlessH264Stream::fromHead($this->head($this->inBandSettings().$kept.$cutUnit));

        $this->assertNotNull($stream);
        $this->assertSame([$this->annexB($kept)], $stream->keyframeGroups());
    }

    #[Test]
    public function the_walker_looks_inside_a_unit_past_the_end_when_it_is_far_from_the_end(): void
    {
        $hiddenKeyframe = $this->keyframe('further on');
        $farUnit = pack('N', 7_000_000)."\x41\x80".str_repeat("\xFF", 1_000).$hiddenKeyframe.str_repeat("\xFF", 4_100_000);
        $kept = $this->keyframe('kept');

        $stream = MoovlessH264Stream::fromHead($this->head($this->inBandSettings().$kept.$farUnit));

        $this->assertNotNull($stream);
        $this->assertSame([$this->annexB($kept), $this->annexB($hiddenKeyframe)], $stream->keyframeGroups());
    }

    #[Test]
    public function a_head_without_mdat_or_with_moov_first_has_no_stream(): void
    {
        $units = $this->inBandSettings().$this->keyframe('frame');

        $this->assertNull(MoovlessH264Stream::fromHead($this->atom('ftyp', 'isom0000').$this->atom('free', $units)));
        $this->assertNull(MoovlessH264Stream::fromHead(
            $this->atom('ftyp', 'isom0000').$this->atom('moov', 'metadata').$this->atom('mdat', $units),
        ));
    }

    #[Test]
    public function a_head_with_settings_but_no_keyframe_has_no_stream(): void
    {
        $this->assertNull(MoovlessH264Stream::fromHead($this->head($this->inBandSettings().$this->slice('p frame'))));
    }

    #[Test]
    public function a_head_with_keyframes_but_no_settings_or_text_has_no_stream(): void
    {
        $this->assertNull(MoovlessH264Stream::fromHead($this->head($this->keyframe('frame'))));
    }

    #[Test]
    public function a_multi_slice_keyframe_is_one_group_and_a_type_one_unit_closes_it(): void
    {
        $firstSlice = $this->keyframe('slice one');
        $secondSlice = $this->keyframe('slice two', firstSlice: false);
        $nextKeyframe = $this->keyframe('next frame');
        $predicted = $this->slice('p frame');
        $afterPredicted = $this->keyframe('not first in picture, but nothing is open', firstSlice: false);

        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->inBandSettings().$firstSlice.$secondSlice.$nextKeyframe.$predicted.$afterPredicted,
        ));

        $this->assertNotNull($stream);
        $this->assertSame([
            $this->annexB($firstSlice).$this->annexB($secondSlice),
            $this->annexB($nextKeyframe),
            $this->annexB($afterPredicted),
        ], $stream->keyframeGroups());
    }

    #[Test]
    public function grouping_stops_at_thirty_groups_and_the_thirtieth_keeps_its_later_slices(): void
    {
        $units = $this->inBandSettings();
        for ($group = 1; $group <= 32; $group++) {
            $units .= $this->keyframe('group '.$group);
            $units .= $this->keyframe('group '.$group.' second slice', firstSlice: false);
        }

        $stream = MoovlessH264Stream::fromHead($this->head($units));

        $this->assertNotNull($stream);
        $groups = $stream->keyframeGroups();
        $this->assertCount(30, $groups);
        $this->assertSame(
            $this->annexB($this->keyframe('group 30')).$this->annexB($this->keyframe('group 30 second slice', firstSlice: false)),
            $groups[29],
        );
    }

    #[Test]
    public function in_band_settings_are_the_distinct_units_in_order_and_stay_in_place_in_the_slice_stream(): void
    {
        $sps = $this->nal("\x67", 'sps one');
        $pps = $this->nal("\x68", 'pps one');
        $secondSps = $this->nal("\x67", 'sps two');
        $frame = $this->keyframe('frame');
        $sei = $this->nal("\x06", 'sei message');
        $predicted = $this->slice('p frame');

        $stream = MoovlessH264Stream::fromHead($this->head(
            $sps.$pps.$frame.$sei.$sps.$pps.$secondSps.$predicted,
        ));

        $this->assertNotNull($stream);
        $this->assertFalse($stream->usesEncoderText());
        $this->assertSame($this->annexB($sps).$this->annexB($pps).$this->annexB($secondSps), $stream->inBandSettings());
        $this->assertSame(
            $this->annexB($sps).$this->annexB($pps).$this->annexB($frame)
                .$this->annexB($sps).$this->annexB($pps).$this->annexB($secondSps).$this->annexB($predicted),
            $stream->sliceStream(),
        );
    }

    #[Test]
    public function in_band_settings_win_over_x264_text(): void
    {
        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei(self::X264_OPTIONS).$this->inBandSettings().$this->keyframe('frame'),
        ));

        $this->assertNotNull($stream);
        $this->assertFalse($stream->usesEncoderText());
    }

    #[Test]
    public function the_x264_route_leaves_settings_units_out_of_the_slice_stream(): void
    {
        $frame = $this->keyframe('frame');
        $predicted = $this->slice('p frame');

        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei(self::X264_OPTIONS).$this->nal("\x67", 'lone sps').$frame.$predicted,
        ));

        $this->assertNotNull($stream);
        $this->assertTrue($stream->usesEncoderText());
        $this->assertSame($this->annexB($frame).$this->annexB($predicted), $stream->sliceStream());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedX264Values(): array
    {
        return [
            'bframes far too high' => ['bframes=999999999'],
            'bframes too high' => ['bframes=17'],
            'bframes negative' => ['bframes=-1'],
            'ref zero' => ['ref=0'],
            'ref too high' => ['ref=17'],
            'b_pyramid too high' => ['b_pyramid=3'],
            'b_pyramid negative' => ['b_pyramid=-1'],
            'chroma_qp_offset too low' => ['chroma_qp_offset=-13'],
            'chroma_qp_offset too high' => ['chroma_qp_offset=13'],
            'crf too high' => ['crf=52'],
            'crf negative' => ['crf=-1'],
            'qp too high' => ['qp=52'],
            'qp negative' => ['qp=-1'],
            'bframes past any integer' => ['bframes=1e300'],
            'interlaced' => ['interlaced=1'],
            'interlaced named' => ['interlaced=tff'],
            'custom matrix' => ['cqm=1'],
            'fake interlaced' => ['fake_interlaced=1'],
        ];
    }

    #[Test]
    #[DataProvider('refusedX264Values')]
    public function a_refused_x264_value_gives_no_route_and_returns_promptly(string $token): void
    {
        $started = microtime(true);

        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei('cabac=1 deblock=1:0:0 analyse=0x3:0x113 me=hex subme=7 threads=12 '.$token).$this->keyframe('frame'),
        ));

        $this->assertNull($stream);
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    #[Test]
    public function x264_text_past_the_first_megabyte_of_mdat_gives_no_route(): void
    {
        $padding = $this->slice(str_repeat('p', 1_048_576));

        $this->assertNull(MoovlessH264Stream::fromHead($this->head(
            $padding.$this->x264Sei(self::X264_OPTIONS).$this->keyframe('frame'),
        )));
    }

    /**
     * Worked by hand from the bit layout in the issue: High profile, level 5.1,
     * log2_max_frame_num 4 (dpb 4 doubled by pyramid plus one is 9), poc type 0
     * with log2_max_poc_lsb 6 ((3+2)*2*2*2 is 40), 4 references, 80x45
     * macroblocks, no crop, no VUI. The PPS is x264's well-known default.
     */
    #[Test]
    public function settings_rebuilt_from_x264_text_match_a_worked_example(): void
    {
        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei(self::X264_OPTIONS).$this->keyframe('frame'),
        ));

        $this->assertNotNull($stream);
        $this->assertTrue($stream->usesEncoderText());
        $this->assertFalse($stream->usesTwoPassRateControl());
        $this->assertSame(23, $stream->picInitQp());
        $this->assertSame(
            self::START_CODE.hex2bin('67640033acd9405005b9').self::START_CODE.hex2bin('68ebe3cb22c0'),
            $stream->rebuiltSettings(1280, 45),
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function picInitQpCases(): array
    {
        return [
            'crf truncates' => ['rc=crf crf=18.7 qp=30', 18],
            'cqp reads qp' => ['rc=cqp qp=20 crf=40', 20],
            'abr is the default' => ['rc=abr bitrate=1000 crf=30', 26],
            'two pass is the default' => ['rc=2pass bitrate=1000', 26],
            'stitchable always wins' => ['rc=crf crf=18.0 stitchable=1', 26],
        ];
    }

    #[Test]
    #[DataProvider('picInitQpCases')]
    public function pic_init_qp_follows_the_rate_control(string $tokens, int $expected): void
    {
        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei('cabac=1 deblock=1:0:0 analyse=0x3:0x113 me=hex subme=7 threads=12 '.$tokens).$this->keyframe('frame'),
        ));

        $this->assertNotNull($stream);
        $this->assertSame($expected, $stream->picInitQp());
    }

    #[Test]
    public function only_rc_two_pass_reports_two_pass_rate_control(): void
    {
        $twoPass = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei('cabac=1 deblock=1:0:0 analyse=0x3:0x113 me=hex subme=7 threads=12 rc=2pass').$this->keyframe('frame'),
        ));

        $this->assertNotNull($twoPass);
        $this->assertTrue($twoPass->usesTwoPassRateControl());
    }

    #[Test]
    public function emulation_prevention_guards_zero_runs_in_a_rebuilt_unit(): void
    {
        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei('cabac=0 deblock=1:0:0 analyse=0x3:0x113 me=hex subme=7 threads=12 chroma_qp_offset=0 rc=abr').$this->keyframe('frame'),
        ));

        $this->assertNotNull($stream);
        foreach ([640, 1280, 1920, 3840] as $width) {
            foreach ([8, 45, 68, 256] as $rows) {
                $settings = substr($stream->rebuiltSettings($width, $rows), 4);
                $units = explode(self::START_CODE, $settings);
                foreach ($units as $unit) {
                    $this->assertDoesNotMatchRegularExpression('/\x00\x00[\x00-\x02]/', $unit);
                    $this->assertNotSame("\x00", substr($unit, -1));
                }
            }
        }
    }

    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function crops(): array
    {
        return [
            '68 rows display 1080' => [1920, 68, 0, 8],
            '34 rows display 540' => [960, 34, 0, 4],
            '23 rows display 360' => [640, 23, 0, 8],
            'other row counts have no bottom crop' => [1280, 45, 0, 0],
            'width crop only' => [854, 30, 10, 0],
            'portrait width crop' => [1080, 120, 8, 0],
        ];
    }

    #[Test]
    #[DataProvider('crops')]
    public function the_sps_writes_the_right_and_bottom_crop(int $width, int $rows, int $right, int $bottom): void
    {
        $stream = MoovlessH264Stream::fromHead($this->head(
            $this->x264Sei('cabac=1 deblock=1:0:0 analyse=0x3:0x113 me=hex subme=7 threads=12 bframes=0 rc=abr').$this->keyframe('frame'),
        ));

        $this->assertNotNull($stream);
        $this->assertSame(
            ['mbw' => intdiv($width + 15, 16), 'rows' => $rows, 'right' => $right, 'bottom' => $bottom],
            $this->readSpsGeometry($stream->rebuiltSettings($width, $rows)),
        );
    }

    /**
     * @return array{mbw: int, rows: int, right: int, bottom: int}
     */
    private function readSpsGeometry(string $settings): array
    {
        $unit = explode(self::START_CODE, substr($settings, 4))[0];
        $rbsp = str_replace("\x00\x00\x03", "\x00\x00", substr($unit, 4));
        $bits = '';
        foreach (str_split($rbsp) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $position = 0;
        $ue = static function () use ($bits, &$position): int {
            $zeros = 0;
            while ($bits[$position + $zeros] === '0') {
                $zeros++;
            }
            $value = bindec(substr($bits, $position + $zeros, $zeros + 1)) - 1;
            $position += ($zeros * 2) + 1;

            return (int) $value;
        };

        $ue(); // sps id
        $ue(); // chroma format
        $ue(); // luma depth
        $ue(); // chroma depth
        $position += 2; // bypass, scaling matrix
        $ue(); // log2 frame num
        $this->assertSame(2, $ue()); // poc type for bframes=0
        $ue(); // reference frames
        $position += 1; // gaps
        $mbw = $ue() + 1;
        $rows = $ue() + 1;
        $position += 2; // frame_mbs_only, direct 8x8
        $right = 0;
        $bottom = 0;
        if ($bits[$position++] === '1') {
            $ue();
            $right = $ue() * 2;
            $ue();
            $bottom = $ue() * 2;
        }

        return ['mbw' => $mbw, 'rows' => $rows, 'right' => $right, 'bottom' => $bottom];
    }

    private function head(string $mdatPayload): string
    {
        return $this->atom('ftyp', 'isom0000').pack('N', strlen($mdatPayload) + 100_000).'mdat'.$mdatPayload;
    }

    private function atom(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8).$type.$payload;
    }

    private function inBandSettings(): string
    {
        return $this->nal("\x67", 'sps').$this->nal("\x68", 'pps');
    }

    private function x264Sei(string $options): string
    {
        return $this->nal("\x06", "\x05\xFF".str_repeat('u', 16).'x264 - core 164 r3108 31e19f9 - H.264/MPEG-4 AVC codec - Copyleft 2003-2023 - options: '.$options."\x00\x80");
    }

    private function keyframe(string $payload, bool $firstSlice = true): string
    {
        return $this->nal("\x65", ($firstSlice ? "\x88" : "\x08").str_pad($payload, 20, '.'));
    }

    private function slice(string $payload): string
    {
        return $this->nal("\x41", "\x9A".str_pad($payload, 20, '.'));
    }

    private function nal(string $header, string $payload): string
    {
        return pack('N', strlen($header.$payload)).$header.$payload;
    }

    /** The Annex-B form of one length-prefixed unit built by {@see nal()}. */
    private function annexB(string $unit): string
    {
        return self::START_CODE.substr($unit, 4);
    }
}
