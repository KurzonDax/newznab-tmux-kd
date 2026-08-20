<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use Illuminate\Support\Str;

/**
 * The result of connecting to one provider, as reported by {@see NntpProviderPool::probe()}.
 */
final readonly class ProviderProbeResult
{
    private function __construct(
        public NntpProvider $provider,
        public bool $ok,
        public string $detail,
        public int $responseTimeMs,
    ) {}

    public static function connected(NntpProvider $provider, int $responseTimeMs): self
    {
        return new self(
            $provider,
            true,
            $provider->username === '' ? 'connected (no auth configured)' : 'connected + authenticated',
            $responseTimeMs,
        );
    }

    public static function failed(NntpProvider $provider, string $reason, int $responseTimeMs): self
    {
        return new self($provider, false, Str::limit($reason, 120), $responseTimeMs);
    }
}
