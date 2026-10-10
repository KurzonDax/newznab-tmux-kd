<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Matching;

use App\Services\MusicIdentity\DTO\ReleaseNameAlbum;

/**
 * Reads artist, album title and year out of a release name (issue #1033). A name can be read more
 * than one way, so every reading is returned, most likely first; the rules and their order are
 * those of the issue's reference implementation, which the measured match counts came from.
 */
final class ReleaseNameAlbumParser
{
    private const string YEAR = '(?:19|20)[0-9]{2}';

    private const string OPEN = '[\[({]';

    private const string CLOSE = '[\])}]';

    private const string GROUP = self::OPEN.'([^\[\](){}]*)'.self::CLOSE;

    private const string COUNTER = '[\[(]\s*\d+\s*\/\s*\d+\s*[\])]';

    private const string FILE_SUFFIX = '/(?:\.part\d+\.rar|\.vol\d+\+\d+\.par2|\.par2|\.rar|\.r\d{2}|\.nzb|\.zip|\.7z|\.sfv|\.nfo|\.m3u|\.cue|\.log'
        .'|\.flac|\.mp3|\.wav|\.wv|\.ape|\.m4a|\.ogg|\.opus)$/iu';

    /** The characters trimmed from both ends of an artist, a title and a format tail. */
    private const string EDGE = ' _.-';

    private const array TRAILING_FORMAT_WORDS = ['flac', 'mp3', 'wav', 'wv', 'ape', 'm4a', 'ogg', 'opus', 'aac', 'alac'];

    private const array FORMAT_WORDS = [
        'flac', 'mp3', 'wav', 'wv', 'ape', 'm4a', 'ogg', 'opus', 'aac', 'alac', 'dsd', 'sacd', 'web', 'cd', 'cdda', 'vinyl', 'lp',
        '320', '256', '192', 'v0', 'v2', '320kbps', 'kbps', 'lossless', '24bit', '16bit', '24', '16', 'hi', 'res', 'hires',
    ];

    /**
     * Words that name a different work than the same title without them. A bracket group holding
     * one is never dropped from the title, and a release group must answer each one in the title.
     */
    public const array DIFFERENT_WORK = [
        'remix', 'remixes', 'remixed', 'mix', 'mixes', 'live', 'instrumental', 'instrumentals', 'acoustic', 'demo', 'demos',
        'karaoke', 'tribute', 'session', 'sessions', 'single', 'singles', 'ep', 'unplugged', 'bootleg', 'dub', 'reprise',
    ];

    /** @return list<ReleaseNameAlbum> */
    public function parse(string $releaseName): array
    {
        if (! mb_check_encoding($releaseName, 'UTF-8')) {
            return [];
        }

        $albums = [];
        foreach ($this->nameCandidates($releaseName) as $candidate) {
            array_push($albums, ...$this->parsesOf($candidate));
        }

        return $albums;
    }

