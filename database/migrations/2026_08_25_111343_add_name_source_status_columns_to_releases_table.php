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
        Schema::table('releases', function (Blueprint $table): void {
            $table->tinyInteger('proc_xxx')
                ->default(0)
                ->after('proc_files');

            $table->tinyInteger('proc_media_movie')
                ->default(0)
                ->after('proc_uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['proc_xxx', 'proc_media_movie']);
        });
    }
};
