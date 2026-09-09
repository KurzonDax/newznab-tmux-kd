<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string OWNER = 'nntmux:post-processing-candidates:523';

    /** @var array<string, array<string, list<string>>> */
    private const array INDEXES = [
        'releases' => [
            'ix_releases_pp_pending_size' => ['passwordstatus', 'haspreview', 'nzbstatus', 'size'],
            'ix_releases_pp_declined_size' => ['additional_pp_claim_token', 'passwordstatus', 'haspreview', 'nzbstatus', 'size'],
        ],
        'releases_groups' => [
            'ix_releases_groups_group_release' => ['groups_id', 'releases_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['name'] === $name || array_slice($index['columns'], 0, count($columns)) === $columns) {
                        continue 2;
                    }
                }

                $grammar = DB::connection()->getQueryGrammar();
                $target = $grammar->wrapTable($table);
                $key = $grammar->wrap($name);
                $fields = $grammar->columnize($columns);
                if (DB::getDriverName() === 'sqlite') {
                    DB::statement('CREATE INDEX '.$key.' ON '.$target.' /* '.self::OWNER.' */ ('.$fields.')');
                } else {
                    DB::statement('ALTER TABLE '.$target.' ADD INDEX '.$key.' ('.$fields.") COMMENT '".self::OWNER."'");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! $this->ownsIndex($table, $name) || ! Schema::hasIndex($table, $columns)) {
                    continue;
                }
                Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }

    /**
     * Persist ownership in the index DDL so a fresh rollback process preserves
     * even a pre-existing index with our suggested name.
     */
    private function ownsIndex(string $table, string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]);

            return $row !== null && str_contains((string) $row->sql, '/* '.self::OWNER.' */');
        }

        return DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? AND index_comment = ? LIMIT 1',
            [DB::connection()->getDatabaseName(), DB::connection()->getTablePrefix().$table, $name, self::OWNER],
        ) !== null;
    }
};
