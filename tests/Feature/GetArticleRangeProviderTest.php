<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class GetArticleRangeProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 12:00:00');
        config(['app.timezone' => 'UTC', 'nntmux_nntp.providers' => [
            ['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid'],
            ['position' => 2, 'name' => 'super', 'host' => 'super.example.invalid'],
        ]]);
        NntpProviderPool::forgetConfiguredProviders();

        $tables = ProductionTables::fromAuthority();
        $tables->create('settings', ['name', 'value']);
        $tables->create('usenet_groups', ['id', 'name', 'active', 'backfill', 'first_record', 'first_record_postdate',
            'last_record', 'last_record_postdate', 'last_updated', 'backfill_settled_at']);
        $tables->create('usenet_group_ingested_ranges');
        $tables->create('usenet_group_provider_cursors');
        $tables->create('usenet_group_provider_ingested_ranges');

        DB::table('settings')->insert(['name' => 'safepartrepair', 'value' => '1']);
        DB::table('usenet_groups')->insert([
            'id' => 1, 'name' => 'alt.test', 'active' => 1, 'backfill' => 0, 'first_record' => 100,
            'last_record' => 5_000, 'last_record_postdate' => '2026-10-02 11:00:00',
        ]);
        DB::table('usenet_group_provider_cursors')->insert([
            'usenet_groups_id' => 1, 'provider' => 'super', 'provider_host' => 'super.example.invalid',
            'last_record' => 9_000, 'last_record_postdate' => '2026-10-02 10:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NntpProviderPool::forgetConfiguredProviders();

        parent::tearDown();
    }

    public function test_a_secondary_range_advances_only_that_providers_cursor(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('useProvider')->once()
            ->withArgs(static fn (NntpProvider $provider, bool $poolFailover): bool => $provider->name === 'super' && ! $poolFailover);
        $nntp->shouldReceive('doConnect')->once()->andReturn(true);
        $nntp->shouldReceive('selectGroup')->andReturn(['group' => 'alt.test', 'first' => 1, 'last' => 20_000]);
        $this->app->instance(NNTPService::class, $nntp);

        $binaries = Mockery::mock(BinariesService::class);
        $binaries->shouldReceive('setNntp')->once()->with($nntp);
        $binaries->shouldReceive('scan')->once()
            ->withArgs(static fn (array $group, int $first, int $last, HeaderScanDirection $direction, string $type): bool => $group['id'] === 1 && $first === 9_001 && $last === 9_100 && $direction === HeaderScanDirection::Head && $type === 'backfill')
            ->andReturn(['firstArticleNumber' => 9_001, 'lastArticleNumber' => 9_100, 'lastArticleDate' => '2026-10-02 11:30:00']);
        $binaries->shouldReceive('lastScanWasRejected')->andReturn(false);
        $this->app->instance(BinariesService::class, $binaries);

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => 9_001, 'last' => 9_100, '--provider' => 'super'])
            ->assertSuccessful();

        $cursor = DB::table('usenet_group_provider_cursors')->where('provider', 'super')->first();
        $this->assertSame(9_100, (int) $cursor->last_record);
        $this->assertSame('2026-10-02 11:30:00', $cursor->last_record_postdate);
        $this->assertSame('2026-10-02 12:00:00', $cursor->last_advanced_at);
        $this->assertSame(5_000, (int) DB::table('usenet_groups')->value('last_record'));
        $this->assertSame(0, DB::table('usenet_group_ingested_ranges')->count());
    }

    public function test_backfill_and_unknown_providers_fail_without_scanning(): void
    {
        $this->app->instance(BinariesService::class, Mockery::mock(BinariesService::class)->shouldNotReceive('scan')->getMock());

        $this->artisan('articles:get-range', ['mode' => 'backfill', 'group' => 'alt.test', 'first' => 1, 'last' => 99, '--provider' => 'super'])
            ->expectsOutputToContain('Backfill reads provider 1 only.')
            ->assertFailed();
        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => 9_001, 'last' => 9_100, '--provider' => 'nowhere'])
            ->expectsOutputToContain('Unknown or disabled NNTP provider: nowhere')
            ->assertFailed();

        $this->assertSame(9_000, (int) DB::table('usenet_group_provider_cursors')->value('last_record'));
    }
}
