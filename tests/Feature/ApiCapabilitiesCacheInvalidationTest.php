<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\Api\ApiCapabilitiesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The capabilities payloads are cached for their full TTL, so a settings save has to
 * clear exactly the keys the service writes -- see issue #414, where the v2 key and the
 * key `Settings::forgetCachedSettings()` cleared had drifted apart.
 */
class ApiCapabilitiesCacheInvalidationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Cache::flush();

        $this->createSchema();
        $this->seedSettings();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Settings::forgetCachedSettings();

        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    public function test_settings_save_refreshes_the_v1_capabilities_payload(): void
    {
        $service = app(ApiCapabilitiesService::class);

        $this->assertSame('Original strapline', $service->v1(false)['server']['strapline']);

        Settings::settingsUpdate(['strapline' => 'Updated strapline']);

        $this->assertSame('Updated strapline', $service->v1(false)['server']['strapline']);
    }

    public function test_settings_save_refreshes_the_v2_capabilities_payload(): void
    {
        $service = app(ApiCapabilitiesService::class);

        $this->assertSame('Original strapline', $service->v2()['server']['strapline']);

        Settings::settingsUpdate(['strapline' => 'Updated strapline']);

        $this->assertSame('Updated strapline', $service->v2()['server']['strapline']);
    }

    public function test_settings_save_forgets_the_cache_keys_the_service_writes(): void
    {
        $service = app(ApiCapabilitiesService::class);

        $service->v1(false);
        $service->v2();

        $this->assertTrue(Cache::has(ApiCapabilitiesService::V1_CACHE_KEY));
        $this->assertTrue(Cache::has(ApiCapabilitiesService::V2_CACHE_KEY));

        Settings::forgetCachedSettings();

        $this->assertFalse(Cache::has(ApiCapabilitiesService::V1_CACHE_KEY));
        $this->assertFalse(Cache::has(ApiCapabilitiesService::V2_CACHE_KEY));
    }

    private function createSchema(): void
    {
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->integer('status')->default(1);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->integer('status')->default(1);
        });

        Schema::create('registration_periods', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::table('root_categories')->insert([
            ['id' => 5000, 'title' => 'TV', 'status' => 1],
        ]);

        DB::table('categories')->insert([
            ['id' => 5030, 'title' => 'SD', 'root_categories_id' => 5000, 'status' => 1],
        ]);
    }

    private function seedSettings(): void
    {
        DB::table('settings')->insert([
            ['name' => 'strapline', 'value' => 'Original strapline'],
            ['name' => 'metakeywords', 'value' => 'test,api'],
            ['name' => 'registerstatus', 'value' => '0'],
        ]);

        Settings::forgetCachedSettings();
    }
}
