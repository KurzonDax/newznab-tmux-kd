<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('release_music_identifications')) {
            return;
        }

        // Dropping and re-adding the same constraint name in one ALTER TABLE fails on MariaDB with errno 121.
        $this->dropForeignKeyIfExists('release_music_identifications', 'FK_rmi_supersedes');

        DB::statement(
            'ALTER TABLE `release_music_identifications`
                ADD CONSTRAINT `FK_rmi_supersedes`
                FOREIGN KEY (`supersedes_id`)
                REFERENCES `release_music_identifications` (`id`)
                ON DELETE SET NULL
                ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('release_music_identifications')) {
            return;
        }

        $this->dropForeignKeyIfExists('release_music_identifications', 'FK_rmi_supersedes');

        DB::statement(
            'ALTER TABLE `release_music_identifications`
                ADD CONSTRAINT `FK_rmi_supersedes`
                FOREIGN KEY (`supersedes_id`)
                REFERENCES `release_music_identifications` (`id`)
                ON DELETE RESTRICT
                ON UPDATE CASCADE'
        );
    }

    private function dropForeignKeyIfExists(string $table, string $constraintName): void
    {
        $exists = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_TYPE = "FOREIGN KEY"
               AND TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?',
            [$table, $constraintName]
        );

        if ($exists !== []) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraintName}`");
        }
    }
};
