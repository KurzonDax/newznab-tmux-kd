<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\NNTP\NntpProviderPool;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use Illuminate\Support\Str;

/**
 * Probes every configured and enabled NNTP provider.
 *
 * Configured means probed -- there is no opt-in flag. Losing the primary is Critical (header
 * scanning stops dead); losing any other backbone is Major (article operations lose reach but
 * keep working through the survivors).
 */
class NntpProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly NntpProviderPool $pool,
    ) {}

    public function identifier(): string
    {
        return 'nntp';
    }

    public function probe(): ProbeResult
    {
        try {
            $timings = [];
            $failure = null;

            foreach ($this->pool->enabledProviders() as $provider) {
                $result = $this->pool->probe($provider);
                $timings[$provider->name] = $result->responseTimeMs;

                if (! $result->ok && $failure === null) {
                    $failure = [
                        'impact' => $provider->isPrimary() ? IncidentImpactEnum::Critical : IncidentImpactEnum::Major,
                        'reason' => 'NNTP provider '.$provider->label().' failed: '.$result->detail,
                    ];
                }
            }

            if ($failure !== null) {
                return new ProbeResult(
                    ok: false,
                    responseTimeMs: 0,
                    impact: $failure['impact'],
                    reason: $failure['reason'],
                    metadata: ['providers' => $timings],
                );
            }

            return new ProbeResult(
                ok: true,
                responseTimeMs: $timings === [] ? 0 : (int) max($timings),
                impact: null,
                reason: $this->connectedSummary($timings),
                metadata: ['providers' => $timings],
            );
        } catch (\Throwable $e) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: 0,
                impact: IncidentImpactEnum::Critical,
                reason: 'NNTP probe failed: '.Str::limit($e->getMessage(), 120),
            );
        } finally {
            $this->pool->quit();
        }
    }

    /**
     * @param  array<string, int>  $timings
     */
    private function connectedSummary(array $timings): string
    {
        if ($timings === []) {
            return 'No enabled NNTP providers to probe';
        }

        $parts = [];
        foreach ($timings as $name => $ms) {
            $parts[] = $name.' '.$ms.'ms';
        }

        return 'NNTP connected: '.implode(', ', $parts);
    }
}
