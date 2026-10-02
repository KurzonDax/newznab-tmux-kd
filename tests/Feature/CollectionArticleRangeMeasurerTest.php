<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\CollectionArticleRangeMeasurer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class CollectionArticleRangeMeasurerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tables = ProductionTables::fromAuthority();
        $tables->create('binaries', ['id', 'collections_id']);
        $tables->create('parts', ['binaries_id', 'number', 'partnumber']);
    }

    public function test_parts_stored_from_a_secondary_provider_do_not_count_as_article_numbers(): void
    {
        // Collection 1 mixes provider 1's numbers with secondary-only parts (number 0);
        // collection 2 came from a secondary provider alone.
        DB::table('binaries')->insert([['id' => 10, 'collections_id' => 1], ['id' => 20, 'collections_id' => 2]]);
        DB::table('parts')->insert([
            ['binaries_id' => 10, 'number' => 0, 'partnumber' => 1],
            ['binaries_id' => 10, 'number' => 5_001, 'partnumber' => 2],
            ['binaries_id' => 10, 'number' => 5_009, 'partnumber' => 3],
            ['binaries_id' => 10, 'number' => 0, 'partnumber' => 4],
            ['binaries_id' => 20, 'number' => 0, 'partnumber' => 1],
            ['binaries_id' => 20, 'number' => 0, 'partnumber' => 2],
        ]);

        $this->assertSame([1 => ['first' => 5_001, 'last' => 5_009]], (new CollectionArticleRangeMeasurer)->measure([1, 2]));
    }
}
