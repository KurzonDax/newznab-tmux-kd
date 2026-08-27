<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('root_categories', function (Blueprint $table): void {
            $table->boolean('dynamic_preview_budget')->default(false)->after('generate_previews');
        });

        // Default: enabled for the XXX root only. Every other root — and
        // any eligible root toggled off — keeps the fixed-count behavior.
        DB::table('root_categories')->where('id', 6000)->update(['dynamic_preview_budget' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('root_categories', function (Blueprint $table): void {
            $table->dropColumn('dynamic_preview_budget');
        });
    }
};
