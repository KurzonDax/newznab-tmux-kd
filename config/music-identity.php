<?php

declare(strict_types=1);
use App\Services\MusicIdentity\MusicIdentityConfiguration;

return [
    // Raising the version re-resolves every eligible audio release against MusicBrainz; it is
    // written once, in MusicIdentityConfiguration::DEFAULT_ALGORITHM_VERSION (v2: accepted search
    // text, #308; v3: an accepted album's genres, track list and credited artists, #313).
    'algorithm_version' => MusicIdentityConfiguration::DEFAULT_ALGORITHM_VERSION,
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
        // A fingerprint match is contradicted when the file's whole duration differs from every
        // MusicBrainz length of the matched recording by more than both tolerances.
        'fingerprint_duration_tolerance_milliseconds' => 10_000,
        'fingerprint_duration_tolerance_ratio' => 0.1,
    ],

    'candidate_generation' => [
        'distinctive_track_evidence_limit' => 4,
        'exact_identifier_limit' => 12,
        'provider_result_limit' => 15,
        'hydration_limit' => 8,
        'hydrated_release_edition_limit' => 8,
    ],

    // Album covers from the public Cover Art Archive for releases whose current decision accepts an album:
    // one lookup at a time across every worker, at least min_interval_milliseconds apart.
    'cover_art' => [
        'base_url' => 'https://coverartarchive.org',
        'min_interval_milliseconds' => 1_000,
        'lock_wait_seconds' => 10,
        'timeout_seconds' => 15,
        'connect_timeout_seconds' => 5,
        'backfill_batch_size' => 10,
        'retry' => [
            'initial_seconds' => 3_600,
            'maximum_seconds' => 604_800,
        ],
    ],

    // AcoustID fingerprint lookups (lookup only, never submission) for releases still unresolved or
    // ambiguous after MusicBrainz matching. Each lookup sends a stored fingerprint and duration.
    'acoustid' => [
        // Empty by default: fingerprints are still stored, but no AcoustID request is made.
        'client_key' => env('ACOUSTID_CLIENT_KEY'),
        'lookup_url' => 'https://api.acoustid.org/v2/lookup',
        // Shared by every worker process, retries included.
        'requests_per_second' => 3,
        'lock_wait_seconds' => 10,
        'timeout_seconds' => 10,
        'connect_timeout_seconds' => 5,
        'cache_ttl_seconds' => 2_592_000,
        // Files shorter than this are never looked up.
        'minimum_duration_milliseconds' => 1_000,
        // The whole lookup step stops (retryable) once this is spent, well inside lease_seconds.
        'lookup_budget_seconds' => 60,
        'retry' => [
            'attempts' => 3,
            'backoff_milliseconds' => 1_000,
            // A Retry-After longer than this defers the decision instead of waiting in the worker.
            'maximum_wait_seconds' => 30,
        ],
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
