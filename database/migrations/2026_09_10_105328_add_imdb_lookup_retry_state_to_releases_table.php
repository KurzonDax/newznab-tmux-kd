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
            $table->timestamp('imdb_lookup_attempted_at')->nullable();
            $table->unsignedTinyInteger('imdb_lookup_attempts')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['imdb_lookup_attempted_at', 'imdb_lookup_attempts']);
        });
    }
};
