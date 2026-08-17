<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillSettingsMigrationTest extends TestCase
{
    public function test_legacy_safe_mode_is_enabled_and_the_obsolete_group_limit_is_removed(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        DB::table('settings')->insert([
            ['name' => 'backfill', 'value' => '4'],
            ['name' => 'backfill_groups', 'value' => '12'],
            ['name' => 'backfill_qty', 'value' => '100000'],
        ]);

        $migration = require database_path('migrations/2026_08_17_204656_normalize_backfill_settings.php');
        $migration->up();

        $this->assertSame('1', (string) DB::table('settings')->where('name', 'backfill')->value('value'));
        $this->assertFalse(DB::table('settings')->where('name', 'backfill_groups')->exists());
        $this->assertSame('100000', (string) DB::table('settings')->where('name', 'backfill_qty')->value('value'));
    }
}
