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
        Schema::table('predb', function (Blueprint $table): void {
            $table->dateTime('next_predb_search_at')->nullable()->after('searched');
            $table->index(
                ['searched', 'next_predb_search_at', 'predate', 'id'],
                'ix_predb_search_lifecycle',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('predb', function (Blueprint $table): void {
            $table->dropIndex('ix_predb_search_lifecycle');
            $table->dropColumn('next_predb_search_at');
        });
    }
};
