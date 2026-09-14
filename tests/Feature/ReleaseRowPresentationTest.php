<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use Illuminate\Support\Facades\Blade;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseRowPresentationTest extends TestCase
{
    use IsolatedSqliteDatabase;

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

    public function test_release_rows_show_megabytes_below_one_gigabyte(): void
    {
        $rows = collect([
            Release::factory()->make(['id' => 1, 'guid' => 'small-release', 'searchname' => 'Small release', 'size' => 524288000]),
            Release::factory()->make(['id' => 2, 'guid' => 'large-release', 'searchname' => 'Large release', 'size' => 1610612736]),
        ]);

        $html = Blade::render('<x-release-results :results="$rows" />', ['rows' => $rows]);

        $this->assertStringContainsString('500.00 MB', $html);
        $this->assertStringContainsString('1.50 GB', $html);
    }
}
