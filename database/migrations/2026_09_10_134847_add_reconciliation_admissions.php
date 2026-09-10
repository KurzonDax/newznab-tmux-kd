<?php

declare(strict_types=1);

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
        Schema::create('reconciliation_decisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('base_collection_id')->primary();
            $table->string('decision_id', 64);
        });
        Schema::create('reconciliation_admissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('collection_id')->primary();
            $table->string('decision_id', 64)->index();
            $table->string('revision', 64);
            $table->timestamp('admitted_at');
            $table->timestamp('expires_at')->index();
            $table->string('state', 24)->default('admitted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_admissions');
        Schema::dropIfExists('reconciliation_decisions');
    }
};
