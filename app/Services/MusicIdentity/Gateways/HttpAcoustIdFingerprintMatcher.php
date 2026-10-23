<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Contracts\AcousticFingerprintMatcher;
use App\Services\MusicIdentity\DTO\AcousticFingerprintMatch;
use App\Services\MusicIdentity\DTO\AcousticFingerprintQuery;
use App\Services\MusicIdentity\DTO\AcousticRecording;
use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;
use App\Services\MusicIdentity\Support\MusicIdentityValueNormalizer;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * AcoustID `/v2/lookup` by fingerprint. Requests are POSTed with the configured client key, the
 * whole-file duration and the fingerprint through the provider gateway, which spaces them across
 * workers, retries a throttled or unreadable answer and honours Retry-After for every worker.
 * Every validated answer (a no-match or a rejection of the fingerprint included) is cached per
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

    // @phpstan-ignore constructor.unusedParameter (Its callers still pass the pacer; the provider gateway now paces every AcoustID request.)
    public function __construct(AcoustIdRequestPacer $pacer) {}

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
        try {
            $response = app(ProviderGateway::class)
                ->http('acoustid', classify: fn (ResponseInterface $response): ProviderOutcome => $this->classify($response))
                ->asForm()
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
            throw new AcousticFingerprintLookupException('AcoustID request failed: '.$exception->getMessage(), retryable: true, previous: $exception);
        }

        $payload = $this->decode($response->body());
        if ($response->successful() && $payload !== null && ($payload['status'] ?? null) === 'ok') {
            return $payload;
        }

        $errorCode = $this->errorCode($payload);
        $message = $response->successful() && $errorCode === null
            ? 'AcoustID returned an unreadable response.'
            : sprintf('AcoustID lookup returned HTTP %d%s.', $response->status(), $this->errorDescription($payload));

        // A rejection of this fingerprint is its answer, cached like any other. Anything else
        // (a rejection of the deployment, or throttling and failures the retries did not get
        // past) defers the decision.
        $rejectsFingerprint = $this->classify($response->toPsrResponse()) === ProviderOutcome::Rejected
            && ! $this->rejectsDeployment($response->status(), $errorCode);

        throw new AcousticFingerprintLookupException($message, retryable: ! $rejectsFingerprint, cacheable: $rejectsFingerprint);
    }

    /**
     * What one AcoustID answer means: an ok payload is found, throttling, a failure or an
     * unreadable 2xx reply is unavailable (and retried), any other refusal is rejected.
     */
    private function classify(ResponseInterface $response): ProviderOutcome
    {
        $status = $response->getStatusCode();
        $successful = $status >= 200 && $status < 300;
        $payload = $this->decode((string) $response->getBody());
        if ($successful && $payload !== null && ($payload['status'] ?? null) === 'ok') {
            return ProviderOutcome::Found;
        }

        $errorCode = $this->errorCode($payload);
        if ($this->rejectsDeployment($status, $errorCode)) {
            return ProviderOutcome::Rejected;
        }

        return $status === 429
            || $status >= 500
            || in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)
            || ($successful && $errorCode === null)
            ? ProviderOutcome::Unavailable
            : ProviderOutcome::Rejected;
    }

    /** A rejection of the deployment (its client key), not of the fingerprint. */
    private function rejectsDeployment(int $status, ?int $errorCode): bool
    {
        return in_array($status, [401, 403], true) || in_array($errorCode, self::CONFIGURATION_ERROR_CODES, true);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $body): ?array
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
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
