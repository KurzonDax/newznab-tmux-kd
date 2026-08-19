<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'ix_releases_fromname_postdate';

    public function up(): void
    {
        if (! Schema::hasTable('releases') || Schema::hasIndex('releases', self::INDEX)) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('CREATE INDEX `'.self::INDEX.'` ON `releases` (`fromname`(191), `postdate` DESC)');

            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->index(['fromname', 'postdate'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('releases') || ! Schema::hasIndex('releases', self::INDEX)) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
