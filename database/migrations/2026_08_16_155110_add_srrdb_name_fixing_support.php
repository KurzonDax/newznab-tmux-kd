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
            $table->unsignedTinyInteger('proc_srrdb')
                ->default(0)
                ->after('proc_crc32')
                ->comment('SRRDB archive-CRC lookup state: 0 pending, 1 processed, 2 ambiguous');
        });

        Schema::create('srrdb_lookups', function (Blueprint $table): void {
            $table->string('crc32', 8)->primary();
            $table->string('status', 20);
            $table->json('payload')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index('checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('srrdb_lookups');

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn('proc_srrdb');
        });
    }
};
