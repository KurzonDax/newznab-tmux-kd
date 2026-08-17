<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\RegexService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegexServiceCacheTest extends TestCase
{
    public function test_repeated_regex_attempts_reuse_empty_local_result(): void
    {
        Cache::spy();
        DB::shouldReceive('select')->once()->andReturn([]);

        $service = new RegexService('collection_regexes');

        $this->assertSame('', $service->tryRegex('subject one', 'alt.test'));
        $this->assertSame('', $service->tryRegex('subject two', 'alt.test'));

        Cache::shouldHaveReceived('get')->once();
        Cache::shouldHaveReceived('put')->once();
    }

    public function test_expired_local_regex_result_is_refetched(): void
    {
        $this->freezeSecond();
        Cache::spy();
        DB::shouldReceive('select')->twice()->andReturn([]);

        $service = new RegexService('collection_regexes');
        $service->tryRegex('subject one', 'alt.test');
        $this->travel(14)->minutes();
        $service->tryRegex('subject two', 'alt.test');
        $this->travel(2)->minutes();
        $service->tryRegex('subject three', 'alt.test');

        Cache::shouldHaveReceived('get')->twice();
        Cache::shouldHaveReceived('put')->twice();
    }
}
