<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReleaseDisplayNameMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname');
            $table->string('searchname_normalized')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');

        parent::tearDown();
    }

    public function test_migration_adds_the_column_and_backfills_existing_rows(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'searchname' => 'FC2.PPV.4963596.No.Amazing.1080p.mp4'],
            ['id' => 2, 'searchname' => 'ClubSweethearts.2016.01.09.Anna.Rey.XXX.1080p.mp4'],
            ['id' => 3, 'searchname' => 'Already Readable Name 1080p'],
        ]);

        $this->runDisplayNameMigration();

        $this->assertSame(
            [
                'FC2 PPV 4963596 No Amazing 1080p MP4',
                'ClubSweethearts 2016.01.09 Anna Rey XXX 1080p MP4',
                'Already Readable Name 1080p',
            ],
            DB::table('releases')->orderBy('id')->pluck('display_name')->all(),
        );
    }

    public function test_search_name_values_keeps_the_display_name_in_the_same_write(): void
    {
        DB::table('releases')->insert(['id' => 1, 'searchname' => 'Old.Name.1080p.mp4']);
        $this->runDisplayNameMigration();

        DB::table('releases')
            ->where('id', 1)
            ->update(Release::searchNameValues('Some.Movie.2019.1080p.BluRay.H.264.DD5.1.mkv'));

        $row = DB::table('releases')->where('id', 1)->first();

        $this->assertSame('Some.Movie.2019.1080p.BluRay.H.264.DD5.1.mkv', $row->searchname);
        $this->assertSame('Some Movie 2019 1080p BluRay H.264 DD5.1 MKV', $row->display_name);
        $this->assertNotNull($row->searchname_normalized);
    }

    private function runDisplayNameMigration(): void
    {
        $migration = require database_path('migrations/2026_08_27_210000_add_display_name_to_releases_table.php');
        $migration->up();
    }
}
