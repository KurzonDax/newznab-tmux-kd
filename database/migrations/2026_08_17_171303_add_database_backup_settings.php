<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore($this->settings());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('name', array_column($this->settings(), 'name'))->delete();
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function settings(): array
    {
        return [
            ['name' => 'backup_enabled', 'value' => '0'],
            ['name' => 'backup_full_dow', 'value' => '0'],
            ['name' => 'backup_full_time', 'value' => '02:00'],
            ['name' => 'backup_daily_time', 'value' => '02:00'],
            ['name' => 'backup_location', 'value' => storage_path('app/backups')],
            ['name' => 'backup_keep_fulls', 'value' => '4'],
            ['name' => 'backup_pause_tmux', 'value' => '1'],
            ['name' => 'backup_incl_working', 'value' => '1'],
            ['name' => 'backup_dump_binary', 'value' => ''],
            ['name' => 'backup_offsite_path', 'value' => ''],
            ['name' => 'backup_offsite_after', 'value' => '0'],
            ['name' => 'backup_offsite_keep', 'value' => '0'],
            ['name' => 'backup_run_request', 'value' => ''],
            ['name' => 'backup_pause_marker', 'value' => ''],
        ];
    }
};
