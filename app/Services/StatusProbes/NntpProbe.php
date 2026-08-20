<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;

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
            $providers = $this->pool->enabledProviders();
            $timings = [];
            $failure = null;

            foreach ($providers as $provider) {
                $result = $this->probeProvider($provider);
                $timings[$provider->name] = $result['responseTimeMs'];

                if (! $result['ok'] && $failure === null) {
                    $failure = [
                        'impact' => $provider->isPrimary() ? IncidentImpactEnum::Critical : IncidentImpactEnum::Major,
                        'reason' => 'NNTP provider '.$provider->label().' failed: '.$result['reason'],
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
                reason: 'NNTP probe failed: '.\Str::limit($e->getMessage(), 120),
            );
        } finally {
            $this->pool->quit();
        }
    }

    /**
     * @return array{ok: bool, reason: string, responseTimeMs: int}
     */
    private function probeProvider(NntpProvider $provider): array
    {
        $client = $this->pool->clientFor($provider);

        $start = hrtime(true);
        $result = $client->doConnect(compression: false);
        $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($result === true) {
            return ['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => $elapsed];
        }

        if (NNTPService::isError($result)) {
            return ['ok' => false, 'reason' => (string) $result->getMessage(), 'responseTimeMs' => $elapsed];
        }

        return ['ok' => false, 'reason' => 'Unknown connection result', 'responseTimeMs' => $elapsed];
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
