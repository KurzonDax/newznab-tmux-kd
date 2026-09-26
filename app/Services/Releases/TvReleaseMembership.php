<?php

declare(strict_types=1);

namespace App\Services\Releases;

final class TvReleaseMembership
{
    /** @return array{numbers: list<int>|null, season: ?int, fullSeason: bool, linked: int} */
    public function describe(object $release): array
    {
        $none = ['numbers' => [], 'season' => null, 'fullSeason' => false, 'linked' => 0];
        if ((int) $release->videos_id <= 0) {
            return $none;
        }
        $name = str_replace(['_', '–', '—'], ['.', '-', '-'], (string) $release->searchname);
        if (preg_match('/\bS(\d{1,4})[ ._-]?EP?\d{1,3}-S(\d{1,4})[ ._-]?EP?\d{1,3}\b/i', $name, $range)) {
            if ((int) $range[1] !== (int) $range[2]) {
                return $none;
            }
            $name = preg_replace('/-S\d{1,4}[ ._-]?(?=EP?\d)/i', '-', $name) ?? $name;
        }
        if (preg_match('/\bS(\d{1,4})[ ._-]?EP?(\d{1,3})((?:(?:E|[+]|-E?)\d{1,3})*)\b/i', $name, $match)) {
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
        if (preg_match('/\b(?:S|Season[ ._-]*)(\d{1,4})[ ._-]+(?:COMPLETE|FULL(?:[ ._-]+SEASON)?|PACK|COMBINED)\b/i', $name, $match)
            || preg_match('/\b(?:COMPLETE|FULL)[ ._-]+(?:S|Season[ ._-]*)(\d{1,4})\b/i', $name, $match)) {
            $season = (int) $match[1];

            return ['numbers' => null, 'season' => $season, 'fullSeason' => true, 'linked' => 0];
        }
        // The fansub form `[Group] Title S3 - 13 [1080p]` is season 3 episode 13, not a pack.
        if (preg_match('/\bS(\d{1,4})[ ._]*-[ ._]*(\d{1,3})\b/i', $name, $match)) {
            return ['numbers' => [(int) $match[2]], 'season' => (int) $match[1], 'fullSeason' => false, 'linked' => 0];
        }
        // `S13 - Season 13 - Episode 47` names an episode in words; an Episode range or a bonus, extra or special does not.
        $notAnEpisode = '/\bEpi(?:sode)?s?[ ._-]*\d{1,3}[ ._]*-[ ._]*\d{1,3}\b|\bS\d{1,4}\b.*\b(?:Bonus|Extras?|Specials?)\b/i';
        if (preg_match('/\bS(\d{1,4})\b.*?\bEpi(?:sode)?[ ._-]*(\d{1,3})\b/i', $name, $match) && ! preg_match($notAnEpisode, $name)) {
            return ['numbers' => [(int) $match[2]], 'season' => (int) $match[1], 'fullSeason' => false, 'linked' => 0];
        }
        // A bare season is a pack only when nothing in the name looks like an episode, a single disc or a multi-season set.
        if (preg_match('/\bS(\d{1,4})\b/i', $name, $match) && ! preg_match($notAnEpisode, $name)
            && ! preg_match('/EP?\d|\b(?:Part|Pt)[ ._-]*\d|\d{1,2}x\d{2}| - \d{1,3}|\bS\d{1,4}[ ._-]?D(?:isc)?[ ._-]?\d|\bS\d{1,4}[ ._-]?-[ ._-]?S\d{1,4}/i', $name)) {
            return ['numbers' => null, 'season' => (int) $match[1], 'fullSeason' => true, 'linked' => 0];
        }

        return ['numbers' => [], 'season' => null, 'fullSeason' => false, 'linked' => (int) $release->tv_episodes_id];
    }
}
