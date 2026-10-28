<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

class ImdbScraper
{
    /** How long IMDb's answer that it has no such title is kept. */
    protected const int NOT_FOUND_TTL_HOURS = 6;

    protected Client $searchClient;

    private bool $lastSearchAvailable = false;

    public function wasSearchUnavailable(): bool
    {
        return ! $this->lastSearchAvailable;
    }

    /**
     * Only IMDb's suggestion search is sent, through the provider gateway as `imdb_search` with
     * its own breaker. A WAF challenge is no answer and counts against the breaker. An injected
     * client's handler is used as the transport.
     */
    public function __construct(?Client $client = null)
    {
        $options = [
            'timeout' => 10,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ],
        ];
        $classify = fn (ResponseInterface $response): ?ProviderOutcome => $this->isWafResponse($response->getStatusCode(), (string) $response->getBody())
            ? ProviderOutcome::Unavailable
            : null;
        $gateway = app(ProviderGateway::class);
        $this->searchClient = $gateway->guzzle('imdb_search', $options, $client, classify: $classify);
    }

    /**
     * Search IMDb suggestion API for a title.
     *
     * @return array<string, mixed>
     */
    public function search(string $query): array
    {
        $this->lastSearchAvailable = false;

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $norm = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $query) ?? '');
        $slug = preg_replace('/\s+/', '_', trim($norm)) ?? '';
        if ($slug === '') {
            return [];
        }

        $prefix = substr($slug, 0, 1);
        $cacheKey = 'imdb_search_typed_'.md5($slug);
        if (Cache::has($cacheKey)) {
            $this->lastSearchAvailable = true;

            return Cache::get($cacheKey);
        }

        $url = 'https://v2.sg.media-imdb.com/suggestion/'.urlencode($prefix).'/'.urlencode($slug).'.json';

        try {
            $res = $this->searchClient->get($url, [
                'headers' => [
                    'Accept' => 'application/json,text/plain,*/*',
                    'Origin' => 'https://www.imdb.com',
                    'Referer' => 'https://www.imdb.com/find/?q='.rawurlencode($query).'&s=tt',
                ],
            ]);
            $body = (string) $res->getBody();

            $results = [];
            if ($res->getStatusCode() === 200 && ! $this->isWafResponse($res->getStatusCode(), $body)) {
                $json = json_decode($body, true);
                $this->lastSearchAvailable = is_array($json) && isset($json['d']) && is_array($json['d']);
                $results = $this->parseSuggestionJson($body);
            }

            if ($results === []) {
                if ($this->lastSearchAvailable) {
                    Cache::put($cacheKey, [], now()->addHours(self::NOT_FOUND_TTL_HOURS));
                }

                return [];
            }

            $this->lastSearchAvailable = true;
            Cache::put($cacheKey, $results, now()->addHours(12));

            return $results; // @phpstan-ignore return.type
        } catch (\Throwable $e) {
            Log::debug('IMDb search error '.$query.': '.$e->getMessage());

            return [];
        }
    }

    private function isWafResponse(int $statusCode, string $body): bool
    {
        if ($statusCode === 202) {
            return true;
        }

        if ($body === '') {
            return false;
        }

        return Str::contains($body, [
            'window.awsWafCookieDomainList',
            'window.gokuProps',
            'challenge.js',
            'captcha',
            'awswaf',
        ], true);
    }

    /**
     * @return array<int, array{imdbid: string, title: string, year: string, type: string}>
     */
    private function parseSuggestionJson(string $body): array
    {
        $json = json_decode($body, true);
        if (! is_array($json)) {
            return [];
        }

        $results = [];
        foreach (($json['d'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_string($row['id']) || ! str_starts_with($row['id'], 'tt')) {
                continue;
            }

            $title = trim((string) ($row['l'] ?? ''));
            if ($title === '') {
                continue;
            }

            $results[] = [
                'imdbid' => substr($row['id'], 2),
                'title' => $title,
                'year' => trim((string) ($row['y'] ?? '')),
                'type' => (string) ($row['qid'] ?? $row['q'] ?? ''),
            ];

            if (count($results) >= 25) {
                break;
            }
        }

        return $results;
    }
}
