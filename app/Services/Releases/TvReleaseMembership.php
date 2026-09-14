<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Support\Collection;

final class TvReleaseMembership
{
    /**
     * Resolve only explicit declarations within a positively identified show.
     *
     * @template TKey of array-key
     *
     * @param  Collection<TKey, \stdClass>  $episodes
     * @return array{episodes: list<int>, season: ?int, fullSeason: bool}
     */
    public function resolve(object $release, Collection $episodes): array
    {
        $none = ['episodes' => [], 'season' => null, 'fullSeason' => false];
        if ((int) $release->videos_id <= 0) {
            return $none;
        }
        $episodes = $episodes->where('videos_id', (int) $release->videos_id);
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

            return ['episodes' => $episodes->where('series', $season)->whereIn('episode', $numbers)->pluck('id')->map(intval(...))->unique()->values()->all(), 'season' => $season, 'fullSeason' => false];
        }
        if (preg_match('/\b(?:S|Season[ ._-]*)(\d{1,3})[ ._-]+(?:COMPLETE|FULL(?:[ ._-]+SEASON)?|PACK)\b/i', $name, $match)
            || preg_match('/\b(?:COMPLETE|FULL)[ ._-]+(?:S|Season[ ._-]*)(\d{1,3})\b/i', $name, $match)) {
            $season = (int) $match[1];

            return ['episodes' => $episodes->where('series', $season)->where('episode', '>', 0)->pluck('id')->map(intval(...))->unique()->values()->all(), 'season' => $season, 'fullSeason' => true];
        }
        $episode = $episodes->firstWhere('id', (int) $release->tv_episodes_id);

        return $episode === null ? $none : ['episodes' => [(int) $episode->id], 'season' => (int) $episode->series, 'fullSeason' => false];
    }
}
