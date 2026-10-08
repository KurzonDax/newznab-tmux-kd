<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\DTO\AcousticFingerprintQuery;
use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;
use App\Services\MusicIdentity\Gateways\AcoustIdRequestPacer;
use App\Services\MusicIdentity\Gateways\HttpAcoustIdFingerprintMatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HttpAcoustIdFingerprintMatcherTest extends TestCase
{
    private const string LOOKUP_URL = 'https://acoustid.test/v2/lookup';

    private const string CLIENT_KEY = 'synthetic-client-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'music-identity.acoustid.client_key' => self::CLIENT_KEY,
            'music-identity.acoustid.lookup_url' => self::LOOKUP_URL,
            'music-identity.acoustid.requests_per_second' => 3,
            'music-identity.acoustid.retry.attempts' => 3,
            'music-identity.acoustid.retry.backoff_milliseconds' => 1_000,
            'music-identity.acoustid.retry.maximum_wait_seconds' => 30,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-08 12:00:00');
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_posts_the_client_key_whole_file_duration_fingerprint_and_identifier_meta(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-one-recording.json'))]);

        $matches = $this->matcher()->lookup($this->lookupQuery());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::LOOKUP_URL
                && $request->isForm()
                && $request['client'] === self::CLIENT_KEY
                && (int) $request['duration'] === 241
                && $request['fingerprint'] === 'AQADtSyntheticFingerprintOne'
                && $request['meta'] === 'recordingids releaseids releasegroupids'
                && $request['format'] === 'json';
        });
        $this->assertCount(1, $matches);
        $this->assertSame('0a1b2c3d-0000-4000-8000-000000000001', $matches[0]->trackId);
        $this->assertSame(0.962331, $matches[0]->score);
        $this->assertCount(1, $matches[0]->recordings);
        $recording = $matches[0]->recordings[0];
        $this->assertSame('c0ffee00-0000-4000-8000-000000000001', $recording->recordingId);
        $this->assertSame(['c0ffee00-0000-4000-8000-0000000000a1'], $recording->releaseGroupIds);
        $this->assertSame([
            'c0ffee00-0000-4000-8000-0000000000b1' => 'c0ffee00-0000-4000-8000-0000000000a1',
            'c0ffee00-0000-4000-8000-0000000000b2' => 'c0ffee00-0000-4000-8000-0000000000a1',
        ], $recording->releaseGroupByRelease);
    }

    #[Test]
    public function a_match_without_a_musicbrainz_link_has_no_recordings(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-no-link.json'))]);

        $matches = $this->matcher()->lookup($this->lookupQuery());

        $this->assertCount(1, $matches);
        $this->assertSame([], $matches[0]->recordings);
    }

    #[Test]
    public function many_linked_recordings_keep_their_own_releases_and_groups(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-many-recordings.json'))]);

        $matches = $this->matcher()->lookup($this->lookupQuery());

        $this->assertCount(2, $matches);
        $this->assertSame(
            ['c0ffee00-0000-4000-8000-000000000011', 'c0ffee00-0000-4000-8000-000000000012', 'c0ffee00-0000-4000-8000-000000000013'],
            array_map(static fn ($recording): string => $recording->recordingId, $matches[0]->recordings),
        );
        $this->assertSame(['c0ffee00-0000-4000-8000-0000000000b9' => null], $matches[0]->recordings[1]->releaseGroupByRelease);
        $this->assertSame([], $matches[0]->recordings[2]->releaseGroupIds);
        $this->assertSame(0.41, $matches[1]->score);
        $this->assertSame(['c0ffee00-0000-4000-8000-0000000000a4'], $matches[1]->recordings[0]->releaseGroupIds);
    }

    #[Test]
    public function a_genuine_no_match_is_an_empty_cached_answer(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-no-match.json'))]);

        $this->assertSame([], $this->matcher()->lookup($this->lookupQuery()));
        $this->assertSame([], $this->matcher()->lookup($this->lookupQuery()));

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_cached_lookup_sends_no_further_request(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-one-recording.json'))]);

        $first = $this->matcher()->lookup($this->lookupQuery());
        $second = (new HttpAcoustIdFingerprintMatcher(new AcoustIdRequestPacer))->lookup($this->lookupQuery());

        Http::assertSentCount(1);
        $this->assertEquals($first, $second);
    }

    #[Test]
    public function the_cache_is_keyed_by_algorithm_generator_version_hash_and_duration(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-no-match.json'))]);
        $matcher = $this->matcher();

        $matcher->lookup($this->lookupQuery());
        $matcher->lookup($this->lookupQuery(algorithm: 1));
        $matcher->lookup($this->lookupQuery(generatorVersion: 'synthetic-generator-v2'));
        $matcher->lookup($this->lookupQuery(hash: str_repeat('b', 64)));
        $matcher->lookup($this->lookupQuery(durationSeconds: 242));
        $matcher->lookup($this->lookupQuery());

        Http::assertSentCount(5);
    }

    #[Test]
    public function a_rejected_fingerprint_is_an_error_not_a_no_match_and_a_repeat_sends_nothing(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-invalid-fingerprint.json'), 400)]);

        foreach ([1, 2] as $attempt) {
            try {
                $this->matcher()->lookup($this->lookupQuery());
                $this->fail('A rejected fingerprint must not read as a no-match.');
            } catch (AcousticFingerprintLookupException $exception) {
                $this->assertFalse($exception->retryable, 'attempt '.$attempt);
                $this->assertStringContainsString('invalid fingerprint', $exception->getMessage());
            }
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_rejected_client_key_defers_the_lookup_and_is_never_cached(): void
    {
        Http::fake([self::LOOKUP_URL => Http::sequence()
            ->push(['status' => 'error', 'error' => ['code' => 4, 'message' => 'invalid API key']], 400)
            ->push($this->fixture('lookup-no-match.json')),
        ]);

        try {
            $this->matcher()->lookup($this->lookupQuery());
            $this->fail('A rejected client key must throw.');
        } catch (AcousticFingerprintLookupException $exception) {
            $this->assertTrue($exception->retryable, 'a deployment problem defers the decision instead of skipping the file');
        }

        $this->assertSame([], $this->matcher()->lookup($this->lookupQuery()), 'the fixed key is looked up again');
        Http::assertSentCount(2);
    }

    #[Test]
    public function throttling_waits_for_retry_after_and_the_retry_succeeds(): void
    {
        $sentAt = [];
        Http::fake(function () use (&$sentAt) {
            $sentAt[] = now()->getPreciseTimestamp(3);

            return count($sentAt) === 1
                ? Http::response($this->fixture('lookup-too-many-requests.json'), 429, ['Retry-After' => '4'])
                : Http::response($this->fixture('lookup-one-recording.json'));
        });

        $matches = $this->matcher()->lookup($this->lookupQuery());

        $this->assertCount(2, $sentAt);
        $this->assertGreaterThanOrEqual(4_000, $sentAt[1] - $sentAt[0], 'the retry honours Retry-After');
        $this->assertCount(1, $matches);
    }

    #[Test]
    public function throttling_that_outlasts_the_retries_is_a_retryable_error_and_is_never_cached(): void
    {
        $unavailable = Http::response($this->fixture('lookup-too-many-requests.json'), 503);
        Http::fake([self::LOOKUP_URL => Http::sequence([$unavailable, $unavailable, $unavailable])
            ->push($this->fixture('lookup-no-match.json')),
        ]);

        try {
            $this->matcher()->lookup($this->lookupQuery());
            $this->fail('Exhausted retries must throw.');
        } catch (AcousticFingerprintLookupException $exception) {
            $this->assertTrue($exception->retryable);
        }
        Http::assertSentCount(3);

        $this->assertSame([], $this->matcher()->lookup($this->lookupQuery()), 'the failure was not cached');
        Http::assertSentCount(4);
    }

    #[Test]
    public function a_retry_after_received_by_one_worker_holds_off_the_other(): void
    {
        $sentAt = [];
        Http::fake(function (Request $request) use (&$sentAt) {
            $sentAt[] = [$request['fingerprint'], now()->getPreciseTimestamp(3)];

            return count($sentAt) === 1
                ? Http::response($this->fixture('lookup-too-many-requests.json'), 429, ['Retry-After' => '20'])
                : Http::response($this->fixture('lookup-no-match.json'));
        });
        config(['music-identity.acoustid.retry.attempts' => 1]);

        try {
            $this->matcher()->lookup($this->lookupQuery(fingerprint: 'worker-a', hash: hash('sha256', 'worker-a')));
            $this->fail('The throttled worker has no retries left.');
        } catch (AcousticFingerprintLookupException $exception) {
            $this->assertTrue($exception->retryable);
        }
        $this->matcher()->lookup($this->lookupQuery(fingerprint: 'worker-b', hash: hash('sha256', 'worker-b')));

        $this->assertSame('worker-b', $sentAt[1][0]);
        $this->assertGreaterThanOrEqual(20_000, $sentAt[1][1] - $sentAt[0][1], 'the second worker waited out the shared hold-off');
    }

    #[Test]
    public function a_retry_after_beyond_the_maximum_wait_defers_without_waiting(): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($this->fixture('lookup-too-many-requests.json'), 429, ['Retry-After' => '3600'])]);

        try {
            $this->matcher()->lookup($this->lookupQuery());
            $this->fail('A long Retry-After must defer the lookup.');
        } catch (AcousticFingerprintLookupException $exception) {
            $this->assertTrue($exception->retryable);
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_connection_failure_is_retried_then_retryable(): void
    {
        Http::fake(static fn () => throw new ConnectionException('connection refused'));

        try {
            $this->matcher()->lookup($this->lookupQuery());
            $this->fail('A connection failure must throw.');
        } catch (AcousticFingerprintLookupException $exception) {
            $this->assertTrue($exception->retryable);
        }
    }

    #[Test]
    public function a_2xx_reply_without_an_ok_status_or_error_code_is_retried(): void
    {
        Http::fake([self::LOOKUP_URL => Http::sequence()
            ->push(['status' => 'pending'], 200)
            ->push($this->fixture('lookup-no-match.json')),
        ]);

        $this->assertSame([], $this->matcher()->lookup($this->lookupQuery()));
        Http::assertSentCount(2);
    }

    #[Test]
    public function an_unreadable_response_is_retryable_and_never_cached(): void
    {
        Http::fake([self::LOOKUP_URL => Http::sequence()
            ->push('not json', 200)
            ->push('not json', 200)
            ->push('not json', 200)
            ->push(['status' => 'ok', 'results' => [['id' => 'x', 'score' => 0.9, 'recordings' => [['id' => 'not-an-mbid']]]]])
            ->push($this->fixture('lookup-no-match.json')),
        ]);

        foreach ([1, 2] as $attempt) {
            try {
                $this->matcher()->lookup($this->lookupQuery());
                $this->fail('An unreadable response must throw on attempt '.$attempt.'.');
            } catch (AcousticFingerprintLookupException $exception) {
                $this->assertTrue($exception->retryable);
            }
        }
        $this->assertSame([], $this->matcher()->lookup($this->lookupQuery()));
        Http::assertSentCount(5);
    }

    #[Test]
    public function without_a_client_key_the_matcher_is_dormant(): void
    {
        config(['music-identity.acoustid.client_key' => '']);
        Http::fake();

        $this->assertFalse($this->matcher()->available());
        $this->expectException(AcousticFingerprintLookupException::class);

        try {
            $this->matcher()->lookup($this->lookupQuery());
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function two_workers_and_a_retry_stay_within_the_shared_three_requests_per_second(): void
    {
        $sentAt = [];
        $throttledOnce = false;
        Http::fake(function (Request $request) use (&$sentAt, &$throttledOnce) {
            $sentAt[] = now()->getPreciseTimestamp(3);
            if ($request['fingerprint'] === 'worker-b-2' && ! $throttledOnce) {
                $throttledOnce = true;

                return Http::response($this->fixture('lookup-too-many-requests.json'), 503);
            }

            return Http::response($this->fixture('lookup-no-match.json'));
        });
        config(['music-identity.acoustid.retry.backoff_milliseconds' => 0]);
        $firstWorker = new HttpAcoustIdFingerprintMatcher(new AcoustIdRequestPacer);
        $secondWorker = new HttpAcoustIdFingerprintMatcher(new AcoustIdRequestPacer);

        foreach ([1, 2, 3] as $index) {
            $firstWorker->lookup($this->lookupQuery(fingerprint: 'worker-a-'.$index, hash: hash('sha256', 'worker-a-'.$index)));
            $secondWorker->lookup($this->lookupQuery(fingerprint: 'worker-b-'.$index, hash: hash('sha256', 'worker-b-'.$index)));
        }
        $dispatches = count($sentAt);
        $firstWorker->lookup($this->lookupQuery(fingerprint: 'worker-a-1', hash: hash('sha256', 'worker-a-1')));
        $secondWorker->lookup($this->lookupQuery(fingerprint: 'worker-a-1', hash: hash('sha256', 'worker-a-1')));

        $this->assertSame(7, $dispatches, 'six lookups and one retry');
        $this->assertCount($dispatches, $sentAt, 'a cached lookup sends nothing, in either worker');
        for ($index = 3; $index < count($sentAt); $index++) {
            $this->assertGreaterThanOrEqual(
                1_000,
                $sentAt[$index] - $sentAt[$index - 3],
                'no one-second window holds more than three dispatches across both workers',
            );
        }
    }

    private function matcher(): HttpAcoustIdFingerprintMatcher
    {
        return new HttpAcoustIdFingerprintMatcher(new AcoustIdRequestPacer);
    }

    private function lookupQuery(
        string $fingerprint = 'AQADtSyntheticFingerprintOne',
        ?string $hash = null,
        int $algorithm = 2,
        string $generatorVersion = 'synthetic-generator-v1',
        int $durationSeconds = 241,
    ): AcousticFingerprintQuery {
        return new AcousticFingerprintQuery(
            fingerprint: $fingerprint,
            fingerprintHash: $hash ?? str_repeat('a', 64),
            algorithm: $algorithm,
            generatorVersion: $generatorVersion,
            durationSeconds: $durationSeconds,
        );
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/AcoustId/'.$name)), true, flags: JSON_THROW_ON_ERROR);
    }
}
