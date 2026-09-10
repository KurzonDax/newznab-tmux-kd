<?php

declare(strict_types=1);

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
        Schema::create('reconciliation_budget_deferrals', function (Blueprint $table): void {
            $table->string('bucket', 32);
            $table->string('decision_id', 64);
            $table->primary(['bucket', 'decision_id']);
        });
        if (Schema::hasTable('settings')) {
            DB::table('settings')->insertOrIgnore([
                ['name' => 'reconciliation_hourly_mib', 'value' => '256'],
                ['name' => 'reconciliation_daily_mib', 'value' => '2048'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_budget_deferrals');
    }
};
