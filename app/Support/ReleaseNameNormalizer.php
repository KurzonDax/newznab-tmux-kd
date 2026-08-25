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
    /** Leading multipart counter decoration: [10/88] */
    private const string COUNTER_PREFIX_REGEX = '/^(?:\[\d+\/\d+\]\s*)+/';

    /** A trailing yEnc marker, optionally separated from the filename by whitespace. */
    private const string YENC_SUFFIX_REGEX = '/\s+yenc\s*$/i';

    /** A filename that is wrapped in double quotes after outer subject decoration is removed. */
    private const string WRAPPING_QUOTES_REGEX = '/^"([^"]*)"$/';

    /** Par2 recovery volume suffix: .vol012+10.par2 */
    private const string VOLUME_SUFFIX_REGEX = '/\.vol\d+\+\d+\.par2$/i';

    /** Archive suffix, with or without a part number: .part018.rar, .rar, .par2 */
    private const string ARCHIVE_SUFFIX_REGEX = '/(\.part\d+)?\.(rar|7z|zip|par2)$/i';

    public static function normalize(string $name): string
    {
        $normalized = trim($name);

        $normalized = (string) preg_replace(self::COUNTER_PREFIX_REGEX, '', $normalized);
        $normalized = (string) preg_replace(self::YENC_SUFFIX_REGEX, '', trim($normalized));
        $normalized = trim($normalized);

        if (preg_match(self::WRAPPING_QUOTES_REGEX, $normalized, $hit) === 1) {
            $normalized = trim($hit[1]);
        }

        $normalized = (string) preg_replace(self::VOLUME_SUFFIX_REGEX, '', $normalized);
        $normalized = (string) preg_replace(self::ARCHIVE_SUFFIX_REGEX, '', $normalized);

        return trim($normalized);
    }
}
