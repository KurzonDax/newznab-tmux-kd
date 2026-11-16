<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database backups no longer come in Full and Daily kinds (ADR 0016).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('database_backups', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'status', 'finished_at']);
        });

        Schema::table('database_backups', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_backups', function (Blueprint $table): void {
            $table->string('kind', 10)->default('daily')->after('id');
            $table->index(['kind', 'status', 'finished_at']);
        });
    }
};
