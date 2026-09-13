<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NumberedMkvArchiveTestCase;

class NumberedMkvArchiveMigrationMariaDbTest extends NumberedMkvArchiveTestCase
{
    private bool $ownsTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
            $this->markTestSkipped('Requires the disposable cbp_integration Sail database.');
        }
        $stock = (array) DB::table('collection_regexes')->where('id', 113)->sole();
        config(['database.connections.numbered_mkv_rule_test' => [
            ...config('database.connections.mariadb'),
            'host' => 'mariadb', 'database' => 'cbp_integration',
            'username' => getenv('CBP_INTEGRATION_DB_USERNAME'),
            'password' => getenv('CBP_INTEGRATION_DB_PASSWORD'),
            'prefix' => 'numbered_mkv_rule_'.bin2hex(random_bytes(6)).'_',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ], 'database.default' => 'numbered_mkv_rule_test']);
        $this->ownsTables = true;
        Schema::create('collection_regexes', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('group_regex');
            $table->text('regex');
            $table->integer('status');
            $table->integer('ordinal');
            $table->text('description');
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('groups_id');
            $table->integer('collection_regexes_id');
            $table->string('subject');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('collections_id');
            $table->string('name', 1000);
        });
        DB::table('collection_regexes')->insert($stock);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.boneless']);
    }

    protected function tearDown(): void
    {
        if ($this->ownsTables) {
            foreach (['binaries', 'collections', 'usenet_groups', 'collection_regexes'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::disconnect('numbered_mkv_rule_test');
            config(['database.default' => 'sqlite']);
        }
        parent::tearDown();
    }

    public function test_innodb_upgrade_rollback_lock_and_byte_exact_ownership(): void
    {
        $updated = DB::table('collection_regexes')->where('id', 113)->value('regex');
        $queries = [];
        DB::connection()->beforeExecuting(static function (string $sql) use (&$queries): void {
            if (str_contains($sql, 'collection_regexes')) {
                $queries[] = [$sql, DB::transactionLevel()];
            }
        });
        foreach (['up' => self::PREVIOUS, 'down' => $updated] as $direction => $source) {
            foreach (['regex' => str_replace('yEnc', 'YENC', $source), 'group_regex' => '^Alt\\.binaries\\.boneless$'] as $field => $local) {
                DB::table('collection_regexes')->where('id', 113)->update([
                    'regex' => $source, 'group_regex' => '^alt\\.binaries\\.boneless$', $field => $local,
                ]);
                $before = DB::table('collection_regexes')->get()->toJson();
                Cache::forever('collection_regexes_revision', 'before');
                $this->migration()->{$direction}();
                $this->assertSame($before, DB::table('collection_regexes')->get()->toJson());
                $this->assertSame('before', Cache::get('collection_regexes_revision'));
            }
            DB::table('collection_regexes')->where('id', 113)->update([
                'regex' => $source, 'group_regex' => '^alt\\.binaries\\.boneless$',
            ]);
            $id = DB::table('collections')->insertGetId(['groups_id' => 1, 'collection_regexes_id' => 113,
                'subject' => '[02/05] - "Example.mkv.part01.rar" yEnc']);
            $queries = [];
            $this->migration()->{$direction}();
            $this->assertCount(2, $queries);
            $this->assertStringContainsString('for update', $queries[0][0]);
            $this->assertSame([1, 1], array_column($queries, 1));
            foreach ($queries as [$sql]) {
                foreach (['id', 'status', 'ordinal'] as $column) {
                    $this->assertStringContainsString('`'.$column.'` = ?', $sql);
                }
                foreach (['regex', 'group_regex'] as $column) {
                    $this->assertStringContainsString('CAST('.$column.' AS BINARY) = CAST(? AS BINARY)', $sql);
                }
            }
            $this->assertNotSame('before', Cache::get('collection_regexes_revision'));
            $this->assertTrue(DB::table('collections')->where('id', $id)->exists());
            $this->assertSame($direction === 'up' ? $updated : self::PREVIOUS, DB::table('collection_regexes')->where('id', 113)->value('regex'));
        }
    }

    public function test_innodb_commits_alone_advance_the_revision(): void
    {
        $updated = DB::table('collection_regexes')->where('id', 113)->value('regex');
        foreach (['up' => self::PREVIOUS, 'down' => $updated] as $direction => $source) {
            DB::table('collection_regexes')->where('id', 113)->update(['regex' => $source, 'description' => 'Local explanation']);
            Cache::forever('collection_regexes_revision', 'before');
            DB::beginTransaction();
            try {
                $this->migration()->{$direction}();
                $this->assertSame('before', Cache::get('collection_regexes_revision'));
            } finally {
                DB::rollBack();
            }
            $this->assertSame($source, DB::table('collection_regexes')->where('id', 113)->value('regex'));
            $this->assertSame('before', Cache::get('collection_regexes_revision'));
            DB::transaction(function () use ($direction): void {
                $this->migration()->{$direction}();
                $this->assertSame('before', Cache::get('collection_regexes_revision'));
            });
            $revision = Cache::get('collection_regexes_revision');
            $this->assertNotSame('before', $revision);
            $this->assertSame('Local explanation', DB::table('collection_regexes')->where('id', 113)->value('description'));
            $this->migration()->{$direction}();
            $this->assertSame($revision, Cache::get('collection_regexes_revision'));
        }
    }
}
