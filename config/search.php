<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default search driver that will be used to
    | perform full-text search operations. Supported: "manticore"
    |
    */
    'default' => env('SEARCH_DRIVER', 'manticore'),

    'index_generation' => env('SEARCH_INDEX_GENERATION', '1'),

    'cursor_ttl_minutes' => (int) env('SEARCH_CURSOR_TTL_MINUTES', 15),

    'reconciliation' => [
        'batch_size' => (int) env('SEARCH_RECONCILE_BATCH_SIZE', 100),
        'cron' => env('SEARCH_RECONCILE_CRON', '*/5 * * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Driver Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure each search driver as needed.
    |
    */
    'drivers' => [

        'manticore' => [
            'driver' => 'manticore',
            'host' => env('MANTICORESEARCH_HOST', '127.0.0.1'),
            'port' => env('MANTICORESEARCH_PORT', 9308),
            'scheme' => env('MANTICORESEARCH_SCHEME', 'http'),
            'username' => env('MANTICORESEARCH_USERNAME'),
            'password' => env('MANTICORESEARCH_PASSWORD'),
            'token' => env('MANTICORESEARCH_TOKEN'),
            'hosts' => env('MANTICORESEARCH_HOSTS', ''),
            'retries' => env('MANTICORESEARCH_RETRIES', 2),
            'indexes' => [
                'releases' => env('MANTICORESEARCH_INDEX_RELEASES', 'releases_rt'),
                'predb' => env('MANTICORESEARCH_INDEX_PREDB', 'predb_rt'),
                'movies' => env('MANTICORESEARCH_INDEX_MOVIES', 'movies_rt'),
                'music' => env('MANTICORESEARCH_INDEX_MUSIC', 'music_rt'),
                'books' => env('MANTICORESEARCH_INDEX_BOOKS', 'books_rt'),
                'games' => env('MANTICORESEARCH_INDEX_GAMES', 'games_rt'),
                'console' => env('MANTICORESEARCH_INDEX_CONSOLE', 'console_rt'),
                'steam' => env('MANTICORESEARCH_INDEX_STEAM', 'steam_rt'),
                'anime' => env('MANTICORESEARCH_INDEX_ANIME', 'anime_rt'),
            ],
            'max_matches' => env('MANTICORESEARCH_MAX_MATCHES', 10000),
            'retry_attempts' => (int) env('MANTICORESEARCH_RETRY_ATTEMPTS', 2),
            'retry_delay_ms' => (int) env('MANTICORESEARCH_RETRY_DELAY_MS', 100),
            'cache_minutes' => env('MANTICORESEARCH_CACHE_MINUTES', 5),
            'suggest' => [
                'enabled' => env('MANTICORESEARCH_SUGGEST_ENABLED', true),
                'max_edits' => env('MANTICORESEARCH_SUGGEST_MAX_EDITS', 4),
            ],
            'fuzzy' => [
                'enabled' => env('MANTICORESEARCH_FUZZY_ENABLED', true),
                'max_distance' => env('MANTICORESEARCH_FUZZY_MAX_DISTANCE', 2),
            ],
        ],

    ],

];
