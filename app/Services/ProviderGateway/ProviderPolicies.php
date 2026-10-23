<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use App\Services\MusicIdentity\CoverArt\CoverArtPacer;
use InvalidArgumentException;

/**
 * Resolves a provider's {@see ProviderPolicy} on every call: `config/provider-gateway.php`
 * for settings without another home, and each provider's existing configuration
 * (`tmdb.*`, `nntmux_api.*`, `music-identity.*`, `nntmux_srrdb.*`, `decision-model.*`)
 * for the rest, so a runtime change to either applies to the next request.
 */
final class ProviderPolicies
{
    public function for(string $provider): ProviderPolicy
    {
        $entries = (array) config('provider-gateway.providers', []);
        if (! array_key_exists($provider, $entries)) {
            throw new InvalidArgumentException("Unknown provider gateway entry [{$provider}].");
        }

        $settings = array_replace_recursive(
            (array) config('provider-gateway.defaults', []),
            (array) $entries[$provider],
            $this->existingConfiguration($provider),
        );
        $pace = (array) ($settings['pace'] ?? []);
        $budget = (array) ($settings['budget'] ?? []);
        $retry = (array) ($settings['retry'] ?? []);
        $breaker = (array) ($settings['breaker'] ?? []);
        $hosts = $pace['hosts'] ?? null;

        return new ProviderPolicy(
            provider: $provider,
            minIntervalMs: max(0, (int) ($pace['min_interval_ms'] ?? 0)),
            sharedPace: ($pace['scope'] ?? 'shared') === 'shared',
            skipWhenBusy: ($pace['when_busy'] ?? 'wait') === 'skip',
            paceHosts: is_array($hosts) ? array_values(array_map(static fn (mixed $host): string => strtolower((string) $host), $hosts)) : null,
            serial: (bool) ($pace['serial'] ?? false),
            serialLock: (string) ($pace['serial_lock'] ?? 'provider-gateway:'.$provider.':serial'),
            serialLockSeconds: max(1, (int) ($pace['serial_lock_seconds'] ?? 60)),
            lockWaitSeconds: max(0, (int) ($pace['lock_wait_seconds'] ?? 10)),
            holdOn429Seconds: max(0, (int) ($pace['hold_on_429_seconds'] ?? 0)),
            budgetMax: isset($budget['max']) ? max(0, (int) $budget['max']) : null,
            budgetWindowSeconds: max(1, (int) ($budget['window_seconds'] ?? 60)),
            budgetKey: (string) ($budget['key'] ?? 'provider-gateway:'.$provider.':budget'),
            attempts: max(1, (int) ($retry['attempts'] ?? 1)),
            backoffMs: max(0, (int) ($retry['backoff_ms'] ?? 0)),
            multiplier: max(1.0, (float) ($retry['multiplier'] ?? 1)),
            retryTransport: (bool) ($retry['transport'] ?? true),
            retryServerErrors: (bool) ($retry['server_errors'] ?? true),
            retryStatuses: array_values(array_map(intval(...), (array) ($retry['statuses'] ?? [429]))),
            retryAfterMaxSeconds: max(0, (int) ($retry['retry_after_max_seconds'] ?? 30)),
            defaultRetryAfterSeconds: isset($retry['default_retry_after_seconds']) ? max(0, (int) $retry['default_retry_after_seconds']) : null,
            failureThreshold: max(1, (int) ($breaker['failure_threshold'] ?? 3)),
            openSeconds: array_key_exists('open_seconds', $breaker) && $breaker['open_seconds'] === null ? null : max(0, (int) ($breaker['open_seconds'] ?? 60)),
            breakerScope: (string) ($breaker['scope'] ?? 'shared'),
            escalate: ($breaker['scope'] ?? 'shared') === 'shared' && (bool) ($breaker['escalate'] ?? true),
            unavailableStatuses: array_values(array_map(intval(...), (array) ($settings['unavailable_statuses'] ?? []))),
            ledgerHost: isset($settings['ledger_host']) ? (string) $settings['ledger_host'] : null,
        );
    }

