<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\PrivacyPolicyController;
use App\Models\Settings;
use App\Services\Api\ApiCapabilitiesService;
use App\Support\SiteViewSettings;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class SiteViewSettingsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_raw_values_and_privacy_text_are_preserved_while_the_converted_view_is_memoized(): void
    {
        $raw = ['null' => null, 'empty' => '', 'zero' => '0', 'negative' => '-3', 'float' => '2.5', 'numeric' => '00123', 'privacy_policy' => '00123'];
        foreach ($raw as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }

        $settings = app(SiteViewSettings::class);
        $this->assertSame($raw, $settings->raw()->only(array_keys($raw))->all());
        $converted = $settings->converted();
        $this->assertSame(['null' => null, 'empty' => '', 'zero' => 0, 'negative' => -3, 'float' => 2.5, 'numeric' => 123, 'privacy_policy' => 123], array_intersect_key($converted, $raw));
        $settings->raw()->put('numeric', '999');
        $this->assertSame($converted, $settings->converted());
        $controller = app(PrivacyPolicyController::class);
        $this->assertSame($settings->raw(), $controller->settings);
        $this->assertSame('00123', $controller->privacyPolicy()->getData()['privacy_content']);
    }

    /** @return array<string, array{string}> */
    public static function cacheStates(): array
    {
        return ['cold' => ['cold'], 'warm' => ['warm'], 'read outage' => ['read outage'], 'write outage' => ['write outage']];
    }

    #[DataProvider('cacheStates')]
    public function test_nested_public_and_admin_views_share_the_controller_snapshot(string $state): void
    {
        $this->prepareNestedViews();
        DB::table('settings')->insert(['name' => 'title', 'value' => 'Snapshot']);
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'select "value", "name" from "settings"')) {
                $reads++;
            }
        });
        $cache = \Mockery::mock(Cache::getFacadeRoot());
        Cache::swap($cache);
        $get = $cache->shouldReceive('get')->with(SiteViewSettings::CACHE_KEY)->once();
        if ($state === 'read outage') {
            $get->andThrow(new RuntimeException('Cache unavailable'));
        } else {
            $get->andReturn($state === 'warm' ? ['title' => 'Snapshot'] : null);
        }
        if ($state === 'cold' || $state === 'write outage') {
            $put = $cache->shouldReceive('put')->with(SiteViewSettings::CACHE_KEY, \Mockery::type('array'), 300)->once();
            if ($state === 'write outage') {
                $put->andThrow(new RuntimeException('Cache unavailable'));
            } else {
                $put->andReturnTrue();
            }
        }

        $controller = app(PrivacyPolicyController::class);
        $this->assertSame('Snapshot', $controller->settings->get('title'));
        app(SiteViewSettings::class)->raw()->put('title', 'Must not reconvert');
        $this->assertSame('Snapshot|Snapshot|Snapshot', view('admin.snapshot')->render());
        $this->assertSame($state === 'warm' ? 0 : 1, $reads);
    }

    public function test_settings_write_invalidates_the_snapshot_and_rollout_keys_before_rendering_again(): void
    {
        $this->prepareNestedViews();
        Settings::settingsUpsert(['title' => 'Before']);
        $this->assertSame('Before|Before|Before', view('admin.snapshot')->render());
        $keys = ['site_settings', 'site_settings_array', 'site_settings_converted', ApiCapabilitiesService::V1_CACHE_KEY, ApiCapabilitiesService::V2_CACHE_KEY];
        foreach ($keys as $key) {
            Cache::put($key, 'stale', 300);
        }

        Settings::settingsUpdate(['title' => 'After']);
        $this->assertNull(Cache::get(SiteViewSettings::CACHE_KEY));
        foreach ($keys as $key) {
            $this->assertNull(Cache::get($key));
        }
        $this->assertSame('After|After|After', view('admin.snapshot')->render());
        $this->assertSame('After', app(PrivacyPolicyController::class)->settings->get('title'));
    }

    public function test_next_request_in_the_same_container_loads_a_new_snapshot(): void
    {
        $this->prepareNestedViews();
        Settings::settingsUpsert(['title' => 'First request']);
        $first = app(SiteViewSettings::class);
        $this->assertSame('First request|First request|First request', view('admin.snapshot')->render());

        Cache::put(SiteViewSettings::CACHE_KEY, ['title' => 'Next request'], 300);
        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(SiteViewSettings::class));
        $this->assertSame('Next request|Next request|Next request', view('admin.snapshot')->render());
        $this->assertSame('Next request', app(PrivacyPolicyController::class)->settings->get('title'));
    }

    private function prepareNestedViews(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        Cache::put('content_useful_links', collect(), 300);
        $directory = $this->makeTempDirectory('site-view-settings');
        mkdir($directory.'/layouts');
        mkdir($directory.'/admin');
        file_put_contents($directory.'/layouts/guest.blade.php', '{{ $site["title"] }}');
        file_put_contents($directory.'/admin/snapshot.blade.php', '{{ $site["title"] }}|@include("layouts.guest")|@include("layouts.guest")');
        app('view')->getFinder()->prependLocation($directory);
    }
}
