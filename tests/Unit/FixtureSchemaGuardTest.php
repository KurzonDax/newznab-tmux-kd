<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixtureSchemaGuard;
use Tests\Support\SchemaAuthority;

final class FixtureSchemaGuardTest extends TestCase
{
    public function test_catches_the_680_loop_fixture_and_accepts_its_production_identity(): void
    {
        $guard = new FixtureSchemaGuard((new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables());
        $pdo = new PDO('sqlite::memory:');
        foreach (['video_data', 'audio_data'] as $table) {
            $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, releases_id INTEGER)");
        }
        $violations = $guard->inspect($pdo);
        self::assertSame(['R1', 'R2', 'R3'], array_column($violations, 'rule'));
        self::assertSame(['video_data'], array_values(array_unique(array_column($violations, 'table'))));
        self::assertSame('id', $violations[0]['subject']);
        self::assertStringContainsString('releases_id', $violations[0]['authority']);
        $pdo->exec('DROP TABLE video_data');
        $pdo->exec('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY)');
        self::assertSame([], $guard->inspect($pdo));
    }

    public function test_all_unique_keys_and_partial_fixtures_follow_the_production_cardinality(): void
    {
        $guard = new FixtureSchemaGuard((new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables());
        foreach ([
            ['CREATE TABLE video_data (releases_id INTEGER)', ['R2']],
            ['CREATE TABLE releases (id INTEGER PRIMARY KEY)', []],
            ['CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT)', ['R2']],
            ['CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT UNIQUE, collectionhash TEXT UNIQUE)', []],
            ['CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE)', ['R3']],
            ['CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER)', []],
            ['CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER UNIQUE)', ['R3']],
            ['CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER, audioid INTEGER)', ['R2']],
            ['CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER, audioid INTEGER, UNIQUE (releases_id, audioid))', []],
            ['CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY) WITHOUT ROWID', []],
        ] as [$sql, $expected]) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec($sql);
            self::assertSame($expected, array_column($guard->inspect($pdo), 'rule'), $sql);
        }
    }

