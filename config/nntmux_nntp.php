<?php

declare(strict_types=1);

/**
 * NNTP provider configuration.
 *
 * Providers are declared as numbered env groups:
 *
 *   NNTP_PROVIDER_{n}_NAME         Short label, REQUIRED. Appears in every log line
 *                                  and error message raised by a provider operation.
 *   NNTP_PROVIDER_{n}_HOST         Hostname. A provider exists only when HOST is set.
 *   NNTP_PROVIDER_{n}_PORT
 *   NNTP_PROVIDER_{n}_SSL
 *   NNTP_PROVIDER_{n}_USERNAME
 *   NNTP_PROVIDER_{n}_PASSWORD
 *   NNTP_PROVIDER_{n}_CONNECTIONS  Advisory only -- an operator-chosen split of what may
 *                                  be a shared account budget. Nothing enforces it.
 *   NNTP_PROVIDER_{n}_TIMEOUT      Socket timeout, seconds.
 *   NNTP_PROVIDER_{n}_ENABLED      Excluded from every operation when false.
 *
 * Position 1 is the primary. Roles come from position, not from flags: provider 1 serves
 * all header traffic (article numbers are per-server, so header state is only meaningful
 * against one numbering) and is first in article-operation order. Every enabled provider
 * participates in article operations, in listed order.
 */
$providers = [];

for ($position = 1; $position <= 10; $position++) {
    $host = (string) env("NNTP_PROVIDER_{$position}_HOST", '');

    if (trim($host) === '') {
        continue;
    }

    $providers[] = [
        'position' => $position,
        'name' => (string) env("NNTP_PROVIDER_{$position}_NAME", ''),
        'host' => trim($host),
        'port' => (int) env("NNTP_PROVIDER_{$position}_PORT", 119),
        'ssl' => (bool) env("NNTP_PROVIDER_{$position}_SSL", false),
        'username' => (string) env("NNTP_PROVIDER_{$position}_USERNAME", ''),
        'password' => (string) env("NNTP_PROVIDER_{$position}_PASSWORD", ''),
        'connections' => (int) env("NNTP_PROVIDER_{$position}_CONNECTIONS", 1),
        'timeout' => (int) env("NNTP_PROVIDER_{$position}_TIMEOUT", 120),
        'enabled' => (bool) env("NNTP_PROVIDER_{$position}_ENABLED", true),
    ];
}

return [
    'providers' => $providers,

    /*
     * Header compression is a single global flag: it only matters for header traffic,
     * and header traffic is primary-pinned, so there is nothing per-provider to say.
     */
    'compressed_headers' => env('NNTP_COMPRESSED_HEADERS', false),

    /*
     * Per-process circuit breaker for article operations. After this many consecutive
     * failures a provider is skipped for article ops until the cooldown expires.
     */
    'breaker' => [
        'failure_threshold' => (int) env('NNTP_BREAKER_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('NNTP_BREAKER_COOLDOWN_SECONDS', 60),
    ],
];
