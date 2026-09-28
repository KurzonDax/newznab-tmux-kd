<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** A deleted release takes its release_audio_languages rows with it (the SQLite testing connection enforces foreign keys). */
final class ReleaseAudioLanguagesCascadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProductionTables::fromAuthority()->create('releases', ['id', 'guid', 'searchname', 'categories_id']);
        (require database_path('migrations/2026_09_27_200000_add_release_audio_languages.php'))->up();
    }

    public function test_deleting_a_release_leaves_none_of_its_rows_and_keeps_the_names(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'guid' => 'guid-1', 'searchname' => 'Movie.2020.MULTi.1080p', 'categories_id' => 2040],
            ['id' => 2, 'guid' => 'guid-2', 'searchname' => 'Movie.2021.1080p', 'categories_id' => 2040],
        ]);
        DB::table('languages')->insert([['id' => 1, 'name' => 'English'], ['id' => 2, 'name' => 'Hindi']]);
        DB::table('release_audio_languages')->insert([
            ['releases_id' => 1, 'languages_id' => 1],
            ['releases_id' => 1, 'languages_id' => 2],
            ['releases_id' => 2, 'languages_id' => 1],
        ]);
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->once()->with('guid-1');
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->once()->with('guid-1');
        Search::shouldReceive('deleteRelease')->once()->with(1);

        app(ReleaseManagementService::class)->deleteSingle(['g' => 'guid-1', 'i' => 1], $nzb, $images);

        $this->assertSame(0, DB::table('releases')->where('id', 1)->count());
        $this->assertSame(0, DB::table('release_audio_languages')->where('releases_id', 1)->count());
        $this->assertSame(1, DB::table('release_audio_languages')->where('releases_id', 2)->count());
        $this->assertSame(2, DB::table('languages')->count());
    }
}
