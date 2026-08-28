<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable attempted-failure counter for duplicate absorption: a preserved
     * collection whose absorb keeps failing settles as an ordinary duplicate
     * once the counter reaches the cap, instead of retrying every cycle
     * forever. Deferred absorbs never increment it.
     */
    public function up(): void
    {
        if (! Schema::hasTable('collections') || Schema::hasColumn('collections', 'absorb_attempts')) {
            return;
        }

        // No ->after(): appending keeps the ADD COLUMN instant on every
        // MariaDB version; mid-table placement is instant only on >= 10.4.
        Schema::table('collections', function (Blueprint $table): void {
            $table->unsignedTinyInteger('absorb_attempts')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('collections') || ! Schema::hasColumn('collections', 'absorb_attempts')) {
            return;
        }

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropColumn('absorb_attempts');
        });
    }
};
