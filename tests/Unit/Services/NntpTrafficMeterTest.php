<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\NntpTrafficMeter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class NntpTrafficMeterTest extends TestCase
{
    private float $now = 1_000.0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:30:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_bytes_received_before_sixty_seconds_are_not_written(): void
    {
        $this->createTable();
        $meter = $this->meter();

        $meter->received('alpha', 100);
        $this->now += 59.9;
        $meter->received('alpha', 50);

        $this->assertSame(0, DB::table('nntp_provider_traffic')->count());
    }

    public function test_the_first_read_at_sixty_seconds_writes_the_summed_bytes_to_todays_row(): void
    {
        $this->createTable();
        $meter = $this->meter();

        $meter->received('alpha', 100);
        $meter->received('beta', 7);
        $this->now += 60;
        $meter->received('alpha', 50);

        $this->assertSame(['alpha' => 150, 'beta' => 7], $this->bytesOn('2026-10-16'));
    }

    public function test_a_second_flush_on_the_same_day_adds_to_the_row(): void
    {
        $this->createTable();
        $meter = $this->meter();

        $meter->received('alpha', 100);
        $meter->flush();
        $meter->received('alpha', 25);
        $meter->flush();

        $this->assertSame(['alpha' => 125], $this->bytesOn('2026-10-16'));
    }

    public function test_nothing_is_written_inside_a_transaction_and_the_next_eligible_read_after_commit_writes_it(): void
    {
        $this->createTable();
        $meter = $this->meter();

        DB::transaction(function () use ($meter): void {
            $meter->received('alpha', 100);
            $this->now += 60;
            $meter->received('alpha', 20);
            $meter->flush();
        });
        $this->assertSame(0, DB::table('nntp_provider_traffic')->count());

        $this->now += 1;
        $meter->received('alpha', 3);

        $this->assertSame(['alpha' => 123], $this->bytesOn('2026-10-16'));
    }

    public function test_a_failed_write_keeps_the_bytes_pending_logs_once_and_the_next_flush_writes_them(): void
    {
        Log::spy();
        $meter = $this->meter();

        $meter->received('alpha', 100);
        $meter->flush();
        $meter->received('alpha', 10);
        $meter->flush();

        Log::shouldHaveReceived('warning')->once();

        $this->createTable();
        $meter->flush();

        $this->assertSame(['alpha' => 110], $this->bytesOn('2026-10-16'));
    }

    public function test_the_row_is_dated_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        Carbon::setTestNow(Carbon::parse('2026-10-17 02:00:00', 'UTC'));
        $this->createTable();
        $meter = $this->meter();

        $meter->received('alpha', 9);
        $meter->flush();

        $this->assertSame(['alpha' => 9], $this->bytesOn('2026-10-16'));
    }

    private function meter(): NntpTrafficMeter
    {
        return new NntpTrafficMeter(fn (): float => $this->now);
    }

    private function createTable(): void
    {
        ProductionTables::fromAuthority()->create('nntp_provider_traffic');
    }

    /** @return array<string, int> */
    private function bytesOn(string $day): array
    {
        return DB::table('nntp_provider_traffic')->where('day', $day)->orderBy('provider')
            ->pluck('bytes_received', 'provider')->map(fn (mixed $bytes): int => (int) $bytes)->all();
    }
}
