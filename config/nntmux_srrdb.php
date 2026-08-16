<?php

return [
    'enabled' => (bool) env('SRRDB_ENABLED', false),
    'base_url' => env('SRRDB_BASE_URL', 'https://api.srrdb.com/v1'),
    'user_agent' => env('SRRDB_USER_AGENT', 'NNTmux SRRDB name fixing'),
    'max_requests_per_cycle' => (int) env('SRRDB_MAX_REQUESTS_PER_CYCLE', 25),
    'requests_per_second' => (float) env('SRRDB_REQUESTS_PER_SECOND', 1),
    'timeout_seconds' => (int) env('SRRDB_TIMEOUT_SECONDS', 10),
    'retry_attempts' => (int) env('SRRDB_RETRY_ATTEMPTS', 3),
    'backoff_milliseconds' => (int) env('SRRDB_BACKOFF_MILLISECONDS', 250),
    'circuit_breaker_failures' => (int) env('SRRDB_CIRCUIT_BREAKER_FAILURES', 3),
    'positive_ttl_days' => (int) env('SRRDB_POSITIVE_TTL_DAYS', 365),
    'negative_ttl_days' => (int) env('SRRDB_NEGATIVE_TTL_DAYS', 30),
    'release_size_tolerance_percent' => (int) env('SRRDB_RELEASE_SIZE_TOLERANCE_PERCENT', 5),
];
