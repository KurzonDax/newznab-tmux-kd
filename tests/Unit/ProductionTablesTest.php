<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FixtureSchemaGuard;
use Tests\Support\ProductionTables;
use Tests\Support\SchemaAuthority;

final class ProductionTablesTest extends TestCase
{
    /** @return array<string, array{string, ?list<string>}> */
    public static function fixtures(): array
    {
        return [
            'releases' => ['releases', null],
            'releases subset' => ['releases', ['id', 'guid', 'searchname', 'categories_id', 'postdate']],
            'usenet_groups' => ['usenet_groups', null],
            'usenet_groups subset' => ['usenet_groups', ['id', 'name', 'active']],
            'users' => ['users', null],
            'users subset' => ['users', ['id', 'username', 'email', 'api_token']],
            'users without identity' => ['users', ['username', 'email']],
            'categories' => ['categories', null],
            'categories subset' => ['categories', ['id', 'title', 'root_categories_id']],
            'root_categories' => ['root_categories', null],
            'root_categories subset' => ['root_categories', ['id', 'generate_clips']],
            'settings' => ['settings', null],
            'settings subset' => ['settings', ['name']],
        ];
    }

    /** @param ?list<string> $columns */
    #[DataProvider('fixtures')]
    public function test_builder_tables_satisfy_the_guard(string $table, ?array $columns): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(ProductionTables::fromAuthority()->createStatement($table, $columns));

        self::assertSame([], $this->guard()->inspect($pdo));
        $expected = $columns ?? $this->authority()[$table]['columns'];
        self::assertEqualsCanonicalizing($expected, array_column($pdo->query("PRAGMA table_xinfo($table)")->fetchAll(PDO::FETCH_ASSOC), 'name'));
    }

    public function test_release_guid_is_unique_only_when_the_subset_includes_it(): void
    {
        $tables = ProductionTables::fromAuthority();
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($tables->createStatement('releases', ['id', 'guid', 'name']));
        self::assertSame([['guid']], $this->uniqueIndexes($pdo, 'releases'));
        $pdo->exec("INSERT INTO releases (guid) VALUES ('same')");
        try {
            $pdo->exec("INSERT INTO releases (guid) VALUES ('same')");
            self::fail('A second release with the same guid was accepted.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('UNIQUE constraint failed: releases.guid', $exception->getMessage());
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($tables->createStatement('releases', ['id', 'name']));
        self::assertSame([], $this->uniqueIndexes($pdo, 'releases'));
    }

    public function test_ordinary_values_round_trip_and_production_defaults_apply(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(ProductionTables::fromAuthority()->createStatement('releases'));
        $pdo->prepare('INSERT INTO releases (guid, leftguid, name, size, postdate, fromname, nfostatus) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['abc', 'a', 'Name', 524288000, '2026-09-17 12:00:00', null, 1]);

        $row = $pdo->query('SELECT * FROM releases')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, $row['id']);
        self::assertSame(524288000, $row['size']);
        self::assertSame('2026-09-17 12:00:00', $row['postdate']);
        self::assertNull($row['fromname']);
        self::assertSame('', $row['searchname']);
        self::assertSame(10, $row['categories_id']);
        self::assertSame(-1, $row['passwordstatus']);
        self::assertSame(0.0, $row['completion']);
        self::assertSame(1, $row['name_direct_work_pending'], 'Generated columns follow the production expression.');
    }

    public function test_quoted_and_timestamp_defaults_follow_the_authority(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($this->builder(<<<'SQL'
            CREATE TABLE `widgets` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `label` varchar(20) NOT NULL DEFAULT 'it''s \\ fine' COMMENT 'DEFAULT 5 is only a comment',
              `status` enum('new','DEFAULT old') NOT NULL DEFAULT 'new',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `ratio` decimal(5,2) NOT NULL DEFAULT 100.00,
              `note` text DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;
            SQL)->createStatement('widgets'));
        $pdo->exec('INSERT INTO widgets DEFAULT VALUES');

        $row = $pdo->query('SELECT * FROM widgets')->fetch(PDO::FETCH_ASSOC);
        self::assertSame("it's \\ fine", $row['label']);
        self::assertSame('new', $row['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at']);
        self::assertEquals(100, $row['ratio']);
        self::assertNull($row['note']);
    }

    public function test_unknown_tables_and_columns_fail_by_name(): void
    {
        $tables = ProductionTables::fromAuthority();
        foreach ([
            [static fn () => $tables->createStatement('invented_table'), 'no production table invented_table'],
            [static fn () => $tables->createStatement('releases', ['id', 'invented_column', 'source']), 'releases has no column invented_column, source'],
            [static fn () => $tables->createStatement('releases', []), 'releases needs at least one column'],
            [static fn () => $tables->createStatement('releases', ['id', 'name_evidence_work_pending']), 'releases.name_evidence_work_pending is generated from proc_media_movie, proc_srrdb, proc_uid, proc_xxx'],
        ] as [$build, $message]) {
            try {
                $build();
                self::fail('Expected failure: '.$message);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_output_follows_a_changed_authority_without_editing_the_builder(): void
    {
        $before = $this->builder(<<<'SQL'
            CREATE TABLE `widgets` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(255) NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;
            SQL);
        $after = $this->builder(<<<'SQL'
            CREATE TABLE `widgets` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(255) NOT NULL,
              `owner_id` bigint(20) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `widgets_owner_name` (`owner_id`,`name`)
            ) ENGINE=InnoDB;
            SQL);

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($before->createStatement('widgets'));
        self::assertSame(['id', 'name'], array_column($pdo->query('PRAGMA table_xinfo(widgets)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
        self::assertSame([], $this->uniqueIndexes($pdo, 'widgets'));

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($after->createStatement('widgets'));
        self::assertSame(['id', 'name', 'owner_id'], array_column($pdo->query('PRAGMA table_xinfo(widgets)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
        self::assertSame([['name', 'owner_id']], $this->uniqueIndexes($pdo, 'widgets'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('widgets has no column owner_id');
        $before->createStatement('widgets', ['id', 'owner_id']);
    }

    public function test_unsupported_types_fail_instead_of_guessing(): void
    {
        $builder = $this->builder(<<<'SQL'
            CREATE TABLE `shapes` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `outline` geometry NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;
            SQL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shapes.outline has unsupported type geometry');
        $builder->createStatement('shapes');
    }

    private function builder(string $dump): ProductionTables
    {
        $path = tempnam(sys_get_temp_dir(), 'production-tables-');
        try {
            file_put_contents($path, $dump."\n");

            return new ProductionTables(new SchemaAuthority($path));
        } finally {
            unlink($path);
        }
    }

    /** @return list<list<string>> Unique indexes other than the primary key, columns sorted. */
    private function uniqueIndexes(PDO $pdo, string $table): array
    {
        $keys = [];
        foreach ($pdo->query("PRAGMA index_list($table)")->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['unique'] && $index['origin'] !== 'pk') {
                $columns = array_column($pdo->query('PRAGMA index_info('.$pdo->quote($index['name']).')')->fetchAll(PDO::FETCH_ASSOC), 'name');
                sort($columns);
                $keys[] = $columns;
            }
        }

        return $keys;
    }

    private function guard(): FixtureSchemaGuard
    {
        return new FixtureSchemaGuard($this->authority());
    }

    /** @return array<string, array{columns: list<string>, primary: list<string>, uniques: list<list<string>>, autoIncrement: ?string}> */
    private function authority(): array
    {
        return (new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables();
    }
}
