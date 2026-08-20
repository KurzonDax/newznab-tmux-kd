<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair state for the completion sweep.
 *
 * The sweep used to delete any release measured below `completionpercent`. Many of those are
 * recoverable -- the articles are still on the provider, only the headers were missed -- so the
 * sweep now waits for a *final* repair outcome. Both columns are load-bearing: the sweep reads
 * `repair_outcome`, and the retry pass reads both to find releases whose retry window has passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('repair_attempted_at')
                ->nullable()
                ->after('completion')
                ->comment('When the repair engine last worked this release (both passes stamp it)');

            $table->string('repair_outcome', 16)
                ->nullable()
                ->after('repair_attempted_at')
                ->comment('retry-pending | repaired | failed | skipped-floor; null = never offered to repair');

            // The sweep: outcome first (it is the gate), then completion for the threshold range.
            $table->index(['repair_outcome', 'completion'], 'ix_releases_repair_sweep');

            // The retry pass: due `retry-pending` releases, oldest attempt first.
            $table->index(['repair_outcome', 'repair_attempted_at'], 'ix_releases_repair_retry');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_repair_sweep');
            $table->dropIndex('ix_releases_repair_retry');
            $table->dropColumn(['repair_attempted_at', 'repair_outcome']);
        });
    }
};
