<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SecondarySearchIndex;
use App\Services\Releases\ReleaseDerivedFacts;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;

/**
 * Search Service Manager - handles driver resolution for search functionality.
 *
 * This service manager provides a unified interface for full-text search operations.
 * ManticoreSearch is the only backend; SEARCH_DRIVER selects it by name.
 */
class SearchService extends Manager implements SearchServiceInterface
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('search.default', 'manticore');
    }

    /**
     * Create the ManticoreSearch driver instance.
     */
    protected function createManticoreDriver(): SearchDriverInterface
    {
        $config = $this->config->get('search.drivers.manticore', []);

        return new ManticoreSearchDriver($config);
    }

    /**
     * Get a driver instance.
     *
     * @param  string|null  $driver
     *
     * @throws \InvalidArgumentException
     */
    public function driver($driver = null): SearchDriverInterface
    {
        return parent::driver($driver);
    }

    /**
     * Check if the current driver is available.
     */
    public function isAvailable(): bool
    {
        return $this->driver()->isAvailable();
    }

    /**
     * Get the current driver name.
     */
    public function getCurrentDriver(): string
    {
        return $this->driver()->getDriverName();
    }

    // Implement SearchServiceInterface methods by delegating to the current driver

    /**
     * Check if suggest is enabled.
     */
    public function isSuggestEnabled(): bool
    {
        return $this->driver()->isSuggestEnabled();
    }

    /**
     * Check if fuzzy search is enabled.
     */
    public function isFuzzyEnabled(): bool
    {
        return $this->driver()->isFuzzyEnabled();
    }

    /**
     * Get the releases index name.
     */
    public function getReleasesIndex(): string
    {
        return $this->driver()->getReleasesIndex();
    }

    /**
     * Insert a release into the search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function insertRelease(array $parameters): void
    {
        $this->driver()->insertRelease($parameters);
    }

    /**
     * Update a release in the search index. Every release change ends here, so the
     * release's derived facts are brought up to date first, whatever the driver.
     */
    public function updateRelease(int|string $releaseID): void
    {
        $this->container->make(ReleaseDerivedFacts::class)->refresh((int) $releaseID);
        $this->driver()->updateRelease($releaseID);
    }

    /**
     * Delete a release from the search index.
     */
    public function deleteRelease(int $id): void
    {
        $this->deleteReleases([$id]);
    }

    public function deleteReleases(iterable $ids): void
    {
        $this->driver()->deleteReleases($ids);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function releaseDocumentsAfterId(int $afterId, int $limit): array
    {
        return $this->driver()->releaseDocumentsAfterId($afterId, $limit);
    }

    /**
     * Insert a predb record into the search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function insertPredb(array $parameters): void
    {
        $this->driver()->insertPredb($parameters);
    }

    /**
     * Update a predb record in the search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function updatePreDb(array $parameters): void
    {
        $this->driver()->updatePreDb($parameters);
    }

    /**
     * Search the releases index.
     *
     * @param  array<string, mixed>  $phrases
     * @return array<string, mixed>
     */
    public function searchReleases(array|string $phrases, int $limit = 1000): array
    {
        return $this->driver()->searchReleases($phrases, $limit);
    }

    /**
     * Search releases with fuzzy fallback.
     *
     * If exact search returns no results and fuzzy is enabled, this method
     * will automatically try a fuzzy search as a fallback.
     *
     * @param  array<string, mixed>  $phrases
     * @return array<string, mixed>
     */
    public function searchReleasesWithFuzzy(array|string $phrases, int $limit = 1000, bool $forceFuzzy = false): array
    {
        return $this->driver()->searchReleasesWithFuzzy($phrases, $limit, $forceFuzzy);
    }

    /**
     * Search the predb index.
     *
     * @param  array<string, mixed>  $searchTerm
     * @return array<string, mixed>
     */
    public function searchPredb(array|string $searchTerm): array
    {
        return $this->driver()->searchPredb($searchTerm);
    }

    /**
     * Get spell correction suggestions.
     */
    public function suggest(string $query, ?string $index = null): array
    {
        return $this->driver()->suggest($query, $index);
    }

    /**
     * Truncate/clear an index (remove all documents).
     *
     * @param  array<string, mixed>|string  $indexes  Index name(s) to truncate
     */
    public function truncateIndex(array|string $indexes): void
    {
        $this->driver()->truncateIndex($indexes);
    }

    /**
     * Optimize index for better search performance.
     */
    public function optimizeIndex(): void
    {
        $this->driver()->optimizeIndex();
    }

    /**
     * Bulk insert multiple releases into the index.
     *
     * @param  array<string, mixed>  $releases  Array of release data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertReleases(array $releases): array
    {
        return $this->driver()->bulkInsertReleases($releases);
    }

    /**
     * Bulk insert multiple predb records into the index.
     *
     * @param  array<string, mixed>  $predbRecords  Array of predb data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertPredb(array $predbRecords): array
    {
        return $this->driver()->bulkInsertPredb($predbRecords);
    }

    /**
     * Insert a movie into the movies search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function insertMovie(array $parameters): void
    {
        $this->driver()->insertMovie($parameters);
    }

    /**
     * Delete a movie from the search index.
     */
    public function deleteMovie(int $id): void
    {
        $this->driver()->deleteMovie($id);
    }

    /**
     * Bulk insert multiple movies into the index.
     *
     * @param  array<string, mixed>  $movies  Array of movie data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertMovies(array $movies): array
    {
        return $this->driver()->bulkInsertMovies($movies);
    }

    /** {@inheritdoc} */
    public function searchEntityFields(string $index, array $fields, string $key, int $limit = 500, int $afterId = 0): array
    {
        return $this->driver()->searchEntityFields($index, $fields, $key, $limit, $afterId);
    }

    /**
     * Search releases by external media IDs.
     * Used to find releases associated with a specific movie or TV show.
     *
     * @param  array<string, mixed>  $externalIds
     * @return array<string, mixed>
     */
    public function searchReleasesByExternalId(array $externalIds, int $limit = 1000): array
    {
        return $this->driver()->searchReleasesByExternalId($externalIds, $limit);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{ids: list<int>, total: int, fuzzy: bool}
     */
    public function searchReleasesFiltered(array $criteria, int $limit, int $offset = 0): array
    {
        return $this->driver()->searchReleasesFiltered($criteria, $limit, $offset);
    }

    public function searchReleasePage(ReleaseSearchQuery $query): SearchPage
    {
        $page = $this->driver()->searchReleasePage($query);
        Log::debug('Release search completed', [
            'driver' => $page->driver,
            'available' => $page->available,
            'duration_ms' => round($page->durationMs, 2),
            'result_count' => count($page->ids),
            'total' => $page->total,
            'fuzzy' => $page->fuzzy,
            'pagination_mode' => $query->cursor === null ? 'offset' : 'cursor',
            'offset' => $query->offset,
            'limit' => $query->limit,
            'track_total' => $query->trackTotal,
        ]);

        return $page;
    }

    public function insertSecondary(SecondarySearchIndex $index, int $id, array $document): void
    {
        $this->driver()->insertSecondary($index, $id, $document);
    }

    public function deleteSecondary(SecondarySearchIndex $index, int $id): void
    {
        $this->driver()->deleteSecondary($index, $id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     */
    public function bulkInsertSecondary(SecondarySearchIndex $index, array $documents): array
    {
        return $this->driver()->bulkInsertSecondary($index, $documents);
    }

    public function searchSecondary(SecondarySearchIndex $index, string $query, int $limit = 100): array
    {
        return $this->driver()->searchSecondary($index, $query, $limit);
    }

    public function searchAnimeTitle(string $query, int $limit = 100): array
    {
        return $this->driver()->searchAnimeTitle($query, $limit);
    }
}
