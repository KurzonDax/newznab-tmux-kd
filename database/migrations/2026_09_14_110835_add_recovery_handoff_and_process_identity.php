<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('obfuscation_recovery_attempts', 'handoff')) {
            Schema::table('obfuscation_recovery_attempts', function (Blueprint $table): void {
                $table->json('handoff')->nullable();
                $table->boolean('handoff_conflict')->default(false);
            });
        }
        foreach (['obfuscation_recovery_slots' => 'owner_', 'obfuscation_recovery_work' => 'claim_owner_'] as $name => $prefix) {
            if (! Schema::hasColumn($name, $prefix.'machine')) {
                Schema::table($name, function (Blueprint $table) use ($prefix): void {
                    $table->char($prefix.'machine', 64)->nullable();
                    $table->char($prefix.'boot', 64)->nullable();
                    $table->char($prefix.'namespace', 64)->nullable();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Ownership proof survives rollback; legacy opaque owners remain unknown.
    }
};
