<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * amazonsleep throttles external metadata lookups (books, music, console). It has never
     * been seeded, and Settings::settingsUpdate() only UPDATEs, so the admin form could never
     * create the row. Add it at the value its consumers already fall back to.
     */
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'name' => 'amazonsleep',
            'value' => '1000',
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('name', 'amazonsleep')->delete();
    }
};
