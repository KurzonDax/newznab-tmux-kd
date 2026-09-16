<?php

declare(strict_types=1);

return [
    /*
     * Repository policy is maintained in AGENTS.md and its contextual references.
     * Exclude the installed presets so explicit guideline generation is a no-op.
     */
    'guidelines' => [
        'exclude' => [
            'foundation',
            'boost',
            'php',
            'deployments',
            'sail',
            'tests',
            'laravel/core',
            'livewire/core',
            'pint/core',
            'phpunit/core',
            'revolution/laravel-boost-phpstorm-copilot/core',
        ],
    ],
];
