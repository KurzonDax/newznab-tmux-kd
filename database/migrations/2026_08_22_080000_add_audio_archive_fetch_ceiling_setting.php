<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'name' => 'audio_max_archive_mb',
            'value' => '1024',
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('name', 'audio_max_archive_mb')->delete();
    }
};
