<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CollectionsCleaningService;

/**
 * Strips the leftovers of a raw Usenet subject from a release/search name.
 *
 * A searchname must never keep the double quotes that wrapped the filename in
 * the subject, nor the archive volume suffix of whichever part happened to be
 * the first article seen. Two copies of the same upload only dedupe when both
 * sides reduce to the same string.
 *
 * Posters decorate the filename with more than the multipart counter and the
 * yEnc marker: separators such as ' - ' or '_-_' sit between the counter and
 * the opening quote, and a size annotation such as ' - 288,96 MB' can sit
 * after the closing one. Both are stripped before the quote unwrap, which
 * still requires the whole remainder to be the quoted span. That requirement
 * is the guard for subjects carrying the real release name outside the
 * quotes ('Title - [1/3] - "Title.rar"'): the leading strip removes nothing
 * from a string that starts with alphanumeric text, so the remainder is not a
 * bare quoted span and the title survives.
 */
final class ReleaseNameNormalizer
{
    /** Leading multipart counter decoration: [10/88] */
    private const string COUNTER_PREFIX_REGEX = '/^(?:\[\d+\/\d+\]\s*)+/';

    /** A trailing yEnc marker, optionally separated from the filename by whitespace. */
    private const string YENC_SUFFIX_REGEX = '/\s+yenc\s*$/i';

    /**
     * Leading poster decoration that carries no name of its own: ' - ', '_-_', '. '.
     *
     * Deliberately byte-oriented and without the /u flag so a subject that is
     * not valid UTF-8 still normalizes instead of collapsing to an empty
     * string. High bytes are kept so a name opening on a non-ASCII letter is
     * left alone, and the double quote is kept so the unwrap below can fire.
     */
    private const string LEADING_RESIDUE_REGEX = '/^[^A-Za-z0-9"\x80-\xFF]+/';

    /**
     * A trailing size annotation: ' - 288,96 MB', '- 7,87 GB'.
     *
     * Mirrors {@see CollectionsCleaningService::REGEX_SUBJECT_SIZE},
     * anchored to the end of the string.
     */
    private const string SIZE_SUFFIX_REGEX = '/[\-_\s]{0,3}\d+([.,]\d+)? [kKmMgG][bB][\-_\s]{0,3}$/';

    /** A filename that is wrapped in double quotes after outer subject decoration is removed. */
    private const string WRAPPING_QUOTES_REGEX = '/^"([^"]*)"$/';

    /** Par2 recovery volume suffix: .vol012+10.par2 */
    private const string VOLUME_SUFFIX_REGEX = '/\.vol\d+\+\d+\.par2$/i';

    /** Archive suffix, with or without a part number: .part018.rar, .rar, .par2, .nfo */
    private const string ARCHIVE_SUFFIX_REGEX = '/(\.part\d+)?\.(rar|7z|zip|par2|nfo)$/i';

    public static function normalize(string $name): string
    {
        $normalized = trim($name);

        $normalized = (string) preg_replace(self::COUNTER_PREFIX_REGEX, '', $normalized);
        $normalized = (string) preg_replace(self::YENC_SUFFIX_REGEX, '', trim($normalized));
        $normalized = (string) preg_replace(self::SIZE_SUFFIX_REGEX, '', trim($normalized));
        $normalized = (string) preg_replace(self::LEADING_RESIDUE_REGEX, '', trim($normalized));
        $normalized = trim($normalized);

        if (preg_match(self::WRAPPING_QUOTES_REGEX, $normalized, $hit) === 1) {
            $normalized = trim($hit[1]);
        }

        $normalized = (string) preg_replace(self::VOLUME_SUFFIX_REGEX, '', $normalized);
        $normalized = (string) preg_replace(self::ARCHIVE_SUFFIX_REGEX, '', $normalized);

        return trim($normalized);
    }
}
