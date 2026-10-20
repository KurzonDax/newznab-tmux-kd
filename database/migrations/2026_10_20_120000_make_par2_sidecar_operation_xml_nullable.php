<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('par2_sidecar_operations', function (Blueprint $table): void {
            $table->longText('target_xml')->nullable()->change();
            $table->longText('source_xml')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally empty. Finished and rename-only operations hold NULL NZB copies that
        // cannot be reconstructed, so both columns must stay nullable for the existing rows.
    }
};
