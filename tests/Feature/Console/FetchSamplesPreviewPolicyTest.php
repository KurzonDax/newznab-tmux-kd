<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FetchSamplesPreviewPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->unsignedInteger('categories_id');
            $table->integer('jpgstatus')->default(0);
        });

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title')->default('');
            $table->boolean('generate_previews')->default(true);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title')->default('');
            $table->unsignedInteger('root_categories_id')->nullable();
        });

        DB::table('root_categories')->insert([
            ['id' => 2000, 'title' => 'Movies', 'generate_previews' => 1],
            ['id' => 6000, 'title' => 'XXX', 'generate_previews' => 0],
        ]);

        DB::table('categories')->insert([
            ['id' => 2040, 'root_categories_id' => 2000],
            ['id' => 6010, 'root_categories_id' => 6000],
        ]);
    }

    public function test_it_refuses_to_run_when_every_category_is_in_a_disabled_root(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010, 'jpgstatus' => 0]);

        $this->artisan('releases:fetch-samples', ['--category' => '6010', '--dry-run' => true])
            ->expectsOutputToContain('Skipping category id(s) [6010]: Preview Generation is disabled for their root categories.')
            ->expectsOutputToContain('All supplied categories belong to roots with Preview Generation disabled. Command will not run.')
            ->assertSuccessful();
    }

    public function test_it_skips_disabled_root_categories_and_processes_the_rest(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010, 'jpgstatus' => 0],
            ['id' => 2, 'guid' => 'guid-2', 'categories_id' => 2040, 'jpgstatus' => 0],
        ]);

        $this->artisan('releases:fetch-samples', ['--category' => '6010,2040', '--dry-run' => true])
            ->expectsOutputToContain('Skipping category id(s) [6010]: Preview Generation is disabled for their root categories.')
            ->expectsOutputToContain('Categories: [2040]')
            ->expectsOutputToContain('Found 1 matching release(s). Processing 1. (dry-run)')
            ->expectsOutputToContain('guid-2')
            ->assertSuccessful();
    }
}
