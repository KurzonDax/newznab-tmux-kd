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
        Schema::create('usenet_group_ingested_ranges', function (Blueprint $table) {
            $table->unsignedInteger('usenet_groups_id');
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate')->nullable();
            $table->primary(['usenet_groups_id', 'first_record']);
            $table->foreign('usenet_groups_id')->references('id')->on('usenet_groups')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usenet_group_ingested_ranges');
    }
};
