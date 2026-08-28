<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Renders a release's searchname as something a person can read.
 *
 * Filename-derived searchnames arrive dot-separated
 * ("FC2.PPV.4963596.No.Amazing...1080p.mp4"), which is unreadable in a browse
 * list. Spacing them is almost right: a handful of tokens carry meaning in
 * their separators — dates, version numbers, and audio/video codec names — and
 * must survive verbatim.
 *
 * The rules are a recognition allowlist, never a guess: a token this class does
 * not recognise degrades to plain spacing rather than to a wrong reading. That
 * makes the result deterministic and pure, so the derived column can be rebuilt
 * in bulk whenever the rules improve.
 *
 * Category-aware rules (Books, Audio, PC) are a deliberate future extension:
 * add a second recognition pass, do not special-case inside the generic one.
 */
final class ReleaseDisplayNameFormatter
{
    /** The width of releases.display_name. */
    private const int MAX_LENGTH = 255;

    /**
     * Spans whose separators are part of the token and must be copied verbatim.
     *
     * Order matters: the scanner takes the leftmost match and consumes it, so
     * longer and more specific shapes are listed before the ones they contain.
     */
    private const string PROTECTED_SPANS_REGEX = '/'
        // YYYY.MM.DD and NN.NN.NN with a plausible month. The date order is
        // deliberately not decided here — both shapes are simply preserved.
        .'(?<!\d)\d{4}\.(?:0[1-9]|1[0-2])\.\d{2}(?!\d)'
        .'|(?<!\d)\d{2}\.(?:0[1-9]|1[0-2])\.\d{2}(?!\d)'
        // Dotted version numbers: v1.2.3
        .'|(?<![\p{L}\d])[vV]\d+(?:\.\d+)+(?!\d)'
        // Audio/video tokens whose dot is part of the name.
        .'|(?<![\p{L}\d])(?:DDP5\.1|DD5\.1|AAC2\.0|H\.26[45])(?!\d)'
        // Not preceded by a digit, nor by a dot that follows one: the "5.1" inside
        // "H.265.1080p" or "1.5.1" is part of a longer number, not a channel layout.
        .'|(?<!\d)(?<!\d\.)(?:7\.1|5\.1|2\.0)(?!\d)'
        .'/iu';

    /**
     * Container extensions kept, uppercased, when they end the name.
     *
     * An allowlist, so a name ending in a short word ("...lilit.red") is spaced
     * like the rest instead of being shouted as an extension.
     */
    private const string TRAILING_EXTENSION_REGEX = '/\.(mp4|mkv|avi|wmv|mov|m4v|flv|mpe?g|m2ts|ts|webm|vob|iso|img'
        .'|mp3|flac|m4a|aac|ogg|opus|wav|wma'
        .'|epub|mobi|azw3|pdf|cbr|cbz|djvu'
        .'|rar|zip|7z|par2|nfo|srt|sub)$/i';

    /**
     * Format a searchname for display. Deterministic, pure, and idempotent.
     *
     * A searchname can still carry raw-subject decoration (a leading ' - ',
     * multipart counters, wrapping quotes, a trailing size annotation), which
     * would defeat the extension rule and survive into the display. The shared
     * subject unwrap runs first; already-clean names pass through it verbatim.
     */
    public static function format(string $searchName): string
    {
        $name = ReleaseNameNormalizer::unwrapSubject($searchName);
        if ($name === '') {
            return '';
        }

        $extension = '';
        if (preg_match(self::TRAILING_EXTENSION_REGEX, $name, $hit) === 1) {
            $extension = ' '.strtoupper($hit[1]);
            $name = substr($name, 0, -\strlen($hit[0]));
        }

        $display = self::spaceAroundProtectedSpans($name).$extension;
        $display = trim((string) preg_replace('/\s+/', ' ', $display));

        return mb_substr($display, 0, self::MAX_LENGTH);
    }

    /**
     * The name to show a user: the derived one, falling back to the searchname.
     *
     * Rows written before the column existed — and any write path that has not
     * been routed through Release::searchNameValues() — leave it null.
     *
     * @param  object|array<string, mixed>|null  $release
     */
    public static function displayFor(object|array|null $release): string
    {
        if ($release === null) {
            return '';
        }

        // Property access rather than get_object_vars(): rows arrive as plain
        // stdClass from the browse queries and as Eloquent models from the
        // details page, and only the model resolves its columns through __get.
        if (\is_array($release)) {
            $displayName = $release['display_name'] ?? null;
            $searchName = $release['searchname'] ?? null;
        } else {
            $displayName = $release->display_name ?? null;
            $searchName = $release->searchname ?? null;
        }

        $displayName = trim((string) $displayName);

        return $displayName !== '' ? $displayName : (string) $searchName;
    }

    /**
     * Copy every recognised span verbatim and space the text between them.
     */
    private static function spaceAroundProtectedSpans(string $name): string
    {
        $display = '';
        $offset = 0;

        while (
            $offset <= \strlen($name)
            && preg_match(self::PROTECTED_SPANS_REGEX, $name, $hit, PREG_OFFSET_CAPTURE, $offset) === 1
        ) {
            [$span, $start] = $hit[0];
            $display .= self::space(substr($name, $offset, $start - $offset)).$span;
            $offset = $start + \strlen($span);
        }

        return $display.self::space(substr($name, $offset));
    }

    private static function space(string $text): string
    {
        return str_replace(['.', '_'], ' ', $text);
    }
}
