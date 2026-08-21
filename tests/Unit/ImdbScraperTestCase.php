<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

abstract class ImdbScraperTestCase extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'title' => 'NNTmux Test',
            'home_link' => '/',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }
}
