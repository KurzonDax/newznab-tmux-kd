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
        $descriptor = $this->describe($release);
        if ($descriptor['season'] !== null) {
            return ['episodes' => $catalog->members((int) $release->videos_id, $descriptor['season'], $descriptor['numbers']),
                'season' => $descriptor['season'], 'fullSeason' => $descriptor['fullSeason']];
        }
        $episode = $catalog->linked((int) $release->videos_id, $descriptor['linked']);

        return $episode === null ? ['episodes' => [], 'season' => null, 'fullSeason' => false]
            : ['episodes' => [$episode['id']], 'season' => $episode['season'], 'fullSeason' => false];
    }

    /** @return array{numbers: list<int>|null, season: ?int, fullSeason: bool, linked: int} */
    public function describe(object $release): array
    {
        $none = ['numbers' => [], 'season' => null, 'fullSeason' => false, 'linked' => 0];
        if ((int) $release->videos_id <= 0) {
            return $none;
        }
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

            return ['numbers' => array_values(array_unique($numbers)), 'season' => $season, 'fullSeason' => false, 'linked' => 0];
        }
        if (preg_match('/\b(?:S|Season[ ._-]*)(\d{1,3})[ ._-]+(?:COMPLETE|FULL(?:[ ._-]+SEASON)?|PACK)\b/i', $name, $match)
            || preg_match('/\b(?:COMPLETE|FULL)[ ._-]+(?:S|Season[ ._-]*)(\d{1,3})\b/i', $name, $match)) {
            $season = (int) $match[1];

            return ['numbers' => null, 'season' => $season, 'fullSeason' => true, 'linked' => 0];
        }

        return ['numbers' => [], 'season' => null, 'fullSeason' => false, 'linked' => (int) $release->tv_episodes_id];
    }
}
