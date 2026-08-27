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
        Schema::create('release_video_clips', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('releases_id')->comment('FK to releases.id');
            $table->string('extension', 8);
            $table->string('mime', 32);
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamps();

            $table->unique('releases_id');

            $table->foreign('releases_id')->references('id')->on('releases')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_video_clips');
    }
};
