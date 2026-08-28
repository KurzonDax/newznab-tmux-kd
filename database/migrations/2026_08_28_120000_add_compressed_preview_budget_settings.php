<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site settings for extending the dynamic preview budget to archive-wrapped
 * video, and for the minimum Clip duration floor.
 *
 * `preview_max_rar_parts` bounds how many archive parts the compressed top-up
 * may touch for one release (the initially fetched part included), mirroring
 * `audio_max_rar_parts`. `clip_minimum_seconds` discards a Clip encode shorter
 * than the floor so a starved extraction stores no video artifact instead of a
 * seconds-long tease; 0 disables the floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore($this->settings());
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('name', array_column($this->settings(), 'name'))->delete();
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public function settings(): array
    {
        return [
            ['name' => 'preview_max_rar_parts', 'value' => '6'],
            ['name' => 'clip_minimum_seconds', 'value' => '5'],
        ];
    }
};
