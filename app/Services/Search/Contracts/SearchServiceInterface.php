<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

use App\Enums\SecondarySearchIndex;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;

/**
 * Interface for the full-text search service (ManticoreSearch).
 *
 * Provides a common API for release and predb search, indexing, and suggestions.
 */
interface SearchServiceInterface
{
    /**
     * Check if suggest is enabled.
     */
    public function isSuggestEnabled(): bool;

    /**
     * Check if fuzzy search is enabled.
     */
    public function isFuzzyEnabled(): bool;

    /**
     * Get the releases index name.
     */
    public function getReleasesIndex(): string;

    /**
     * Insert a release into the search index.
     *
     * @param  array<string, mixed>  $parameters  Release data with 'id', 'name', 'searchname', 'fromname', 'categories_id', 'filename'
     */
    public function insertRelease(array $parameters): void;

    /**
     * Update a release in the search index.
     *
     * @param  int|string  $releaseID  Release ID
     */
    public function updateRelease(int|string $releaseID): void;

    /**
     * Delete a release from the search index.
     *
     * @param  int  $id  Release ID
     */
    public function deleteRelease(int $id): void;

    /**
     * Delete multiple releases from the index in one request.
     *
     * @param  iterable<int|string>  $ids
     */
    public function deleteReleases(iterable $ids): void;

    /**
     * Return the bounded release-document projection after an id cursor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function releaseDocumentsAfterId(int $afterId, int $limit): array;

    /**
     * Insert a predb record into the search index.
     *
     * @param  array<string, mixed>  $parameters  Predb data with 'id', 'title', 'filename', 'source'
     */
    public function insertPredb(array $parameters): void;

    /**
     * Update a predb record in the search index.
     *
     * @param  array<string, mixed>  $parameters  Predb data with 'id', 'title', 'filename', 'source'
     */
    public function updatePreDb(array $parameters): void;

    /**
     * Search the releases index.
     *
     * @param  array<string, mixed>|string  $phrases  Search phrases
     * @param  int  $limit  Maximum number of results
     * @return array<string, mixed> Array of release IDs
     */
    public function searchReleases(array|string $phrases, int $limit = 1000): array;

    /**
     * Search releases with fuzzy fallback.
     *
     * If exact search returns no results and fuzzy is enabled, this method
     * will automatically try a fuzzy search as a fallback.
     *
     * @param  array<string, mixed>|string  $phrases  Search phrases
     * @param  int  $limit  Maximum number of results
     * @param  bool  $forceFuzzy  Force fuzzy search regardless of exact results
     * @return array<string, mixed> Array with 'ids' (release IDs) and 'fuzzy' (bool indicating if fuzzy was used)
     */
    public function searchReleasesWithFuzzy(array|string $phrases, int $limit = 1000, bool $forceFuzzy = false): array;

    /**
     * Search the predb index.
     *
     * @param  array<string, mixed>|string  $searchTerm  Search term(s)
     * @return array<string, mixed> Array of predb records
     */
    public function searchPredb(array|string $searchTerm): array;

    /**
     * Get spell correction suggestions ("Did you mean?").
     *
     * @param  string  $query  The search query to check
     * @param  string|null  $index  Index to use for suggestions
     * @return array<array{suggest: string, distance: int, docs: int}>
     */
    public function suggest(string $query, ?string $index = null): array;

    /**
     * Truncate/clear an index (remove all documents).
     *
     * @param  array<string, mixed>|string  $indexes  Index name(s) to truncate
     */
    public function truncateIndex(array|string $indexes): void;

    /**
     * Optimize index for better search performance.
     */
    public function optimizeIndex(): void;

    /**
     * Bulk insert multiple releases into the index.
     *
     * @param  array<string, mixed>  $releases  Array of release data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertReleases(array $releases): array;

    /**
     * Bulk insert multiple predb records into the index.
     *
     * @param  array<string, mixed>  $predbRecords  Array of predb data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertPredb(array $predbRecords): array;

    /**
     * Insert a movie into the movies search index.
     *
     * @param  array<string, mixed>  $parameters  Movie data with id, imdbid, tmdbid, traktid, title, year, genre, actors, director, rating
     */
    public function insertMovie(array $parameters): void;

    /**
     * Delete a movie from the search index.
     *
     * @param  int  $id  Movie ID
     */
    public function deleteMovie(int $id): void;

    /**
     * Bulk insert multiple movies into the index.
     *
     * @param  array<string, mixed>  $movies  Array of movie data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertMovies(array $movies): array;

    /**
     * @param  array<string, string>  $fields
     * @return array{ids:list<int>, keys:list<int|string>, available:bool, has_more:bool}
     */
    public function searchEntityFields(string $index, array $fields, string $key, int $limit = 500, int $afterId = 0): array;

    /**
     * Search releases by external media IDs.
     * Used to find releases associated with a specific movie or TV show.
     *
     * @param  array<string, mixed>  $externalIds  Associative array of external IDs, e.g., ['imdbid' => 123456, 'tmdbid' => 789]
     * @param  int  $limit  Maximum number of results
     * @return array<string, mixed> Array of release IDs
     */
    public function searchReleasesByExternalId(array $externalIds, int $limit = 1000): array;

    /**
     * Filter/sort releases in the search index (category, age, size, group, password) with optional full-text.
     *
     * Web criteria additionally accept web_search, entity_filters (release attribute => entity keys),
     * poster, web_force_fuzzy, and sort_name ordering. Web callers request only one bounded page.
     *
     * @param  array<string, mixed>  $criteria  Keys: phrases (string|array|null), category_ids (list<int>|null),
     *                                          excluded_category_ids (list<int>), min_size (int), max_age_days (int),
     *                                          groups_id (int|null), password_allow_rar (bool), password_status_min (int|null),
     *                                          has_media_info (bool|null), media_unique_id (string|null),
     *                                          min_video_width (int), max_video_width (int),
     *                                          min_video_height (int), max_video_height (int), min_completion (int),
     *                                          sort_field (string), sort_dir (string), try_fuzzy (bool), release_ids (list<int>|null),
     *                                          excluded_movieinfo_id (int|null: a film whose releases are left out),
     *                                          imported_only (bool: only releases added from an imported NZB, `is_imported`)
     * @return array{ids: list<int>, total: int, fuzzy: bool, available?: bool, has_more?: bool}
     */
    public function searchReleasesFiltered(array $criteria, int $limit, int $offset = 0): array;

    public function searchReleasePage(ReleaseSearchQuery $query): SearchPage;

    /**
     * Upsert a metadata document (music, books, games, console, steam, anime).
     *
     * @param  array<string, mixed>  $document  Fields for the index (no id key required)
     */
    public function insertSecondary(SecondarySearchIndex $index, int $id, array $document): void;

    public function deleteSecondary(SecondarySearchIndex $index, int $id): void;

    /**
     * @param  array<int, array<string, mixed>>  $documents  Each row must include int 'id'
     * @return array<string, mixed>
     */
    public function bulkInsertSecondary(SecondarySearchIndex $index, array $documents): array;

    /**
     * Full-text search on a secondary index.
     *
     * @return array{id: list<int>, data: list<array<string, mixed>>}
     */
    public function searchSecondary(SecondarySearchIndex $index, string $query, int $limit = 100): array;

    /**
     * @return list<int> Distinct anidbids from anime index hits
     */
    public function searchAnimeTitle(string $query, int $limit = 100): array;
}
