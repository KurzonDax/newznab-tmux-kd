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
        DB::table('settings')
            ->where('name', 'backfill')
            ->where('value', '4')
            ->update(['value' => '1']);

        DB::table('settings')
            ->where('name', 'backfill_groups')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
