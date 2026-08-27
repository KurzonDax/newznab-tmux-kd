<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            ['name' => 'preview_target_seconds', 'value' => '30'],
            ['name' => 'preview_max_fetch_mb', 'value' => '300'],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('name', ['preview_target_seconds', 'preview_max_fetch_mb'])->delete();
    }
};
