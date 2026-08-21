<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ReleaseProcessingService;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Boots Laravel with a file-backed SQLite DB containing a minimal {@code settings} table.
 *
 * {@see ProcessReleasesCommand} is constructor-injected with {@see ReleaseProcessingService},
 * which queries {@code settings} during registration; the default in-memory PHPUnit DB has no schema yet.
 */
abstract class SearchConsoleCommandTestCase extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }
}
