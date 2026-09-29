<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the XXX > VR category (6046) that the adult sorter files releases into, where an
 * install lacks it. A database built from the schema dump has no XXX root yet; the
 * categories seeder supplies the row there, so the insert waits for the root.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('categories')->where('id', 6046)->exists()
            || ! DB::table('root_categories')->where('id', 6000)->exists()) {
            return;
        }

        DB::table('categories')->insert([
            'id' => 6046,
            'title' => 'VR',
            'root_categories_id' => 6000,
            'status' => 1,
            'description' => null,
            'minsizetoformrelease' => 0,
            'maxsizetoformrelease' => 0,
        ]);
    }

    public function down(): void
    {
        // The row stays: the adult sorter still files releases into it.
    }
};
