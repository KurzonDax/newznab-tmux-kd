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
            $table->boolean('generate_previews')->default(true)->after('discard_executables');
        });

        // Shipping changes nothing: every existing root keeps generation on
        // until an operator flips it off.
        DB::table('root_categories')->update(['generate_previews' => true]);

        // The dormant legacy columns are removed, not extended: the
        // root_categories flag had no runtime reader, and the categories flag
        // was hard-coded off by the admin UI on every save.
        Schema::table('root_categories', function (Blueprint $table): void {
            $table->dropColumn('disablepreview');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('disablepreview');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('disablepreview')->default(false);
        });

        Schema::table('root_categories', function (Blueprint $table): void {
            $table->boolean('disablepreview')->default(false);
        });

        Schema::table('root_categories', function (Blueprint $table): void {
            $table->dropColumn('generate_previews');
        });
    }
};
