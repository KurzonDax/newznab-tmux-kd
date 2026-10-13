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
        DB::table('settings')->insertOrIgnore([
            ['name' => 'decision_model_mode', 'value' => 'off'],
            ['name' => 'decision_model_apply_threshold', 'value' => '0.80'],
            ['name' => 'decision_model_arbitrate_below', 'value' => '0.70'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')
            ->whereIn('name', ['decision_model_mode', 'decision_model_apply_threshold', 'decision_model_arbitrate_below'])
            ->delete();
    }
};