    /** Lowercase words only: `&` reads "and", apostrophes vanish, everything else that is not a letter or digit separates words. */
    public function fold(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = str_replace('&', ' and ', mb_strtolower($text));
        $text = str_replace(['’', "'", '`'], '', $text);
        $text = trim($this->replace('/[^\p{L}\p{N}]+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * The texts a name may hold an album in: what stands before the first double quote, then the
     * first quoted text; the whole name when it has no quote.
     *
     * @return list<string>
     */
    private function nameCandidates(string $name): array
    {
        $quote = strpos($name, '"');
        if ($quote === false) {
            $candidates = [$this->tidy($name)];
        } else {
            $candidates = [$this->tidy(substr($name, 0, $quote))];
            if (preg_match('/"([^"]+)"/u', $name, $quoted) === 1) {
                $candidates[] = $this->tidy($quoted[1]);
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')));
    }

    /** Drops what a post adds around the name: a link, file suffixes, part counters, wrapping parentheses. */
    private function tidy(string $name): string
    {
        $name = $this->strip($this->replace('/\s+https?:\/\/\S+.*$/iu', '', $name));
        do {
            $before = $name;
            $name = $this->strip($this->replace(self::FILE_SUFFIX, '', $name));
        } while ($name !== $before);
        $name = $this->replace('/^\s*'.self::COUNTER.'\s*(?:-\s*)?/u', '', $name);
        $name = $this->replace('/\s*(?:-\s*)?'.self::COUNTER.'\s*(?:-\s*)?$/u', '', $name);
        $name = trim($name, ' -');

        return $this->wrappedInParentheses($name) ? $this->strip(substr($name, 1, -1)) : $name;
    }

    /** Whether the opening parenthesis at the start closes only at the last character. */
    private function wrappedInParentheses(string $name): bool
    {
        if (! str_starts_with($name, '(') || ! str_ends_with($name, ')')) {
            return false;
        }

        $depth = 0;
        $last = strlen($name) - 1;
        for ($index = 0; $index <= $last; $index++) {
            if ($name[$index] === '(') {
                $depth++;
            } elseif ($name[$index] === ')') {
                $depth--;
            }
            if ($depth === 0 && $index < $last) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ReleaseNameAlbum> */
    private function parsesOf(string $candidate): array
    {
        $parses = [];
        if (str_contains($candidate, ' - ')) {
            $segments = array_map($this->strip(...), explode(' - ', $candidate));
            if (count($segments) >= 3 && $this->fold($segments[1]) === $this->fold($segments[0])) {
                array_splice($segments, 1, 1);
            }
            $readings = [[$segments[0], implode(' - ', array_slice($segments, 1))]];
            if (count($segments) >= 3) {
                $readings[] = [$segments[0], $segments[1]];
                // The second segment is not read as the artist behind a one-word first segment holding a digit ("oz1978_004").
                if (preg_match('/\d/u', $segments[0]) !== 1 || preg_match('/\s/u', $segments[0]) === 1) {
                    $readings[] = [$segments[1], implode(' - ', array_slice($segments, 2))];
                }
            }
            foreach ($readings as [$artist, $rest]) {
                [$title, $year] = $this->splitYear($rest);
                $parses[] = $this->finish($artist, $title, $year);
            }
        } elseif (! str_contains($candidate, ' ') && substr_count($candidate, '-') >= 2) {
            $segments = array_values(array_filter(explode('-', $candidate), static fn (string $segment): bool => $segment !== ''));
            if (count($segments) >= 3) {
                $year = null;
                foreach (array_slice($segments, 2) as $segment) {
                    if ($this->isYear($segment)) {
                        $year = (int) $segment;
                        break;
                    }
                }
                $parses[] = $this->finish($this->spaced($segments[0]), $this->spaced($segments[1]), $year);
                if ($this->isYear($segments[1])) {
                    $parses[] = $this->finish($this->spaced($segments[0]), $this->spaced($segments[2]), (int) $segments[1]);
                }
            }
        } elseif (preg_match('/^(.+?)-('.self::YEAR.')-(.+)$/u', $candidate, $match) === 1) {
            [$title] = $this->splitYear($match[3]);
            $parses[] = $this->finish($match[1], $title, (int) $match[2]);
        }

        return array_values(array_filter($parses));
    }

    /**
     * The title and year in what follows the artist: the last bracketed year, else a year at the
     * end, else a leading "YYYY - ".
     *
     * @return array{string, int|null}
     */
    private function splitYear(string $rest): array
    {
        $title = $this->stripFormatTail($rest);
        $bracketed = '/'.self::OPEN.'('.self::YEAR.')(?:[-.]\d{2}[-.]\d{2})?'.self::CLOSE.'/u';
        if (preg_match_all($bracketed, $title, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            $last = $matches[array_key_last($matches)];
            $after = $last[0][1] + strlen($last[0][0]);

            return [$this->stripFormatTail(substr($title, 0, $last[0][1]).' '.substr($title, $after)), (int) $last[1][0]];
        }
        if (preg_match('/\s+('.self::YEAR.')$/u', $title, $match, PREG_OFFSET_CAPTURE) === 1) {
            return [$this->stripFormatTail(substr($title, 0, $match[0][1])), (int) $match[1][0]];
        }
        if (preg_match('/^('.self::YEAR.')\s+-\s+(.+)$/u', $title, $match) === 1) {
            return [$this->stripFormatTail($match[2]), (int) $match[1]];
        }

        return [$title, null];
    }

    /** Removes trailing format groups ("[FLAC]", "(320)") and trailing format words until none is left. */
    private function stripFormatTail(string $text): string
    {
        while (true) {
            $before = $text;
            $text = trim($text, self::EDGE);
            if (preg_match('/'.self::GROUP.'\s*$/u', $text, $group, PREG_OFFSET_CAPTURE) === 1) {
                $words = $this->fold($group[1][0]);
                if ($this->onlyFormatTokens($words) && preg_match('/^'.self::YEAR.'(?:\s|$)/u', $words ?? '') !== 1) {
                    $text = substr($text, 0, $group[0][1]);

                    continue;
                }
            }
            if (preg_match('/[\s\-_.]([A-Za-z0-9]+)$/u', $text, $word, PREG_OFFSET_CAPTURE) === 1
                && in_array(strtolower($word[1][0]), self::TRAILING_FORMAT_WORDS, true)) {
                $text = substr($text, 0, $word[0][1]);
            }
            if (trim($text, self::EDGE) === trim($before, self::EDGE)) {
                return trim($text, self::EDGE);
            }
        }
    }

    private function onlyFormatTokens(?string $words): bool
    {
        foreach ($words === null ? [] : explode(' ', $words) as $word) {
            if (! in_array($word, self::FORMAT_WORDS, true) && preg_match('/^\d+(?:kbps|bit|khz)?\z/u', $word) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function finish(string $artist, string $title, ?int $year): ?ReleaseNameAlbum
    {
        $artist = trim($artist, self::EDGE);
        $title = trim($title, self::EDGE);
        if (in_array($this->fold($artist), ['va', 'various', 'various artists'], true)) {
            $artist = 'Various Artists';
        }
        if ($this->fold($artist) === null || $this->fold($title) === null) {
            return null;
        }

        return new ReleaseNameAlbum($artist, $this->variantsOf($title), $year);
    }

    /**
     * The title, then the title without its bracket groups when that still reads as a title and
     * no removed group names a different work.
     *
     * @return non-empty-list<string>
     */
    private function variantsOf(string $title): array
    {
        preg_match_all('/'.self::GROUP.'/u', $title, $groups);
        $removedWords = explode(' ', $this->fold(implode(' ', $groups[1])) ?? '');
        $bare = trim($this->replace('/\s*'.self::GROUP.'/u', '', $title), self::EDGE);
        $bareWords = $this->fold($bare);
        if ($bareWords === null
            || $bareWords === $this->fold($title)
            || array_intersect($removedWords, self::DIFFERENT_WORK) !== []) {
            return [$title];
        }

        return [$title, $bare];
    }

    private function isYear(string $text): bool
    {
        return preg_match('/^'.self::YEAR.'\z/', $text) === 1;
    }

    /** A scene name's segment with its `_` and `.` runs read as spaces. */
    private function spaced(string $segment): string
    {
        return $this->strip($this->replace('/[_.]+/u', ' ', $segment));
    }

    private function strip(string $text): string
    {
        return $this->replace('/^\s+|\s+$/u', '', $text);
    }

    private function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
