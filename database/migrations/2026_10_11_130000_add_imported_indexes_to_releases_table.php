<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two indexes an Imported only releases list reads from (issue #1049): `is_imported`, the
 * sort's date and the id, then the columns the lists' other conditions test. The band, film and
 * game indexes do not hold `is_imported` and stay as they are.
 *
 * Both are added in one ALTER: the band lists force them by name, so a failure part-way must
 * never leave one missing.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const array INDEXES = [
        'ix_releases_imported_posted' => ['is_imported', 'postdate', 'id', 'category_band', 'categories_id', 'resolution', 'source', 'passwordstatus', 'videos_id', 'completion', 'groups_id'],
        'ix_releases_imported_added' => ['is_imported', 'adddate', 'id', 'category_band', 'categories_id', 'resolution', 'source', 'passwordstatus', 'videos_id', 'completion', 'groups_id'],
    ];

    public function up(): void
    {
        if (! $this->hasCategoryBand()) {
            return;
        }

        $changes = [];
        foreach (self::INDEXES as $name => $columns) {
            $changes[] = "ADD INDEX `{$name}` (`".implode('`, `', $columns).'`)';
        }

        $this->alter($changes);
    }

    public function down(): void
    {
        if (! $this->hasCategoryBand()) {
            return;
        }

        $this->alter(array_map(static fn (string $name): string => "DROP INDEX `{$name}`", array_keys(self::INDEXES)));
    }

    /** `category_band` exists only on MariaDB and MySQL. */
    private function hasCategoryBand(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @param list<string> $changes */
    private function alter(array $changes): void
    {
        DB::statement('ALTER TABLE `'.DB::getTablePrefix().'releases` '.implode(', ', $changes));
    }
};
