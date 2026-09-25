<?php

declare(strict_types=1);

namespace App\Services\MediaInfo;

/**
 * Plain names for the media info block (docs/proposals/tv-redesign/SPEC.md 3.5): codecs,
 * audio formats, channels, HDR, subtitle formats and languages. The tables are the approved
 * prototype's. A value is looked up by the stored MediaInfo `format`, else the codec id, else
 * the legacy format; an unmapped value shows the stored format string and never a codec id.
 */
final class MediaInfoNames
{
    /** Video, keyed by MediaInfo Format or legacy videoformat. */
    public const VIDEO = [
        'AVC' => 'H.264', 'HEVC' => 'H.265', 'AV1' => 'AV1', 'MPEG Video' => 'MPEG-2',
        'MPEG-4 Visual' => 'MPEG-4 (Xvid/DivX)', 'VP9' => 'VP9', 'xvid' => 'MPEG-4 (Xvid/DivX)',
    ];

    /** Video, keyed by codec id (the releases row chip's map). */
    public const VIDEO_CODEC_IDS = [
        'V_MPEG4/ISO/AVC' => 'H.264', 'V_MPEGH/ISO/HEVC' => 'H.265', 'V_AV1' => 'AV1', 'V_MPEG2' => 'MPEG-2',
    ];

    /** Audio: the full name. */
    public const AUDIO = [
        'E-AC-3' => 'Dolby Digital Plus', 'E-AC-3 JOC' => 'Dolby Digital Plus with Atmos', 'AC-3' => 'Dolby Digital',
        'AAC LC' => 'AAC', 'AAC LC SBR' => 'HE-AAC', 'DTS XLL' => 'DTS-HD Master Audio', 'DTS' => 'DTS',
        'MLP FBA' => 'Dolby TrueHD', 'FLAC' => 'FLAC', 'Opus' => 'Opus', 'MPEG Audio' => 'MP2/MP3', 'PCM' => 'PCM',
    ];

    /** Audio: the short name used in the glance row. */
    public const AUDIO_SHORT = [
        'E-AC-3' => 'E-AC-3', 'E-AC-3 JOC' => 'E-AC-3 Atmos', 'AC-3' => 'AC-3', 'AAC LC' => 'AAC',
        'AAC LC SBR' => 'HE-AAC', 'DTS XLL' => 'DTS-HD MA', 'MLP FBA' => 'TrueHD',
    ];

    public const CHANNELS = [1 => 'Mono', 2 => 'Stereo', 6 => '5.1', 8 => '7.1'];

    /** Channel counts by MediaInfo channel positions, for tracks that stored only the layout. */
    public const CHANNEL_LAYOUTS = ['1/0/0' => 1, '2/0/0' => 2, '3/2/0.1' => 6];

    /** Subtitles: the name, keyed by format or codec id. */
    public const SUBTITLES = [
        'UTF-8' => 'SRT', 'ASS' => 'ASS', 'PGS' => 'PGS', 'Timed Text' => 'Timed Text', 'S_TEXT/WEBVTT' => 'WebVTT',
    ];

    /** Picture-based subtitle formats: the only ones that get an "Image" chip. */
    public const PICTURE_SUBTITLES = ['PGS', 'VobSub', 'DVB Subtitle', 'RLE'];

    public const LANGUAGES = [
        'en' => 'English', 'eng' => 'English', 'ko' => 'Korean', 'kor' => 'Korean', 'ja' => 'Japanese', 'jpn' => 'Japanese',
        'pt' => 'Portuguese', 'por' => 'Portuguese', 'es' => 'Spanish', 'spa' => 'Spanish', 'fr' => 'French', 'fre' => 'French',
        'fra' => 'French', 'de' => 'German', 'ger' => 'German', 'deu' => 'German', 'it' => 'Italian', 'ita' => 'Italian',
        'nl' => 'Dutch', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish', 'pl' => 'Polish',
        'ru' => 'Russian', 'tr' => 'Turkish', 'ar' => 'Arabic', 'he' => 'Hebrew', 'hi' => 'Hindi', 'th' => 'Thai',
        'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay', 'zh' => 'Chinese', 'chi' => 'Chinese', 'zho' => 'Chinese',
        'cs' => 'Czech', 'hu' => 'Hungarian', 'ro' => 'Romanian', 'el' => 'Greek', 'uk' => 'Ukrainian',
    ];

