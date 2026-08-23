<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class SettingsNameLengthTest extends TestCase
{
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function schemaDumps(): array
    {
        return [
            'MySQL' => ['mysql-schema.sql'],
            'MariaDB' => ['mariadb-schema.sql'],
        ];
    }

    #[DataProvider('schemaDumps')]
    public function test_schema_dumps_can_store_every_seeded_and_migrated_setting_name(string $schemaFile): void
    {
        $columnLength = $this->settingsNameColumnLength($schemaFile);
        $this->assertSame(255, $columnLength, $schemaFile.' must not narrow settings.name');

        (new SettingsTableSeeder)->run();

        $names = DB::table('settings')
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        foreach ([
            '2026_08_21_120200_add_repair_and_rescan_settings.php',
            '2026_08_21_140000_add_audio_postprocessing_settings.php',
        ] as $migrationFile) {
            $names = [...$names, ...$this->migrationSettingNames($migrationFile)];
        }

        $tooLong = array_values(array_filter(
            $names,
            static fn (string $name): bool => strlen($name) > $columnLength,
        ));

        $this->assertSame([], $tooLong, 'Setting names exceed '.$schemaFile.' capacity');
    }

    private function settingsNameColumnLength(string $schemaFile): int
    {
        $schema = file_get_contents(database_path('schema/'.$schemaFile));
        $this->assertIsString($schema);

        $matched = preg_match(
            '/CREATE TABLE `settings` \(\s*`name` varchar\((\d+)\)/',
            $schema,
            $matches,
        );
        $this->assertSame(1, $matched, 'Unable to parse settings.name from '.$schemaFile);

        return (int) ($matches[1] ?? 0);
    }

    /** @return list<string> */
    private function migrationSettingNames(string $migrationFile): array
    {
        $migration = require database_path('migrations/'.$migrationFile);
        $this->assertInstanceOf(Migration::class, $migration);

        $settings = new ReflectionMethod($migration, 'settings');
        $this->assertTrue($settings->isPublic(), $migrationFile.' settings() must be public');

        $rows = $settings->invoke($migration);
        $this->assertIsArray($rows);

        return array_values(array_map(
            static fn (mixed $name): string => (string) $name,
            array_column($rows, 'name'),
        ));
    }
}
