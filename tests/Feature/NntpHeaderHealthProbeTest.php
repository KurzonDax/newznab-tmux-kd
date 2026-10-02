<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentImpactEnum;
use App\Services\NNTP\NntpProviderPool;
use App\Services\StatusProbes\NntpHeaderHealthProbe;
use App\Services\StatusProbes\ProbeResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class NntpHeaderHealthProbeTest extends TestCase
{
    private const string NOW = '2026-10-02 14:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        config(['app.timezone' => 'UTC', 'cache.default' => 'array', 'nntmux_nntp.providers' => [
            ['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid'],
            ['position' => 2, 'name' => 'super', 'host' => 'super.example.invalid'],
            ['position' => 3, 'name' => 'spare', 'host' => 'spare.example.invalid', 'enabled' => false],
        ]]);
        NntpProviderPool::forgetConfiguredProviders();
        Cache::flush();

        $tables = ProductionTables::fromAuthority();
        $tables->create('settings', ['name', 'value']);
        $tables->create('usenet_groups', ['id', 'name', 'active', 'backfill', 'last_updated']);
        $tables->create('usenet_group_provider_cursors');
        $tables->create('releases', ['id', 'guid', 'adddate', 'postdate']);
        (require database_path('migrations/2026_10_02_000100_create_nntp_listing_coverage.php'))->up();

        $this->healthy();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NntpProviderPool::forgetConfiguredProviders();

        parent::tearDown();
    }

    public function test_a_healthy_system_passes(): void
    {
        $result = $this->probe();

        $this->assertTrue($result->ok, $result->reason);
        $this->assertNull($result->impact);
        $this->assertSame('Header scanning and listings healthy.', $result->reason);
    }

    public function test_a_fails_when_both_of_the_last_two_full_hours_have_fewer_than_ten_fresh_releases(): void
    {
        DB::table('releases')->delete();
        $this->freshReleases(9, '2026-10-02 12:30:00');
        $this->freshReleases(9, '2026-10-02 13:30:00');

        $this->assertFailure(IncidentImpactEnum::Major, 'Fresh releases stalled: 9 and 9 in the last two full hours (alert below 10).');

        $this->freshReleases(1, '2026-10-02 12:45:00');
        $this->assertTrue($this->probe()->ok);
    }

    public function test_a_counts_a_release_formed_before_delaytime_less_two_hours_as_fresh(): void
    {
        DB::table('releases')->delete();
        $this->freshReleases(10, '2026-10-02 13:30:00', minutesAfterPosting: 599);
        $this->assertTrue($this->probe()->ok);

        DB::table('releases')->delete();
        $this->freshReleases(10, '2026-10-02 13:30:00', minutesAfterPosting: 720);
        $this->assertFailure(IncidentImpactEnum::Major, 'Fresh releases stalled: 0 and 0 in the last two full hours (alert below 10).');
    }

    /** @param array<string, string> $settings */
    #[DataProvider('closedGates')]
    public function test_a_is_skipped_while_the_gates_are_closed_or_recently_opened(array $settings, int $openedMinutesAgo): void
    {
        DB::table('releases')->delete();
        foreach ($settings as $name => $value) {
            DB::table('settings')->where('name', $name)->update(['value' => $value]);
        }
        Cache::forever(NntpHeaderHealthProbe::GATES_OPEN_SINCE, now()->subMinutes($openedMinutesAgo)->timestamp);

        $result = $this->probe();

        $this->assertTrue($result->ok, $result->reason);
    }

    /** @return array<string, array{array<string, string>, int}> */
    public static function closedGates(): array
    {
        return [
            'tmux stopped' => [['running' => '0'], 600],
            'header scanning off' => [['binaries' => '0'], 600],
            'release formation off' => [['releases' => '0'], 600],
            // 14:30 now; gates opened at 12:31, after 12:00 (two full hours back).
            'opened under two full hours ago' => [[], 119],
        ];
    }

    public function test_b_fails_when_provider_one_has_not_advanced_an_ordinary_group_for_an_hour(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['last_updated' => '2026-10-02 13:29:00']);
        // A backfilling group's fresh write does not count.
        DB::table('usenet_groups')->insert(['id' => 6, 'name' => 'alt.backfilling', 'active' => 1, 'backfill' => 1, 'last_updated' => '2026-10-02 14:29:00']);

        $this->assertFailure(IncidentImpactEnum::Major, 'primary header scanning has not advanced any group for 61 minutes.');

        DB::table('usenet_groups')->where('id', 1)->update(['last_updated' => '2026-10-02 13:31:00']);
        $this->assertTrue($this->probe()->ok);
    }

    public function test_b_fails_when_an_enabled_secondary_provider_has_not_advanced_for_an_hour(): void
    {
        DB::table('usenet_group_provider_cursors')->where('provider', 'super')->update(['last_advanced_at' => '2026-10-02 13:29:00']);

        $this->assertFailure(IncidentImpactEnum::Major, 'super header scanning has not advanced any group for 61 minutes.');

        DB::table('usenet_group_provider_cursors')->where('provider', 'super')->update(['last_advanced_at' => '2026-10-02 13:31:00']);
        $this->assertTrue($this->probe()->ok);
    }

    public function test_b_ignores_providers_without_cursors_and_cursors_of_disabled_providers(): void
    {
        DB::table('usenet_group_provider_cursors')->where('provider', 'super')->delete();
        DB::table('usenet_group_provider_cursors')->insert(['usenet_groups_id' => 1, 'provider' => 'spare', 'provider_host' => 'spare.example.invalid',
            'last_record' => 1, 'last_advanced_at' => '2026-09-01 00:00:00']);

        $this->assertTrue($this->probe()->ok);
    }

    public function test_c_fails_when_the_newest_runs_median_for_a_pair_is_below_eighty_percent(): void
    {
        DB::table('nntp_listing_coverage')->delete();
        $this->canaryRun30MinutesAgo([['primary', 'super', 1000, 700], ['primary', 'super', 2000, 1500], ['primary', 'super', 900, 900],
            ['super', 'primary', 30000, 29000], ['super', 'primary', 30000, 29000]]);

        $this->assertFailure(IncidentImpactEnum::Major, 'super lists only 72.5% of what primary lists (median of 2 groups).');
    }

    /** @param list<array{string, string, int, int}> $rows */
    #[DataProvider('passingRuns')]
    public function test_c_passes_on_too_few_qualifying_groups_a_high_median_or_an_old_run(array $rows, int $minutesAgo): void
    {
        DB::table('nntp_listing_coverage')->delete();
        $this->canaryRun($rows, $minutesAgo);
        // A conclusive earlier run keeps check d quiet.
        $this->canaryRun([['primary', 'super', 5000, 5000], ['primary', 'super', 5000, 5000],
            ['super', 'primary', 5000, 5000], ['super', 'primary', 5000, 5000]], 170);

        $result = $this->probe();

        $this->assertTrue($result->ok, $result->reason);
    }

    /** @return array<string, array{list<array{string, string, int, int}>, int}> */
    public static function passingRuns(): array
    {
        return [
            'one qualifying group' => [[['primary', 'super', 1000, 100], ['primary', 'super', 999, 0]], 30],
            'median at eighty percent' => [[['primary', 'super', 1000, 700], ['primary', 'super', 1000, 900]], 30],
            'newest run over two hours old' => [[['primary', 'super', 1000, 100], ['primary', 'super', 1000, 100]], 121],
        ];
    }

    public function test_d_fails_minor_when_no_run_in_three_hours_is_conclusive(): void
    {
        DB::table('nntp_listing_coverage')->delete();
        $this->canaryRun([['primary', 'super', 5000, 5000], ['primary', 'super', 5000, 5000],
            ['super', 'primary', 5000, 5000], ['super', 'primary', 5000, 5000]], 200);
        // Recent, but provider 2 was sampled in only one group.
        $this->canaryRun([['primary', 'super', 5000, 5000], ['primary', 'super', 5000, 5000], ['super', 'primary', 5000, 5000]], 20);

        $this->assertFailure(IncidentImpactEnum::Minor, 'Listing canary has had no conclusive run since 2026-10-02 11:10:00.');

        DB::table('nntp_listing_coverage')->delete();
        $this->assertFailure(IncidentImpactEnum::Minor, 'Listing canary has had no conclusive run since it started.');

        Cache::forever(NntpHeaderHealthProbe::CANARY_WATCH_SINCE, now()->subMinutes(179)->timestamp);
        $this->assertTrue($this->probe()->ok);
    }

    public function test_the_first_failing_check_in_order_is_reported(): void
    {
        DB::table('releases')->delete();
        DB::table('usenet_groups')->where('id', 1)->update(['last_updated' => '2026-10-02 10:00:00']);
        DB::table('nntp_listing_coverage')->delete();

        $this->assertFailure(IncidentImpactEnum::Major, 'Fresh releases stalled: 0 and 0 in the last two full hours (alert below 10).');
    }

    public function test_a_failing_data_query_is_a_major_failure(): void
    {
        Schema::drop('releases');

        $result = $this->probe();

        $this->assertFalse($result->ok);
        $this->assertSame(IncidentImpactEnum::Major, $result->impact);
        $this->assertStringStartsWith('NNTP headers probe failed: ', $result->reason);
    }

    public function test_the_health_check_opens_and_resolves_an_auto_incident(): void
    {
        $tables = ProductionTables::fromAuthority();
        foreach (['service_statuses', 'service_incidents', 'service_incident_service_status'] as $table) {
            $tables->create($table);
        }
        DB::table('service_statuses')->insert(['name' => 'NNTP headers', 'slug' => 'nntp-headers', 'check_type' => 'probe',
            'probe_identifier' => 'nntp-headers', 'status' => 'operational', 'is_enabled' => 1, 'sort_order' => 1, 'uptime_percentage' => 100]);
        DB::table('releases')->delete();

        $this->artisan('nntmux:check-service-health')->assertSuccessful();

        $incident = DB::table('service_incidents')->first();
        $this->assertNotNull($incident);
        $this->assertStringContainsString('Fresh releases stalled', (string) $incident->title);
        $this->assertNull($incident->resolved_at);

        $this->freshReleases(10, '2026-10-02 13:30:00');
        Cache::flush();
        Cache::forever(NntpHeaderHealthProbe::GATES_OPEN_SINCE, now()->subHours(5)->timestamp);
        Cache::forever(NntpHeaderHealthProbe::CANARY_WATCH_SINCE, now()->subHours(5)->timestamp);
        $this->artisan('nntmux:check-service-health')->assertSuccessful();

        $this->assertNotNull(DB::table('service_incidents')->where('id', $incident->id)->value('resolved_at'));
    }

    /** Gates and watch open for hours, both providers advancing, fresh releases flowing, a good canary run. */
    private function healthy(): void
    {
        foreach (['running' => '1', 'binaries' => '1', 'releases' => '1', 'delaytime' => '12'] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
        Cache::forever(NntpHeaderHealthProbe::GATES_OPEN_SINCE, now()->subHours(5)->timestamp);
        Cache::forever(NntpHeaderHealthProbe::CANARY_WATCH_SINCE, now()->subHours(5)->timestamp);
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.busy', 'active' => 1, 'backfill' => 0, 'last_updated' => '2026-10-02 14:29:59'],
            ['id' => 2, 'name' => 'alt.idle', 'active' => 0, 'backfill' => 0, 'last_updated' => '2026-10-02 14:29:59'],
            ['id' => 3, 'name' => 'alt.idle3', 'active' => 0, 'backfill' => 0, 'last_updated' => null],
            ['id' => 4, 'name' => 'alt.idle4', 'active' => 0, 'backfill' => 0, 'last_updated' => null],
            ['id' => 5, 'name' => 'alt.idle5', 'active' => 0, 'backfill' => 0, 'last_updated' => null],
        ]);
        DB::table('usenet_group_provider_cursors')->insert(['usenet_groups_id' => 1, 'provider' => 'super', 'provider_host' => 'super.example.invalid',
            'last_record' => 1, 'last_advanced_at' => '2026-10-02 14:29:00']);
        $this->freshReleases(10, '2026-10-02 12:30:00');
        $this->freshReleases(10, '2026-10-02 13:30:00');
        $this->canaryRun([['primary', 'super', 30000, 26000], ['primary', 'super', 30000, 26000],
            ['super', 'primary', 30000, 29000], ['super', 'primary', 30000, 29000]], 23);
    }

    private function freshReleases(int $count, string $addedAt, int $minutesAfterPosting = 60): void
    {
        $added = Carbon::parse($addedAt);
        for ($i = 0; $i < $count; $i++) {
            DB::table('releases')->insert(['guid' => uniqid('release-', true), 'adddate' => $added->format('Y-m-d H:i:s'),
                'postdate' => $added->copy()->subMinutes($minutesAfterPosting)->format('Y-m-d H:i:s')]);
        }
    }

    /** @param list<array{string, string, int, int}> $rows sample, reference, sampled, found */
    private function canaryRun30MinutesAgo(array $rows): void
    {
        $this->canaryRun($rows, 30);
    }

    /** @param list<array{string, string, int, int}> $rows sample, reference, sampled, found */
    private function canaryRun(array $rows, int $minutesAgo): void
    {
        $measuredAt = now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s');
        foreach ($rows as $group => [$sample, $reference, $sampled, $found]) {
            DB::table('nntp_listing_coverage')->insert(['measured_at' => $measuredAt, 'groups_id' => $group + 1,
                'sample_provider' => $sample, 'reference_provider' => $reference, 'sample_time' => $measuredAt,
                'sampled' => $sampled, 'found' => $found]);
        }
    }

    private function probe(): ProbeResult
    {
        return app(NntpHeaderHealthProbe::class)->probe();
    }

    private function assertFailure(IncidentImpactEnum $impact, string $reason): void
    {
        $result = $this->probe();

        $this->assertFalse($result->ok);
        $this->assertSame($impact, $result->impact);
        $this->assertSame($reason, $result->reason);
    }
}
