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
        Schema::table('tv_info', function (Blueprint $table): void {
            $table->boolean('banner')
                ->default(false)
                ->after('image')
                ->comment('Does the video have a series banner?');
            $table->index('banner', 'ix_tv_info_banner');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tv_info', function (Blueprint $table): void {
            $table->dropIndex('ix_tv_info_banner');
            $table->dropColumn('banner');
        });
    }
};
