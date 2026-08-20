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
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->foreignId('forced_root_categories_id')
                ->nullable()
                ->after('obfuscated_default_root_categories_id')
                ->constrained('root_categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('forced_root_categories_id');
        });
    }
};
