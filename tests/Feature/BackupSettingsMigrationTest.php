<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupSettingsMigrationTest extends TestCase
{
    private const string DAILY_ONLY_MIGRATION = 'migrations/2026_11_16_130000_switch_backup_settings_to_daily_only.php';

    public function test_seeder_and_upgrade_migration_supply_safe_backup_defaults(): void
    {
        $this->createSettingsTable();

        (new SettingsTableSeeder)->run();

        $this->assertSame('0', $this->setting('backup_enabled'));
        $this->assertSame('02:00', $this->setting('backup_time'));
        $this->assertSame(storage_path('app/backups'), $this->setting('backup_location'));
        $this->assertSame('4', $this->setting('backup_keep'));
        $this->assertSame('7', $this->setting('backup_offsite_keep'));
        $this->assertSame('1', $this->setting('backup_pause_tmux'));
        $this->assertSame('1', $this->setting('backup_incl_working'));
        foreach (['backup_full_dow', 'backup_full_time', 'backup_daily_time', 'backup_keep_fulls'] as $removed) {
            $this->assertNull($this->setting($removed), $removed);
        }

        DB::table('settings')->delete();
        DB::table('settings')->insert([
            ['name' => 'backup_enabled', 'value' => '1'],
            ['name' => 'backup_full_dow', 'value' => '3'],
            ['name' => 'backup_full_time', 'value' => '04:00'],
            ['name' => 'backup_daily_time', 'value' => '01:30'],
            ['name' => 'backup_keep_fulls', 'value' => '1'],
            ['name' => 'backup_offsite_keep', 'value' => '8'],
        ]);
        $this->dailyOnlyMigration()->up();

        $this->assertSame('1', $this->setting('backup_enabled'));
        $this->assertSame('01:30', $this->setting('backup_time'));
        $this->assertSame('1', $this->setting('backup_keep'));
        $this->assertSame('8', $this->setting('backup_offsite_keep'));
        foreach (['backup_full_dow', 'backup_full_time', 'backup_daily_time', 'backup_keep_fulls'] as $removed) {
            $this->assertNull($this->setting($removed), $removed);
        }

        // Installs that already ran the 2026_08_17 migration never run it again, so it keeps its original keys.
        $original = (string) file_get_contents(database_path('migrations/2026_08_17_171303_add_database_backup_settings.php'));
        $this->assertStringContainsString("['name' => 'backup_full_dow', 'value' => '0']", $original);
        $this->assertStringContainsString("['name' => 'backup_keep_fulls', 'value' => '4']", $original);
    }

    #[DataProvider('offsiteKeepValues')]
    public function test_upgrade_maps_offsite_keep_into_the_one_to_ten_range(string $stored, string $expected): void
    {
        $this->createSettingsTable();
        DB::table('settings')->insert([
            ['name' => 'backup_daily_time', 'value' => '02:00'],
            ['name' => 'backup_keep_fulls', 'value' => '4'],
            ['name' => 'backup_offsite_keep', 'value' => $stored],
        ]);

        $this->dailyOnlyMigration()->up();

        $this->assertSame($expected, $this->setting('backup_offsite_keep'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function offsiteKeepValues(): array
    {
        return [
            'zero kept everything' => ['0', '10'],
            'empty' => ['', '10'],
            'above ten' => ['11', '10'],
            'far above ten' => ['1000', '10'],
            'one' => ['1', '1'],
            'seven' => ['7', '7'],
            'ten' => ['10', '10'],
        ];
    }

    private function createSettingsTable(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 25)->primary();
            $table->text('value')->nullable();
        });
    }

    private function dailyOnlyMigration(): object
    {
        return require database_path(self::DAILY_ONLY_MIGRATION);
    }

    private function setting(string $name): ?string
    {
        $value = DB::table('settings')->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }
}
