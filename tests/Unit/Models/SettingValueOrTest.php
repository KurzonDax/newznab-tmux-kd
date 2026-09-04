<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settings::settingValue() distinguishes a missing row (null) from a row cleared in the
 * admin form (''), which every consumer then has to remember to collapse. settingValueOr()
 * is the single place that collapse happens.
 */
class SettingValueOrTest extends TestCase
{
    #[Test]
    public function a_missing_row_resolves_to_the_default(): void
    {
        $this->createSettingsTable();

        $this->assertSame(1000, Settings::settingValueOr('amazonsleep', 1000));
    }

    #[Test]
    public function a_missing_settings_table_resolves_to_the_default(): void
    {
        $this->assertFalse(Schema::hasTable('settings'));

        $this->assertSame(1000, Settings::settingValueOr('amazonsleep', 1000));
    }

    #[Test]
    public function a_cleared_row_resolves_to_the_default(): void
    {
        $this->createSettingsTable();
        DB::table('settings')->insert(['name' => 'amazonsleep', 'value' => '']);

        $this->assertSame(1000, Settings::settingValueOr('amazonsleep', 1000));
    }

    #[Test]
    public function a_null_row_resolves_to_the_default(): void
    {
        $this->createSettingsTable();
        DB::table('settings')->insert(['name' => 'amazonsleep', 'value' => null]);

        $this->assertSame(1000, Settings::settingValueOr('amazonsleep', 1000));
    }

    #[Test]
    public function an_explicit_zero_is_kept(): void
    {
        $this->createSettingsTable();
        DB::table('settings')->insert(['name' => 'amazonsleep', 'value' => '0']);

        $this->assertSame(0, Settings::settingValueOr('amazonsleep', 1000));
    }

    #[Test]
    public function a_stored_value_is_returned_exactly_as_settingvalue_returns_it(): void
    {
        $this->createSettingsTable();
        DB::table('settings')->insert([
            ['name' => 'amazonsleep', 'value' => '250'],
            ['name' => 'imdblanguage', 'value' => 'de'],
            ['name' => 'completionpercent', 'value' => '99.5'],
        ]);

        $this->assertSame(Settings::settingValue('amazonsleep'), Settings::settingValueOr('amazonsleep', 1000));
        $this->assertSame(250, Settings::settingValueOr('amazonsleep', 1000));
        $this->assertSame('de', Settings::settingValueOr('imdblanguage', 'en'));
        $this->assertSame(99.5, Settings::settingValueOr('completionpercent', 95));
    }

    #[Test]
    public function a_non_numeric_row_is_handed_back_untouched_for_the_caller_to_cast(): void
    {
        $this->createSettingsTable();
        DB::table('settings')->insert(['name' => 'maxbooksprocessed', 'value' => 'not a number']);

        $this->assertSame('not a number', Settings::settingValueOr('maxbooksprocessed', 300));
    }

    private function createSettingsTable(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
    }
}
