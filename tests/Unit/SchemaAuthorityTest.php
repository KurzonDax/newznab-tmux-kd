<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaAuthority;

final class SchemaAuthorityTest extends TestCase
{
    public function test_reads_production_video_and_audio_identities_independently_of_fixtures(): void
    {
        $tables = (new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'))->tables();

        self::assertSame(['releases_id'], $tables['video_data']['primary']);
        self::assertNotContains('id', $tables['video_data']['columns']);
        self::assertNull($tables['video_data']['autoIncrement']);
        self::assertSame(['id'], $tables['audio_data']['primary']);
        self::assertSame([['audioid', 'releases_id']], $tables['audio_data']['uniques']);
        self::assertSame('id', $tables['audio_data']['autoIncrement']);
    }

    public function test_every_current_migration_is_recorded_in_the_authority(): void
    {
        $root = dirname(__DIR__, 2);
        $authority = new SchemaAuthority($root.'/database/schema/mariadb-schema.sql');
        $authority->assertFresh(glob($root.'/database/migrations/*.php'));
        self::addToAssertionCount(1);
    }

    public function test_an_unrecorded_migration_requires_refreshing_the_dump(): void
    {
        $authority = new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2099_01_01_000000_new_column; refresh');
        $authority->assertFresh(['/migrations/2099_01_01_000000_new_column.php']);
    }

    public function test_unsupported_table_syntax_fails_instead_of_skipping_a_table(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'schema-authority-');
        try {
            file_put_contents($path, "CREATE TABLE unquoted (id INT);\n");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('must parse every table');
            (new SchemaAuthority($path))->tables();
        } finally {
            unlink($path);
        }
    }
}
