<?php

declare(strict_types=1);

namespace Tests\Feature\ProviderGateway;

use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use App\Services\ProviderGateway\ProviderResult;
use App\Services\ProviderGateway\ShortCircuit;
use App\Services\ProviderGateway\ShortCircuitConnectException;
use App\Services\ProviderGateway\ShortCircuitConnectionException;
use App\Services\ProviderGateway\ShortCircuitReason;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ProviderGatewayTest extends TestCase
{
    private const string URL = 'https://provider.test/lookup';

    /** Answers every faked request; a test swaps it to script the provider. */
    private Closure $responder;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow('2026-10-23 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests();
        $this->responder = static fn (Request $request) => Http::response(['ok' => true]);
        Http::fake(fn (Request $request) => ($this->responder)($request));
        config(['provider-gateway.providers.fixture' => [
            'pace' => ['min_interval_ms' => 1_000],
            'retry' => ['attempts' => 3],
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function consecutive_requests_are_spaced_by_the_minimum_interval_and_a_cache_hit_waits_nothing(): void
    {
        $sentAt = [];
        $this->responder = function () use (&$sentAt) {
            $sentAt[] = now()->getPreciseTimestamp(3);

            return Http::response(['ok' => true]);
        };

        foreach (range(1, 4) as $request) {
            $this->gateway()->http('fixture')->get(self::URL);
        }

        $this->assertCount(4, $sentAt);
        $this->assertGreaterThanOrEqual(3 * 1_000, $sentAt[3] - $sentAt[0]);
        for ($index = 1; $index < 4; $index++) {
            $this->assertGreaterThanOrEqual(1_000, $sentAt[$index] - $sentAt[$index - 1]);
        }

        $before = now()->getPreciseTimestamp(3);
        Cache::put('fixture_answer', ['id' => 1], 60);
        $result = $this->gateway()->remember('fixture', 'fixture_answer', 60, 60, fn (): ProviderResult => $this->fail('A cache hit must not ask the provider.'));

        $this->assertTrue($result->isFound());
        $this->assertSame($before, now()->getPreciseTimestamp(3), 'a cache hit waits nothing');
        Http::assertSentCount(4);
        $this->assertSame(1, $this->gateway()->counters('fixture')['cache_hits']);
    }

    #[Test]
    public function a_short_retry_after_is_waited_and_the_request_retried(): void
    {
        $sentAt = [];
        $this->responder = function () use (&$sentAt) {
            $sentAt[] = now()->getPreciseTimestamp(3);

            return count($sentAt) === 1
                ? Http::response('busy', 503, ['Retry-After' => '2'])
                : Http::response(['ok' => true]);
        };

        $response = $this->gateway()->http('fixture')->get(self::URL);

        $this->assertTrue($response->successful());
        $this->assertCount(2, $sentAt);
        $this->assertGreaterThanOrEqual(2_000, $sentAt[1] - $sentAt[0]);
        $this->assertSame(ProviderOutcome::Found, $this->gateway()->lastOutcome('fixture'));
    }

    #[Test]
    public function a_long_retry_after_is_not_waited_and_holds_every_worker(): void
    {
        $this->responder = static fn () => Http::response('busy', 429, ['Retry-After' => '120']);
        $started = now()->getPreciseTimestamp(3);

        $response = $this->gateway()->http('fixture')->get(self::URL);

        $this->assertSame(429, $response->status());
        $this->assertSame(ProviderOutcome::Unavailable, $this->gateway()->lastOutcome('fixture'));
        $this->assertSame($started, now()->getPreciseTimestamp(3), 'the worker did not wait out the Retry-After');
        $this->assertEquals(now()->addSeconds(120), $this->gateway()->heldUntil('fixture'));
        Http::assertSentCount(1);

        // A second worker (its own gateway, sharing the cache store) does not wait either.
        $secondWorker = $this->freshGateway();
        try {
            $secondWorker->http('fixture')->get(self::URL);
            $this->fail('A request during the hold must not be sent.');
        } catch (ShortCircuitConnectionException $exception) {
            $this->assertSame(ShortCircuitReason::PacerSkip, $exception->reason());
        }
        $this->assertSame($started, now()->getPreciseTimestamp(3));
        Http::assertSentCount(1);
        $this->assertSame(1, $secondWorker->counters('fixture')['pacer_skips']);
    }

    #[Test]
    public function failed_attempts_open_the_breaker_and_an_open_breaker_sends_nothing(): void
    {
        $this->responder = static fn () => Http::response('down', 503);

        $response = $this->gateway()->http('fixture')->get(self::URL);

        $this->assertSame(503, $response->status());
        Http::assertSentCount(3);
        $this->assertTrue($this->gateway()->isOpen('fixture'));

        try {
            $this->gateway()->http('fixture')->get(self::URL);
            $this->fail('An open breaker must not send.');
        } catch (ConnectionException $exception) {
            $this->assertInstanceOf(ShortCircuit::class, $exception);
            $this->assertSame(ShortCircuitReason::BreakerOpen, $exception->reason());
        }
        Http::assertSentCount(3);
        $this->assertSame(ProviderOutcome::Unavailable, $this->gateway()->lastOutcome('fixture'));
        $this->assertSame(1, $this->gateway()->counters('fixture')['breaker_opens']);
    }

    #[Test]
    public function exactly_one_worker_sends_the_trial_and_a_failed_trial_doubles_the_open_time_up_to_an_hour(): void
    {
        config(['provider-gateway.providers.fixture.retry.attempts' => 1]);
        $this->openFixtureBreaker();

        $refusedDuringTrial = null;
        $this->responder = function () use (&$refusedDuringTrial) {
            try {
                // A second worker asks while the first worker's trial is in flight.
                $this->freshGateway()->http('fixture')->get(self::URL);
            } catch (ShortCircuitConnectionException $exception) {
                $refusedDuringTrial = $exception->reason();
            }

            return Http::response('still down', 503);
        };
        $this->travel(60)->seconds();
        $sent = $this->sent();

        $this->gateway()->http('fixture')->get(self::URL);

        $this->assertSame($sent + 1, $this->sent(), 'only the trial was sent');
        $this->assertSame(ShortCircuitReason::TrialClaimed, $refusedDuringTrial);
        $this->assertTrue($this->gateway()->isOpen('fixture'));
        $this->travel(119)->seconds();
        $this->assertTrue($this->gateway()->isOpen('fixture'), 'the failed trial re-opened it for 120 s');
        $this->travel(1)->seconds();
        $this->assertFalse($this->gateway()->isOpen('fixture'));

        // Keep failing trials: 240, 480, ... never more than an hour.
        $this->responder = static fn () => Http::response('still down', 503);
        $openTimes = [];
        foreach (range(1, 6) as $trial) {
            $this->gateway()->http('fixture')->get(self::URL);
            $openTimes[] = $this->secondsUntilClosed();
        }
        $this->assertSame([240, 480, 960, 1_920, 3_600, 3_600], $openTimes);

        // A successful trial closes it and resets the doubling.
        $this->responder = static fn () => Http::response(['ok' => true]);
        $this->gateway()->http('fixture')->get(self::URL);
        $this->assertFalse($this->gateway()->isOpen('fixture'));
        $this->responder = static fn () => Http::response('down', 503);
        foreach (range(1, 3) as $failure) {
            $this->gateway()->http('fixture')->get(self::URL);
        }
        $this->assertSame(60, $this->secondsUntilClosed());
    }

    #[Test]
    public function a_rejection_is_neither_retried_nor_counted_by_the_breaker(): void
    {
        $this->responder = static fn () => Http::response('bad key', 401);

        foreach (range(1, 5) as $call) {
            $this->assertSame(401, $this->gateway()->http('fixture')->get(self::URL)->status());
        }

        Http::assertSentCount(5);
        $this->assertFalse($this->gateway()->isOpen('fixture'));
        $this->assertSame(ProviderOutcome::Rejected, $this->gateway()->lastOutcome('fixture'));
    }

    #[Test]
    public function a_404_is_not_found_and_a_reported_empty_answer_is_not_found(): void
    {
        $this->responder = static fn (Request $request) => $request->url() === self::URL
            ? Http::response('missing', 404)
            : Http::response(['results' => []]);

        $this->gateway()->http('fixture')->get(self::URL);
        $this->assertSame(ProviderOutcome::NotFound, $this->gateway()->lastOutcome('fixture'));
        Http::assertSentCount(1);

        $this->gateway()->http('fixture')->get('https://provider.test/empty');
        $this->assertSame(ProviderOutcome::Found, $this->gateway()->lastOutcome('fixture'));
        $this->gateway()->report('fixture', ProviderOutcome::NotFound);

        $this->assertSame(ProviderOutcome::NotFound, $this->gateway()->lastOutcome('fixture'));
        $counters = $this->gateway()->counters('fixture');
        $this->assertSame(0, $counters['found']);
        $this->assertSame(2, $counters['not_found']);
    }

    #[Test]
    public function remember_caches_answers_under_the_existing_key_and_never_caches_unavailable_or_rejected(): void
    {
        $gateway = $this->gateway();

        $unavailable = $gateway->remember('fixture', 'fixture_movie_1', 600, 60, static fn (): ProviderResult => ProviderResult::unavailable());
        $rejected = $gateway->remember('fixture', 'fixture_movie_2', 600, 60, static fn (): ProviderResult => ProviderResult::rejected());
        $notFound = $gateway->remember('fixture', 'fixture_movie_3', 600, 60, static fn (): ProviderResult => ProviderResult::notFound());
        $found = $gateway->remember('fixture', 'fixture_movie_4', 600, 60, static fn (): ProviderResult => ProviderResult::found(['title' => 'Film']));

        $this->assertTrue($unavailable->isUnavailable());
        $this->assertFalse(Cache::has('fixture_movie_1'));
        $this->assertSame(ProviderOutcome::Rejected, $rejected->outcome);
        $this->assertFalse(Cache::has('fixture_movie_2'));
        $this->assertTrue($notFound->isNotFound());
        $this->assertFalse(Cache::get('fixture_movie_3'), 'a not-found answer is stored as false, the shape readers already know');
        $this->assertSame(['title' => 'Film'], $found->value);
        $this->assertSame(['title' => 'Film'], Cache::get('fixture_movie_4'));

        $this->travel(61)->seconds();
        $this->assertFalse(Cache::has('fixture_movie_3'), 'not found keeps its own time');
        $this->assertTrue(Cache::has('fixture_movie_4'));

        // Entries written before the gateway read back the same way.
        Cache::put('fixture_legacy_false', false, 60);
        Cache::put('fixture_legacy_empty', [], 60);
        $this->assertTrue($gateway->remember('fixture', 'fixture_legacy_false', 60, 60, fn (): ProviderResult => $this->fail('cached'))->isNotFound());
        $this->assertTrue($gateway->remember('fixture', 'fixture_legacy_empty', 60, 60, fn (): ProviderResult => $this->fail('cached'))->isNotFound());
    }

    #[Test]
    public function a_failing_lock_backend_paces_in_this_process_and_the_lookup_still_runs(): void
    {
        Cache::extend('locks-down', fn ($app) => Cache::repository(new class extends ArrayStore
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379]');
            }
        }));
        config(['cache.stores.locks-down' => ['driver' => 'locks-down'], 'cache.default' => 'locks-down']);
        Cache::forgetDriver('locks-down');
        $sentAt = [];
        $this->responder = function () use (&$sentAt) {
            $sentAt[] = now()->getPreciseTimestamp(3);

            return Http::response(['ok' => true]);
        };

        $gateway = $this->freshGateway();
        $gateway->http('fixture')->get(self::URL);
        $gateway->http('fixture')->get(self::URL);

        $this->assertCount(2, $sentAt);
        $this->assertGreaterThanOrEqual(1_000, $sentAt[1] - $sentAt[0], 'still spaced, in this process');
    }

    #[Test]
    public function a_guzzle_client_short_circuits_with_a_connect_exception(): void
    {
        $this->openFixtureBreaker();
        $handler = new MockHandler([new PsrResponse(200)]);
        $client = $this->gateway()->guzzle('fixture', handler: $handler);

        try {
            $client->get(self::URL);
            $this->fail('An open breaker must not send.');
        } catch (ConnectException $exception) {
            $this->assertInstanceOf(ShortCircuitConnectException::class, $exception);
        }

        $this->assertCount(1, $handler, 'the queued response was never used');
    }

    #[Test]
    public function a_guzzle_client_retries_transport_failures_through_its_handler(): void
    {
        $handler = new MockHandler([
            new ConnectException('timed out', new PsrRequest('GET', self::URL)),
            new PsrResponse(200, [], 'ok'),
        ]);

        $response = $this->gateway()->guzzle('fixture', handler: $handler)->get(self::URL);

        $this->assertSame('ok', (string) $response->getBody());
        $counters = $this->gateway()->counters('fixture');
        $this->assertSame(2, $counters['requests']);
        $this->assertSame(1, $counters['transport_failures']);
        $this->assertSame(1, $counters['retries']);
    }

    #[Test]
    public function call_counts_only_exceptions_as_failures(): void
    {
        foreach (range(1, 3) as $call) {
            $this->assertTrue($this->gateway()->call('fixture', static fn (): bool => false)->isNotFound());
        }
        $this->assertFalse($this->gateway()->isOpen('fixture'));

        foreach (range(1, 3) as $call) {
            $result = $this->gateway()->call('fixture', static fn () => throw new RuntimeException('curl failed'));
            $this->assertTrue($result->isUnavailable());
        }
        $this->assertTrue($this->gateway()->isOpen('fixture'));

        $ran = false;
        $result = $this->gateway()->call('fixture', static function () use (&$ran): array {
            $ran = true;

            return ['id' => 1];
        });
        $this->assertFalse($ran);
        $this->assertTrue($result->isUnavailable());
    }

    #[Test]
    public function counters_show_requests_retries_429s_waits_and_breaker_opens_and_expire_after_eight_days(): void
    {
        $sequence = Http::sequence()
            ->push('slow down', 429, ['Retry-After' => '1'])
            ->push('down', 503)
            ->push(['ok' => true])
            ->push('down', 503)
            ->push('down', 503)
            ->push('down', 503);
        $this->responder = static fn (Request $request) => $sequence($request);

        $this->gateway()->http('fixture')->get(self::URL);
        $this->gateway()->http('fixture')->get(self::URL);

        $counters = $this->gateway()->counters('fixture');
        $this->assertSame(6, $counters['requests']);
        $this->assertSame(4, $counters['retries']);
        $this->assertSame(1, $counters['status_429']);
        $this->assertSame(4, $counters['status_5xx']);
        $this->assertSame(1, $counters['found']);
        $this->assertSame(1, $counters['breaker_opens']);
        $this->assertGreaterThanOrEqual(1_000, $counters['waited_ms']);

        $today = now()->copy();
        $this->travel(8)->days();
        $this->travel(1)->seconds();
        $this->assertSame(0, $this->gateway()->counters('fixture', $today)['requests']);
    }

    private function openFixtureBreaker(): void
    {
        $gateway = $this->freshGateway();
        $this->responder = static fn () => Http::response('down', 503);
        config(['provider-gateway.providers.fixture.retry.attempts' => 1]);
        foreach (range(1, 3) as $failure) {
            $gateway->http('fixture')->get(self::URL);
        }
        $this->assertTrue($gateway->isOpen('fixture'));
    }

    private function sent(): int
    {
        return Http::recorded()->count();
    }

    /** How long the open breaker stays open; the clock ends just after it closes. */
    private function secondsUntilClosed(): int
    {
        $seconds = (int) round(((float) Cache::get('provider-gateway:fixture:breaker:open_until') - now()->getPreciseTimestamp(3)) / 1_000);
        $this->travel($seconds - 1)->seconds();
        $this->assertTrue($this->gateway()->isOpen('fixture'));
        $this->travel(1)->seconds();
        $this->assertFalse($this->gateway()->isOpen('fixture'));

        return $seconds;
    }

    private function gateway(): ProviderGateway
    {
        return app(ProviderGateway::class);
    }

    /** Another worker: its own process state, the same cache store. */
    private function freshGateway(): ProviderGateway
    {
        return app()->build(ProviderGateway::class);
    }
}
