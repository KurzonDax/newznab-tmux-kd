<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\MusicIdentity\Gateways\MusicBrainzRequestPacer;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class MusicBrainzProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly MusicBrainzRequestPacer $requestPacer,
    ) {}

    public function identifier(): string
    {
        return 'musicbrainz';
    }

    public function probe(): ProbeResult
    {
        $endpoint = trim((string) config('music-identity.musicbrainz.endpoint_url'));
        if ($endpoint === '') {
            return new ProbeResult(
                ok: true,
                responseTimeMs: 0,
                impact: null,
                reason: 'MusicBrainz is dormant (no endpoint configured)',
                metadata: ['configured' => false],
            );
        }

        if (strtolower((string) parse_url($endpoint, PHP_URL_HOST)) === 'musicbrainz.org'
            && trim((string) config('music-identity.musicbrainz.user_agent_contact')) === '') {
            return $this->failed(0, 'MUSICBRAINZ_USER_AGENT_CONTACT is required for musicbrainz.org');
        }

        try {
            $start = hrtime(true);
            $response = $this->request($endpoint)->get(rtrim($endpoint, '/').'/recording', [
                'query' => 'recording:"NNTmux health check"',
                'limit' => 1,
                'offset' => 0,
                'fmt' => 'json',
            ]);
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            if (! $response->successful()) {
                return $this->failed($elapsed, sprintf('MusicBrainz returned HTTP %d', $response->status()));
            }

            try {
                $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                return $this->failed($elapsed, 'MusicBrainz returned invalid JSON: '.$exception->getMessage());
            }
            if (! is_array($payload)
                || ! isset($payload['recording-count'], $payload['recording-offset'], $payload['recordings'])
                || ! is_int($payload['recording-count'])
                || ! is_int($payload['recording-offset'])
                || ! is_array($payload['recordings'])
                || ! array_is_list($payload['recordings'])) {
                return $this->failed($elapsed, 'MusicBrainz returned an unexpected health response');
            }

            $metadata = ['configured' => true];
            $replication = $this->replicationMetadata($endpoint);
            if ($replication !== []) {
                $metadata = array_merge($metadata, $replication);
                $warningSeconds = max(1, (int) config('music-identity.musicbrainz.replication_warning_hours', 48)) * 3600;
                if (($replication['replicationAgeSeconds'] ?? 0) > $warningSeconds) {
                    return new ProbeResult(
                        ok: false,
                        responseTimeMs: $elapsed,
                        impact: IncidentImpactEnum::Minor,
                        reason: 'MusicBrainz replication is stale',
                        metadata: $metadata,
                    );
                }
            }

            return new ProbeResult(
                ok: true,
                responseTimeMs: $elapsed,
                impact: null,
                reason: 'MusicBrainz API available',
                metadata: $metadata,
            );
        } catch (Throwable $exception) {
            return $this->failed(0, 'MusicBrainz check failed: '.Str::limit($exception->getMessage(), 120));
        }
    }

    private function request(string $endpoint): PendingRequest
    {
        $contact = trim((string) config('music-identity.musicbrainz.user_agent_contact'));
        $userAgent = $contact === '' ? 'NNTmux/1.0' : 'NNTmux/1.0 ('.$contact.')';

        return Http::acceptJson()
            ->withUserAgent($userAgent)
            ->connectTimeout(max(0.1, (float) config('music-identity.musicbrainz.connect_timeout_seconds', 2)))
            ->timeout(max(0.1, (float) config('music-identity.musicbrainz.timeout_seconds', 8)))
            ->beforeSending(function () use ($endpoint): void {
                $this->requestPacer->pace($endpoint);
            });
    }

    /** @return array{lastReplicationAt: string, replicationAgeSeconds: int}|array{} */
    private function replicationMetadata(string $endpoint): array
    {
        $statusUrl = trim((string) config('music-identity.musicbrainz.replication_status_url'));
        if ($statusUrl === '') {
            $statusUrl = $this->inferredReplicationStatusUrl($endpoint) ?? '';
        }
        if ($statusUrl === '') {
            return [];
        }

        try {
            $response = $this->request($statusUrl)->accept('text/html')->get($statusUrl);
            if (! $response->successful()
                || preg_match('/Last replication packet received at\s+([0-9T:+\-.Z]+)/i', $response->body(), $match) !== 1) {
                return [];
            }

            $replicatedAt = Carbon::parse($match[1])->utc();

            return [
                'lastReplicationAt' => $replicatedAt->toAtomString(),
                'replicationAgeSeconds' => max(0, (int) $replicatedAt->diffInSeconds(now(), absolute: false)),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private function inferredReplicationStatusUrl(string $endpoint): ?string
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if ($host === '' || $host === 'musicbrainz.org') {
            return null;
        }

        $scheme = (string) parse_url($endpoint, PHP_URL_SCHEME);
        $port = parse_url($endpoint, PHP_URL_PORT);

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port).'/';
    }

    private function failed(int $elapsed, string $reason): ProbeResult
    {
        return new ProbeResult(
            ok: false,
            responseTimeMs: $elapsed,
            impact: IncidentImpactEnum::Major,
            reason: $reason,
            metadata: ['configured' => true],
        );
    }
}
