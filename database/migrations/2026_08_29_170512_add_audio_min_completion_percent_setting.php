<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'name' => 'audio_min_completion_percent',
            'value' => '95',
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('name', 'audio_min_completion_percent')->delete();
    }
};
