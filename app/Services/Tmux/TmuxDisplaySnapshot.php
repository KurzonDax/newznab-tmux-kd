<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use LogicException;
use Throwable;

/** Keeps the last successful exact display separate from operational work gates. */
class TmuxDisplaySnapshot
{
    /** @var array<string, array{observed_at: int, counts: array<string, mixed>}> */
    private array $lastSuccessful = [];

    public function __construct(private Repository $cache) {}

    /**
     * @param  callable(): array<string, mixed>  $capture
     * @return array{observed_at: int, counts: array<string, mixed>}|null
     */
    public function get(string $scope, callable $capture): ?array
    {
        $key = 'tmux:display:'.$scope;
        $lock = null;
        try {
            $snapshot = $this->cache->get($key);
            if (is_array($snapshot)) {
                $this->lastSuccessful[$scope] = $snapshot;
            }
            if ($snapshot !== null && CarbonImmutable::now()->timestamp < $snapshot['observed_at'] + 300) {
                return $snapshot;
            }

            $store = $this->cache->getStore();
            if (! $store instanceof LockProvider) {
                throw new LogicException('Display snapshots require an atomic cache lock.');
            }
            $lock = $store->lock($key.':refresh', 60);
            if (! $lock->get()) {
                return $this->lastSuccessful[$scope] ?? null;
            }

            $snapshot = $this->cache->get($key);
            if ($snapshot === null || CarbonImmutable::now()->timestamp >= $snapshot['observed_at'] + 300) {
                $counts = $capture();
                $snapshot = ['observed_at' => CarbonImmutable::now()->timestamp, 'counts' => $counts];
                $this->cache->forever($key, $snapshot);
            }
            $this->lastSuccessful[$scope] = $snapshot;
        } catch (Throwable) {
            return $this->lastSuccessful[$scope] ?? null;
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // Cache failure must not discard a successful observation.
            }
        }

        return $this->lastSuccessful[$scope] ?? null;
    }
}
