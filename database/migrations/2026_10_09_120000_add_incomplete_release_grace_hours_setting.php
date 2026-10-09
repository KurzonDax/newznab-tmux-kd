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
        DB::table('settings')->insertOrIgnore(['name' => 'incomplete_release_grace_hours', 'value' => '72']);
    }

    /**
     * Preserve the operator's value on rollback.
     */
    public function down(): void {}
};
