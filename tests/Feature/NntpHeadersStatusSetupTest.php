<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\NntpHeaderHealthProbe;
use App\Services\StatusProbes\ProbeResult;
use App\Services\StatusProbes\ServiceProbeRegistry;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class NntpHeadersStatusSetupTest extends TestCase
{
    public function test_the_migration_adds_the_coverage_table_and_one_status_row_and_removes_both(): void
    {
        $tables = ProductionTables::fromAuthority();
        $tables->create('usenet_groups', ['id', 'name']);
        $tables->create('service_statuses');
        DB::table('service_statuses')->insert(['name' => 'NNTP', 'slug' => 'nntp', 'check_type' => 'probe', 'probe_identifier' => 'nntp',
            'status' => 'operational', 'is_enabled' => 1, 'sort_order' => 4, 'uptime_percentage' => 100]);
        $migration = require database_path('migrations/2026_10_02_000100_create_nntp_listing_coverage.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('nntp_listing_coverage',
            ['id', 'measured_at', 'groups_id', 'sample_provider', 'reference_provider', 'sample_time', 'sampled', 'found']));
        $this->assertSame(1, DB::table('service_statuses')->where('slug', 'nntp-headers')->count());
        $this->assertDatabaseHas('service_statuses', ['slug' => 'nntp-headers', 'name' => 'NNTP headers', 'endpoint_url' => null,
            'check_type' => 'probe', 'probe_identifier' => 'nntp-headers', 'status' => 'operational', 'is_enabled' => 1]);
        $this->assertGreaterThan(4, (int) DB::table('service_statuses')->where('slug', 'nntp-headers')->value('sort_order'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('nntp_listing_coverage'));
        $this->assertSame(0, DB::table('service_statuses')->where('slug', 'nntp-headers')->count());
        $this->assertSame(1, DB::table('service_statuses')->count());
    }

    public function test_the_registry_dispatches_nntp_headers_to_its_probe(): void
    {
        $result = new ProbeResult(ok: false, responseTimeMs: 3, impact: IncidentImpactEnum::Minor, reason: 'from the header probe');
        $probe = Mockery::mock(NntpHeaderHealthProbe::class);
        $probe->shouldReceive('identifier')->andReturn('nntp-headers');
        $probe->shouldReceive('probe')->once()->andReturn($result);
        $this->app->instance(NntpHeaderHealthProbe::class, $probe);

        $this->assertSame($result, app(ServiceProbeRegistry::class)->run('nntp-headers'));
    }

    public function test_the_listing_canary_is_the_last_scheduled_event_and_runs_at_minute_seven(): void
    {
        $event = collect(app(Schedule::class)->events())->last();

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('nntp-listing-canary', $event->description);
        $this->assertSame('7 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        Carbon::setTestNow('2026-10-02 14:07:00');
        $this->assertTrue($event->isDue($this->app));
        Carbon::setTestNow('2026-10-02 14:08:00');
        $this->assertFalse($event->isDue($this->app));
        Carbon::setTestNow();
    }
}
