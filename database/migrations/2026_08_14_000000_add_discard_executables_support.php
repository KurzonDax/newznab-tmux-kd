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
            $table->boolean('discard_executables')->default(false)->after('disablepreview');
        });

        // Executables are never legitimate in these roots; ship with discarding on.
        // Other (1), Console (1000) and PC (4000) keep the default (off).
        DB::table('root_categories')
            ->whereIn('id', [2000, 3000, 5000, 6000, 7000])
            ->update(['discard_executables' => true]);

        DB::table('settings')->insertOrIgnore([
            'name' => 'discard_executable_extensions',
            'value' => 'dll|exe|msi|scr|com|bat|cmd|pif',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('root_categories', function (Blueprint $table): void {
            $table->dropColumn('discard_executables');
        });

        DB::table('settings')->where('name', 'discard_executable_extensions')->delete();
    }
};
