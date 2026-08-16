<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RefineReleasesFromMediaInfoCommandTest extends TestCase
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
            $table->unsignedInteger('categories_id');
            $table->boolean('iscategorized')->default(false);
            $table->integer('haspreview')->default(0);
            $table->integer('passwordstatus')->default(0);
        });
        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('containerformat')->nullable();
            $table->string('videoformat')->nullable();
            $table->string('videocodec')->nullable();
            $table->integer('videowidth')->nullable();
            $table->integer('videoheight')->nullable();
        });
        Schema::create('audio_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('audioid')->default(1);
            $table->string('audioformat')->nullable();
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('generate_previews')->default(true);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('root_categories_id');
        });

        DB::table('root_categories')->insert([
            ['id' => Category::MOVIE_ROOT],
            ['id' => Category::TV_ROOT],
            ['id' => Category::MUSIC_ROOT],
        ]);
        DB::table('categories')->insert([
            ['id' => Category::MOVIE_OTHER, 'root_categories_id' => Category::MOVIE_ROOT],
            ['id' => Category::MOVIE_UHD, 'root_categories_id' => Category::MOVIE_ROOT],
            ['id' => Category::TV_OTHER, 'root_categories_id' => Category::TV_ROOT],
            ['id' => Category::TV_SD, 'root_categories_id' => Category::TV_ROOT],
            ['id' => Category::MUSIC_OTHER, 'root_categories_id' => Category::MUSIC_ROOT],
            ['id' => Category::MUSIC_LOSSLESS, 'root_categories_id' => Category::MUSIC_ROOT],
        ]);

        DB::table('releases')->insert([
            ['id' => 1, 'categories_id' => Category::MOVIE_OTHER],
            ['id' => 2, 'categories_id' => Category::TV_OTHER],
            ['id' => 3, 'categories_id' => Category::MUSIC_OTHER],
            ['id' => 4, 'categories_id' => Category::MOVIE_HD],
        ]);
        DB::table('video_data')->insert([
            ['releases_id' => 1, 'videowidth' => 3840, 'videoheight' => 2160],
            ['releases_id' => 2, 'videowidth' => 640, 'videoheight' => 480],
            ['releases_id' => 4, 'videowidth' => 3840, 'videoheight' => 2160],
        ]);
        DB::table('audio_data')->insert([
            'releases_id' => 3,
            'audioformat' => 'FLAC',
        ]);
    }

    public function test_dry_run_honors_limit_reports_rule_counts_and_does_not_write(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->artisan('releases:refine-from-mediainfo', [
            '--limit' => 2,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run: inspected 2; refined 2; unchanged 0.')
            ->expectsOutputToContain('video_sd: 1')
            ->expectsOutputToContain('video_uhd: 1')
            ->assertSuccessful();

        $this->assertSame(Category::MOVIE_OTHER, (int) DB::table('releases')->where('id', 1)->value('categories_id'));
        $this->assertSame(Category::TV_OTHER, (int) DB::table('releases')->where('id', 2)->value('categories_id'));
    }

    public function test_root_filter_applies_only_matching_releases_and_reports_counts(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1);

        $this->artisan('releases:refine-from-mediainfo', ['--root' => 'movies'])
            ->expectsOutputToContain('inspected 1; refined 1; unchanged 0.')
            ->expectsOutputToContain('video_uhd: 1')
            ->assertSuccessful();

        $this->assertSame(Category::MOVIE_UHD, (int) DB::table('releases')->where('id', 1)->value('categories_id'));
        $this->assertSame(Category::TV_OTHER, (int) DB::table('releases')->where('id', 2)->value('categories_id'));
    }
}
