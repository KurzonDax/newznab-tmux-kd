<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Support\ReleaseCompletion;
use App\Support\ReleaseSearchIndexDocument;
use App\View\Components\CompletionFilter;
use Tests\TestCase;

/**
 * The threshold has to mean the same thing on both query paths: plain category
 * browse resolves it in SQL, keyword search resolves it in the search index,
 * and the index only has an answer if `completion` is an indexed attribute.
 */
final class ReleaseCompletionFilterTest extends TestCase
{
    public function test_the_toolbar_offers_the_documented_menu_and_defaults_to_all(): void
    {
        $filter = new CompletionFilter(baseUrl: 'http://localhost/browse/All', queryParams: []);

        $this->assertSame([0, 80, 95, 100], array_keys($filter->thresholds));
        $this->assertSame('All releases', $filter->currentLabel);
        $this->assertSame(0, $filter->currentThreshold);
    }

    public function test_the_toolbar_preserves_the_other_query_parameters(): void
    {
        $filter = new CompletionFilter(
            currentThreshold: 95,
            baseUrl: 'http://localhost/browse/All',
            queryParams: ['ob' => 'size_desc', 't' => '5030'],
        );

        $this->assertSame('At least 95%', $filter->currentLabel);
        $this->assertSame('http://localhost/browse/All?ob=size_desc&t=5030', $filter->thresholdUrls[0]);
        $this->assertSame('http://localhost/browse/All?ob=size_desc&t=5030&minc=80', $filter->thresholdUrls[80]);
        $this->assertSame(ReleaseCompletion::REQUEST_KEY, 'minc');
    }

    public function test_the_search_query_carries_the_threshold_to_the_drivers(): void
    {
        $query = ReleaseSearchQuery::fromCriteria(['min_completion' => 95], 100);

        $this->assertSame(95, $query->minCompletion);
        $this->assertSame(95, $query->criteria()['min_completion']);
        $this->assertSame(0, ReleaseSearchQuery::fromCriteria([], 100)->criteria()['min_completion']);
    }

    public function test_elasticsearch_filters_on_the_completion_attribute(): void
    {
        $driver = app(ElasticSearchDriver::class);
        $build = new \ReflectionMethod($driver, 'buildElasticsearchReleaseFilters');

        $filters = $build->invoke($driver, ['min_completion' => 80]);
        $this->assertContains(['range' => ['completion' => ['gte' => 80]]], $filters);

        $unfiltered = $build->invoke($driver, ['min_completion' => 0]);
        foreach ($unfiltered as $clause) {
            $this->assertArrayNotHasKey('completion', $clause['range'] ?? []);
        }
    }

    public function test_the_release_index_carries_completion(): void
    {
        $columns = ManticoreIndexRegistry::definitions()['releases']['columns'];

        $this->assertSame(['type' => 'float'], $columns['completion']);
        $this->assertContains('completion', ReleaseSearchIndexDocument::fields());
        $this->assertSame(99.7, ReleaseSearchIndexDocument::normalize(['completion' => 99.7])['completion']);
        $this->assertSame(0.0, ReleaseSearchIndexDocument::normalize([])['completion']);

        $mapping = file_get_contents(app_path('Console/Commands/NntmuxCreateESIndexes.php'));
        $this->assertIsString($mapping);
        $this->assertStringContainsString("'completion' => ['type' => 'float']", $mapping);
    }

    public function test_the_index_projection_and_sync_keep_completion_current(): void
    {
        // Repair and rescan already re-sync a release whose completion improved,
        // so the attribute only needed to join the projection the sync reads and
        // the observer's list of fields that make an Eloquent write index-dirty.
        $projection = file_get_contents(app_path('Services/Search/Support/ReleaseIndexProjection.php'));
        $observer = file_get_contents(app_path('Observers/ReleaseObserver.php'));

        $this->assertStringContainsString("'r.completion'", (string) $projection);
        $this->assertStringContainsString("'completion',", (string) $observer);
    }

    public function test_both_query_paths_apply_the_same_threshold(): void
    {
        $browse = file_get_contents(app_path('Services/Releases/ReleaseBrowseService.php'));
        $search = file_get_contents(app_path('Services/Releases/ReleaseSearchService.php'));

        // Plain category browse resolves it in SQL...
        $this->assertStringContainsString('AND r.completion >= %d', (string) $browse);
        $this->assertStringContainsString("'min_completion' => \$minCompletion", (string) $browse);

        // ...and keyword search hands it to the index, with the MySQL fallback matching.
        $this->assertStringContainsString("'min_completion' => \$minCompletion", (string) $search);
        $this->assertStringContainsString("sprintf('r.completion >= %d', \$minCompletion)", (string) $search);
    }
}
