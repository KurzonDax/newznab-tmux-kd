<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;

class ImdbScraperRealDataTest extends ImdbScraperTestCase
{
    public function test_search_returns_empty_for_empty_query(): void
    {
        $scraper = new ImdbScraper;
        $this->assertSame([], $scraper->search(''));
        $this->assertSame([], $scraper->search('   '));
    }
}
