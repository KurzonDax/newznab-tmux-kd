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
 * the opening quote, counters stack behind them, and a size annotation such
 * as ' - 288,96 MB' can sit after the closing quote. All of it is stripped
 * before the quote unwrap, which still requires the whole remainder to be the
 * quoted span. That requirement is the guard for subjects carrying the real
 * release name outside the quotes ('Title - [1/3] - "Title.rar"'): the
 * leading strip removes nothing from a string that starts with a letter or a
 * digit, so the remainder is not a bare quoted span and the title survives.
 */
final class ReleaseNameNormalizer
{
    /** One leading multipart counter: [10/88], (002/159) */
    private const string COUNTER_PREFIX_REGEX = '/^[\[(]\d+\/\d+[\])]\s*/';

    /** A trailing yEnc marker, optionally separated from the filename by whitespace. */
    private const string YENC_SUFFIX_REGEX = '/\s+yenc\s*$/i';

    /**
     * Leading poster decoration that carries no name of its own: ' - ', '_-_', ' – '.
     *
     * The double quote is excluded so the unwrap below can still fire, and the
     * opening brackets are excluded so a counter waiting behind a separator
     * stays matchable instead of losing its bracket to this strip.
     */
    private const string LEADING_RESIDUE_REGEX = '/^[^\p{L}\p{N}"\[(]+/u';

    /**
     * The same class in bytes, for a subject that is not valid UTF-8.
     *
     * High bytes are kept rather than stripped: without the character
     * boundaries the /u flag provides there is no telling a non-ASCII letter
     * from non-ASCII punctuation, and keeping the name is the safer miss.
     */
    private const string LEADING_RESIDUE_FALLBACK_REGEX = '/^[^A-Za-z0-9"\[(\x80-\xFF]+/';

    /** A trailing size annotation: ' - 288,96 MB', '- 7,87 GB'. */
    private const string SIZE_SUFFIX_REGEX = '/'.CollectionsCleaningService::REGEX_SUBJECT_SIZE.'$/';

    /** A filename that is wrapped in double quotes after outer subject decoration is removed. */
    private const string WRAPPING_QUOTES_REGEX = '/^"([^"]*)"$/';

    /** Par2 recovery volume suffix: .vol012+10.par2 */
    private const string VOLUME_SUFFIX_REGEX = '/\.vol\d+\+\d+\.par2$/i';

    /**
     * Suffix of a file that is not the release itself: .part018.rar, .rar, .par2, .nfo.
     *
     * The NFO belongs here for the same dedupe reason as the first RAR volume:
     * an upload first seen as its NFO and one first seen as its first volume
     * must reduce to the same string.
     */
    private const string ARCHIVE_OR_NFO_SUFFIX_REGEX = '/(\.part\d+)?\.(rar|7z|zip|par2|nfo)$/i';

    public static function normalize(string $name): string
    {
        $normalized = self::unwrapSubject($name);

        $normalized = (string) preg_replace(self::VOLUME_SUFFIX_REGEX, '', $normalized);
        $normalized = (string) preg_replace(self::ARCHIVE_OR_NFO_SUFFIX_REGEX, '', $normalized);

        return trim($normalized);
    }

    /**
     * The subject-unwrap phase alone: poster decoration off the outside of the
     * name, case preserved, the filename kept whole.
     *
     * Shared by {@see self::normalize()} and the display formatter, which must
     * keep (and uppercase) a real trailing extension the archive-identity
     * strips in normalize() would remove.
     */
    public static function unwrapSubject(string $name): string
    {
        $unwrapped = trim($name);

        $unwrapped = (string) preg_replace(self::YENC_SUFFIX_REGEX, '', $unwrapped);
        $unwrapped = (string) preg_replace(self::SIZE_SUFFIX_REGEX, '', trim($unwrapped));
        $unwrapped = self::stripLeadingDecoration(trim($unwrapped));

        if (preg_match(self::WRAPPING_QUOTES_REGEX, $unwrapped, $hit) === 1) {
            $unwrapped = trim($hit[1]);
        }

        return trim($unwrapped);
    }

    /**
     * Peels counters and separator residue off the front in whichever order
     * the poster stacked them, so '[1/8] - [01/17] - ' leaves nothing behind.
     *
     * Stripping only once would half-remove the second counter and leave a
     * worse name than it started with.
     */
    private static function stripLeadingDecoration(string $name): string
    {
        do {
            $previous = $name;
            $name = (string) preg_replace(self::COUNTER_PREFIX_REGEX, '', $name);
            $name = self::stripLeadingResidue($name);
        } while ($name !== $previous);

        return $name;
    }

    private static function stripLeadingResidue(string $name): string
    {
        return preg_replace(self::LEADING_RESIDUE_REGEX, '', $name)
            ?? (string) preg_replace(self::LEADING_RESIDUE_FALLBACK_REGEX, '', $name);
    }
}
