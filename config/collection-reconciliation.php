<?php

declare(strict_types=1);

return [
    'candidate_limit' => (int) env('COLLECTION_RECONCILIATION_CANDIDATE_LIMIT', 100),
    'cycle_seconds' => (float) env('COLLECTION_RECONCILIATION_CYCLE_SECONDS', 30),
    'head_bytes' => 64 * 1024,
    'body_bytes' => 2 * 1024 * 1024,
    'decision_bytes' => 16 * 1024 * 1024,
    'hour_bytes' => 256 * 1024 * 1024,
    'day_bytes' => 2 * 1024 * 1024 * 1024,
    'decision_seconds' => 900,
    'request_seconds' => 20,
    'lease_seconds' => 120,
    'cache_seconds' => 7 * 86400,
    'retry_seconds' => [60, 300],
];
