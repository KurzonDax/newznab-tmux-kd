<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The audio path's knobs are site settings, so they have to exist for a fresh
 * install (seeder) and for one upgraded in place (migration), and the admin form
 * has to be able to write them.
 */
class AudioProcessingSettingsTest extends TestCase
{
    /** @var list<string> */
    private const array AUDIO_SETTINGS = [
        'postthreadsaudio',
        'audio_segments_to_download',
        'audio_max_rar_parts',
        'audio_preview_seconds',
        'audio_preview_start_seconds',
        'audio_spectrogram',
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
    }

    public function test_the_migration_adds_every_setting_and_retires_the_old_one(): void
    {
        DB::table('settings')->insert([['name' => 'saveaudiopreview', 'value' => '1']]);

        $this->migration()->up();

        foreach (self::AUDIO_SETTINGS as $name) {
            $this->assertDatabaseHas('settings', ['name' => $name]);
        }
        $this->assertDatabaseMissing('settings', ['name' => 'saveaudiopreview']);
    }

    public function test_the_migration_leaves_an_operator_tuned_value_alone(): void
    {
        DB::table('settings')->insert([['name' => 'audio_preview_seconds', 'value' => '45']]);

        $this->migration()->up();

        $this->assertSame('45', DB::table('settings')->where('name', 'audio_preview_seconds')->value('value'));
    }

    public function test_the_migration_rolls_back(): void
    {
        $this->migration()->up();
        $this->migration()->down();

        foreach (self::AUDIO_SETTINGS as $name) {
            $this->assertDatabaseMissing('settings', ['name' => $name]);
        }
        $this->assertDatabaseHas('settings', ['name' => 'saveaudiopreview']);
    }

    public function test_the_seeder_carries_the_same_keys_and_no_longer_carries_saveaudiopreview(): void
    {
        $seeder = file_get_contents(database_path('seeders/SettingsTableSeeder.php'));

        $this->assertIsString($seeder);
        foreach (self::AUDIO_SETTINGS as $name) {
            $this->assertStringContainsString("'name' => '".$name."'", $seeder);
        }
        $this->assertStringNotContainsString('saveaudiopreview', $seeder);
    }

    public function test_the_admin_save_path_persists_every_audio_setting(): void
    {
        $this->migration()->up();

        // What AdminSiteController::edit() does with the submitted form.
        Settings::settingsUpdate([
            'postthreadsaudio' => '4',
            'audio_segments_to_download' => '20',
            'audio_max_rar_parts' => '3',
            'audio_preview_seconds' => '45',
            'audio_preview_start_seconds' => '0',
            'audio_spectrogram' => '0',
        ]);

        foreach ([
            'postthreadsaudio' => '4',
            'audio_segments_to_download' => '20',
            'audio_max_rar_parts' => '3',
            'audio_preview_seconds' => '45',
            'audio_preview_start_seconds' => '0',
            'audio_spectrogram' => '0',
        ] as $name => $expected) {
            $this->assertSame($expected, DB::table('settings')->where('name', $name)->value('value'), $name);
        }
    }

    public function test_the_admin_form_exposes_the_audio_settings_and_drops_the_retired_one(): void
    {
        $postProcessing = file_get_contents(
            resource_path('views/admin/site/sections/postprocessing-settings.blade.php')
        );
        $lookup = file_get_contents(resource_path('views/admin/site/sections/lookup-settings.blade.php'));

        $this->assertIsString($postProcessing);
        $this->assertIsString($lookup);

        foreach (self::AUDIO_SETTINGS as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $postProcessing, $name.' should be editable');
        }
        $this->assertStringNotContainsString('saveaudiopreview', $lookup);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_21_140000_add_audio_postprocessing_settings.php');
    }
}
