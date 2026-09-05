<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The rows the cutover removes, and the ones it deliberately keeps.
 */
final class DeadSettingsCleanupTest extends TestCase
{
    /**
     * The names the migration drops, taken from the migration itself.
     *
     * Restating them here would only prove the two lists agree. What is worth proving is that
     * whatever the migration lists really has no reader, which
     * {@see self::test_nothing_reads_a_setting_the_migration_drops()} checks against `app/`.
     *
     * @return list<string>
     */
    private function droppedSettings(): array
    {
        /** @var object{DEAD_SETTINGS: list<string>} $migration */
        $migration = $this->migration();

        return $migration::DEAD_SETTINGS;
    }

    /**
     * Named in the audit as dead alongside `colors`, but they are not: every disabled-pane
     * message goes through TmuxTaskRunner::getRandomColor(), which reads all three.
     *
     * @var list<string>
     */
    private const array KEPT_DESPITE_THE_AUDIT = ['colors_start', 'colors_end', 'colors_exc'];

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

    public function test_the_seeder_no_longer_offers_a_setting_nothing_reads(): void
    {
        (new SettingsTableSeeder)->run();

        foreach ($this->droppedSettings() as $name) {
            $this->assertNull(
                DB::table('settings')->where('name', $name)->value('value'),
                $name.' has no reader and must not be seeded.'
            );
        }
    }

    public function test_the_colour_range_survives_because_it_still_has_a_reader(): void
    {
        (new SettingsTableSeeder)->run();

        foreach (self::KEPT_DESPITE_THE_AUDIT as $name) {
            $this->assertNotNull(
                DB::table('settings')->where('name', $name)->value('value'),
                $name.' is read by TmuxTaskRunner::getRandomColor() and must stay seeded.'
            );
        }
    }

    public function test_every_seeded_name_appears_exactly_once(): void
    {
        (new SettingsTableSeeder)->run();

        $source = file_get_contents(database_path('seeders/SettingsTableSeeder.php'));
        $this->assertIsString($source);

        preg_match_all("/'name' => '([a-z0-9_]+)'/", $source, $matches);
        $declared = $matches[1];

        $this->assertNotSame([], $declared, 'The seeder should still declare settings.');

        $duplicates = array_keys(array_filter(array_count_values($declared), static fn (int $count): bool => $count > 1));
        $this->assertSame([], $duplicates, 'A name declared twice silently keeps only the last value.');

        // The rows that reach the table have to match what the file declares. They did not
        // before: two entries carried the same hand-written array key, so PHP dropped one of
        // them on the way in and fix_names_timeout was never seeded on a fresh install.
        $this->assertCount(count($declared), DB::table('settings')->get());
        $this->assertSame('1200', DB::table('settings')->where('name', 'fix_names_timeout')->value('value'));
    }

    public function test_the_migration_removes_the_dead_rows_and_is_safe_to_run_twice(): void
    {
        $dropped = $this->droppedSettings();

        (new SettingsTableSeeder)->run();
        DB::table('settings')->insert(array_map(
            static fn (string $name): array => ['name' => $name, 'value' => '1'],
            $dropped,
        ));

        $before = DB::table('settings')->count();

        $this->migration()->up();

        foreach ($dropped as $name) {
            $this->assertNull(DB::table('settings')->where('name', $name)->value('value'), $name);
        }

        $after = DB::table('settings')->count();
        $this->assertSame($before - count($dropped), $after);

        $this->migration()->up();
        $this->assertSame($after, DB::table('settings')->count(), 'A rerun must change nothing.');
    }

    public function test_nothing_reads_a_setting_the_migration_drops(): void
    {
        // The canonical read paths. A dropped name appearing as an argument to one of these
        // would mean the migration deletes a row something still resolves at runtime.
        $readers = [
            'Settings::settingValue',
            'Settings::settingValueOr',
            'SettingNumber::int',
            'SettingNumber::string',
        ];

        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($this->droppedSettings() as $name) {
                foreach ($readers as $reader) {
                    if (str_contains($contents, $reader."('".$name."'")) {
                        $offenders[] = $file->getPathname().' reads '.$name;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'The migration drops a setting something still reads.');
    }

    public function test_the_migration_leaves_every_row_something_still_reads(): void
    {
        (new SettingsTableSeeder)->run();
        $survivors = DB::table('settings')->pluck('name')->all();

        $this->migration()->up();

        foreach (self::KEPT_DESPITE_THE_AUDIT as $name) {
            $this->assertNotNull(DB::table('settings')->where('name', $name)->value('value'), $name);
        }

        foreach (app(SettingsRegistry::class)->keys() as $key) {
            if (! in_array($key, $survivors, true)) {
                continue;
            }

            $this->assertNotNull(
                DB::table('settings')->where('name', $key)->value('value'),
                $key.' is rendered by the settings hub and must not be deleted.'
            );
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_05_120000_drop_settings_with_no_reader.php');
    }
}
