<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** A deleted release takes its release_tv_episodes rows with it (the SQLite testing connection enforces foreign keys). */
final class ReleaseTvEpisodesCascadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProductionTables::fromAuthority()->create('releases', ['id', 'guid', 'searchname', 'categories_id', 'videos_id']);
        // The migration's foreign key, declared the same way.
        Schema::create('release_tv_episodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->unsignedSmallInteger('season');
            $table->unsignedSmallInteger('episode')->nullable();
            $table->index('releases_id', 'ix_release_tv_episodes_releases_id');
            $table->foreign('releases_id', 'fk_release_tv_episodes_releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function test_deleting_a_release_leaves_none_of_its_rows(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'guid' => 'guid-1', 'searchname' => 'Show.S01E01E02.1080p', 'categories_id' => 5040, 'videos_id' => 7],
            ['id' => 2, 'guid' => 'guid-2', 'searchname' => 'Show.S02.COMPLETE.1080p', 'categories_id' => 5040, 'videos_id' => 7],
        ]);
        DB::table('release_tv_episodes')->insert([
            ['releases_id' => 1, 'season' => 1, 'episode' => 1],
            ['releases_id' => 1, 'season' => 1, 'episode' => 2],
            ['releases_id' => 2, 'season' => 2, 'episode' => null],
        ]);
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->once()->with('guid-1');
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->once()->with('guid-1');
        Search::shouldReceive('deleteRelease')->once()->with(1);

        app(ReleaseManagementService::class)->deleteSingle(['g' => 'guid-1', 'i' => 1], $nzb, $images);

        $this->assertSame(0, DB::table('releases')->where('id', 1)->count());
        $this->assertSame(0, DB::table('release_tv_episodes')->where('releases_id', 1)->count());
        $this->assertSame(1, DB::table('release_tv_episodes')->where('releases_id', 2)->count());
    }
}
