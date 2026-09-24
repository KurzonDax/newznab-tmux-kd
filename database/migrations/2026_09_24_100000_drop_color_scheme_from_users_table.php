<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coral is the only accent, so the per-user colour scheme is gone.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('color_scheme');
        });
    }

    /**
     * Restore the column as the original migration created it.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('color_scheme', 20)->default('blue')->after('theme_preference');
        });
    }
};
