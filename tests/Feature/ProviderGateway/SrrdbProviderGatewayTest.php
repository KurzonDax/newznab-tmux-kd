<?php

declare(strict_types=1);

namespace Tests\Feature\ProviderGateway;

use App\Services\NameFixing\Srrdb\SrrdbLookupResult;
use App\Services\NameFixing\Srrdb\SrrdbLookupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** SRRDB's spacing and breaker, now kept by the provider gateway. */
final class SrrdbProviderGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProductionTables::fromAuthority()->create('srrdb_lookups', ['crc32', 'status', 'payload', 'checked_at', 'created_at', 'updated_at']);
        Carbon::setTestNow('2026-10-23 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests();
        config([
            'nntmux_srrdb.base_url' => 'https://api.srrdb.test/v1',
            'nntmux_srrdb.requests_per_second' => 1,
            'nntmux_srrdb.retry_attempts' => 1,
            'nntmux_srrdb.backoff_milliseconds' => 0,
            'nntmux_srrdb.circuit_breaker_failures' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function searches_are_a_second_apart(): void
    {
        $sentAt = [];
        Http::fake(function () use (&$sentAt) {
            $sentAt[] = now()->getPreciseTimestamp(3);

            return Http::response(['results' => [], 'resultsCount' => 0]);
        });
        $service = new SrrdbLookupService;

        $service->lookup('0053CA13', 900_000, 1_000_000, true);
        $service->lookup('AABBCCDD', 900_000, 1_000_000, true);

        $this->assertCount(2, $sentAt);
        $this->assertGreaterThanOrEqual(1_000, $sentAt[1] - $sentAt[0]);
    }

    #[Test]
    public function failed_attempts_stop_this_instance_only(): void
    {
        Http::fake(['api.srrdb.test/*' => Http::response('down', 503)]);
        $service = new SrrdbLookupService;

        foreach (['0053CA13', 'AABBCCDD', 'DEADBEEF'] as $crc) {
            $this->assertSame(SrrdbLookupResult::STATUS_UNAVAILABLE, $service->lookup($crc, 900_000, 1_000_000, true)->status);
        }
        Http::assertSentCount(2);

        (new SrrdbLookupService)->lookup('DEADBEEF', 900_000, 1_000_000, true);
        Http::assertSentCount(3);
    }
}
