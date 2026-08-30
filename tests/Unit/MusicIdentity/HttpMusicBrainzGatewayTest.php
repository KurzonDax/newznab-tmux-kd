<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\Exceptions\InvalidMusicBrainzResponse;
use App\Services\MusicIdentity\Exceptions\MusicBrainzCircuitOpen;
use App\Services\MusicIdentity\Exceptions\MusicBrainzConfigurationException;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\Gateways\HttpMusicBrainzGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpMusicBrainzGatewayTest extends TestCase
{
    private const string RECORDING_ID = '6e615e70-7f67-4f38-9f95-9dfbd579a2b3';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        $this->configureGateway();
    }

    public function test_it_is_dormant_until_an_endpoint_is_configured(): void
    {
        config(['music-identity.musicbrainz.endpoint_url' => null]);
        Http::fake();

        $candidates = $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));
        $metadata = $this->gateway()->hydrate(new CandidateIdentifiers(recordingId: self::RECORDING_ID));

        $this->assertSame([], $candidates->recordings);
        $this->assertSame([], $metadata->recordings);
        $this->assertSame([], $metadata->releases);
        Http::assertNothingSent();
    }

    public function test_exact_recording_lookup_uses_explicit_includes_and_normalizes_the_provider_shape(): void
    {
        Http::fake(['*' => Http::response($this->fixture('recording-lookup.json'))]);

        $result = $this->gateway()->candidatesFor(new RecordingQuery(recordingId: self::RECORDING_ID));

        $this->assertCount(1, $result->recordings);
        $this->assertSame(self::RECORDING_ID, $result->recordings[0]['recordingId']);
        $this->assertSame('No Surprises', $result->recordings[0]['title']);
        $this->assertSame('Radiohead', $result->recordings[0]['artistCredit']);
        $this->assertSame(['GBAYE9701372'], $result->recordings[0]['isrcs']);
        $this->assertSame(['recording_lookup'], $result->recordings[0]['sources']);

        Http::assertSent(function (Request $request): bool {
            $query = $this->queryParameters($request);

            return str_contains($request->url(), '/recording/'.self::RECORDING_ID)
                && $query['fmt'] === 'json'
                && $query['inc'] === 'artist-credits+isrcs+releases+release-groups';
        });
    }

    public function test_lucene_values_are_escaped_before_the_query_is_url_encoded(): void
    {
        Http::fake(['*' => Http::response($this->fixture('recording-search.json'))]);

        $result = $this->gateway()->candidatesFor(new RecordingQuery(
            title: 'AC/DC + Live (Deluxe)',
            artist: 'Guns N\' Roses',
            durationMs: 150_000,
        ));

        $this->assertCount(1, $result->recordings);
        Http::assertSent(function (Request $request): bool {
            $query = $this->queryParameters($request);

            return $query['query'] === 'recording:"AC\\/DC \\+ Live \\(Deluxe\\)" AND artist:"Guns N\' Roses" AND qdur:[72 TO 77]'
                && $query['limit'] === '25'
                && $query['offset'] === '0';
        });
    }

    public function test_release_browse_pagination_advances_by_the_actual_number_returned(): void
    {
        Http::fake(function (Request $request) {
            $query = $this->queryParameters($request);

            if (str_contains($request->url(), '/recording/'.self::RECORDING_ID)) {
                return Http::response($this->fixture('recording-lookup.json'));
            }

            if (($query['offset'] ?? null) === '0') {
                return Http::response($this->fixture('release-browse-page-1.json'));
            }

            if (($query['offset'] ?? null) === '2') {
                return Http::response($this->fixture('release-browse-page-2.json'));
            }

            return Http::response(['error' => 'unexpected offset'], 500);
        });

        $metadata = $this->gateway()->hydrate(new CandidateIdentifiers(recordingId: self::RECORDING_ID));

        $this->assertSame(
            ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'],
            array_column($metadata->releases, 'releaseId'),
        );

        $offsets = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/release?')) {
                $offsets[] = $this->queryParameters($request)['offset'];
            }
        }
        $this->assertSame(['0', '2'], $offsets);
    }

    public function test_the_observed_isrc_shape_without_embedded_releases_is_supported(): void
    {
        Http::fake(['*' => Http::response($this->fixture('isrc-without-releases.json'))]);

        $result = $this->gateway()->candidatesFor(new RecordingQuery(isrc: 'GBAYE9701372'));

        $this->assertCount(1, $result->recordings);
        $this->assertSame(self::RECORDING_ID, $result->recordings[0]['recordingId']);
        $this->assertSame([], $result->recordings[0]['releaseIds']);
        $this->assertSame(['isrc_lookup'], $result->recordings[0]['sources']);
    }

    public function test_disc_id_results_are_flattened_to_recording_candidates(): void
    {
        Http::fake(['*' => Http::response($this->fixture('discid-lookup.json'))]);

        $result = $this->gateway()->candidatesFor(new RecordingQuery(discId: 'I5l9cCSFccLKFEKS.7wqSZAorPU-'));

        $this->assertCount(2, $result->recordings);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $result->recordings[0]['releaseIds'][0]);
        $this->assertSame(['disc_id_lookup'], $result->recordings[0]['sources']);
    }

    public function test_invalid_json_is_rejected_instead_of_leaking_an_untrusted_shape(): void
    {
        Http::fake(['*' => Http::response('{broken', 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(InvalidMusicBrainzResponse::class);

        $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));
    }

    public function test_malformed_valid_json_is_rejected_before_it_can_enter_cache(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['recording-count' => 1, 'recording-offset' => 0, 'recordings' => 'not-a-list'])
            ->push($this->fixture('recording-search.json'))]);

        try {
            $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));
            $this->fail('Malformed provider JSON should be rejected.');
        } catch (InvalidMusicBrainzResponse) {
            // A corrected provider response must be requested again, not read from cache.
        }

        $result = $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));

        $this->assertCount(1, $result->recordings);
        Http::assertSentCount(2);
    }

    public function test_hydration_batches_exact_lookups_and_returns_only_normalized_metadata(): void
    {
        Http::fake(function (Request $request) {
            $query = $this->queryParameters($request);
            if (str_contains($request->url(), '/release-group/')) {
                return Http::response($this->fixture('release-group-lookup.json'));
            }
            if (str_contains($request->url(), '/release/'.'11111111-1111-4111-8111-111111111111')) {
                return Http::response($this->fixture('release-lookup.json'));
            }
            if (str_contains($request->url(), '/recording/'.self::RECORDING_ID)) {
                return Http::response($this->fixture('recording-lookup.json'));
            }
            if (isset($query['recording']) || isset($query['release-group'])) {
                return Http::response([
                    'release-count' => 1,
                    'release-offset' => 0,
                    'releases' => [$this->fixture('release-lookup.json')],
                ]);
            }

            return Http::response(['error' => 'unexpected request'], 500);
        });

        $metadata = $this->gateway()->hydrate(new CandidateIdentifiers(
            recordingId: self::RECORDING_ID,
            releaseId: '11111111-1111-4111-8111-111111111111',
            releaseGroupId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ));

        $this->assertCount(1, $metadata->recordings);
        $this->assertCount(1, $metadata->releases);
        $this->assertCount(1, $metadata->releaseGroups);
        $this->assertSame('XL Recordings', $metadata->releases[0]['labels'][0]['labelName']);
        $this->assertSame('No Surprises', $metadata->releases[0]['media'][0]['tracks'][0]['recording']['title']);
        $this->assertArrayNotHasKey('artist-credit', $metadata->releases[0]);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/release/11111111-1111-4111-8111-111111111111')) {
                return false;
            }

            return $this->queryParameters($request)['inc']
                === 'recordings+artist-credits+labels+release-groups+media+discids+isrcs+genres+tags+url-rels';
        });
    }

    public function test_transient_failures_are_retried_with_the_configured_policy(): void
    {
        config([
            'music-identity.musicbrainz.retry.attempts' => 2,
            'music-identity.musicbrainz.retry.backoff_milliseconds' => 0,
        ]);
        Http::fake(['*' => Http::sequence()
            ->pushStatus(503)
            ->push($this->fixture('recording-search.json'))]);

        $result = $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));

        $this->assertCount(1, $result->recordings);
        Http::assertSentCount(2);
    }

    public function test_each_retry_consumes_the_request_budget(): void
    {
        config([
            'music-identity.musicbrainz.request_budget' => 1,
            'music-identity.musicbrainz.retry.attempts' => 2,
            'music-identity.musicbrainz.retry.backoff_milliseconds' => 0,
        ]);
        Http::fake(['*' => Http::sequence()
            ->pushStatus(503)
            ->push($this->fixture('recording-search.json'))]);

        try {
            $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));
            $this->fail('The retry should exhaust the physical-request budget.');
        } catch (MusicBrainzGatewayException $exception) {
            $this->assertSame('MusicBrainz request budget exhausted.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_public_retries_are_paced_at_one_request_per_second(): void
    {
        config([
            'music-identity.musicbrainz.endpoint_url' => 'https://musicbrainz.org/ws/2/',
            'music-identity.musicbrainz.user_agent_contact' => 'ops@example.test',
            'music-identity.musicbrainz.retry.attempts' => 2,
            'music-identity.musicbrainz.retry.backoff_milliseconds' => 0,
        ]);
        Http::fake(['*' => Http::sequence()
            ->pushStatus(503)
            ->push($this->fixture('recording-search.json'))]);

        $startedAt = microtime(true);
        $result = $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));

        $this->assertCount(1, $result->recordings);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $startedAt);
        Http::assertSentCount(2);
    }

    public function test_repeated_provider_failures_open_the_circuit(): void
    {
        config([
            'music-identity.musicbrainz.retry.attempts' => 1,
            'music-identity.musicbrainz.circuit_breaker.failure_threshold' => 2,
            'music-identity.musicbrainz.circuit_breaker.open_seconds' => 60,
        ]);
        Http::fake(['*' => Http::response([], 503)]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->gateway()->candidatesFor(new RecordingQuery(title: 'Failure '.$attempt));
                $this->fail('The failed provider request should throw.');
            } catch (MusicBrainzGatewayException) {
                // The first two failed operations build the shared provider circuit state.
            }
        }

        $this->expectException(MusicBrainzCircuitOpen::class);
        $this->gateway()->candidatesFor(new RecordingQuery(title: 'Blocked'));
    }

    public function test_permanent_client_errors_do_not_open_the_provider_circuit(): void
    {
        config([
            'music-identity.musicbrainz.retry.attempts' => 1,
            'music-identity.musicbrainz.circuit_breaker.failure_threshold' => 2,
        ]);
        Http::fake(['*' => Http::sequence()
            ->pushStatus(400)
            ->pushStatus(400)
            ->push($this->fixture('recording-search.json'))]);

        foreach (['Bad one', 'Bad two'] as $title) {
            try {
                $this->gateway()->candidatesFor(new RecordingQuery(title: $title));
                $this->fail('The permanent client response should throw.');
            } catch (MusicBrainzGatewayException $exception) {
                $this->assertSame('MusicBrainz returned HTTP 400.', $exception->getMessage());
            }
        }

        $result = $this->gateway()->candidatesFor(new RecordingQuery(title: 'Valid request'));

        $this->assertCount(1, $result->recordings);
        Http::assertSentCount(3);
    }

    public function test_invalid_identifiers_are_rejected_before_dispatch(): void
    {
        Http::fake();

        try {
            $this->gateway()->hydrate(new CandidateIdentifiers(recordingId: 'not-an-mbid'));
            $this->fail('Invalid identifiers must be rejected locally.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('recordingId must be a valid MusicBrainz identifier.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_exact_lookups_outlive_search_cache_entries(): void
    {
        config([
            'music-identity.musicbrainz.cache.exact_ttl_seconds' => 3600,
            'music-identity.musicbrainz.cache.search_ttl_seconds' => 1,
        ]);
        Http::fake(function (Request $request) {
            return Http::response(str_contains($request->url(), '/recording/'.self::RECORDING_ID)
                ? $this->fixture('recording-lookup.json')
                : $this->fixture('recording-search.json'));
        });

        $this->gateway()->candidatesFor(new RecordingQuery(recordingId: self::RECORDING_ID));
        $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));
        $this->travel(2)->seconds();
        $this->gateway()->candidatesFor(new RecordingQuery(recordingId: self::RECORDING_ID));
        $this->gateway()->candidatesFor(new RecordingQuery(title: 'No Surprises'));

        Http::assertSentCount(3);
    }

    public function test_public_endpoint_mode_identifies_the_client_and_requires_contact_information(): void
    {
        config([
            'music-identity.musicbrainz.endpoint_url' => 'https://musicbrainz.org/ws/2/',
            'music-identity.musicbrainz.user_agent_contact' => 'ops@example.test',
        ]);
        Http::fake(['*' => Http::response($this->fixture('recording-lookup.json'))]);

        $this->gateway()->candidatesFor(new RecordingQuery(recordingId: self::RECORDING_ID));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'User-Agent',
            'NNTmux/1.0 (ops@example.test)',
        ));

        Cache::flush();
        config(['music-identity.musicbrainz.user_agent_contact' => '']);
        Http::fake();

        $this->expectException(MusicBrainzConfigurationException::class);
        $this->gateway()->candidatesFor(new RecordingQuery(recordingId: self::RECORDING_ID));
    }

    private function gateway(): HttpMusicBrainzGateway
    {
        return app(HttpMusicBrainzGateway::class);
    }

    private function configureGateway(): void
    {
        config([
            'music-identity.musicbrainz.endpoint_url' => 'https://musicbrainz.test/ws/2/',
            'music-identity.musicbrainz.user_agent_contact' => '',
            'music-identity.musicbrainz.provider_version' => 'ws2-test-v1',
            'music-identity.musicbrainz.request_budget' => 12,
            'music-identity.musicbrainz.max_concurrency' => 3,
            'music-identity.musicbrainz.timeout_seconds' => 5,
            'music-identity.musicbrainz.connect_timeout_seconds' => 2,
            'music-identity.musicbrainz.retry.attempts' => 1,
            'music-identity.musicbrainz.retry.backoff_milliseconds' => 0,
            'music-identity.musicbrainz.circuit_breaker.failure_threshold' => 3,
            'music-identity.musicbrainz.circuit_breaker.open_seconds' => 60,
            'music-identity.musicbrainz.cache.exact_ttl_seconds' => 3600,
            'music-identity.musicbrainz.cache.search_ttl_seconds' => 60,
            'music-identity.musicbrainz.search_limit' => 25,
            'music-identity.musicbrainz.browse_limit' => 100,
            'music-identity.musicbrainz.public_min_interval_milliseconds' => 1000,
        ]);
    }

    /** @return array<string, string> */
    private function queryParameters(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return array_map(static fn (mixed $value): string => (string) $value, $query);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/MusicBrainz/'.$name));
        $this->assertIsString($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
