<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\TokenPrefixedArchiveTestCase;

class TokenPrefixedArchiveMigrationMariaDbTest extends TokenPrefixedArchiveTestCase
{
    private bool $ownsTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
            $this->markTestSkipped('Requires the disposable cbp_integration Sail database.');
        }
        $stock = (array) DB::table('collection_regexes')->where('id', 284)->sole();
        config(['database.connections.token_rule_test' => [
            ...config('database.connections.mariadb'),
            'host' => 'mariadb', 'database' => 'cbp_integration',
            'username' => getenv('CBP_INTEGRATION_DB_USERNAME'),
            'password' => getenv('CBP_INTEGRATION_DB_PASSWORD'),
            'prefix' => 'token_rule_'.bin2hex(random_bytes(6)).'_',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ], 'database.default' => 'token_rule_test']);
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
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.erotica']);
    }

    protected function tearDown(): void
    {
        if ($this->ownsTables) {
            foreach (['binaries', 'collections', 'usenet_groups', 'collection_regexes'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::disconnect('token_rule_test');
            config(['database.default' => 'sqlite']);
        }
        parent::tearDown();
    }

    public function test_innodb_upgrade_rollback_guard_and_byte_exact_ownership(): void
    {
        $updated = DB::table('collection_regexes')->where('id', 284)->value('regex');
        foreach (['up' => self::PREVIOUS, 'down' => $updated] as $direction => $source) {
            foreach (['regex' => strtoupper($source), 'group_regex' => '^Alt\\.binaries\\.erotica$'] as $field => $local) {
                DB::table('collection_regexes')->where('id', 284)->update([
                    'regex' => $source, 'group_regex' => '^alt\\.binaries\\.erotica$', $field => $local,
                ]);
                $before = DB::table('collection_regexes')->get()->toJson();
                Cache::forever('collection_regexes_revision', 'before');
                $this->migration()->{$direction}();
                $this->assertSame($before, DB::table('collection_regexes')->get()->toJson());
                $this->assertSame('before', Cache::get('collection_regexes_revision'));
            }
            DB::table('collection_regexes')->where('id', 284)->update([
                'regex' => $source, 'group_regex' => '^alt\\.binaries\\.erotica$',
            ]);
            $id = DB::table('collections')->insertGetId(['groups_id' => 1, 'collection_regexes_id' => 284,
                'subject' => 'Sample-B [01/19] - "Sample-B.7z.001" yEnc']);
            $error = null;
            try {
                $this->migration()->{$direction}();
            } catch (RuntimeException $exception) {
                $error = $exception;
            }
            $this->assertNotNull($error);
            $this->assertStringContainsString('drain', $error->getMessage());
            $this->assertSame($source, DB::table('collection_regexes')->where('id', 284)->value('regex'));
            $this->assertSame('before', Cache::get('collection_regexes_revision'));
            DB::table('collections')->where('id', $id)->delete();
            $this->migration()->{$direction}();
            $this->assertSame($direction === 'up' ? $updated : self::PREVIOUS, DB::table('collection_regexes')->where('id', 284)->value('regex'));
        }
    }
}
