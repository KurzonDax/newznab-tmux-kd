<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Data\Api\ReleaseData;
use App\Http\Controllers\Api\XML_Response;
use App\Models\User;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use App\Services\Search\SearchService;
use App\Support\ReleaseSearchIndexDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * API general search (v1 t=search, v2 /api/v2/search) asks the search index to match the music
 * text fields too, and a release found that way is presented exactly as before (#308): no new
 * fields, attributes or values in the v1 XML/JSON or the v2 JSON.
 */
final class ApiGeneralSearchMusicTextTest extends TestCase
{
    /** @var list<ReleaseSearchQuery> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();
        Cache::flush();
        ProductionTables::fromAuthority()->create('settings');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function api_general_search_matches_music_text_and_keeps_its_filters(): void
    {
        $this->searchReturning([]);

        (new ReleaseSearchService)->apiSearch('Recorded Track', -1, 40, 20, 30, [6000], [-1], 1024, 'size_asc');

        $this->assertCount(1, $this->queries);
        $criteria = $this->queries[0]->criteria();
        $this->assertTrue($criteria['music_text']);
        $this->assertSame('Recorded Track', $criteria['phrases']);
        $this->assertSame([6000], $criteria['excluded_category_ids']);
        $this->assertSame(1024, $criteria['min_size']);
        $this->assertSame(30, $criteria['max_age_days']);
        $this->assertSame(['size', 'asc'], [$criteria['sort_field'], $criteria['sort_dir']]);
        $this->assertSame([20, 40], [$this->queries[0]->limit, $this->queries[0]->offset]);
    }

    #[Test]
    public function other_release_searches_do_not_match_music_text(): void
    {
        $this->assertFalse(ReleaseSearchQuery::fromCriteria(['phrases' => 'Recorded Track'], 10)->criteria()['music_text']);
    }

    #[Test]
    public function a_music_matched_release_has_exactly_todays_v1_and_v2_fields(): void
    {
        $plain = $this->document([]);
        $matched = $this->document(['album_title' => 'Example Album Alias Album', 'artist' => 'Example Artist', 'music_tracks' => 'Recorded Track']);
        $this->searchReturning([$plain]);
        $plainRows = (new ReleaseSearchService)->apiSearch('Recorded Track', -1);
        $this->searchReturning([$matched]);
        $matchedRows = (new ReleaseSearchService)->apiSearch('Recorded Track', -1);

        foreach (['0', '1'] as $extended) {
            $this->assertSame($this->v1($plainRows, $extended)->returnXML(), $this->v1($matchedRows, $extended)->returnXML());
            $this->assertSame($this->v1($plainRows, $extended)->returnArray(), $this->v1($matchedRows, $extended)->returnArray());
        }
        $user = (new User)->forceFill(['id' => 1, 'api_token' => 'test-token']);
        $v2 = static fn (object $row): array => ReleaseData::toArrayFromRelease($row, $user, 'https://indexer.example.test/details/', 'https://indexer.example.test/getnzb');
        $this->assertSame($v2($plainRows[0]), $v2($matchedRows[0]));
        $this->assertStringNotContainsString('Recorded Track', (string) $this->v1($matchedRows, '1')->returnXML());
    }

    /** @param list<array<string, mixed>> $documents */
    private function searchReturning(array $documents): void
    {
        $this->queries = [];
        $search = Mockery::mock(SearchService::class, [$this->app]);
        $search->shouldReceive('isAvailable')->andReturn(true);
        $search->shouldReceive('searchReleasePage')->andReturnUsing(function (ReleaseSearchQuery $query) use ($documents): SearchPage {
            $this->queries[] = $query;

            return new SearchPage(array_map(static fn (array $document): int => (int) $document['id'], $documents), count($documents), false, 'manticore', documents: $documents);
        });
        $this->app->instance(SearchService::class, $search);
    }

    /**
     * @param  array<string, string>  $music
     * @return array<string, mixed>
     */
    private function document(array $music): array
    {
        return ReleaseSearchIndexDocument::normalize([
            'id' => 501, 'guid' => 'music-release-guid', 'name' => 'Obfuscated.Release-GRP', 'searchname' => 'Obfuscated.Release-GRP',
            'fromname' => 'poster@example.test', 'categories_id' => 3040, 'category_name' => 'Audio > Lossless', 'parent_category' => 'Audio',
            'sub_category' => 'Lossless', 'parentid' => 3000, 'groups_id' => 1, 'group_name' => 'alt.binaries.example', 'size' => 123456789,
            'postdate' => '2026-10-01 12:00:00', 'adddate' => '2026-10-02 12:00:00', 'totalpart' => 20, 'grabs' => 3, 'comments' => 1,
            ...$music,
        ]);
    }

    private function v1(mixed $rows, string $extended): XML_Response
    {
        return new XML_Response([
            'Parameters' => [
                'extended' => $extended, 'del' => '0', 'token' => 'test-token', 'requests' => 1, 'apilimit' => 100,
                'grabs' => 0, 'downloadlimit' => 100, 'oldestapi' => '', 'oldestgrab' => '',
            ],
            'Data' => $rows,
            'Server' => ['server' => [
                'title' => 'NNTmux Tests', 'strapline' => 'Testing', 'email' => 'noreply@example.test', 'meta' => 'usenet',
                'url' => 'https://indexer.example.test',
            ]],
            'Offset' => 0,
            'Type' => 'api',
        ]);
    }
}
