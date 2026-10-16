<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NntpProviderPool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class AdminSiteStatsProviderDownloadsTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        foreach (['grab_stats', 'release_stats', 'signup_stats', 'role_stats', 'nntp_provider_traffic'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:30:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NntpProviderPool::forgetConfiguredProviders();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_each_configured_provider_shows_today_and_the_last_seven_and_thirty_full_days(): void
    {
        $this->providers([
            ['position' => 1, 'name' => 'alpha', 'host' => 'news.alpha.test', 'port' => 563],
            ['position' => 2, 'name' => 'beta', 'host' => 'news.beta.test', 'port' => 119, 'enabled' => false],
        ]);
        foreach (['2026-10-16' => 1, '2026-10-15' => 2, '2026-10-09' => 4, '2026-10-08' => 8,
            '2026-09-16' => 16, '2026-09-15' => 32] as $day => $gigabytes) {
            DB::table('nntp_provider_traffic')->insert(['provider' => 'alpha', 'day' => $day, 'bytes_received' => $gigabytes * 1_000_000_000]);
        }
        DB::table('nntp_provider_traffic')->insert(['provider' => 'retired', 'day' => '2026-10-16', 'bytes_received' => 5]);

        $response = $this->actingAs($this->admin())->get('/admin/site-stats');

        $response->assertOk()->assertSeeInOrder([
            'Usenet provider downloads',
            'Today', 'Oct 16, 2026',
            'Last 7 full days', 'Oct 9 – Oct 15, 2026',
            'Last 30 full days', 'Sep 16 – Oct 15, 2026',
            'alpha', '(news.alpha.test:563)', 'Counting began Sep 15, 2026',
            '1.00 GB', '1,000,000,000 bytes',
            '6.00 GB', '6,000,000,000 bytes',
            '30.00 GB', '30,000,000,000 bytes',
            'beta', '(news.beta.test:119)', 'No downloads recorded yet',
            '0.00 GB', '0 bytes', '0.00 GB', '0 bytes', '0.00 GB', '0 bytes',
        ])->assertDontSee('retired');
    }

    public function test_the_section_says_so_when_no_provider_is_configured(): void
    {
        $this->providers([]);

        $this->actingAs($this->admin())->get('/admin/site-stats')
            ->assertOk()
            ->assertSee('No Usenet providers are configured, so there is nothing to count.')
            ->assertSee('No statistics available');
    }

    public function test_a_range_across_the_new_year_names_both_years(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-03 09:30:00', config('app.timezone')));
        $this->providers([['position' => 1, 'name' => 'alpha', 'host' => 'news.alpha.test', 'port' => 563]]);

        $this->actingAs($this->admin())->get('/admin/site-stats')
            ->assertOk()
            ->assertSee('Dec 27, 2026 – Jan 2, 2027')
            ->assertSee('Dec 4, 2026 – Jan 2, 2027');
    }

    /** @param list<array<string, mixed>> $providers */
    private function providers(array $providers): void
    {
        config(['nntmux_nntp.providers' => $providers]);
        NntpProviderPool::forgetConfiguredProviders();
    }
}
