<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A setting has to arrive at the same value on a fresh install and on one upgraded in place,
 * and re-running the migration that introduced it must not stamp an operator's value back to
 * the default.
 *
 * These were written against the old site-edit form because that is where the settings were
 * added; they never had anything to do with the form itself, so the cutover moved them here
 * rather than dropping them.
 */
final class SettingsMigrationDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    public function test_descriptive_title_setting_defaults_on_for_fresh_and_existing_installs(): void
    {
        (new SettingsTableSeeder)->run();
        $this->assertSame('1', $this->settingValue('descriptive_title_rename'));

        DB::table('settings')->where('name', 'descriptive_title_rename')->delete();
        $migration = $this->migration('2026_08_16_024113_add_descriptive_title_rename_setting.php');
        $migration->up();
        $this->assertSame('1', $this->settingValue('descriptive_title_rename'));

        DB::table('settings')->where('name', 'descriptive_title_rename')->update(['value' => '0']);
        $migration->up();
        $this->assertSame('0', $this->settingValue('descriptive_title_rename'));

        $migration->down();
        $this->assertNull($this->settingValue('descriptive_title_rename'));
    }

    public function test_forced_root_pc_escape_defaults_off_for_fresh_and_existing_installs(): void
    {
        (new SettingsTableSeeder)->run();
        $this->assertSame('0', $this->settingValue('forced_root_pc_escape'));

        DB::table('settings')->where('name', 'forced_root_pc_escape')->delete();
        $migration = $this->migration('2026_08_31_145035_add_forced_root_pc_escape_setting.php');
        $migration->up();
        $this->assertSame('0', $this->settingValue('forced_root_pc_escape'));

        DB::table('settings')->where('name', 'forced_root_pc_escape')->update(['value' => '1']);
        $migration->up();
        $this->assertSame('1', $this->settingValue('forced_root_pc_escape'));

        $migration->down();
        $this->assertNull($this->settingValue('forced_root_pc_escape'));
    }

    public function test_repair_and_rescan_settings_are_seeded_and_backfilled_for_existing_installs(): void
    {
        (new SettingsTableSeeder)->run();
        $this->assertSame('72', $this->settingValue('repair_retry_after_hours'));
        $this->assertSame('500000', $this->settingValue('rescan_max_articles_per_release'));

        DB::table('settings')->whereIn('name', ['repair_limit', 'rescan_limit'])->delete();
        $migration = $this->migration('2026_08_21_120200_add_repair_and_rescan_settings.php');
        $migration->up();
        $this->assertSame('250', $this->settingValue('repair_limit'));
        $this->assertSame('100', $this->settingValue('rescan_limit'));

        // Re-running must not stamp an operator's value back to the default.
        DB::table('settings')->where('name', 'repair_limit')->update(['value' => '10']);
        $migration->up();
        $this->assertSame('10', $this->settingValue('repair_limit'));

        $migration->down();
        $this->assertNull($this->settingValue('repair_limit'));
    }

    private function migration(string $file): Migration
    {
        return require database_path('migrations/'.$file);
    }

    private function settingValue(string $name): ?string
    {
        $value = DB::table('settings')->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }
}
