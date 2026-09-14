<?php

declare(strict_types=1);

namespace App\Services\Releases;

final class TvReleaseMembership
{
    /**
     * Resolve only explicit declarations within a positively identified show.
     *
     * @return array{episodes: list<int>, season: ?int, fullSeason: bool}
     */
    public function resolve(object $release, TvEpisodeCatalog $catalog): array
    {
        $none = ['episodes' => [], 'season' => null, 'fullSeason' => false];
        if ((int) $release->videos_id <= 0) {
            return $none;
        }
        $show = (int) $release->videos_id;
        $name = str_replace(['_', '–', '—'], ['.', '-', '-'], (string) $release->searchname);
        if (preg_match('/\bS(\d{1,3})E\d{1,3}-S(\d{1,3})E\d{1,3}\b/i', $name, $range)) {
            if ((int) $range[1] !== (int) $range[2]) {
                return $none;
            }
            $name = preg_replace('/-S\d{1,3}(?=E\d)/i', '-', $name) ?? $name;
        }
        if (preg_match('/\bS(\d{1,3})E(\d{1,3})((?:(?:E|[+]|-E?)\d{1,3})*)\b/i', $name, $match)) {
            $season = (int) $match[1];
            $numbers = [(int) $match[2]];
            preg_match_all('/(E|[+]|-E?)(\d{1,3})/i', $match[3], $extra, PREG_SET_ORDER);
            foreach ($extra as $part) {
                $number = (int) $part[2];
                $previous = $numbers[array_key_last($numbers)];
                if (str_starts_with($part[1], '-') && $number < $previous) {
                    return $none;
                }
                if (str_starts_with($part[1], '-')) {
                    array_push($numbers, ...range($previous, $number));
                } else {
                    $numbers[] = $number;
                }
            }

            return ['episodes' => $catalog->members($show, $season, $numbers), 'season' => $season, 'fullSeason' => false];
        }
        if (preg_match('/\b(?:S|Season[ ._-]*)(\d{1,3})[ ._-]+(?:COMPLETE|FULL(?:[ ._-]+SEASON)?|PACK)\b/i', $name, $match)
            || preg_match('/\b(?:COMPLETE|FULL)[ ._-]+(?:S|Season[ ._-]*)(\d{1,3})\b/i', $name, $match)) {
            $season = (int) $match[1];

            return ['episodes' => $catalog->members($show, $season), 'season' => $season, 'fullSeason' => true];
        }
        $episode = $catalog->linked($show, (int) $release->tv_episodes_id);

        return $episode === null ? $none : ['episodes' => [$episode['id']], 'season' => $episode['season'], 'fullSeason' => false];
    }
}
