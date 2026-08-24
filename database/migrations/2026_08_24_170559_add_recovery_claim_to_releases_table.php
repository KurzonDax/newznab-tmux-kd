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
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('recovery_claimed_at')
                ->nullable()
                ->after('rescan_outcome')
                ->comment('Live lease shared by segment repair and whole-file header re-scan');

            $table->index('recovery_claimed_at', 'ix_releases_recovery_claim');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_recovery_claim');
            $table->dropColumn('recovery_claimed_at');
        });
    }
};
