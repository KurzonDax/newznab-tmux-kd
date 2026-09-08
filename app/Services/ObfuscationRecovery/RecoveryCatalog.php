<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class RecoveryCatalog
{
    private static ?int $publication = null;

    /** @var array<string,true> */
    private static array $cacheResults = [];

    /** @template T
     * @param  callable():T  $operation
     * @return T
     */
    public static function run(int $releaseId, callable $operation): mixed
    {
        $previous = self::$publication;
        $previousCache = self::$cacheResults;
        $publication = (new RecoveryIdentityPolicy)->publication($releaseId);
        self::$cacheResults = [];
        self::$publication = $publication !== null && $publication->state === 'published' && $publication->deleted_at === null
            ? (int) $publication->id : null;
        try {
            return $operation();
        } finally {
            self::$publication = $previous;
            self::$cacheResults = $previousCache;
        }
    }

    /** @param array<string,mixed> $options */
    public static function client(array $options = []): Client
    {
        $stack = HandlerStack::create();
        $stack->push(self::middleware(), 'recovery_catalog');

        return new Client([...$options, 'handler' => $stack]);
    }

    public static function middleware(): callable
    {
        return static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $id = self::begin($request->getUri()->getHost(), 'http');
            try {
                return $handler($request, $options)->then(
                    static function (ResponseInterface $response) use ($id): ResponseInterface {
                        self::finish($id, $response->getStatusCode() < 400 ? 'success' : 'http_failure', $response->getStatusCode(), $response->getBody()->getSize());

                        return $response;
                    },
                    static function (mixed $reason) use ($id): PromiseInterface {
                        self::finish($id, 'transport_failure');

                        return Create::rejectionFor($reason);
                    },
                );
            } catch (Throwable $error) {
                self::finish($id, 'transport_failure');
                throw $error;
            }
        };
    }

    /** @template T
     * @param  callable():T  $operation
     * @return T
     */
    public static function legacy(string $provider, callable $operation): mixed
    {
        $id = self::begin($provider, 'legacy_http');
        try {
            $result = $operation();
            self::finish($id, $result === false || $result === null ? 'unavailable' : 'response_received');

            return $result;
        } catch (Throwable $error) {
            self::finish($id, 'transport_failure');
            throw $error;
        }
    }

    public static function cacheHit(CacheHit $event): void
    {
        if (self::$publication === null || isset(self::$cacheResults[$event->key])
            || preg_match('/^(?:(tmdb|omdb)_movie_|(imdb)_(?:movie|scrape_id|search)_|(fanarttv)_(?:movie|tv|music)_|(trakt)_(?:episode|show_ids|movie|search|show|seasons|season_episodes)_)/i', $event->key, $match) !== 1) {
            return;
        }
        self::$cacheResults[$event->key] = true;
        $provider = array_values(array_filter(array_slice($match, 1)))[0];
        self::finish(self::begin(strtolower($provider), 'cache'), 'hit', bytes: 0);
    }

    public static function compact(): int
    {
        return DB::transaction(function (): int {
            $rows = DB::table('obfuscation_recovery_catalog')->whereNull('aggregate_digest')
                ->where('created_at', '<=', now()->subDays(RecoveryCompaction::DETAIL_DAYS))->orderBy('created_at')->orderBy('id')
                ->limit(100)->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($row->finished_at === null) {
                    $row->outcome = 'interrupted_unknown';
                    $row->response_bytes = null;
                    $row->unknown_response_sizes = (int) $row->requests;
                }
                $day = substr($row->created_at, 0, 10).' 00:00:00';
                $digest = (new RecoveryIdentity)->digest([(string) $row->publication_id, $row->provider, $row->kind,
                    $row->outcome, (string) $row->http_status, $day]);
                DB::table('obfuscation_recovery_catalog')->insertOrIgnore([
                    'aggregate_digest' => $digest, 'publication_id' => $row->publication_id, 'provider' => $row->provider,
                    'kind' => $row->kind, 'outcome' => $row->outcome, 'http_status' => $row->http_status,
                    'requests' => 0, 'response_bytes' => 0, 'unknown_response_sizes' => 0, 'created_at' => $day, 'finished_at' => now(),
                ]);
                $aggregate = DB::table('obfuscation_recovery_catalog')->where('aggregate_digest', $digest)->lockForUpdate()->first();
                DB::table('obfuscation_recovery_catalog')->where('id', $aggregate->id)->update([
                    'requests' => (int) $aggregate->requests + (int) $row->requests,
                    'response_bytes' => (int) $aggregate->response_bytes + (int) $row->response_bytes,
                    'unknown_response_sizes' => (int) $aggregate->unknown_response_sizes + (int) $row->unknown_response_sizes,
                ]);
                DB::table('obfuscation_recovery_catalog')->where('id', $row->id)->delete();
            }

            return $rows->count();
        }, 1);
    }

    private static function begin(string $provider, string $kind): ?int
    {
        if (self::$publication === null) {
            return null;
        }

        return DB::table('obfuscation_recovery_catalog')->insertGetId([
            'publication_id' => self::$publication, 'provider' => substr(strtolower($provider), 0, 255),
            'kind' => $kind, 'outcome' => 'started', 'created_at' => now(),
        ]);
    }

    private static function finish(?int $id, string $outcome, ?int $status = null, ?int $bytes = null): void
    {
        if ($id === null) {
            return;
        }
        DB::table('obfuscation_recovery_catalog')->where('id', $id)->whereNull('finished_at')->update([
            'outcome' => $outcome, 'http_status' => $status, 'response_bytes' => $bytes,
            'unknown_response_sizes' => (int) ($bytes === null), 'finished_at' => now(),
        ]);
    }
}