    public static function video(?string $format, ?string $codec): ?string
    {
        $format = self::filled($format);

        return $format === null ? self::VIDEO_CODEC_IDS[(string) $codec] ?? null : self::VIDEO[$format] ?? $format;
    }

    /** @return array{name: string, short: string}|null */
    public static function audio(?string $format): ?array
    {
        $format = self::filled($format);
        if ($format === null) {
            return null;
        }

        return ['name' => self::AUDIO[$format] ?? $format, 'short' => self::AUDIO_SHORT[$format] ?? $format];
    }

    /** Atmos is Dolby's joint object coding, which MediaInfo writes as "JOC" in the format. */
    public static function atmos(?string $format): bool
    {
        return str_contains((string) $format, 'JOC');
    }

    /** "Mono", "Stereo", "5.1", "7.1", else "N channels"; the legacy text starts with the count. */
    public static function channels(int|string|null $channels, ?string $layout = null): ?string
    {
        $count = is_int($channels) ? $channels : (preg_match('/^\s*(\d+)/', (string) $channels, $match) === 1 ? (int) $match[1] : 0);
        if ($count <= 0) {
            $count = self::CHANNEL_LAYOUTS[(string) $layout] ?? 0;
        }

        return $count <= 0 ? null : self::CHANNELS[$count] ?? $count.' channels';
    }

    /**
     * HDR as chips: Dolby Vision (with its profile), then HDR10+ or HDR10; an HDR string none of
     * these match is shown as stored.
     *
     * @return list<array{label: string, kind: string}>
     */
    public static function hdr(?string $hdr): array
    {
        $hdr = self::filled($hdr);
        if ($hdr === null) {
            return [];
        }
        $chips = [];
        if (str_contains($hdr, 'Dolby Vision')) {
            $chips[] = ['label' => 'Dolby Vision'.(preg_match('/Profile ([\d.]+)/', $hdr, $match) === 1 ? ' · profile '.$match[1] : ''), 'kind' => 'dv'];
        }
        if (preg_match('/HDR10\+|SMPTE ST 2094 App 4/', $hdr) === 1) {
            $chips[] = ['label' => 'HDR10+', 'kind' => 'hdr10plus'];
        } elseif (preg_match('/HDR10|SMPTE ST 2086/', $hdr) === 1) {
            $chips[] = ['label' => 'HDR10', 'kind' => 'hdr'];
        }

        return $chips === [] ? [['label' => $hdr, 'kind' => 'other']] : $chips;
    }

    /** @return array{name: string, picture: bool}|null */
    public static function subtitle(?string $format, ?string $codec): ?array
    {
        $format = self::filled($format);
        $name = $format === null ? self::SUBTITLES[(string) $codec] ?? null : self::SUBTITLES[$format] ?? $format;

        return $name === null ? null : ['name' => $name, 'picture' => in_array($format, self::PICTURE_SUBTITLES, true)];
    }

    /** "en" reads "English", "pt-BR" "Portuguese (BR)"; a name already stored (legacy) is kept. */
    public static function language(?string $language): ?string
    {
        $language = self::filled($language);
        if ($language === null) {
            return null;
        }
        $parts = preg_split('/[-_]/', $language, 2) ?: [$language];
        $name = self::LANGUAGES[strtolower($parts[0])] ?? null;
        if ($name === null) {
            return $language;
        }
        if (! isset($parts[1])) {
            return $name;
        }

        return $name.' ('.($parts[1] === '419' ? 'Latin America' : strtoupper($parts[1])).')';
    }

    private static function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
