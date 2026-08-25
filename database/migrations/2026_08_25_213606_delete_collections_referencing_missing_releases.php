<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collections') || ! Schema::hasTable('releases')) {
            return;
        }

        DB::table('collections')
            ->whereNotNull('releases_id')
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('releases')
                    ->whereColumn('releases.id', 'collections.releases_id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Deleted staging rows cannot be reconstructed.
    }
};
