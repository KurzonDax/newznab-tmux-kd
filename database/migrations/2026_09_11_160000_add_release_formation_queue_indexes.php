<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['collections' => 'filecheck', 'releases' => 'nzbstatus'] as $table => $state) {
            $index = $table.'_formation_queue';
            if (Schema::hasIndex($table, $index)) {
                continue;
            }
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $wrapped = DB::connection()->getQueryGrammar()->wrapTable($table);
                DB::statement("ALTER TABLE {$wrapped} ADD INDEX {$index} (groups_id, {$state}, id), ALGORITHM=INPLACE, LOCK=NONE");
            } else {
                Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->index(['groups_id', $state, 'id'], $index));
            }
        }
    }

    public function down(): void
    {
        foreach (['collections', 'releases'] as $table) {
            Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropIndex($table.'_formation_queue'));
        }
    }
};
