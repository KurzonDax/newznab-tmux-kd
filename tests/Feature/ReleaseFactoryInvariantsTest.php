<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseFactoryInvariantsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL DEFAULT "",
            searchname VARCHAR(255) NOT NULL DEFAULT "",
            fromname VARCHAR(255) NULL,
            postdate DATETIME NULL,
            adddate DATETIME NULL,
            guid CHAR(36) NOT NULL UNIQUE,
            leftguid CHAR(1) NOT NULL,
            categories_id INTEGER NOT NULL DEFAULT 10,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            passwordstatus INTEGER NOT NULL DEFAULT -1,
            isrenamed INTEGER NOT NULL DEFAULT 0
        )');
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_factory_produces_uuid_guid_with_matching_leftguid(): void
    {
        for ($iteration = 0; $iteration < 5; $iteration++) {
            $attributes = Release::factory()->raw();

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
                (string) $attributes['guid'],
                'The factory must produce a UUID guid that fits the char(36) column.',
            );
            $this->assertSame(36, mb_strlen((string) $attributes['guid']));
            $this->assertSame(
                mb_strtolower(mb_substr((string) $attributes['guid'], 0, 1)),
                mb_strtolower((string) $attributes['leftguid']),
                'leftguid must be the first character of guid.',
            );
        }
    }

    public function test_factory_rows_satisfy_the_release_table_constraints(): void
    {
        foreach (Release::factory()->count(3)->raw() as $attributes) {
            DB::table('releases')->insert($attributes);
        }

        $rows = DB::table('releases')->get(['guid', 'leftguid']);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(mb_substr((string) $row->guid, 0, 1), (string) $row->leftguid);
        }
        $this->assertSame(3, $rows->pluck('guid')->unique()->count());
    }
}
