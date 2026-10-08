<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which source set the current search name (issue #309): the name-fixing type that renamed the
     * release ("Audio tags", "NFO", "MusicBrainz", ...) or "Manual" for an admin edit. Existing rows
     * stay NULL: their source was never recorded, and an unrecorded trusted name stays protected.
     */
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->string('name_source', 64)->nullable()->after('is_trusted_name')
                ->comment('Source that set the current search name; NULL when unrecorded');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn('name_source');
        });
    }
};
