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
        Schema::create('collection_sweep_cursors', function (Blueprint $table): void {
            $table->string('scope', 96)->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->unsignedBigInteger('high_water_id')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_sweep_cursors');
    }
};
