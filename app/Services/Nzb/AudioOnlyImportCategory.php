<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Category;
use App\Services\AdditionalProcessing\PostedFileClassifier;

/**
 * Picks the Music category for an imported NZB whose content files are all
 * audio, from the posted file extensions alone.
 */
final class AudioOnlyImportCategory
{
    /** Files that travel with a release and say nothing about its content. */
    private const array SIDECAR_EXTENSIONS = [
        'par2', 'nfo', 'sfv', 'md5', 'txt', 'log', 'cue', 'm3u', 'm3u8', 'accurip',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'nzb',
    ];

    private const array LOSSLESS_EXTENSIONS = ['flac', 'ape', 'wav', 'aiff', 'aif', 'dsf', 'dff', 'tak', 'tta', 'wv'];

    private const array LOSSY_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'opus', 'wma', 'mpc'];

    private const string AUDIOBOOK_EXTENSION = 'm4b';

    /**
     * The Music category for these NZB file subjects, or null when any content
     * file is not audio or no audio file is present.
     *
     * @param  array<array-key, string>  $subjects
     */
    public static function categoryFor(array $subjects): ?int
    {
        $audioFiles = 0;
        $audiobookFiles = 0;
        $hasLossless = false;

        foreach ($subjects as $subject) {
            $extension = self::extension(PostedFileClassifier::postedFilename($subject));

            if ($extension === null) {
                return null;
            }
            if (in_array($extension, self::SIDECAR_EXTENSIONS, true)) {
                continue;
            }

            if (in_array($extension, self::LOSSLESS_EXTENSIONS, true)) {
                $hasLossless = true;
            } elseif ($extension === self::AUDIOBOOK_EXTENSION) {
                $audiobookFiles++;
            } elseif (! in_array($extension, self::LOSSY_EXTENSIONS, true)) {
                return null;
            }
            $audioFiles++;
        }

        return match (true) {
            $audioFiles === 0 => null,
            $audiobookFiles === $audioFiles => Category::MUSIC_AUDIOBOOK,
            $hasLossless => Category::MUSIC_LOSSLESS,
            default => Category::MUSIC_MP3,
        };
    }

    /**
     * The lowercased text after the last dot, or null for a name with none.
     */
    private static function extension(string $filename): ?string
    {
        $dot = strrpos($filename, '.');

        return $dot === false ? null : mb_strtolower(substr($filename, $dot + 1));
    }
}
