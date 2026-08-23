<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WidenSettingsNameColumnMigrationTest extends TestCase
{
    /** @var list<string> */
    private const array TRUNCATED_NAMES = [
        'audio_preview_start_secon',
        'audio_segments_to_downloa',
        'discard_executable_extens',
        'repair_stat_sample_per_fi',
        'rescan_max_articles_per_r',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 255)->primary();
            $table->string('value', 1000)->default('');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    public function test_migration_repairs_truncated_names_preserves_unambiguous_values_and_is_idempotent(): void
    {
        DB::table('settings')->insert([
            ['name' => 'audio_preview_start_secon', 'value' => '10'],
            ['name' => 'audio_segments_to_downloa', 'value' => '12'],
            ['name' => 'discard_executable_extens', 'value' => 'dll|exe'],
            ['name' => 'repair_stat_sample_per_fi', 'value' => '2'],
            ['name' => 'rescan_max_articles_per_r', 'value' => '500000'],
        ]);

        $migration = $this->migration();
        $migration->up();

        $expected = [
            'audio_preview_start_seconds' => '10',
            'audio_segments_to_download' => '12',
            'discard_executable_extensions' => 'dll|exe',
            'repair_stat_sample_per_file' => '2',
            'rescan_max_articles_per_release' => '500000',
            'rescan_max_articles_per_run' => '5000000',
        ];

        $this->assertSame($expected, $this->currentSettings());

        foreach (self::TRUNCATED_NAMES as $truncatedName) {
            $this->assertDatabaseMissing('settings', ['name' => $truncatedName]);
        }

        $this->assertTrue(
            DB::table('settings')->pluck('name')->every(
                static fn (mixed $name): bool => strlen((string) $name) > 25
            )
        );

        $migration->up();

        $this->assertSame($expected, $this->currentSettings());
    }

    public function test_migration_leaves_existing_full_name_values_untouched(): void
    {
        $expected = [
            'audio_preview_start_seconds' => '17',
            'audio_segments_to_download' => '24',
            'discard_executable_extensions' => 'exe|scr',
            'repair_stat_sample_per_file' => '5',
            'rescan_max_articles_per_release' => '750000',
            'rescan_max_articles_per_run' => '9000000',
        ];

        DB::table('settings')->insert(
            array_map(
                static fn (string $name, string $value): array => compact('name', 'value'),
                array_keys($expected),
                array_values($expected),
            )
        );

        $this->migration()->up();

        $this->assertSame($expected, $this->currentSettings());
    }

    /** @return array<string, string> */
    private function currentSettings(): array
    {
        return DB::table('settings')
            ->orderBy('name')
            ->pluck('value', 'name')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }

    private function migration(): Migration
    {
        $paths = glob(database_path('migrations/*_widen_settings_name_column.php')) ?: [];
        $this->assertCount(1, $paths);

        $path = $paths[0] ?? null;
        $this->assertIsString($path);

        $migration = require $path;
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
