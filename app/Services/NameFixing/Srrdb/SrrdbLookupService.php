<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Srrdb;

use App\Services\NameFixing\DonorMatchSelector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class SrrdbLookupService
{
    private readonly SrrdbRequestBudget $budget;

    private readonly DonorMatchSelector $sizeMatcher;

    private int $consecutiveFailures = 0;

    private bool $circuitOpen = false;

    public function __construct(
        ?SrrdbRequestBudget $budget = null,
        ?DonorMatchSelector $sizeMatcher = null,
    ) {
        $configuredRate = (float) config('nntmux_srrdb.requests_per_second', 1);
        $this->budget = $budget ?? new SrrdbRequestBudget(
            (int) config('nntmux_srrdb.max_requests_per_cycle', 25),
            $configuredRate <= 0 ? 0 : min(1, $configuredRate),
        );
        $this->sizeMatcher = $sizeMatcher ?? new DonorMatchSelector;
    }

    public function lookup(string $crc, int $fileSize, int $releaseSize, bool $complete): SrrdbLookupResult
    {
        $crc = strtoupper(trim($crc));
        if (! preg_match('/^[A-F0-9]{8}$/', $crc)) {
            return new SrrdbLookupResult(SrrdbLookupResult::STATUS_NO_MATCH);
        }

        $cached = $this->cached($crc, $fileSize, $releaseSize, $complete);
        if ($cached !== null) {
            return $cached;
        }

        $search = $this->requestJson("search/archive-crc:{$crc}/archive-size:{$fileSize}");
        if ($search === null) {
            return new SrrdbLookupResult(SrrdbLookupResult::STATUS_UNAVAILABLE);
        }

        if (! is_array($search['results'] ?? null) || ! is_numeric($search['resultsCount'] ?? null)) {
            return new SrrdbLookupResult(SrrdbLookupResult::STATUS_UNAVAILABLE);
        }

        $results = array_values($search['results']);
        $resultsCount = (int) $search['resultsCount'];
        if ($resultsCount < count($results)) {
            return new SrrdbLookupResult(SrrdbLookupResult::STATUS_UNAVAILABLE);
        }

        if ($resultsCount > count($results)) {
            return $this->remember($crc, new SrrdbLookupResult(SrrdbLookupResult::STATUS_AMBIGUOUS));
        }

        if ($results === []) {
            return $this->remember($crc, new SrrdbLookupResult(SrrdbLookupResult::STATUS_NO_MATCH));
        }

        $confirmed = [];
        foreach ($results as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $releaseName = (string) ($candidate['release'] ?? $candidate['name'] ?? '');
            if ($releaseName === '') {
                continue;
            }

            $details = $this->requestJson('details/'.rawurlencode($releaseName));
            if ($details === null) {
                return new SrrdbLookupResult(SrrdbLookupResult::STATUS_UNAVAILABLE);
            }

            $containsArchive = $this->detailsContainArchive($details, $crc, $fileSize);
            if ($containsArchive === null) {
                return new SrrdbLookupResult(SrrdbLookupResult::STATUS_UNAVAILABLE);
            }

            if (! $containsArchive) {
                continue;
            }

            $resultSize = (int) ($candidate['size'] ?? 0);
            if ($complete && ! $this->releaseSizeMatches($resultSize, $releaseSize)) {
                continue;
            }

            $confirmed[] = $this->metadata($candidate, $releaseName, $fileSize);
            if (count($confirmed) > 1) {
                break;
            }
        }

        if (count($confirmed) !== 1) {
            return $this->remember($crc, new SrrdbLookupResult(SrrdbLookupResult::STATUS_AMBIGUOUS));
        }

        return $this->remember($crc, new SrrdbLookupResult(
            SrrdbLookupResult::STATUS_MATCH,
            $confirmed[0],
        ));
    }

    /** @return array<string, mixed>|null */
    private function requestJson(string $path): ?array
    {
        if ($this->circuitOpen) {
            return null;
        }

        $attempts = max(1, (int) config('nntmux_srrdb.retry_attempts', 3));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (! $this->budget->acquire()) {
                return null;
            }

            try {
                $response = $this->http()->get($path);
            } catch (ConnectionException) {
                $this->recordFailure();
                if ($this->circuitOpen || $attempt === $attempts) {
                    return null;
                }

                $this->backoff($attempt);

                continue;
            }

            if ($response->successful()) {
                $this->consecutiveFailures = 0;
                $json = $response->json();

                return is_array($json) ? $json : null;
            }

            if (! in_array($response->status(), [408, 425, 429], true) && ! $response->serverError()) {
                return null;
            }

            $this->recordFailure();
            if ($this->circuitOpen || $attempt === $attempts) {
                return null;
            }

            $this->backoff($attempt);
        }

        return null;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('nntmux_srrdb.base_url'), '/'))
            ->acceptJson()
            ->withUserAgent((string) config('nntmux_srrdb.user_agent'))
            ->timeout((int) config('nntmux_srrdb.timeout_seconds', 10));
    }

    private function recordFailure(): void
    {
        $this->consecutiveFailures++;
        $threshold = max(1, (int) config('nntmux_srrdb.circuit_breaker_failures', 3));
        $this->circuitOpen = $this->consecutiveFailures >= $threshold;
    }

    private function backoff(int $attempt): void
    {
        $base = max(0, (int) config('nntmux_srrdb.backoff_milliseconds', 250));
        if ($base > 0) {
            usleep($base * (2 ** ($attempt - 1)) * 1000);
        }
    }

    /** @param array<string, mixed> $details */
    private function detailsContainArchive(array $details, string $crc, int $fileSize): ?bool
    {
        if ($details === []) {
            return false;
        }

        if (! is_array($details['files'] ?? null)) {
            return null;
        }

        $files = $details['files'];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            if (strtoupper((string) ($file['crc'] ?? '')) === $crc
                && (int) ($file['size'] ?? 0) === $fileSize) {
                return true;
            }
        }

        return false;
    }

    private function releaseSizeMatches(int $candidateSize, int $releaseSize): bool
    {
        return $this->sizeMatcher->select(
            [(object) ['size' => $candidateSize]],
            $releaseSize,
            (int) config('nntmux_srrdb.release_size_tolerance_percent', 5),
        ) !== null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function metadata(array $candidate, string $releaseName, int $fileSize): array
    {
        return [
            'release' => $releaseName,
            'predate' => $candidate['date'] ?? null,
            'size' => (int) ($candidate['size'] ?? 0),
            'has_nfo' => $this->parseBooleanFlag($candidate['hasNFO'] ?? false),
            'imdb_id' => isset($candidate['imdbId']) ? (string) $candidate['imdbId'] : null,
            'category' => isset($candidate['category']) ? (string) $candidate['category'] : null,
            'archive_file_size' => $fileSize,
        ];
    }

    private function parseBooleanFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'yes', 'true'], true);
    }

    private function cached(string $crc, int $fileSize, int $releaseSize, bool $complete): ?SrrdbLookupResult
    {
        $row = DB::table('srrdb_lookups')->where('crc32', $crc)->first();
        if ($row === null) {
            return null;
        }

        $status = (string) $row->status;
        $ttlDays = $status === SrrdbLookupResult::STATUS_MATCH
            ? (int) config('nntmux_srrdb.positive_ttl_days', 365)
            : (int) config('nntmux_srrdb.negative_ttl_days', 30);
        if (CarbonImmutable::parse((string) $row->checked_at)->addDays(max(0, $ttlDays))->isPast()) {
            return null;
        }

        $metadata = json_decode((string) ($row->payload ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        if ($status === SrrdbLookupResult::STATUS_MATCH
            && ((int) ($metadata['archive_file_size'] ?? 0) !== $fileSize
                || ($complete && ! $this->releaseSizeMatches((int) ($metadata['size'] ?? 0), $releaseSize)))) {
            return null;
        }

        return new SrrdbLookupResult($status, $metadata);
    }

    private function remember(string $crc, SrrdbLookupResult $result): SrrdbLookupResult
    {
        $now = now();
        DB::table('srrdb_lookups')->updateOrInsert(
            ['crc32' => $crc],
            [
                'status' => $result->status,
                'payload' => $result->metadata === [] ? null : json_encode($result->metadata, JSON_THROW_ON_ERROR),
                'checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return $result;
    }
}
