<?php

declare(strict_types=1);

return [
    'working_tables' => [
        'binaries',
        'collections',
        'missed_parts',
        'parts',
    ],
    'working_patterns' => [
        '/^(?:multigroup_)?(?:binaries|collections|missed_parts|parts)(?:_\d+)?$/',
    ],
    'throwaway_tables' => [
        'cache',
        'cache_locks',
        'database_backups',
        'download_stats',
        'failed_jobs',
        'grab_stats',
        'jobs',
        'job_batches',
        'release_stats',
        'request_logs',
        'role_stats',
        'sessions',
        'signup_stats',
        'system_metrics',
        'user_activities',
        'user_activity_stats',
        'user_activity_stats_hourly',
    ],
    'throwaway_patterns' => [
        '/^pulse_/',
        '/^telescope_/',
        '/(?:^|_)stats(?:_|$)/',
        '/(?:^|_)logs?(?:_|$)/',
    ],
    'free_space_multiplier' => 2,
    'lock_seconds' => 90000,
    'operation_timeout_seconds' => 86400,
    'process_timeout_seconds' => 82800,
    'offsite_lock_seconds' => 90000,
    'offsite_operation_timeout_seconds' => 86400,
    'offsite_process_timeout_seconds' => 82800,
    'pause_stale_after_minutes' => 120,
];
