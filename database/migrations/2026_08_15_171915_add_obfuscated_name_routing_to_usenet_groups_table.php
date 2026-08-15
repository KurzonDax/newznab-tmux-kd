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
            $table->boolean('route_obfuscated_names')->default(false)->after('backfill_target');
            $table->foreignId('obfuscated_default_root_categories_id')
                ->nullable()
                ->after('route_obfuscated_names')
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
            $table->dropConstrainedForeignId('obfuscated_default_root_categories_id');
            $table->dropColumn('route_obfuscated_names');
        });
    }
};
