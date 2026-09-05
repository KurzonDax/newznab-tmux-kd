<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\BookService;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * amazonsleep throttles the external metadata lookups book and console releases make.
 * It has never had a settings row, so settingsUpdate() -- an UPDATE, never an INSERT -- could
 * not create one from the admin form: the field was permanently unmanageable. The row has to
 * exist both on a fresh install (seeder) and on one upgraded in place (migration).
 */
class AmazonSleepSettingTest extends TestCase
{
    private const DEFAULT_MILLISECONDS = '1000';

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
    }

    public function test_the_migration_adds_the_row_with_the_coded_default(): void
    {
        $this->migration()->up();

        $this->assertSame(self::DEFAULT_MILLISECONDS, $this->storedValue());
    }

    public function test_running_the_migration_twice_is_safe(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(1, DB::table('settings')->where('name', 'amazonsleep')->count());
        $this->assertSame(self::DEFAULT_MILLISECONDS, $this->storedValue());
    }

    public function test_the_migration_leaves_an_operator_tuned_value_alone(): void
    {
        DB::table('settings')->insert(['name' => 'amazonsleep', 'value' => '250']);

        $this->migration()->up();

        $this->assertSame('250', $this->storedValue());
    }

    public function test_the_migration_rolls_back(): void
    {
        $this->migration()->up();
        $this->migration()->down();

        $this->assertDatabaseMissing('settings', ['name' => 'amazonsleep']);
    }

    public function test_a_fresh_install_seeds_the_setting(): void
    {
        (new SettingsTableSeeder)->run();

        $this->assertSame(self::DEFAULT_MILLISECONDS, $this->storedValue());
        $this->assertSame(1000, (new BookService)->sleeptime);
    }

    public function test_the_admin_save_path_persists_the_setting_once_the_row_exists(): void
    {
        $this->migration()->up();

        // What the settings hub's Service etiquette card writes.
        Settings::settingsUpdate(['amazonsleep' => '250']);

        $this->assertSame('250', $this->storedValue());
        $this->assertSame(250, (new BookService)->sleeptime);
    }

    public function test_the_settings_hub_exposes_the_setting(): void
    {
        $location = app(SettingsRegistry::class)->locate('amazonsleep');

        $this->assertNotNull($location, 'amazonsleep must stay editable from the hub.');
        $this->assertSame('metadata-lookups', $location->section->id);
    }

    private function storedValue(): ?string
    {
        return DB::table('settings')->where('name', 'amazonsleep')->value('value');
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_04_164905_add_amazonsleep_setting.php');
    }
}
