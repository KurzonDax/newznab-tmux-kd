<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MediaInfo\MediaInfoNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaInfoNamesTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function videoCodecs(): iterable
    {
        yield 'AVC' => ['AVC', 'V_MPEG4/ISO/AVC', 'H.264'];
        yield 'HEVC' => ['HEVC', 'V_MPEGH/ISO/HEVC', 'H.265'];
        yield 'AV1' => ['AV1', 'V_AV1', 'AV1'];
        yield 'MPEG Video' => ['MPEG Video', 'V_MPEG2', 'MPEG-2'];
        yield 'MPEG-4 Visual' => ['MPEG-4 Visual', 'XVID', 'MPEG-4 (Xvid/DivX)'];
        yield 'VP9' => ['VP9', null, 'VP9'];
        yield 'legacy xvid' => ['xvid', null, 'MPEG-4 (Xvid/DivX)'];
        yield 'codec id only' => [null, 'V_MPEGH/ISO/HEVC', 'H.265'];
        yield 'codec id only, AVC' => [null, 'V_MPEG4/ISO/AVC', 'H.264'];
        yield 'codec id only, MPEG-2' => [null, 'V_MPEG2', 'MPEG-2'];
        yield 'unmapped shows the format' => ['VC-1', 'V_MS/VFW/FOURCC / WVC1', 'VC-1'];
        yield 'unmapped codec id alone shows nothing' => [null, 'V_MS/VFW/FOURCC / WVC1', null];
        yield 'an unmapped format wins over a mapped codec id' => ['Other Video', 'V_MPEGH/ISO/HEVC', 'Other Video'];
        // the spellings production stores (adult-redesign DATA-CONTRACT 4.5, issue #1218)
        yield 'vp09' => ['vp09', 'vp09', 'VP9'];
        yield 'av01' => ['av01', 'av01', 'AV1'];
        yield 'x264' => ['x264', null, 'H.264'];
        yield 'a stream-number codec reads by its format' => ['AVC', '27', 'H.264'];
        yield 'a four-character codec reads by its format' => ['HEVC', 'hvc1', 'H.265'];
    }

    #[DataProvider('videoCodecs')]
    public function test_video_codecs_read_plainly(?string $format, ?string $codec, ?string $expected): void
    {
        self::assertSame($expected, MediaInfoNames::video($format, $codec));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function audioFormats(): iterable
    {
        yield 'E-AC-3' => ['E-AC-3', 'Dolby Digital Plus', 'E-AC-3', false];
        yield 'E-AC-3 JOC' => ['E-AC-3 JOC', 'Dolby Digital Plus with Atmos', 'E-AC-3 Atmos', true];
        yield 'AC-3' => ['AC-3', 'Dolby Digital', 'AC-3', false];
        yield 'AAC LC' => ['AAC LC', 'AAC', 'AAC', false];
        yield 'AAC LC SBR' => ['AAC LC SBR', 'HE-AAC', 'HE-AAC', false];
        yield 'DTS XLL' => ['DTS XLL', 'DTS-HD Master Audio', 'DTS-HD MA', false];
        yield 'DTS' => ['DTS', 'DTS', 'DTS', false];
        yield 'MLP FBA' => ['MLP FBA', 'Dolby TrueHD', 'TrueHD', false];
        yield 'FLAC' => ['FLAC', 'FLAC', 'FLAC', false];
        yield 'Opus' => ['Opus', 'Opus', 'Opus', false];
        yield 'MPEG Audio' => ['MPEG Audio', 'MP2/MP3', 'MP2/MP3', false];
        yield 'PCM' => ['PCM', 'PCM', 'PCM', false];
        yield 'unmapped shows the format' => ['Vorbis', 'Vorbis', 'Vorbis', false];
        // the spellings production stores (adult-redesign DATA-CONTRACT 4.5, issue #1218)
        yield 'MLP FBA 16-ch' => ['MLP FBA 16-ch', 'Dolby TrueHD with Atmos', 'TrueHD Atmos', false];
        yield 'DTS XLL X' => ['DTS XLL X', 'DTS:X', 'DTS:X', false];
        yield 'DTS ES XLL' => ['DTS ES XLL', 'DTS-HD Master Audio', 'DTS-HD MA', false];
        yield 'DTS XBR' => ['DTS XBR', 'DTS-HD High Resolution Audio', 'DTS-HD HRA', false];
        yield 'DTS ES' => ['DTS ES', 'DTS-ES', 'DTS-ES', false];
        yield 'DTS LBR' => ['DTS LBR', 'DTS Express', 'DTS Express', false];
        yield 'AAC LC SBR PS' => ['AAC LC SBR PS', 'HE-AAC v2', 'HE-AAC v2', false];
    }

    #[DataProvider('audioFormats')]
    public function test_audio_formats_read_plainly(string $format, string $name, string $short, bool $atmos): void
    {
        self::assertSame(['name' => $name, 'short' => $short], MediaInfoNames::audio($format));
        self::assertSame($atmos, MediaInfoNames::atmos($format));
    }

    public function test_audio_without_a_format_has_no_name(): void
    {
        self::assertNull(MediaInfoNames::audio(null));
        self::assertNull(MediaInfoNames::audio(''));
    }

    /** @return iterable<string, array{int|string|null, ?string, ?string}> */
    public static function channelCounts(): iterable
    {
        yield 'mono' => [1, '1/0/0', 'Mono'];
        yield 'stereo' => [2, '2/0/0', 'Stereo'];
        yield '5.1' => [6, '3/2/0.1', '5.1'];
        yield '7.1' => [8, '3/2/2.1', '7.1'];
        yield 'other count' => [5, '3/2/0', '5 channels'];
        yield 'layout only' => [null, '3/2/0.1', '5.1'];
        yield 'legacy text' => ['6', null, '5.1'];
        yield 'legacy text with words' => ['2 channels', null, 'Stereo'];
        yield 'nothing' => [null, null, null];
    }

    #[DataProvider('channelCounts')]
    public function test_channels_read_plainly(int|string|null $channels, ?string $layout, ?string $expected): void
    {
        self::assertSame($expected, MediaInfoNames::channels($channels, $layout));
    }

    /** @return iterable<string, array{?string, list<array{label: string, kind: string}>}> */
    public static function hdrFormats(): iterable
    {
        yield 'none' => [null, []];
        yield 'HDR10' => ['SMPTE ST 2086, HDR10 compatible', [['label' => 'HDR10', 'kind' => 'hdr']]];
        yield 'bare ST 2086' => ['SMPTE ST 2086', [['label' => 'HDR10', 'kind' => 'hdr']]];
        yield 'HDR10+' => ['SMPTE ST 2094 App 4, Version 1, HDR10+ Profile B compatible', [['label' => 'HDR10+', 'kind' => 'hdr10plus']]];
        yield 'bare ST 2094' => ['SMPTE ST 2094 App 4, Version 1', [['label' => 'HDR10+', 'kind' => 'hdr10plus']]];
        yield 'Dolby Vision profile 5' => ['Dolby Vision, Version 1.0, Profile 5, dvhe.05.06, BL+RPU', [['label' => 'Dolby Vision · profile 5', 'kind' => 'dv']]];
        yield 'Dolby Vision with HDR10' => [
            'Dolby Vision, Version 1.0, Profile 8.1, dvhe.08.06, BL+RPU, HDR10 compatible / SMPTE ST 2086, Version HDR10, HDR10 compatible',
            [['label' => 'Dolby Vision · profile 8.1', 'kind' => 'dv'], ['label' => 'HDR10', 'kind' => 'hdr']],
        ];
        yield 'Dolby Vision with HDR10+' => [
            'Dolby Vision, Version 1.0, Profile 8.1, dvhe.08.06, BL+RPU, HDR10 compatible / SMPTE ST 2094 App 4, Version HDR10+ Profile B, HDR10+ Profile B compatible',
            [['label' => 'Dolby Vision · profile 8.1', 'kind' => 'dv'], ['label' => 'HDR10+', 'kind' => 'hdr10plus']],
        ];
        yield 'unknown HDR is shown as stored' => ['HLG', [['label' => 'HLG', 'kind' => 'other']]];
    }

    /** @param list<array{label: string, kind: string}> $expected */
    #[DataProvider('hdrFormats')]
    public function test_hdr_reads_plainly(?string $hdr, array $expected): void
    {
        self::assertSame($expected, MediaInfoNames::hdr($hdr));
    }

    /** @return iterable<string, array{?string, ?string, ?array{name: string, picture: bool}}> */
    public static function subtitleFormats(): iterable
    {
        yield 'UTF-8' => ['UTF-8', 'S_TEXT/UTF8', ['name' => 'SRT', 'picture' => false]];
        yield 'ASS' => ['ASS', 'S_TEXT/ASS', ['name' => 'ASS', 'picture' => false]];
        yield 'PGS' => ['PGS', 'S_HDMV/PGS', ['name' => 'PGS', 'picture' => true]];
        yield 'Timed Text' => ['Timed Text', 'tx3g', ['name' => 'Timed Text', 'picture' => false]];
        yield 'WebVTT by format' => ['S_TEXT/WEBVTT', null, ['name' => 'WebVTT', 'picture' => false]];
        yield 'WebVTT by codec id' => [null, 'S_TEXT/WEBVTT', ['name' => 'WebVTT', 'picture' => false]];
        yield 'VobSub is a picture' => ['VobSub', 'S_VOBSUB', ['name' => 'VobSub', 'picture' => true]];
        yield 'unmapped codec id alone shows nothing' => [null, 'S_TEXT/UTF8', null];
        yield 'an unmapped format wins over a mapped codec id' => ['Other Text', 'S_TEXT/WEBVTT', ['name' => 'Other Text', 'picture' => false]];
        yield 'nothing' => [null, null, null];
    }

    /** @param array{name: string, picture: bool}|null $expected */
    #[DataProvider('subtitleFormats')]
    public function test_subtitle_formats_read_plainly(?string $format, ?string $codec, ?array $expected): void
    {
        self::assertSame($expected, MediaInfoNames::subtitle($format, $codec));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function languages(): iterable
    {
        yield 'code' => ['en', 'English'];
        yield 'three letters' => ['ger', 'German'];
        yield 'region' => ['pt-BR', 'Portuguese (BR)'];
        yield 'Latin America' => ['es-419', 'Spanish (Latin America)'];
        yield 'legacy name kept' => ['English (US)', 'English (US)'];
        yield 'unknown code kept' => ['tlh', 'tlh'];
        yield 'nothing' => [null, null];
    }

    #[DataProvider('languages')]
    public function test_languages_read_plainly(?string $language, ?string $expected): void
    {
        self::assertSame($expected, MediaInfoNames::language($language));
    }
}
