<?php

declare(strict_types=1);

return [
    'algorithm_version' => 'music-identity-v1',
    'policy_version' => 'shadow-v1',
    'application_mode' => 'shadow',
    'apply_decisions' => false,
    'worker_parallelism_max' => 8,

    'musicbrainz' => [
        // Empty by default: local evidence capture remains active, but no provider calls occur.
        'endpoint_url' => env('MUSICBRAINZ_ENDPOINT_URL'),
        // Required automatically when endpoint_url points to the public musicbrainz.org host.
        'user_agent_contact' => env('MUSICBRAINZ_USER_AGENT_CONTACT'),
        'provider_version' => 'ws2-v1',
        'request_budget' => 12,
        'max_concurrency' => 4,
        'timeout_seconds' => 8,
        'connect_timeout_seconds' => 2,
        'search_limit' => 25,
        'browse_limit' => 100,
        'public_min_interval_milliseconds' => 1_000,
        // A private mirror may expose its replication timestamp on this HTML page.
        'replication_status_url' => null,
        'replication_warning_hours' => 48,
        'retry' => [
            'attempts' => 3,
            'backoff_milliseconds' => 250,
        ],
        'circuit_breaker' => [
            'failure_threshold' => 3,
            'open_seconds' => 60,
        ],
        'cache' => [
            'exact_ttl_seconds' => 604_800,
            'search_ttl_seconds' => 3_600,
        ],
    ],
];
