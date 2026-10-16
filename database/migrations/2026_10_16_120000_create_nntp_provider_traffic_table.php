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
        // Bytes read from each Usenet provider per day (app timezone), for the Statistics
        // page. One row per provider NAME per day; NntpTrafficMeter adds to it.
        Schema::create('nntp_provider_traffic', function (Blueprint $table) {
            $table->string('provider', 64);
            $table->date('day');
            $table->unsignedBigInteger('bytes_received')->default(0);

            $table->primary(['provider', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nntp_provider_traffic');
    }
};
