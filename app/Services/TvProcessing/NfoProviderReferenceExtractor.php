<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

class NfoProviderReferenceExtractor
{
    /** @var array<'imdb'|'tvdb'|'tmdb'|'tvmaze'|'tvrage', string> */
    private const array PATTERNS = [
        'imdb' => '/\btt(\d{6,10})\b/i',
        'tvdb' => '~\bthetvdb\.com/\?(?:[^\s]*&)?(?:amp;)?id=(\d+)~i',
        'tmdb' => '~\bthemoviedb\.org/tv/(\d+)~i',
        'tvmaze' => '~\btvmaze\.com/shows/(\d+)~i',
        'tvrage' => '~\btvrage\.com/shows/id-(\d+)~i',
    ];

    /**
     * @return list<array{provider: 'imdb'|'tvdb'|'tmdb'|'tvmaze'|'tvrage', id: non-empty-string}>
     */
    public function extract(string $text): array
    {
        $references = [];

        foreach (self::PATTERNS as $provider => $pattern) {
            preg_match_all($pattern, $text, $matches);

            foreach (array_unique($matches[1]) as $id) {
                if ($id !== '') {
                    $references[] = ['provider' => $provider, 'id' => $id];
                }
            }
        }

        return $references;
    }
}
