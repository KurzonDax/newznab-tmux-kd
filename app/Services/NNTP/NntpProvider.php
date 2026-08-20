<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use InvalidArgumentException;

/**
 * One configured NNTP backbone.
 *
 * Position carries the role: position 1 is the primary, which is the only provider that
 * ever serves header traffic (article numbers are per-server, so group positions, backfill
 * ranges and part-repair ranges are only meaningful against a single numbering). Every
 * enabled provider participates in article operations, in position order.
 */
final readonly class NntpProvider
{
    public function __construct(
        public int $position,
        public string $name,
        public string $host,
        public int $port,
        public bool $ssl,
        public string $username,
        public string $password,
        /** Advisory only: an operator-chosen split of what may be a shared account budget. */
        public int $connections,
        public int $timeout,
        public bool $enabled,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $position = (int) ($config['position'] ?? 0);

        if ($position < 1) {
            throw new InvalidArgumentException('NNTP provider config is missing a position.');
        }

        $name = trim((string) ($config['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException(
                "NNTP provider {$position} is missing a required NAME. Set NNTP_PROVIDER_{$position}_NAME "
                .'to a short label (e.g. "us-backbone") -- it identifies the provider in every log line.'
            );
        }

        $host = trim((string) ($config['host'] ?? ''));

        if ($host === '') {
            throw new InvalidArgumentException("NNTP provider {$position} ({$name}) is missing a host.");
        }

        return new self(
            position: $position,
            name: $name,
            host: $host,
            port: (int) ($config['port'] ?? 119),
            ssl: (bool) ($config['ssl'] ?? false),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            connections: max(1, (int) ($config['connections'] ?? 1)),
            timeout: max(1, (int) ($config['timeout'] ?? 120)),
            enabled: (bool) ($config['enabled'] ?? true),
        );
    }

    public function isPrimary(): bool
    {
        return $this->position === 1;
    }

    /**
     * Human-readable identity for logs and error messages. Never omit the name.
     */
    public function label(): string
    {
        return sprintf('%s (%s:%d)', $this->name, $this->host, $this->port);
    }
}
