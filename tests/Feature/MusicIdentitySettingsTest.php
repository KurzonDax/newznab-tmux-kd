<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MusicIdentity\MusicIdentityConfiguration;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MusicIdentitySettingsTest extends TestCase
{
    /**
     * The runtime controls the feature still has.
     *
     * `music_identity_shadow` was one of these until #443: its value reached a property nobody
     * read, while the real shadow switch is `music-identity.application_mode` in config. The
     * migration below still creates the row on an upgrade path, and a later migration drops it.
     *
     * @var array<string, string>
     */
    private const array SETTINGS = [
        'music_identity_enabled' => '1',
        'music_identity_workers' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('service_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('endpoint_url')->nullable();
            $table->string('check_type')->default('http');
            $table->string('probe_identifier')->nullable();
            $table->string('status');
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function test_the_migration_adds_runtime_controls_and_the_status_probe_entry(): void
    {
        $this->migration()->up();

        foreach (self::SETTINGS as $name => $default) {
            $this->assertSame($default, $this->settingValue($name));
        }
        $this->assertDatabaseHas('service_statuses', [
            'slug' => 'musicbrainz',
            'check_type' => 'probe',
            'probe_identifier' => 'musicbrainz',
            'is_enabled' => true,
        ]);
    }

    public function test_the_migration_preserves_operator_values_and_rolls_back_only_its_rows(): void
    {
        DB::table('settings')->insert(['name' => 'music_identity_workers', 'value' => '6']);
        $this->migration()->up();

        $this->assertSame('6', $this->settingValue('music_identity_workers'));

        $this->migration()->down();

        foreach ([...array_keys(self::SETTINGS), 'music_identity_shadow'] as $name) {
            $this->assertNull($this->settingValue($name));
        }
        $this->assertDatabaseMissing('service_statuses', ['slug' => 'musicbrainz']);
    }

    public function test_fresh_install_and_admin_surfaces_include_every_runtime_control(): void
    {
        $seeder = file_get_contents(database_path('seeders/SettingsTableSeeder.php'));
        $registry = app(SettingsRegistry::class);

        $this->assertIsString($seeder);
        foreach (array_keys(self::SETTINGS) as $name) {
            $this->assertStringContainsString("'name' => '".$name."'", $seeder);
            $this->assertSame('music', $registry->locate($name)?->card->id, $name.' must stay editable from the hub.');
        }

        $this->assertStringNotContainsString("'name' => 'music_identity_shadow'", $seeder);
        $this->assertFalse($registry->has('music_identity_shadow'), 'The retired shadow switch must not come back.');

        (new SettingsTableSeeder)->run();
        foreach (self::SETTINGS as $name => $default) {
            $this->assertSame($default, $this->settingValue($name));
        }
    }

    public function test_runtime_configuration_clamps_workers_and_stays_dormant_without_an_endpoint(): void
    {
        $this->migration()->up();
        DB::table('settings')->where('name', 'music_identity_workers')->update(['value' => '99']);
        config([
            'music-identity.musicbrainz.endpoint_url' => null,
            'music-identity.worker_parallelism_max' => 8,
        ]);

        $configuration = new MusicIdentityConfiguration;

        $this->assertTrue($configuration->enabled);
        $this->assertSame(8, $configuration->workerParallelism);
        $this->assertFalse($configuration->active());

        config(['music-identity.musicbrainz.endpoint_url' => 'https://mirror.test/ws/2/']);
        $this->assertTrue((new MusicIdentityConfiguration)->active());
    }

    public function test_environment_example_documents_the_only_deployment_specific_musicbrainz_values(): void
    {
        $example = file_get_contents(base_path('.env.example'));
        $config = file_get_contents(config_path('music-identity.php'));

        $this->assertIsString($example);
        $this->assertIsString($config);
        $this->assertStringContainsString('MUSICBRAINZ_ENDPOINT_URL=', $example);
        $this->assertStringContainsString('MUSICBRAINZ_USER_AGENT_CONTACT=', $example);
        $this->assertStringContainsString("env('MUSICBRAINZ_ENDPOINT_URL'", $config);
        $this->assertStringContainsString("env('MUSICBRAINZ_USER_AGENT_CONTACT'", $config);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_30_221253_add_music_identity_settings_and_status_probe.php');
    }

    private function settingValue(string $name): ?string
    {
        $value = DB::table('settings')->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }
}
