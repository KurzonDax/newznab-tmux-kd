<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tmux Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for tmux session management.
    | Previously located in misc/update/tmux/tmux.conf, this has been
    | modernized and integrated into Laravel's config system.
    |
    */

    'config_file' => env('TMUX_CONFIG_FILE', config_path('tmux.conf')),

    'session' => [
        'default_name' => 'nntmux',
    ],

    'panes' => [
        'history_limit' => 6000,
        'remain_on_exit' => true,
        'aggressive_resize' => true,
        'monitor_activity' => true,
        'border_style' => 'heavy',    // heavy for bolder lines
        'active_border_color' => 'colour75',   // Sapphire blue (Catppuccin)
        'inactive_border_color' => 'colour238', // Dark grey
    ],

    'colors' => [
        // Modern color scheme (Catppuccin Mocha inspired)
        'base' => 'colour234',        // Darkest background (crust)
        'background' => 'colour235',  // Main background (base)
        'surface' => 'colour237',     // Surface for elevated elements
        'foreground' => 'colour255',  // Main text (text)
        'subtext' => 'colour250',     // Dimmed text (subtext0)
        'overlay' => 'colour238',     // Overlay elements

        // Accent colors (Catppuccin)
        'sapphire' => 'colour75',     // Primary accent
        'teal' => 'colour116',        // Secondary accent
        'green' => 'colour114',       // Success
        'yellow' => 'colour221',      // Warning
        'peach' => 'colour215',       // Attention
        'red' => 'colour210',         // Error/danger
        'pink' => 'colour218',        // Special
        'mauve' => 'colour141',       // Purple
    ],

    'keys' => [
        'mode' => 'vi',
        'prefix' => 'C-a',
    ],

    'mouse' => [
        'enabled' => true,
    ],

    'paths' => [
        'scripts' => base_path('app/Services/Tmux/Scripts'),
        'logs' => storage_path('logs/tmux'),
    ],

    'write_logs' => (bool) env('TMUX_WRITE_LOGS', false),

    'monitor' => [
        'delay' => env('TMUX_MONITOR_DELAY', 10),
        'refresh_interval' => env('TMUX_REFRESH_INTERVAL', 60),
        'failures_before_backoff' => env('TMUX_FAILURES_BEFORE_BACKOFF', 3),
        'max_backoff_seconds' => env('TMUX_MAX_BACKOFF_SECONDS', 1800),
    ],
];
