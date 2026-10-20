<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obfuscation_recovery_expired_headers', fn (Blueprint $table) => $table->index(['first_observed_at'], 'recovery_expired_age'));
    }

    public function down(): void
    {
        Schema::table('obfuscation_recovery_expired_headers', fn (Blueprint $table) => $table->dropIndex('recovery_expired_age'));
    }
};
