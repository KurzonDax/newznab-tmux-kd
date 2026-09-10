<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->index(['groups_id', 'declaredfiles', 'fromname', 'filecheck', 'date', 'id'], 'collections_admission_window');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->dropIndex('collections_admission_window');
        });
    }
};
