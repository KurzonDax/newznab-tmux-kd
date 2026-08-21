<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AdminListPerformancePlanTest extends TestCase
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
            'innerfileblacklist' => '',
            'title' => 'NNTmux Test',
            'home_link' => '/',
        ];
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

    public function test_release_admin_list_uses_versioned_cache_keys(): void
    {
        $modelPath = app_path('Models/Release.php');

        $this->assertFileExists($modelPath);

        $content = file_get_contents($modelPath);

        $this->assertStringContainsString('adminReleasesRangeVersion', $content);
        $this->assertStringContainsString(".'_'.(\$categoryId ?? 'all')", $content);
        $this->assertStringContainsString('Cache::forever(\'adminReleasesRangeVersion\'', $content);
    }

    public function test_group_admin_list_no_longer_groups_by_id(): void
    {
        $modelPath = app_path('Models/UsenetGroup.php');

        $this->assertFileExists($modelPath);

        $content = file_get_contents($modelPath);
        $methodStart = strpos($content, 'public static function getGroupsRange');
        $this->assertIsInt($methodStart);
        $methodBody = substr($content, $methodStart, 1200);

        $this->assertStringContainsString("->select([\n                'id',", $methodBody);
        $this->assertStringContainsString("->orderBy('name')", $methodBody);
        $this->assertStringNotContainsString('groupBy', $methodBody);
    }

    public function test_admin_list_index_migration_contains_narrow_indexes(): void
    {
        $migrationPath = database_path('migrations/2026_07_13_000000_add_admin_list_performance_indexes.php');

        $this->assertFileExists($migrationPath);

        $content = file_get_contents($migrationPath);

        $this->assertStringContainsString('ix_releases_categories_postdate_admin', $content);
        $this->assertStringContainsString('ix_releases_postdate_admin', $content);
        $this->assertStringContainsString('ix_usenet_groups_active_name_admin', $content);
        $this->assertStringContainsString('ix_release_reports_status_created_admin', $content);
    }
}
