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
        DB::table('settings')->insertOrIgnore(['name' => 'lookuppar2', 'value' => '1']);
    }

    /**
     * Preserve operator values for this previously supported legacy setting on rollback.
     */
    public function down(): void {}
};
