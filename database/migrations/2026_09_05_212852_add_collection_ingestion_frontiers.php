<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['last_seen_head_postdate' => 'last_seen_at', 'last_seen_tail_postdate' => 'last_seen_head_postdate'] as $column => $after) {
            if (Schema::hasTable('collections') && ! Schema::hasColumn('collections', $column)) {
                Schema::table('collections', function (Blueprint $table) use ($column, $after): void {
                    $table->dateTime($column)->nullable()->after($after);
                });
            }
        }
        if (Schema::hasTable('usenet_groups') && ! Schema::hasColumn('usenet_groups', 'backfill_settled_at')) {
            Schema::table('usenet_groups', function (Blueprint $table): void {
                $table->dateTime('backfill_settled_at')->nullable()->after('first_record_postdate');
            });
        }
    }

    public function down(): void
    {
        foreach (['last_seen_head_postdate', 'last_seen_tail_postdate'] as $column) {
            if (Schema::hasColumn('collections', $column)) {
                Schema::table('collections', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
        if (Schema::hasColumn('usenet_groups', 'backfill_settled_at')) {
            Schema::table('usenet_groups', function (Blueprint $table): void {
                $table->dropColumn('backfill_settled_at');
            });
        }
    }
};
