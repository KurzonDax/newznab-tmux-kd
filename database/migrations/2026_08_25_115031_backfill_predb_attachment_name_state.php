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
        DB::table('releases')
            ->where('predb_id', '>', 0)
            ->where('isrenamed', 0)
            ->update([
                'isrenamed' => 1,
                'is_trusted_name' => 1,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This provenance repair cannot be reversed without corrupting later transitions.
    }
};
