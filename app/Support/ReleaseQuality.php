<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BrowseRoot;
use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;

final class ReleaseQuality
{
    /**
     * A resolution or source token stands alone when no letter or digit touches it, so
     * `.`, `-`, ` ` and `_` all separate. Written so MariaDB REGEXP reads it the same way.
     */
    private const TOKEN_START = '(?i)(?<![a-z0-9])';

    private const TOKEN_END = '(?![a-z0-9])';

    /** Video resolution name token. */
    public const RESOLUTION_PATTERN = self::TOKEN_START.'(2160|1080|720|576|480)[pi]'.self::TOKEN_END;

    public static function fromName(BrowseRoot $root, string $name): string
    {
        if ($root === BrowseRoot::Audio) {
            if (preg_match('/\bFLAC\b/i', $name)) {
                return preg_match('/\b24[ ._-]?(?:bit|bits)\b/i', $name) ? '24-bit FLAC' : 'FLAC';
            }
            if (preg_match('/\b(MP3|AAC|ALAC|OGG|OPUS|WAV)\b/i', $name, $match)) {
                return strtoupper($match[1]);
            }
        } elseif (($resolution = self::resolutionToken($name)) !== '') {
            return $resolution;
        } elseif ($root === BrowseRoot::Books && preg_match('/\b(EPUB|PDF|MOBI|AZW3?)\b/i', $name, $match)) {
            return strtoupper($match[1]);
        }

        return '';
    }

    public static function label(BrowseRoot $root, string $name): string
    {
        $quality = self::fromName($root, $name);
        $source = preg_match(self::regex(self::sourcePattern()), $name, $match)
            ? str_replace(['.', '_', ' '], '-', $match[1]) : '';

        return implode(' · ', array_filter([$quality, $source]));
    }

    /** The leftmost resolution token in the name, lower-cased, or ''. */
    public static function resolutionToken(string $name): string
    {
        return preg_match(self::regex(self::RESOLUTION_PATTERN), $name, $match) ? strtolower($match[0]) : '';
    }

    /**
     * The one source pattern: every source's name tokens, or only the given source's.
     * Written so MariaDB REGEXP reads it the same way.
     */
    public static function sourcePattern(?ReleaseSource $source = null): string
    {
        $tokens = $source?->nameTokens()
            ?? implode('|', array_map(fn (ReleaseSource $known): string => $known->nameTokens(), ReleaseSource::precedence()));

        return self::TOKEN_START.'('.$tokens.')'.self::TOKEN_END;
    }

    /** Measured video size when there is one (zeros are not a measurement), else the name. */
    public static function resolution(?int $width, ?int $height, string $name): ReleaseResolution
    {
        return ReleaseResolution::fromMeasured(max(0, $width ?? 0), max(0, $height ?? 0))
            ?? ReleaseResolution::fromNameToken(self::resolutionToken($name));
    }

    /** The strongest source the name declares; media info cannot tell a source. */
    public static function source(string $name): ReleaseSource
    {
        foreach (ReleaseSource::precedence() as $source) {
            if (preg_match(self::regex(self::sourcePattern($source)), $name)) {
                return $source;
            }
        }

        return ReleaseSource::Unknown;
    }

    private static function regex(string $pattern): string
    {
        return '~'.$pattern.'~';
    }
}
