<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obfuscation_recovery_gaps', function (Blueprint $table): void {
            $table->unsignedTinyInteger('retries')->default(0)->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('obfuscation_recovery_gaps', function (Blueprint $table): void {
            $table->dropColumn('retries');
        });
    }
};
