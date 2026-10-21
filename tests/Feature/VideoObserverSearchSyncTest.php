<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Saving or deleting a show re-syncs its releases' search documents and writes no
 * TV-show search index (issue #1117 removed the unread tvshows_rt index).
 */
final class VideoObserverSearchSyncTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $searchCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        ProductionTables::fromAuthority()->create('videos');
        ProductionTables::fromAuthority()->create('releases', ['id', 'name', 'searchname', 'videos_id']);
        DB::table('releases')->insert([
            ['id' => 11, 'name' => 'Show.S01E01-GRP', 'searchname' => 'Show.S01E01-GRP', 'videos_id' => 5],
            ['id' => 12, 'name' => 'Show.S01E02-GRP', 'searchname' => 'Show.S01E02-GRP', 'videos_id' => 5],
            ['id' => 13, 'name' => 'Other.S01E01-GRP', 'searchname' => 'Other.S01E01-GRP', 'videos_id' => 6],
        ]);

        $calls = &$this->searchCalls;
        Search::swap(new class($calls)
        {
            /** @param  list<array{string, list<mixed>}>  $calls */
            public function __construct(private array &$calls) {}

            /** @param  list<mixed>  $arguments */
            public function __call(string $method, array $arguments): mixed
            {
                $this->calls[] = [$method, $arguments];

                return null;
            }
        });
    }

    #[Test]
    public function saving_a_show_resyncs_its_releases_and_writes_no_tv_show_index(): void
    {
        $video = Video::query()->create(['id' => 5, 'type' => 0, 'title' => 'Show', 'started' => '2020-01-01 00:00:00']);
        $this->assertSame([], $this->searchCalls, 'creating a show touches no search index');

        $video->update(['title' => 'Show (2020)']);

        $this->assertSame([['updateRelease', [11]], ['updateRelease', [12]]], $this->searchCalls);
    }

    #[Test]
    public function deleting_a_show_resyncs_its_releases_and_writes_no_tv_show_index(): void
    {
        $video = Video::query()->create(['id' => 5, 'type' => 0, 'title' => 'Show', 'started' => '2020-01-01 00:00:00']);
        $this->searchCalls = [];

        $video->delete();

        $this->assertSame([['updateRelease', [11]], ['updateRelease', [12]]], $this->searchCalls);
    }
}
