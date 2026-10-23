<?php

/*
 * One entry per external metadata provider sent through App\Services\ProviderGateway.
 *
 * Only settings without an existing home live here. Providers that already had
 * configuration keep it as the source of truth, read on every call by
 * App\Services\ProviderGateway\ProviderPolicies: tmdb.*, nntmux_api.*,
 * music-identity.*, nntmux_srrdb.* and decision-model.*.
 */
return [
    // Every provider starts from these and overrides what it needs.
    'defaults' => [
        'pace' => [
            // Real requests start at least this far apart.
            'min_interval_ms' => 0,
            // shared: across every worker through the cache store; process: this process only.
            'scope' => 'shared',
            // wait: sleep until the next slot; skip: return unavailable without sending.
            'when_busy' => 'wait',
            // Pace only requests to these hosts; null paces every request.
            'hosts' => null,
            // One request at a time across workers, spaced from the previous one's end.
            'serial' => false,
            'lock_wait_seconds' => 10,
            // A 429 that is not retried holds the pacer at least this long.
            'hold_on_429_seconds' => 0,
        ],
        'budget' => [
            // At most this many requests per window, shared; further requests are refused.
            'max' => null,
            'window_seconds' => 60,
            // The rate limiter key; null uses the provider's own key.
            'key' => null,
        ],
        'retry' => [
            'attempts' => 1,
            'backoff_ms' => 0,
            'multiplier' => 1,
            // Which unavailable answers are retried.
            'transport' => true,
            'server_errors' => true,
            'statuses' => [429],
            // A longer Retry-After is never waited in the worker.
            'retry_after_max_seconds' => 30,
            // Retry-After assumed for a 429 that carries none; null uses the backoff.
            'default_retry_after_seconds' => null,
        ],
        'breaker' => [
            'failure_threshold' => 3,
            // null keeps it open until the owning instance is discarded.
            'open_seconds' => 60,
            // shared, process or instance.
            'scope' => 'shared',
            // Shared breakers re-open for double the time after a failed trial, up to an hour.
            'escalate' => true,
        ],
        // Statuses besides 429 and 5xx that mean the provider is unavailable (for example a spent quota).
        'unavailable_statuses' => [],
        // The recovery ledger's provider name for calls the gateway cannot see (call()).
        'ledger_host' => null,
    ],

    'providers' => [
        'tmdb' => [
            'pace' => ['min_interval_ms' => 1_000],
        ],
        'tvmaze' => [
            'pace' => ['min_interval_ms' => 1_000],
            'ledger_host' => 'api.tvmaze.com',
        ],
        'trakt' => [
            'pace' => ['min_interval_ms' => 1_000],
            'retry' => ['default_retry_after_seconds' => 60],
        ],
        'tvdb' => [
            'pace' => ['min_interval_ms' => 1_000],
        ],
        'imdb' => [],
        // IMDb's suggestion search (v2.sg.media-imdb.com) has its own breaker so that title-page blocks don't stop it.
        'imdb_search' => [],
        'omdb' => [
            // OMDb answers 401 once the daily quota is spent.
            'unavailable_statuses' => [401],
        ],
        'fanarttv' => [],
        'musicbrainz' => [
            'pace' => ['hosts' => ['musicbrainz.org'], 'lock_wait_seconds' => 5],
        ],
        'acoustid' => [],
        'coverart' => [
            'pace' => ['serial' => true],
        ],
        'srrdb' => [
            'pace' => ['scope' => 'process'],
            'retry' => ['statuses' => [408, 425, 429]],
            'breaker' => ['scope' => 'instance', 'open_seconds' => null, 'escalate' => false],
            'unavailable_statuses' => [408, 425],
        ],
        'decision_model' => [
            'breaker' => ['scope' => 'instance', 'open_seconds' => null, 'escalate' => false],
        ],
        'igdb' => [
            'budget' => ['max' => 4, 'window_seconds' => 60, 'key' => 'igdb_api_rate_limit'],
        ],
        'anilist' => [
            // Fifteen a minute, the most the old per-process window let through. A 429 is
            // retried only after a Retry-After of 30 s or less; any other pauses AniList 15 minutes.
            'pace' => ['min_interval_ms' => 4_000, 'scope' => 'process', 'hold_on_429_seconds' => 900],
            'retry' => ['attempts' => 2, 'transport' => false, 'server_errors' => false, 'default_retry_after_seconds' => 900],
            'breaker' => ['scope' => 'process', 'escalate' => false],
        ],
        'isbndb' => [
            'breaker' => ['failure_threshold' => 1, 'open_seconds' => 300],
        ],
        'googlebooks' => [],
        'openlibrary' => [],
        'itunes' => [],
    ],
];
