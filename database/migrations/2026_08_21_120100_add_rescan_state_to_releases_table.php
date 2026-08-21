<?php

use App\Enums\ReleaseRepairOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-scan state, alongside (not instead of) the repair state.
 *
 * The repair engine recovers files with at least one seen segment; the header re-scan recovers
 * files with none. They run as separate passes over the same release, so they keep separate state:
 * a release can be `failed` for repair and still owe the re-scan its turn, and the sweep must wait
 * for both. The values are the same {@see ReleaseRepairOutcome} enum, plus
 * `skipped-budget` for a window too wide to be worth fetching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('rescan_attempted_at')
                ->nullable()
                ->after('repair_outcome')
                ->comment('When the header re-scan last worked this release (both passes stamp it)');

            $table->string('rescan_outcome', 16)
                ->nullable()
                ->after('rescan_attempted_at')
                ->comment('retry-pending | repaired | failed | skipped-floor | skipped-budget; null = never re-scanned');

            // The sweep: outcome first (it is the gate), then completion for the threshold range.
            $table->index(['rescan_outcome', 'completion'], 'ix_releases_rescan_sweep');

            // The retry pass: due `retry-pending` releases, oldest attempt first.
            $table->index(['rescan_outcome', 'rescan_attempted_at'], 'ix_releases_rescan_retry');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_rescan_sweep');
            $table->dropIndex('ix_releases_rescan_retry');
            $table->dropColumn(['rescan_attempted_at', 'rescan_outcome']);
        });
    }
};