    /**
     * The settings a provider already had before the gateway, read from their own keys.
     *
     * @return array<string, mixed>
     */
    private function existingConfiguration(string $provider): array
    {
        return match ($provider) {
            'tmdb' => ['retry' => [
                'attempts' => (int) config('tmdb.retry_times', 3),
                'backoff_ms' => (int) config('tmdb.retry_delay', 100),
            ]],
            'trakt' => ['retry' => [
                'attempts' => (int) config('nntmux_api.trakttv_retry_times', 3),
                'backoff_ms' => (int) config('nntmux_api.trakttv_retry_delay', 100),
            ]],
            'fanarttv' => ['retry' => [
                'attempts' => (int) config('nntmux_api.fanarttv_retry_times', 3),
                'backoff_ms' => (int) config('nntmux_api.fanarttv_retry_delay', 100),
            ]],
            'imdbapi_dev' => [
                'pace' => ['min_interval_ms' => max(0, (int) config('nntmux_api.imdbapi_dev_min_interval_seconds', 15)) * 1_000],
                'breaker' => ['open_seconds' => max(0, (int) config('nntmux_api.imdbapi_dev_cooldown_seconds', 300))],
            ],
            'musicbrainz' => [
                'pace' => ['min_interval_ms' => max(1_000, (int) config('music-identity.musicbrainz.public_min_interval_milliseconds', 1_000))],
                'retry' => [
                    'attempts' => (int) config('music-identity.musicbrainz.retry.attempts', 3),
                    'backoff_ms' => (int) config('music-identity.musicbrainz.retry.backoff_milliseconds', 250),
                ],
                'breaker' => [
                    'failure_threshold' => (int) config('music-identity.musicbrainz.circuit_breaker.failure_threshold', 3),
                    'open_seconds' => max(1, (int) config('music-identity.musicbrainz.circuit_breaker.open_seconds', 60)),
                ],
            ],
            'acoustid' => [
                'pace' => [
                    'min_interval_ms' => (int) ceil(1_000 / max(1, (int) config('music-identity.acoustid.requests_per_second', 3))),
                    'lock_wait_seconds' => (int) config('music-identity.acoustid.lock_wait_seconds', 10),
                ],
                'retry' => [
                    'attempts' => (int) config('music-identity.acoustid.retry.attempts', 3),
                    'backoff_ms' => (int) config('music-identity.acoustid.retry.backoff_milliseconds', 1_000),
                    'multiplier' => 2,
                    'retry_after_max_seconds' => (int) config('music-identity.acoustid.retry.maximum_wait_seconds', 30),
                ],
            ],
            'coverart' => ['pace' => $this->coverArtPace()],
            'srrdb' => [
                'pace' => ['min_interval_ms' => $this->srrdbIntervalMilliseconds()],
                'retry' => [
                    'attempts' => (int) config('nntmux_srrdb.retry_attempts', 3),
                    'backoff_ms' => (int) config('nntmux_srrdb.backoff_milliseconds', 250),
                    'multiplier' => 2,
                ],
                'breaker' => ['failure_threshold' => (int) config('nntmux_srrdb.circuit_breaker_failures', 3)],
            ],
            'decision_model' => [
                'retry' => [
                    'attempts' => (int) config('decision-model.retry_attempts', 3),
                    'backoff_ms' => 250,
                    'multiplier' => 2,
                ],
                'breaker' => ['failure_threshold' => (int) config('decision-model.circuit_breaker_failures', 3)],
            ],
            default => [],
        };
    }

    /**
     * The Cover Art Archive lock: held for the whole lookup, long enough for a request that
     * times out plus the spacing before it.
     *
     * @return array<string, int|string>
     */
    private function coverArtPace(): array
    {
        $intervalMilliseconds = max(0, (int) config('music-identity.cover_art.min_interval_milliseconds', 1_000));

        return [
            'min_interval_ms' => $intervalMilliseconds,
            'serial_lock' => CoverArtPacer::LOCK,
            'serial_lock_seconds' => (int) ceil((float) config('music-identity.cover_art.timeout_seconds', 15)
                + (float) config('music-identity.cover_art.connect_timeout_seconds', 5)
                + $intervalMilliseconds / 1_000) + 5,
            'lock_wait_seconds' => (int) config('music-identity.cover_art.lock_wait_seconds', 10),
        ];
    }

    /** SRRDB is asked at most once a second, or as fast as it answers when the rate is 0. */
    private function srrdbIntervalMilliseconds(): int
    {
        $rate = (float) config('nntmux_srrdb.requests_per_second', 1);

        return $rate <= 0 ? 0 : (int) ceil(1_000 / min(1, $rate));
    }
}
