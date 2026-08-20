<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Strips the leftovers of a raw Usenet subject from a release/search name.
 *
 * A searchname must never keep the double quotes that wrapped the filename in
 * the subject, nor the archive volume suffix of whichever part happened to be
 * the first article seen. Two copies of the same upload only dedupe when both
 * sides reduce to the same string.
 */
final class ReleaseNameNormalizer
{
    /** A name that is wrapped in double quotes end to end, with no quotes inside. */
    private const string WRAPPING_QUOTES_REGEX = '/^"([^"]*)"$/';

    /** Par2 recovery volume suffix: .vol012+10.par2 */
    private const string VOLUME_SUFFIX_REGEX = '/\.vol\d+\+\d+\.par2$/i';

    /** Archive suffix, with or without a part number: .part018.rar, .rar, .par2 */
    private const string ARCHIVE_SUFFIX_REGEX = '/(\.part\d+)?\.(rar|7z|zip|par2)$/i';

    public static function normalize(string $name): string
    {
        $normalized = trim($name);

        if (preg_match(self::WRAPPING_QUOTES_REGEX, $normalized, $hit) === 1) {
            $normalized = trim($hit[1]);
        }

        $normalized = (string) preg_replace(self::VOLUME_SUFFIX_REGEX, '', $normalized);
        $normalized = (string) preg_replace(self::ARCHIVE_SUFFIX_REGEX, '', $normalized);

        return trim($normalized);
    }
}
