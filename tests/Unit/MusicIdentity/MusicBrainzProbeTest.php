<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Enums\IncidentImpactEnum;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\Gateways\HttpMusicBrainzGateway;
use App\Services\StatusProbes\MusicBrainzProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MusicBrainzProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        config([
            'music-identity.musicbrainz.endpoint_url' => 'https://mirror.test/ws/2/',
            'music-identity.musicbrainz.timeout_seconds' => 2,
            'music-identity.musicbrainz.connect_timeout_seconds' => 1,
            'music-identity.musicbrainz.replication_status_url' => 'https://mirror.test/',
            'music-identity.musicbrainz.replication_warning_hours' => 48,
        ]);
    }

    public function test_an_unconfigured_provider_is_reported_as_dormant_without_network_io(): void
    {
        config(['music-identity.musicbrainz.endpoint_url' => null]);
        Http::fake();

        $result = app(MusicBrainzProbe::class)->probe();

        $this->assertTrue($result->ok);
        $this->assertSame('MusicBrainz is dormant (no endpoint configured)', $result->reason);
        $this->assertSame(['configured' => false], $result->metadata);
        Http::assertNothingSent();
    }

    public function test_it_records_api_latency_and_replication_age_when_the_mirror_exposes_it(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://mirror.test/ws/2/')) {
                return Http::response(['recording-count' => 0, 'recording-offset' => 0, 'recordings' => []]);
            }

            return Http::response('<p>Last replication packet received at 2026-08-29T12:00:00Z</p>');
        });
        $this->travelTo('2026-08-30 12:00:00 UTC');

        $result = app(MusicBrainzProbe::class)->probe();

        $this->assertTrue($result->ok);
        $this->assertNull($result->impact);
        $this->assertSame(86_400, $result->metadata['replicationAgeSeconds']);
        $this->assertSame('2026-08-29T12:00:00+00:00', $result->metadata['lastReplicationAt']);
        Http::assertSentCount(2);
    }

    public function test_an_unavailable_endpoint_is_a_major_dependency_degradation(): void
    {
        config(['music-identity.musicbrainz.replication_status_url' => null]);
        Http::fake(['*' => Http::response([], 503)]);

        $result = app(MusicBrainzProbe::class)->probe();

        $this->assertFalse($result->ok);
        $this->assertSame(IncidentImpactEnum::Major, $result->impact);
        $this->assertStringContainsString('HTTP 503', $result->reason);
    }

    public function test_public_probe_requests_share_pacing_with_gateway_traffic(): void
    {
        config([
            'music-identity.musicbrainz.endpoint_url' => 'https://musicbrainz.org/ws/2/',
            'music-identity.musicbrainz.user_agent_contact' => 'ops@example.test',
            'music-identity.musicbrainz.replication_status_url' => null,
            'music-identity.musicbrainz.retry.attempts' => 1,
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/recording/')) {
                return Http::response($this->fixture('recording-lookup.json'));
            }

            return Http::response(['recording-count' => 0, 'recording-offset' => 0, 'recordings' => []]);
        });

        app(HttpMusicBrainzGateway::class)->candidatesFor(new RecordingQuery(
            recordingId: '6e615e70-7f67-4f38-9f95-9dfbd579a2b3',
        ));
        $startedAt = microtime(true);
        $result = app(MusicBrainzProbe::class)->probe();

        $this->assertTrue($result->ok);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $startedAt);
        Http::assertSentCount(2);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/MusicBrainz/'.$name));
        $this->assertIsString($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
