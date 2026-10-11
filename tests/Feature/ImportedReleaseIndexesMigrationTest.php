<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The two indexes an Imported only releases list reads from (issue #1049). */
final class ImportedReleaseIndexesMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_11_130000_add_imported_indexes_to_releases_table';

    public function test_the_schema_authority_records_both_indexes_in_the_stated_column_order_and_the_migration(): void
    {
        $dump = (string) file_get_contents(database_path('schema/mariadb-schema.sql'));
        $releases = strstr(strstr($dump, 'CREATE TABLE `releases` ('), ') ENGINE=', true);

        $this->assertStringContainsString(
            '  KEY `ix_releases_imported_posted` (`is_imported`,`postdate`,`id`,`category_band`,`categories_id`,`resolution`,`source`,`passwordstatus`,`videos_id`,`completion`,`groups_id`)',
            $releases,
        );
        $this->assertStringContainsString(
            '  KEY `ix_releases_imported_added` (`is_imported`,`adddate`,`id`,`category_band`,`categories_id`,`resolution`,`source`,`passwordstatus`,`videos_id`,`completion`,`groups_id`)',
            $releases,
        );
        $this->assertMatchesRegularExpression("/INSERT INTO `migrations` \\(`id`, `migration`, `batch`\\) VALUES \\(\\d+,'".self::MIGRATION."',\\d+\\);/", $dump);
    }

    public function test_a_database_without_the_category_band_is_left_as_it_is(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame([], $statements);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }
}
