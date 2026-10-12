<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Six settings rows nothing reads.
     *
     * The three trailer rows were fields on the Website settings page whose last reader went
     * with the old release page, so editing them changed nothing. The three pre ticket rows
     * have no reader either.
     *
     * @var list<string>
     */
    public const array DEAD_SETTINGS = [
        'trailers_display',
        'trailers_size_x',
        'trailers_size_y',
        'currentppticket',
        'lastpretime',
        'nextppticket',
    ];

    /**
     * Idempotent: a DELETE over a fixed name list is a no-op once the rows are gone.
     */
    public function up(): void
    {
        DB::table('settings')->whereIn('name', self::DEAD_SETTINGS)->delete();
    }

    /**
     * Deliberately irreversible: no code path reads these rows, so there is no value to restore.
     */
    public function down(): void
    {
        // No-op: these rows have no reader, so there is nothing to restore them for.
    }
};
