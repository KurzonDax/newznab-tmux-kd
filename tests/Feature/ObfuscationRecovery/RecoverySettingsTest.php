<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryConfig;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

final class RecoverySettingsTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSettingsHubSchema();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_upgrade_preserves_existing_settings_and_disables_group_admission(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('active')->default(false);
        });
        DB::table('usenet_groups')->insert(['name' => 'alt.binaries.fixture', 'active' => false]);
        DB::table('settings')->insert(['name' => 'obfuscation_recovery_threads', 'value' => '3']);
        $migration = require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php');
        $migration->up();

        $this->assertSame('disabled', DB::table('usenet_groups')->value('obfuscation_recovery_profile'));
        $this->assertSame(0, DB::table('usenet_groups')->value('active'));
        $this->assertSame('3', $this->storedSettingValue('obfuscation_recovery_threads'));
        $this->assertSame('0', $this->storedSettingValue('obfuscation_recovery_enabled'));
        $this->assertSame('144', $this->storedSettingValue('obfuscation_recovery_retention_hours'));
    }

    public function test_fresh_installs_seed_every_control_disabled_by_default(): void
    {
        (new SettingsTableSeeder)->run();
        foreach (RecoveryConfig::DEFAULTS as $key => $value) {
            $this->assertSame((string) $value, $this->storedSettingValue($key));
        }
    }

    public function test_controls_save_through_their_existing_settings_sections(): void
    {
        (new SettingsTableSeeder)->run();
        $this->saveCard('release-formation', 'obfuscated-recovery', $this->currentCardPayload(
            'release-formation', 'obfuscated-recovery', ['obfuscation_recovery_threads' => '60'],
        ));
        $this->assertSame('60', $this->storedSettingValue('obfuscation_recovery_threads'));
        $this->saveCard('post-processing', 'recovered-release-inspection', $this->currentCardPayload(
            'post-processing', 'recovered-release-inspection', ['obfuscation_recovery_enrichment_release_mib' => '0'],
        ));
        $this->assertSame('0', $this->storedSettingValue('obfuscation_recovery_enrichment_release_mib'));
    }

    public function test_invalid_or_cross_card_writes_change_nothing(): void
    {
        (new SettingsTableSeeder)->run();
        foreach ([['obfuscation_recovery_threads' => '61'], ['obfuscation_recovery_threads' => '0'],
            ['obfuscation_recovery_media_candidate_mib' => '0'],
            ['obfuscation_recovery_enrichment_release_mib' => '1'], ['unknown_key' => '1']] as $overrides) {
            $before = DB::table('settings')->orderBy('name')->pluck('value', 'name')->all();
            try {
                $this->saveCard('release-formation', 'obfuscated-recovery', $this->currentCardPayload(
                    'release-formation', 'obfuscated-recovery', $overrides,
                ));
                $this->fail('Invalid settings must be rejected.');
            } catch (ValidationException) {
                $this->assertSame($before, DB::table('settings')->orderBy('name')->pluck('value', 'name')->all());
            }
        }
    }
}