    public function test_unknown_tables_require_a_local_declaration_that_cannot_exempt_production_tables(): void
    {
        $guard = new FixtureSchemaGuard((new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables());
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE purpose_built (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        self::assertSame(['R4'], array_column($guard->inspect($pdo), 'rule'));
        self::assertSame([], $guard->inspect($pdo, ['purpose_built']));
        $pdo->exec('CREATE TEMP TABLE video_data (releases_id INTEGER)');
        self::assertSame(['R2'], array_column($guard->inspect($pdo, ['purpose_built', 'video_data']), 'rule'));
    }

    public function test_memoization_tracks_index_changes_and_partial_indexes_cannot_satisfy_real_keys(): void
    {
        $guard = new FixtureSchemaGuard((new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables());
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_data (releases_id INTEGER)');
        self::assertSame(['R2'], array_column($guard->inspect($pdo), 'rule'));
        self::assertSame(['R2'], array_column($guard->inspect($pdo), 'rule'));
        self::assertSame(1, $guard->validations);
        $pdo->exec('CREATE UNIQUE INDEX video_identity ON video_data(releases_id) WHERE releases_id > 0');
        self::assertSame(['R2'], array_column($guard->inspect($pdo), 'rule'));
        $pdo->exec('DROP INDEX video_identity');
        $pdo->exec('CREATE UNIQUE INDEX video_identity ON video_data(releases_id)');
        self::assertSame([], $guard->inspect($pdo));
        self::assertSame(3, $guard->validations);
        $pdo->exec('DROP INDEX video_identity');
        self::assertSame(['R2'], array_column($guard->inspect($pdo), 'rule'));
        self::assertSame(3, $guard->validations);
    }

    public function test_auto_increment_identity_is_checked_even_with_a_composite_primary_key(): void
    {
        $guard = new FixtureSchemaGuard(['example' => ['columns' => ['id', 'scope'], 'primary' => ['id', 'scope'], 'uniques' => [], 'autoIncrement' => 'id']]);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE example (id INTEGER, scope INTEGER, PRIMARY KEY(id, scope))');
        $violations = $guard->inspect($pdo);
        self::assertSame(['R2'], array_column($violations, 'rule'));
        self::assertSame('(id)', $violations[0]['subject']);
        $pdo->exec('CREATE UNIQUE INDEX identity ON example(id)');
        self::assertSame([], $guard->inspect($pdo));
    }

    public function test_reads_which_tables_a_statement_destroys_creates_and_reshapes(): void
    {
        foreach ([
            'DROP TABLE video_data' => [[[null, 'video_data']], [], []],
            'drop table if exists "main"."video_data"' => [[['main', 'video_data']], [], []],
            'DROP TEMPORARY TABLE IF EXISTS `x_releases`, `x_parts`' => [[[null, 'x_releases'], [null, 'x_parts']], [], []],
            'alter table "__temp__releases" rename to "releases"' => [[[null, '__temp__releases']], ['releases'], ['__temp__releases']],
            'ALTER TABLE releases RENAME COLUMN guid TO old_guid' => [[], [], ['releases']],
            'RENAME TABLE a TO b, `fixture`.c TO d' => [[[null, 'a'], ['fixture', 'c']], ['b', 'd'], []],
            'create table if not exists "main"."video_data" ("releases_id" integer)' => [[], ['video_data'], []],
            'CREATE OR REPLACE TEMPORARY TABLE `x_releases` (id INT)' => [[[null, 'x_releases']], ['x_releases'], []],
            'CREATE UNIQUE INDEX video_identity ON video_data(releases_id)' => [[], [], ['video_data']],
            'DROP INDEX video_identity ON `video_data`' => [[], [], ['video_data']],
            'DROP INDEX video_identity' => [[], [], []],
            'DROP DATABASE IF EXISTS `tv_browse_0123`' => [[['tv_browse_0123', null]], [], []],
            'DETACH DATABASE research' => [[['research', null]], [], []],
            "delete from \"main\".sqlite_master where type in ('table', 'index', 'trigger')" => [[['main', null]], [], []],
            "SET NAMES utf8mb4;\nDROP TABLE IF EXISTS `anidb_info`;\nCREATE TABLE `anidb_info` (id INT)" => [[[null, 'anidb_info']], ['anidb_info'], []],
            'SELECT * FROM releases WHERE name = "drop table releases"' => [[], [], []],
        ] as $sql => [$destroyed, $created, $reshaped]) {
            self::assertSame([
                'destroyed' => array_map(static fn (array $target): array => ['database' => $target[0], 'table' => $target[1]], $destroyed),
                'created' => $created,
                'reshaped' => $reshaped,
            ], FixtureSchemaGuard::schemaChanges($sql), $sql);
        }
    }

    public function test_compares_prefixed_tables_by_their_production_name(): void
    {
        $guard = new FixtureSchemaGuard((new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables());
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE fixture_video_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        $pdo->exec('CREATE TABLE fixture_audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        $result = $guard->examine($pdo, 'fixture_');
        self::assertSame(['audio_data', 'video_data'], $result['tables']);
        self::assertSame(['R1', 'R2', 'R3'], array_column($result['violations'], 'rule'));
        self::assertSame([], $guard->examine($pdo, 'fixture_', ['audio_data'])['violations']);
        self::assertSame(['R4', 'R4'], array_column($guard->examine($pdo)['violations'], 'rule'));
    }

    public function test_attached_databases_cannot_hide_production_or_unknown_tables(): void
    {
        $guard = new FixtureSchemaGuard((new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables());
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('ATTACH DATABASE \':memory:\' AS "research""fixture"');
        $pdo->exec('CREATE TABLE "research""fixture".video_data (releases_id INTEGER)');
        $pdo->exec('CREATE TABLE "research""fixture".invented_table (id INTEGER PRIMARY KEY)');
        $violations = $guard->inspect($pdo);
        self::assertSame(['R4', 'R2'], array_column($violations, 'rule'));
        self::assertSame(['research"fixture'], array_values(array_unique(array_column($violations, 'database'))));
        $pdo->exec('CREATE UNIQUE INDEX "research""fixture".video_identity ON video_data(releases_id)');
        self::assertSame([], $guard->inspect($pdo, ['invented_table']));
    }
}
