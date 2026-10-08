<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Contracts\AcousticFingerprintMatcher;
use App\Services\MusicIdentity\DTO\AcousticFingerprintMatch;
use App\Services\MusicIdentity\DTO\AcousticFingerprintQuery;
use App\Services\MusicIdentity\DTO\AcousticRecording;
use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;
use App\Services\MusicIdentity\Support\MusicIdentityValueNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JsonException;

/**
 * AcoustID `/v2/lookup` by fingerprint. Requests are POSTed with the configured client key, the
 * whole-file duration and the fingerprint, paced by the shared {@see AcoustIdRequestPacer}, and
 * every validated answer (a no-match or a rejection of the fingerprint included) is cached per
 * fingerprint algorithm, generator version, hash and duration. Retryable failures, rejections of
 * the deployment (its client key) included, are never cached.
 */
final readonly class HttpAcoustIdFingerprintMatcher implements AcousticFingerprintMatcher
{
    private const string META = 'recordingids releaseids releasegroupids';

    /** AcoustID error codes for an internal error, an unavailable service and too many requests. */
    private const array RETRYABLE_ERROR_CODES = [5, 13, 14];

    /**
     * AcoustID error codes for an invalid client key, a request not allowed and an unknown
     * application: about the deployment, not the fingerprint, so they defer the decision.
     */
    private const array CONFIGURATION_ERROR_CODES = [4, 12, 17];

    public function __construct(private AcoustIdRequestPacer $pacer) {}

    public function available(): bool
    {
        return $this->clientKey() !== null;
    }

    public function lookup(AcousticFingerprintQuery $query): array
    {
        $clientKey = $this->clientKey();
        if ($clientKey === null) {
            throw new AcousticFingerprintLookupException('No AcoustID client key is configured.', retryable: false);
        }

        $cacheKey = $this->cacheKey($query);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && is_string($cached['rejected'] ?? null)) {
            throw new AcousticFingerprintLookupException($cached['rejected'], retryable: false);
        }
        if (is_array($cached)) {
            return $this->matches($cached);
        }

        $ttl = max(1, (int) config('music-identity.acoustid.cache_ttl_seconds', 2_592_000));
        try {
            $payload = $this->request($clientKey, $query);
        } catch (AcousticFingerprintLookupException $exception) {
            if ($exception->cacheable) {
                Cache::put($cacheKey, ['rejected' => $exception->getMessage()], $ttl);
            }

            throw $exception;
        }
        $matches = $this->matches($payload);
        Cache::put($cacheKey, $payload, $ttl);

        return $matches;
    }

    /** @return array<string, mixed> the decoded `status: ok` payload */
    private function request(string $clientKey, AcousticFingerprintQuery $query): array
    {
        $attempts = max(1, (int) config('music-identity.acoustid.retry.attempts', 3));
        for ($attempt = 1; ; $attempt++) {
            $this->pacer->pace();

            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->connectTimeout(max(0.1, (float) config('music-identity.acoustid.connect_timeout_seconds', 5)))
                    ->timeout(max(0.1, (float) config('music-identity.acoustid.timeout_seconds', 10)))
                    ->post((string) config('music-identity.acoustid.lookup_url', 'https://api.acoustid.org/v2/lookup'), [
                        'format' => 'json',
                        'client' => $clientKey,
                        'duration' => $query->durationSeconds,
                        'fingerprint' => $query->fingerprint,
                        'meta' => self::META,
                    ]);
            } catch (ConnectionException $exception) {
                if ($attempt >= $attempts) {
                    throw new AcousticFingerprintLookupException('AcoustID request failed: '.$exception->getMessage(), retryable: true, previous: $exception);
                }
                $this->wait($this->backoffMilliseconds($attempt));

                continue;
            }

            $payload = $this->decode($response);
            if ($response->successful() && $payload !== null && ($payload['status'] ?? null) === 'ok') {
                return $payload;
            }

            $errorCode = $this->errorCode($payload);
            $message = $response->successful() && $errorCode === null
                ? 'AcoustID returned an unreadable response.'
                : sprintf('AcoustID lookup returned HTTP %d%s.', $response->status(), $this->errorDescription($payload));
            if (in_array($response->status(), [401, 403], true) || in_array($errorCode, self::CONFIGURATION_ERROR_CODES, true)) {
                // A rejection of the deployment, not of this fingerprint: defer the decision until it is fixed.
                throw new AcousticFingerprintLookupException($message, retryable: true);
            }
            $retryable = $response->status() === 429
                || $response->serverError()
                || in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)
                || ($response->successful() && $errorCode === null);
            if (! $retryable) {
                // A rejection of this fingerprint is its answer, cached like any other.
                throw new AcousticFingerprintLookupException($message, retryable: false, cacheable: true);
            }

            $retryAfterMilliseconds = $this->retryAfterMilliseconds($response);
            $maximumWaitMilliseconds = max(0, (int) config('music-identity.acoustid.retry.maximum_wait_seconds', 30)) * 1_000;
            if ($retryAfterMilliseconds !== null) {
                // Every worker honours the service's hold-off, not only the one that received it.
                $this->pacer->holdOff(min($retryAfterMilliseconds, $maximumWaitMilliseconds));
            }
            $delayMilliseconds = max($this->backoffMilliseconds($attempt), $retryAfterMilliseconds ?? 0);
            if ($attempt >= $attempts || $delayMilliseconds > $maximumWaitMilliseconds) {
                throw new AcousticFingerprintLookupException($message, retryable: true);
            }
            $this->wait($this->backoffMilliseconds($attempt));
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response): ?array
    {
        try {
            $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) && ! array_is_list($payload) ? $payload : null;
    }

    /** @param array<string, mixed>|null $payload */
    private function errorCode(?array $payload): ?int
    {
        $error = $payload['error'] ?? null;

        return is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : null;
    }

    /** @param array<string, mixed>|null $payload */
    private function errorDescription(?array $payload): string
    {
        $error = $payload['error'] ?? null;
        if (! is_array($error)) {
            return '';
        }

        return sprintf(' (error %s: %s)', $error['code'] ?? '?', is_string($error['message'] ?? null) ? $error['message'] : 'no message');
    }

    private function backoffMilliseconds(int $attempt): int
    {
        return max(0, (int) config('music-identity.acoustid.retry.backoff_milliseconds', 1_000)) * (2 ** ($attempt - 1));
    }

    private function retryAfterMilliseconds(Response $response): ?int
    {
        $retryAfter = trim($response->header('Retry-After'));
        if ($retryAfter === '') {
            return null;
        }
        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter * 1_000;
        }

        try {
            return max(0, (int) now()->diffInMilliseconds(Carbon::parse($retryAfter), false));
        } catch (\Throwable) {
            return null;
        }
    }

    private function wait(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            Sleep::usleep($milliseconds * 1_000);
        }
    }

    /**
     * Validates the whole payload before anything is used or cached.
     *
     * @param  array<string, mixed>  $payload
     * @return list<AcousticFingerprintMatch>
     */
    private function matches(array $payload): array
    {
        $results = $payload['results'] ?? null;
        if (($payload['status'] ?? null) !== 'ok' || ! is_array($results) || ! array_is_list($results)) {
            throw $this->unreadable();
        }

        $matches = [];
        foreach ($results as $result) {
            if (! is_array($result)
                || ! is_string($result['id'] ?? null)
                || trim($result['id']) === ''
                || ! (is_int($result['score'] ?? null) || is_float($result['score'] ?? null))) {
                throw $this->unreadable();
            }

            $matches[] = new AcousticFingerprintMatch(
                trackId: $result['id'],
                score: (float) $result['score'],
                recordings: array_map(fn (mixed $recording): AcousticRecording => $this->recording($recording), $this->list($result, 'recordings')),
            );
        }

        return $matches;
    }

    private function recording(mixed $recording): AcousticRecording
    {
        if (! is_array($recording)) {
            throw $this->unreadable();
        }

        $releaseGroupIds = [];
        $releaseGroupByRelease = [];
        foreach ($this->list($recording, 'releasegroups') as $releaseGroup) {
            $releaseGroupId = $this->identifier($releaseGroup);
            $releaseGroupIds[] = $releaseGroupId;
            foreach ($this->list($releaseGroup, 'releases') as $release) {
                $releaseGroupByRelease[$this->identifier($release)] = $releaseGroupId;
            }
        }
        foreach ($this->list($recording, 'releases') as $release) {
            $releaseGroupByRelease[$this->identifier($release)] ??= null;
        }

        return new AcousticRecording(
            recordingId: $this->identifier($recording),
            releaseGroupIds: array_values(array_unique($releaseGroupIds)),
            releaseGroupByRelease: $releaseGroupByRelease,
        );
    }

    /**
     * @param  array<mixed>  $parent
     * @return list<mixed>
     */
    private function list(array $parent, string $key): array
    {
        $values = $parent[$key] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw $this->unreadable();
        }

        return $values;
    }

    private function identifier(mixed $entity): string
    {
        $identifier = is_array($entity) && is_string($entity['id'] ?? null)
            ? MusicIdentityValueNormalizer::musicBrainzId($entity['id'])
            : null;
        if ($identifier === null) {
            throw $this->unreadable();
        }

        return $identifier;
    }

    private function unreadable(): AcousticFingerprintLookupException
    {
        return new AcousticFingerprintLookupException('AcoustID returned an unreadable response.', retryable: true);
    }

    private function cacheKey(AcousticFingerprintQuery $query): string
    {
        return 'acoustid:lookup:'.hash('sha256', json_encode([
            'algorithm' => $query->algorithm,
            'generatorVersion' => $query->generatorVersion,
            'fingerprintHash' => $query->fingerprintHash,
            'durationSeconds' => $query->durationSeconds,
            'meta' => self::META,
        ], JSON_THROW_ON_ERROR));
    }

    private function clientKey(): ?string
    {
        $clientKey = trim((string) config('music-identity.acoustid.client_key'));

        return $clientKey === '' ? null : $clientKey;
    }
}
