<?php

declare(strict_types=1);

return [
    'algorithm_version' => 'music-identity-v1',
    'resolver_version' => 'resolver-v1',
    'normalizer_version' => 'normalizer-v1',
    'scorer_version' => 'whole-release-v1',
    'policy_version' => 'shadow-v1',
    'application_mode' => 'shadow',
    'apply_decisions' => false,
    'worker_parallelism_max' => 8,
    'worker_batch_size' => 25,
    'lease_seconds' => 300,
    'candidate_attempt_limit' => 5,

    'retry' => [
        'initial_seconds' => 60,
        'maximum_seconds' => 3_600,
    ],

    'scoring' => [
        'minimum_album_score' => 92,
        'minimum_runner_up_margin' => 5,
    ],

    'candidate_generation' => [
        'distinctive_track_evidence_limit' => 4,
        'exact_identifier_limit' => 12,
        'provider_result_limit' => 15,
        'hydration_limit' => 8,
        'hydrated_release_edition_limit' => 8,
    ],

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
