<?php

return [
    // The client sends nothing while this is false or the base URL is empty.
    'enabled' => (bool) env('DECISION_MODEL_ENABLED', false),
    'base_url' => (string) env('DECISION_MODEL_BASE_URL', ''),
    'api_key' => (string) env('DECISION_MODEL_API_KEY', ''),
    // Sent as the request's "model" field when set, omitted when empty.
    'model' => (string) env('DECISION_MODEL_MODEL', ''),
    'timeout_seconds' => (int) env('DECISION_MODEL_TIMEOUT_SECONDS', 30),
    'retry_attempts' => (int) env('DECISION_MODEL_RETRY_ATTEMPTS', 3),
    'circuit_breaker_failures' => (int) env('DECISION_MODEL_CIRCUIT_BREAKER_FAILURES', 3),

    // Folder of versioned criteria files, one "<use case name>.json" per use case.
    'criteria_path' => config_path('decision-model'),
];
