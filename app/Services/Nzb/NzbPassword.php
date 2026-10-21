<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/**
 * The archive password an NZB carries in its header, as `<meta type="password">secret</meta>`.
 *
 * SABnzbd and NZBGet read the tag and open the release's archives without asking. The stored NZB
 * is the file users download, so a password read from it is the one their downloader uses too.
 */
final class NzbPassword
{
    /**
     * The first non-empty password meta in the NZB's header, or null when there is none.
     *
     * Only the text before the first `<file` is read: the header comes first, so a large NZB is
     * never scanned whole. A header-only slice is not a well-formed document, hence the regex.
     */
    public static function fromXml(string $xml): ?string
    {
        $fileStart = stripos($xml, '<file');
        $head = $fileStart === false ? $xml : substr($xml, 0, $fileStart);

        if (preg_match_all('/<meta\b([^>]*)(?<!\/)>(.*?)<\/meta\s*>/is', $head, $metas, PREG_SET_ORDER) < 1) {
            return null;
        }

        foreach ($metas as [, $attributes, $value]) {
            if (preg_match('/\btype\s*=\s*(["\'])(.*?)\1/is', $attributes, $type) !== 1
                || mb_strtolower(trim($type[2])) !== 'password') {
                continue;
            }

            $password = trim(html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($password !== '') {
                return $password;
            }
        }

        return null;
    }

    /**
     * The NZB with `<meta type="password">` added to its header, creating the header when there
     * is none.
     *
     * The tag is inserted by byte offset rather than through a regex replacement string, which
     * would mangle a password containing `$1` or `\`. Unprefixed, it takes the document's default
     * namespace, so `->head->meta` reads it.
     */
    public static function withPassword(string $xml, string $password): string
    {
        $meta = '<meta type="password">'.htmlspecialchars($password, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</meta>';
        $fileStart = stripos($xml, '<file');
        $head = $fileStart === false ? $xml : substr($xml, 0, $fileStart);

        if (preg_match('/<head\b[^>]*>/i', $head, $tag, PREG_OFFSET_CAPTURE) === 1) {
            [$startTag, $offset] = $tag[0];
            if (str_ends_with($startTag, '/>')) {
                return substr_replace($xml, '<head>'.$meta.'</head>', $offset, strlen($startTag));
            }

            return substr_replace($xml, $meta, $offset + strlen($startTag), 0);
        }

        if (preg_match('/<nzb\b[^>]*>/i', $xml, $tag, PREG_OFFSET_CAPTURE) === 1) {
            [$startTag, $offset] = $tag[0];

            return substr_replace($xml, '<head>'.$meta.'</head>', $offset + strlen($startTag), 0);
        }

        return $xml;
    }
}
