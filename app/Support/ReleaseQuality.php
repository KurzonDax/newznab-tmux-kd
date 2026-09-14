<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BrowseRoot;

final class ReleaseQuality
{
    public static function fromName(BrowseRoot $root, string $name): string
    {
        if ($root === BrowseRoot::Audio) {
            if (preg_match('/\bFLAC\b/i', $name)) {
                return preg_match('/\b24[ ._-]?(?:bit|bits)\b/i', $name) ? '24-bit FLAC' : 'FLAC';
            }
            if (preg_match('/\b(MP3|AAC|ALAC|OGG|OPUS|WAV)\b/i', $name, $match)) {
                return strtoupper($match[1]);
            }
        } elseif (preg_match('/\b(2160|1080|720|576|480)[pi]\b/i', $name, $match)) {
            return strtolower($match[0]);
        } elseif ($root === BrowseRoot::Books && preg_match('/\b(EPUB|PDF|MOBI|AZW3?)\b/i', $name, $match)) {
            return strtoupper($match[1]);
        }

        return '';
    }

    public static function label(BrowseRoot $root, string $name): string
    {
        $quality = self::fromName($root, $name);
        $source = preg_match('/\b(WEB[ ._-]?DL|WEBRip|WEB|BluRay|BDRip|BRRip|HDTV|DVDRip|DVD)\b/i', $name, $match)
            ? str_replace(['.', '_', ' '], '-', $match[1]) : '';

        return implode(' · ', array_filter([$quality, $source]));
    }
}
