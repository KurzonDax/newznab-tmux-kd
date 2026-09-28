<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Filter-led indexes on `releases` for every category (Category, Resolution and Source,
 * each by Posted and Added), with `completion` held in them, and the band, count and
 * per-film indexes extended at the end so every query that uses them today still can.
 *
 * Every change is one ALTER: the TV list forces the band indexes by name, so a failure
 * part-way must never leave one missing. Alter `releases` with
 * docs/releases-table-optimization.md.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const array ADDED = [
        'ix_releases_band_cat_posted' => ['category_band', 'categories_id', 'postdate', 'id', 'resolution', 'source', 'passwordstatus', 'completion'],
        'ix_releases_band_cat_added' => ['category_band', 'categories_id', 'adddate', 'id', 'resolution', 'source', 'passwordstatus', 'completion'],
        'ix_releases_band_res_posted' => ['category_band', 'resolution', 'postdate', 'id', 'source', 'categories_id', 'passwordstatus', 'completion'],
        'ix_releases_band_res_added' => ['category_band', 'resolution', 'adddate', 'id', 'source', 'categories_id', 'passwordstatus', 'completion'],
        'ix_releases_band_src_posted' => ['category_band', 'source', 'postdate', 'id', 'resolution', 'categories_id', 'passwordstatus', 'completion'],
        'ix_releases_band_src_added' => ['category_band', 'source', 'adddate', 'id', 'resolution', 'categories_id', 'passwordstatus', 'completion'],
    ];

    /** @var array<string, array{before: list<string>, after: list<string>}> */
    private const array EXTENDED = [
        'ix_releases_band_posted' => [
            'before' => ['category_band', 'postdate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus'],
            'after' => ['category_band', 'postdate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus', 'videos_id', 'completion'],
        ],
        'ix_releases_band_added' => [
            'before' => ['category_band', 'adddate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus'],
            'after' => ['category_band', 'adddate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus', 'videos_id', 'completion'],
        ],
        'ix_releases_band_count' => [
            'before' => ['category_band', 'resolution', 'source', 'categories_id', 'passwordstatus'],
            'after' => ['category_band', 'resolution', 'source', 'categories_id', 'passwordstatus', 'completion'],
        ],
    ];

    /** Made by 2025_12_22_000000_add_additional_performance_indexes_to_releases_table.php only when no index held its columns. */
    private const string PER_FILM = 'ix_releases_movieinfo_cat';

    /** @var array{before: list<string>, after: list<string>} */
    private const array PER_FILM_COLUMNS = [
        'before' => ['movieinfo_id', 'categories_id', 'passwordstatus', 'postdate'],
        'after' => ['movieinfo_id', 'categories_id', 'passwordstatus', 'postdate', 'adddate', 'resolution', 'source', 'completion'],
    ];

    public function up(): void
    {
        if (! $this->hasCategoryBand()) {
            return;
        }

        $indexes = $this->indexes();
        $changes = [];
        foreach (self::EXTENDED as $name => $columns) {
            $changes[] = $this->drop($name);
            $changes[] = $this->add($name, $columns['after']);
        }
        if (isset($indexes[self::PER_FILM])) {
            $changes[] = $this->drop(self::PER_FILM);
        }
        $changes[] = $this->add(self::PER_FILM, self::PER_FILM_COLUMNS['after']);
        foreach (self::ADDED as $name => $columns) {
            $changes[] = $this->add($name, $columns);
        }

        $this->alter($changes);
    }

    public function down(): void
    {
        if (! $this->hasCategoryBand()) {
            return;
        }

        $indexes = $this->indexes();
        $changes = [];
        foreach (array_keys(self::ADDED) as $name) {
            $changes[] = $this->drop($name);
        }
        foreach (self::EXTENDED as $name => $columns) {
            $changes[] = $this->drop($name);
            $changes[] = $this->add($name, $columns['before']);
        }
        if (isset($indexes[self::PER_FILM])) {
            $changes[] = $this->drop(self::PER_FILM);
        }
        if (! in_array(self::PER_FILM_COLUMNS['before'], array_diff_key($indexes, [self::PER_FILM => true]), true)) {
            $changes[] = $this->add(self::PER_FILM, self::PER_FILM_COLUMNS['before']);
        }

        $this->alter($changes);
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

    private function drop(string $name): string
    {
        return "DROP INDEX `{$name}`";
    }

    /** @param list<string> $columns */
    private function add(string $name, array $columns): string
    {
        return "ADD INDEX `{$name}` (`".implode('`, `', $columns).'`)';
    }

    /**
     * Each index on `releases` with its columns in order.
     *
     * @return array<string, list<string>>
     */
    private function indexes(): array
    {
        $indexes = [];
        foreach (DB::select('SHOW INDEX FROM `'.DB::getTablePrefix().'releases`') as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }

        return array_map(static function (array $columns): array {
            ksort($columns);

            return array_values($columns);
        }, $indexes);
    }
};
