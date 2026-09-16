<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Services\Search\Contracts\SearchServiceInterface;

final class WebSearchEntityLookup
{
    public function __construct(private readonly SearchServiceInterface $search) {}

    /**
     * @param  array<string, string>  $fields
     * @return list<int|string>
     */
    public function keys(string $index, array $fields, string $key): array
    {
        $request = request();
        $cacheKey = self::class.':'.hash('sha256', serialize([$index, $fields, $key]));
        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }

        $keys = [];
        $after = 0;
        do {
            $result = $this->search->searchEntityFields($index, $fields, $key, 500, $after);
            if (! $result['available']) {
                $keys = [];
                break;
            }
            array_push($keys, ...$result['keys']);
            $next = $result['ids'] === [] ? $after : max($result['ids']);
            if ($next <= $after) {
                break;
            }
            $after = $next;
        } while ($result['has_more']);

        $keys = array_values(array_unique($keys));
        $request->attributes->set($cacheKey, $keys);

        return $keys;
    }
}
