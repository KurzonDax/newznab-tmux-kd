<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;
use App\Facades\Search;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\SearchService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** Every release change reaches SearchService::updateRelease(), which keeps resolution and source in step. */
final class ReleaseDerivedFactsTest extends TestCase
{
    /** @var list<array{int, int}> What the search driver saw in `releases` when it was called. */
    private array $indexed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'searchname', 'categories_id', 'resolution', 'source']);
        $tables->create('video_data', ['releases_id', 'videowidth', 'videoheight']);
        $tables->create('media_info_probes');
        $tables->create('media_info_tracks');

        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('updateRelease')->andReturnUsing(function (int|string $id): void {
            $this->indexed[] = $this->facts((int) $id);
        });
        app(SearchService::class)->extend('recording', fn (): SearchDriverInterface => $driver);
        config(['search.default' => 'recording']);
    }

    public function test_a_new_release_gets_both_values_before_it_is_indexed(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP');

        Search::updateRelease(1);

        $expected = [ReleaseResolution::FullHd->value, ReleaseSource::Web->value];
        $this->assertSame($expected, $this->facts(1));
        $this->assertSame([$expected], $this->indexed);
    }

    public function test_a_rename_and_a_recategorisation_leave_both_values_correct(): void
    {
        $this->insertRelease(1, 'a1b2c3d4e5f6');
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Unknown->value, ReleaseSource::Unknown->value], $this->facts(1));

        DB::table('releases')->where('id', 1)->update(['searchname' => 'Movie.2020.2160p.BluRay.REMUX-GRP']);
        Search::updateRelease('1');
        $this->assertSame([ReleaseResolution::Uhd->value, ReleaseSource::Remux->value], $this->facts(1));

        DB::table('releases')->where('id', 1)->update(['categories_id' => 2045]);
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Uhd->value, ReleaseSource::Remux->value], $this->facts(1));
    }

    public function test_media_info_arriving_later_replaces_the_name_and_a_complete_probe_wins(): void
    {
        $this->insertRelease(1, 'Show.S01E01.720p.HDTV');
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Hd->value, ReleaseSource::Hdtv->value], $this->facts(1));

        DB::table('video_data')->insert(['releases_id' => 1, 'videowidth' => 720, 'videoheight' => 576]);
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Sd->value, ReleaseSource::Hdtv->value], $this->facts(1));

        $this->insertProbe(1, 1, '2026-01-01 00:00:00', 'complete', 1920, 1080);
        $this->insertProbe(2, 1, '2026-01-02 00:00:00', 'partial', 3840, 2160);
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::FullHd->value, ReleaseSource::Hdtv->value], $this->facts(1));
    }

    public function test_an_unchanged_release_causes_no_write(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP');
        Search::updateRelease(1);

        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            $writes += (int) preg_match('/^\s*update\b/i', $query->sql);
        });
        Search::updateRelease(1);

        $this->assertSame(0, $writes);
        $this->assertCount(2, $this->indexed);
    }

    public function test_a_missing_release_is_still_handed_to_the_driver(): void
    {
        Search::updateRelease(99);

        $this->assertSame(0, DB::table('releases')->count());
        $this->assertCount(1, $this->indexed);
    }

    private function insertRelease(int $id, string $searchname): void
    {
        DB::table('releases')->insert(['id' => $id, 'searchname' => $searchname, 'categories_id' => 5040, 'resolution' => 0, 'source' => 0]);
    }

    private function insertProbe(int $id, int $releaseId, string $capturedAt, string $completeness, int $width, int $height): void
    {
        DB::table('media_info_probes')->insert([
            'id' => $id, 'releases_id' => $releaseId, 'captured_at' => $capturedAt, 'source_kind' => 'sample',
            'source_completeness' => $completeness, 'schema_version' => 1, 'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
        ]);
        DB::table('media_info_tracks')->insert([
            'media_info_probe_id' => $id, 'type' => 'video', 'track_index' => 0, 'width' => $width, 'height' => $height,
            'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
        ]);
    }

    /** @return array{int, int} */
    private function facts(int $id): array
    {
        $row = DB::table('releases')->where('id', $id)->first(['resolution', 'source']);

        return [(int) ($row->resolution ?? -1), (int) ($row->source ?? -1)];
    }
}
