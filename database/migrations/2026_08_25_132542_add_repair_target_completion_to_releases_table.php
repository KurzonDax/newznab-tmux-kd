<?php

use App\Enums\ReleaseRepairOutcome;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record the policy target each successful recovery verdict was judged under.
 *
 * A `repaired` outcome is only meaningful relative to `completionpercent`. Persisting that target
 * lets the candidate queries reopen successful releases only when an operator raises the policy.
 * Existing successes are stamped at the live target so deploying this migration does not create a
 * mass recovery backlog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->double('repair_target_completion')
                ->nullable()
                ->after('repair_outcome')
                ->comment('Completion target this repaired verdict was judged under; null for other outcomes');
            $table->double('rescan_target_completion')
                ->nullable()
                ->after('rescan_outcome')
                ->comment('Completion target this repaired verdict was judged under; null for other outcomes');
        });

        $configuredTarget = (float) DB::table('settings')
            ->where('name', 'completionpercent')
            ->value('value');
        $targetCompletion = $configuredTarget > 0
            ? $configuredTarget
            : ReleaseRepairOptions::DEFAULT_TARGET_COMPLETION;

        DB::table('releases')
            ->where('repair_outcome', ReleaseRepairOutcome::Repaired->value)
            ->update(['repair_target_completion' => $targetCompletion]);
        DB::table('releases')
            ->where('rescan_outcome', ReleaseRepairOutcome::Repaired->value)
            ->update(['rescan_target_completion' => $targetCompletion]);
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['repair_target_completion', 'rescan_target_completion']);
        });
    }
};
