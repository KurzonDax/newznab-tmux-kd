<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Support\Settings\SettingsRegistry;
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
        'audio_max_archive_mb',
        'audio_min_completion_percent',
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
        $this->ceilingMigration()->up();
        $this->completionMigration()->up();

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
        $this->ceilingMigration()->up();
        $this->completionMigration()->up();
        $this->completionMigration()->down();
        $this->ceilingMigration()->down();
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
        $this->ceilingMigration()->up();
        $this->completionMigration()->up();

        // What the settings hub's Audio previews card writes.
        Settings::settingsUpdate([
            'postthreadsaudio' => '4',
            'audio_segments_to_download' => '20',
            'audio_max_rar_parts' => '3',
            'audio_max_archive_mb' => '512',
            'audio_min_completion_percent' => '90',
            'audio_preview_seconds' => '45',
            'audio_preview_start_seconds' => '0',
            'audio_spectrogram' => '0',
        ]);

        foreach ([
            'postthreadsaudio' => '4',
            'audio_segments_to_download' => '20',
            'audio_max_rar_parts' => '3',
            'audio_max_archive_mb' => '512',
            'audio_min_completion_percent' => '90',
            'audio_preview_seconds' => '45',
            'audio_preview_start_seconds' => '0',
            'audio_spectrogram' => '0',
        ] as $name => $expected) {
            $this->assertSame($expected, DB::table('settings')->where('name', $name)->value('value'), $name);
        }
    }

    public function test_the_settings_hub_exposes_the_audio_settings_and_drops_the_retired_one(): void
    {
        $registry = app(SettingsRegistry::class);

        foreach (self::AUDIO_SETTINGS as $name) {
            $location = $registry->locate($name);

            $this->assertNotNull($location, $name.' should be editable');
            $this->assertSame('audio', $location->card->id, $name.' belongs on the Audio previews card');
        }

        $this->assertFalse($registry->has('saveaudiopreview'), 'The retired switch must not come back.');
    }

    public function test_audio_archive_megabytes_are_converted_to_bytes_and_zero_is_unlimited(): void
    {
        $this->ceilingMigration()->up();

        DB::table('settings')->where('name', 'audio_max_archive_mb')->update(['value' => '512']);
        $this->assertSame(512 * 1024 * 1024, (new AudioProcessingConfiguration)->maxArchiveBytes);

        DB::table('settings')->where('name', 'audio_max_archive_mb')->update(['value' => '0']);
        $this->assertNull((new AudioProcessingConfiguration)->maxArchiveBytes);
    }

    public function test_the_ceiling_migration_preserves_an_operator_tuned_value(): void
    {
        DB::table('settings')->insert(['name' => 'audio_max_archive_mb', 'value' => '2048']);

        $this->ceilingMigration()->up();

        $this->assertSame('2048', DB::table('settings')->where('name', 'audio_max_archive_mb')->value('value'));
    }

    public function test_audio_minimum_completion_defaults_to_95_and_zero_disables_it(): void
    {
        $this->completionMigration()->up();

        $this->assertSame(95.0, (new AudioProcessingConfiguration)->minimumCompletionPercent);

        DB::table('settings')->where('name', 'audio_min_completion_percent')->update(['value' => '0']);
        $this->assertSame(0.0, (new AudioProcessingConfiguration)->minimumCompletionPercent);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_21_140000_add_audio_postprocessing_settings.php');
    }

    private function ceilingMigration(): object
    {
        return require database_path('migrations/2026_08_22_080000_add_audio_archive_fetch_ceiling_setting.php');
    }

    private function completionMigration(): object
    {
        return require database_path('migrations/2026_08_29_170512_add_audio_min_completion_percent_setting.php');
    }
}
