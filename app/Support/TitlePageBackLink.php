<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The back link of a section's title page (the TV show page, the film page): the list or wall
 * the user came from, filters included, when the referrer is one of them; while the user moves
 * between the section's title pages, the first answer, kept in the session; otherwise the
 * section's releases list.
 */
final class TitlePageBackLink
{
    /**
     * @param  array<string, string>  $lists  label => URL of each list, the releases list (the default) first
     * @param  string  $titlePages  the URL the section's title pages start with (`/tv/show`, `/movies/film`)
     * @return array{url: string, label: string}
     */
    public static function resolve(Request $request, string $sessionKey, array $lists, string $titlePages): array
    {
        $default = reset($lists);
        $paths = array_map(static fn (string $url): string => (string) parse_url($url, PHP_URL_PATH), $lists);
        $referrer = (string) $request->headers->get('referer', '');
        $from = parse_url($referrer);
        $sameSite = ($from['host'] ?? null) === $request->getHost();
        $path = $sameSite ? '/'.ltrim((string) ($from['path'] ?? ''), '/') : '';
        $url = match (true) {
            in_array($path, $paths, true) => $referrer,
            str_starts_with($path, parse_url($titlePages, PHP_URL_PATH).'/') => (string) $request->session()->get($sessionKey, $default),
            default => $default,
        };
        $request->session()->put($sessionKey, $url);
        $label = array_search(parse_url($url, PHP_URL_PATH), $paths, true);

        return ['url' => $url, 'label' => is_string($label) ? $label : (string) array_key_first($lists)];
    }
}
