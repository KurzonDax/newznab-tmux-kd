<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Support\Collection;

/** An immutable episode lookup shared by every release in a browse request. */
final class TvEpisodeCatalog
{
    /** @var array<int, array<int, array<int, int>>> */
    private array $seasons = [];

    /** @var array<int, array{id: int, show: int, season: int}> */
    private array $links = [];

    /**
     * @template TKey of array-key
     *
     * @param  Collection<TKey, \stdClass>  $episodes
     */
    public function __construct(Collection $episodes)
    {
        foreach ($episodes->sortBy('id') as $episode) {
            $show = (int) $episode->videos_id;
            $season = (int) $episode->series;
            $number = (int) $episode->episode;
            $id = (int) $episode->id;
            if ($number <= 0) {
                continue;
            }
            $this->seasons[$show][$season][$number] ??= $id;
            $this->links[$id] = ['id' => $this->seasons[$show][$season][$number], 'show' => $show, 'season' => $season];
        }
    }

    /**
     * @param  list<int>|null  $numbers
     * @return list<int>
     */
    public function members(int $show, int $season, ?array $numbers = null): array
    {
        $episodes = $this->seasons[$show][$season] ?? [];

        return array_values($numbers === null ? $episodes : array_intersect_key($episodes, array_flip($numbers)));
    }

    /** @return array{id: int, show: int, season: int}|null */
    public function linked(int $show, int $id): ?array
    {
        $episode = $this->links[$id] ?? null;

        return $episode !== null && $episode['show'] === $show ? $episode : null;
    }
}
