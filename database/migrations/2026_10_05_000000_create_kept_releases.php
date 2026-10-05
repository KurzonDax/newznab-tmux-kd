<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kept_releases', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->timestamp('created_at')->nullable();
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kept_releases');
    }
};
