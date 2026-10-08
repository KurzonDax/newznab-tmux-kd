<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segment repair and the header re-scan are gone, so their per-release state goes with them.
 *
 * `down()` re-adds the columns and indexes as the three migrations that created them defined
 * them. It restores no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_repair_sweep');
            $table->dropIndex('ix_releases_repair_retry');
            $table->dropIndex('ix_releases_rescan_sweep');
            $table->dropIndex('ix_releases_rescan_retry');
            $table->dropColumn([
                'repair_attempted_at',
                'repair_outcome',
                'repair_target_completion',
                'repair_evaluated_target_completion',
                'rescan_attempted_at',
                'rescan_outcome',
                'rescan_target_completion',
                'rescan_evaluated_target_completion',
            ]);
        });
    }

    public function down(): void
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

            $table->index(['repair_outcome', 'completion'], 'ix_releases_repair_sweep');
            $table->index(['repair_outcome', 'repair_attempted_at'], 'ix_releases_repair_retry');
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('rescan_attempted_at')
                ->nullable()
                ->after('repair_outcome')
                ->comment('When the header re-scan last worked this release (both passes stamp it)');

            $table->string('rescan_outcome', 16)
                ->nullable()
                ->after('rescan_attempted_at')
                ->comment('retry-pending | repaired | failed | skipped-floor | skipped-budget; null = never re-scanned');

            $table->index(['rescan_outcome', 'completion'], 'ix_releases_rescan_sweep');
            $table->index(['rescan_outcome', 'rescan_attempted_at'], 'ix_releases_rescan_retry');
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->double('repair_target_completion')
                ->nullable()
                ->after('repair_outcome')
                ->comment('Completion target achieved by this repaired verdict; null for other outcomes');
            $table->double('repair_evaluated_target_completion')
                ->nullable()
                ->after('repair_target_completion')
                ->comment('Latest completion target evaluated by segment repair');
            $table->double('rescan_target_completion')
                ->nullable()
                ->after('rescan_outcome')
                ->comment('Completion target achieved by this repaired verdict; null for other outcomes');
            $table->double('rescan_evaluated_target_completion')
                ->nullable()
                ->after('rescan_target_completion')
                ->comment('Latest completion target evaluated by header re-scan');
        });
    }
};
