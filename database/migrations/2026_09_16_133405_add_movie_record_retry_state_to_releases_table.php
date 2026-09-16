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
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('movie_record_lookup_attempted_at')->nullable();
            $table->unsignedTinyInteger('movie_record_lookup_attempts')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['movie_record_lookup_attempted_at', 'movie_record_lookup_attempts']);
        });
    }
};
