<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Per-provider day counters in the cache store, each kept for eight days from its first
 * increment. A counter that cannot be written is dropped: telemetry never fails a lookup.
 */
final class ProviderCounters
{
    public const array NAMES = [
        'requests', 'found', 'not_found', 'rejected', 'status_4xx', 'status_5xx', 'status_429',
        'transport_failures', 'retries', 'waited_ms', 'breaker_opens', 'budget_refusals',
        'pacer_skips', 'cache_hits',
    ];

    private const int KEEP_SECONDS = 8 * 86_400;

    public function increment(string $provider, string $name, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }

        try {
            $key = $this->key($provider, $name, now());
            Cache::add($key, 0, self::KEEP_SECONDS);
            $by > 0 ? Cache::increment($key, $by) : Cache::decrement($key, -$by);
        } catch (Throwable) {
            // Telemetry is best effort.
        }
    }

    /** @return array<string, int> every counter of the provider for the day (today by default) */
    public function read(string $provider, ?CarbonInterface $day = null): array
    {
        $day ??= now();
        $counters = [];
        foreach (self::NAMES as $name) {
            $counters[$name] = (int) Cache::get($this->key($provider, $name, $day), 0);
        }

        return $counters;
    }

    private function key(string $provider, string $name, CarbonInterface $day): string
    {
        return 'provider-gateway:'.$provider.':counters:'.$day->format('Y-m-d').':'.$name;
    }
}
