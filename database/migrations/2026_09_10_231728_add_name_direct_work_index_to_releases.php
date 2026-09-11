<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        Schema::table('releases', function (Blueprint $table): void {
            $table->boolean('name_direct_work_pending')->virtualAs('(nfostatus = 1 AND proc_nfo = 0) OR proc_files = 0 OR (nzbstatus = 1 AND proc_par2 = 0) OR proc_srr = 0 OR proc_hash16k = 0 OR proc_crc32 = 0');
            $table->boolean('name_evidence_work_pending')->virtualAs('proc_xxx = 0 OR proc_uid = 0 OR proc_media_movie = 0 OR proc_srrdb = 0');
            $table->index(['name_evidence_work_pending', 'isrenamed', 'predb_id', 'leftguid', 'id'], 'releases_name_evidence_work');
            $table->index(['name_direct_work_pending', 'isrenamed', 'predb_id', 'leftguid', 'id'], 'releases_name_direct_work');
        });
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('releases_name_direct_work');
            $table->dropIndex('releases_name_evidence_work');
            $table->dropColumn(['name_direct_work_pending', 'name_evidence_work_pending']);
        });
    }
};
