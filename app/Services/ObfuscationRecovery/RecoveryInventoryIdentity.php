<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final class RecoveryInventoryIdentity
{
    /** @param array<string,string> $names
     * @return array{outcome:string,candidate:?string,trusted:bool,files:list<array{file_id:string,name:string,title:?string,season:?int,episode:?int}>}
     */
    public function media(array $names): array
    {
        $files = [];
        $titles = $seasons = $episodes = $qualities = [];
        $consistent = count($names) > 1 && count($names) <= 32;
        ksort($names, SORT_STRING);
        foreach ($names as $id => $name) {
            $title = $season = $episode = null;
            if (preg_match('/^(.+?)[ ._-]+S([0-9]{1,2})E([0-9]{2,3})(?![0-9E]|[ ._-]+E[0-9])(?:[ ._-]|$)/i', $name, $match) === 1) {
                $title = trim(preg_replace('/[._\s]+/', ' ', $match[1]));
                $season = (int) $match[2];
                $episode = (int) $match[3];
                if ($title === '' || preg_match('/[a-z]{3}/i', $title) !== 1 || preg_match('/^[a-z0-9]{20,}$/iD', $title) === 1) {
                    $consistent = false;
                }
                $titles[mb_strtolower($title)] = $title;
                $seasons[$season] = true;
                $episodes[$episode] = true;
            } else {
                $consistent = false;
            }
            preg_match_all('/(?:^|[ ._-])(2160p|1080p|1080i|720p|576p|480p)(?=[ ._-]|$)/i', $name, $quality);
            $qualities[] = count($quality[1]) === 1 ? strtolower($quality[1][0]) : null;
            $files[] = ['file_id' => $id, 'name' => $name, 'title' => $title, 'season' => $season, 'episode' => $episode];
        }
        $candidate = null;
        if ($consistent && count($titles) === 1 && count($seasons) === 1) {
            $ordered = array_keys($episodes);
            sort($ordered, SORT_NUMERIC);
            $candidate = reset($titles).' S'.sprintf('%02d', array_key_first($seasons)).' Episodes '.$this->ranges($ordered)
                .' - '.count($files).' files';
            if ($qualities[0] !== null && count(array_unique($qualities)) === 1) {
                $candidate .= ' - '.$qualities[0];
            }
            if (strlen($candidate) > 255) {
                $candidate = null;
            }
        }

        return ['outcome' => $candidate === null ? 'bundle_identity_unresolved' : 'descriptive_bundle',
            'candidate' => $candidate, 'trusted' => false, 'files' => $files];
    }

    /** @param list<string> $names */
    public function archive(array $names): ?string
    {
        if (count($names) < 2 || count($names) > 32) {
            return null;
        }
        $root = null;
        $ordinals = [];
        foreach ($names as $name) {
            if (preg_match('/^(.+)\.part([0-9]{2,5})\.rar$/iD', $name, $match) !== 1
                || ($root !== null && $root !== $match[1]) || isset($ordinals[(int) $match[2]])) {
                return null;
            }
            $root = $match[1];
            $ordinals[(int) $match[2]] = true;
        }
        ksort($ordinals, SORT_NUMERIC);

        return array_keys($ordinals) === range(1, count($names)) ? $root : null;
    }

    /** @param list<int> $episodes */
    private function ranges(array $episodes): string
    {
        $ranges = [];
        $start = $last = null;
        foreach ($episodes as $episode) {
            if ($last !== null && $episode !== $last + 1) {
                $ranges[] = sprintf('%02d', $start).($start === $last ? '' : '-'.sprintf('%02d', $last));
                $start = null;
            }
            $start ??= $episode;
            $last = $episode;
        }
        if ($last !== null) {
            $ranges[] = sprintf('%02d', $start).($start === $last ? '' : '-'.sprintf('%02d', $last));
        }

        return implode(',', $ranges);
    }
}
