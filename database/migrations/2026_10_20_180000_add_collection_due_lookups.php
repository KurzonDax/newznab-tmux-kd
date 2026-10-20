<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formation and stuck maintenance find due collections by index lookups instead of walking every
 * waiting collection. The head index serves the frontier-due lookup; the cursor columns hold each
 * group and state's lookup position and watermarks, and when its hourly safety-net walk started.
 */
return new class extends Migration
{
    private const string INDEX = 'ix_collections_group_filecheck_head_id';

    private const array CURSOR_COLUMNS = ['pass_started_at', 'lookup_stage', 'lookup_stamp', 'lookup_id',
        'lookup_started_at', 'touched_since', 'late_since'];

    public function up(): void
    {
        if (Schema::hasColumn('collections', 'last_seen_head_postdate') && ! Schema::hasIndex('collections', self::INDEX)) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $wrapped = DB::connection()->getQueryGrammar()->wrapTable('collections');
                DB::statement("ALTER TABLE {$wrapped} ADD INDEX ".self::INDEX.' (groups_id, filecheck, last_seen_head_postdate, id), ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                Schema::table('collections', static fn (Blueprint $table) => $table->index(
                    ['groups_id', 'filecheck', 'last_seen_head_postdate', 'id'], self::INDEX));
            }
        }

        if (Schema::hasColumn('collection_sweep_cursors', 'pass_started_at')) {
            return;
        }
        Schema::table('collection_sweep_cursors', static function (Blueprint $table): void {
            $table->dateTime('pass_started_at')->nullable()->comment('When the current or last safety-net walk pass started');
            $table->unsignedTinyInteger('lookup_stage')->nullable()->comment('Lookup the current pass is serving');
            $table->dateTime('lookup_stamp')->nullable()->comment('Stamp of the last row the current lookup returned');
            $table->unsignedBigInteger('lookup_id')->nullable()->comment('ID of the last row the current lookup returned');
            $table->dateTime('lookup_started_at')->nullable()->comment('Database clock when the current lookup pass started');
            $table->dateTime('touched_since')->nullable()->comment('Rows stamped at or after this are touched since the last pass');
            $table->dateTime('late_since')->nullable()->comment('Rows stamped at or after this have not yet crossed the late line');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('collection_sweep_cursors', 'pass_started_at')) {
            Schema::table('collection_sweep_cursors', static fn (Blueprint $table) => $table->dropColumn(self::CURSOR_COLUMNS));
        }
        if (Schema::hasIndex('collections', self::INDEX)) {
            Schema::table('collections', static fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
