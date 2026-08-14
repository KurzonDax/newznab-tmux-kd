<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\RootCategory;
use App\Models\Settings;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Discards releases that contain executable files.
 *
 * A discard is a permanent, complete purge of a release (database row with
 * cascaded file rows, NZB on disk, preview/cover images, search-index
 * documents), controlled per root category via `root_categories.discard_executables`
 * and tuned globally through the `discard_executable_extensions` setting.
 * Distinct from the `innerfileblacklist` hide-as-passworded mechanism, which
 * keeps the release. See docs/adr/0003-discard-executable-releases.md.
 */
class ExecutableReleaseDiscardService
{
    public const string DEFAULT_EXTENSIONS = 'dll|exe|msi|scr|com|bat|cmd|pif';

    public const string EXTENSIONS_SETTING = 'discard_executable_extensions';

    private const int SWEEP_BATCH_SIZE = 500;

    /**
     * Release columns needed for the discard action and its audit log line.
     */
    private const array RELEASE_COLUMNS = ['id', 'guid', 'name', 'searchname', 'fromname', 'categories_id'];

    private ?string $extensionRegex = null;

    /**
     * @var list<string>|null
     */
    private ?array $extensions = null;

    /**
     * @var array<int, bool>|null
     */
    private ?array $rootToggles = null;

    /**
     * @var array<int, int|null>
     */
    private array $categoryRootMap = [];

    public function __construct(
        private readonly ReleaseManagementService $releaseManagement = new ReleaseManagementService,
    ) {}

    /**
     * Does this file name carry one of the configured executable extensions?
     */
    public function matchesExecutablePattern(string $fileName): bool
    {
        return $fileName !== '' && preg_match($this->extensionRegex(), $fileName) === 1;
    }

    /**
     * Should a release in this leaf category be discarded for carrying this file?
     */
    public function shouldDiscard(string $fileName, int $categoriesId): bool
    {
        return $this->matchesExecutablePattern($fileName) && $this->discardEnabledForCategory($categoriesId);
    }

    /**
     * Is discarding enabled for the root category this leaf category rolls up to?
     */
    public function discardEnabledForCategory(int $categoriesId): bool
    {
        $rootCategoryId = $this->rootCategoryIdFor($categoriesId);

        return $rootCategoryId !== null && ($this->rootToggles()[$rootCategoryId] ?? false);
    }

    /**
     * Scan a complete file list (PAR2 or archive entries with a `name` key) and
     * return the first file name that warrants a discard, or null.
     *
     * @param  iterable<int|string, array<string, mixed>>  $files
     */
    public function firstDiscardableFileName(iterable $files, int $categoriesId): ?string
    {
        foreach ($files as $file) {
            if (! isset($file['name'])) {
                continue;
            }

            if ($this->shouldDiscard((string) $file['name'], $categoriesId)) {
                return (string) $file['name'];
            }
        }

        return null;
    }

    /**
     * Permanently purge a release and all its artifacts.
     *
     * Delegates to the existing complete-delete entry point, which removes the
     * NZB file, preview/cover images, the search-index document and the release
     * row (file rows follow by foreign-key cascade).
     */
    public function discard(Release $release, string $matchedFileName): void
    {
        Log::warning('Discarding release containing executable file', [
            'release_id' => (int) $release->id,
            'name' => (string) ($release->searchname ?? $release->name ?? ''),
            'categories_id' => (int) $release->categories_id,
            'poster' => (string) ($release->fromname ?? ''),
            'file' => $matchedFileName,
        ]);

        $this->releaseManagement->deleteSingle(
            ['g' => (string) $release->guid, 'i' => (int) $release->id],
            app(NzbService::class),
            new ReleaseImageService,
        );
    }

    /**
     * Discard a release by ID, loading the columns needed for the audit log.
     *
     * @return bool True when the release existed and was discarded.
     */
    public function discardById(int $releaseId, string $matchedFileName): bool
    {
        $release = Release::query()
            ->where('id', $releaseId)
            ->first(self::RELEASE_COLUMNS);

        if ($release === null) {
            return false;
        }

        $this->discard($release, $matchedFileName);

        return true;
    }

    /**
     * Purge every existing release with a recorded executable file in a
     * toggled-on root category. Covers the backlog plus anything that slipped
     * past inline detection.
     *
     * @param  callable(Release, string): void|null  $onDiscard  Invoked after each discard.
     * @return int Number of releases discarded.
     */
    public function sweep(?callable $onDiscard = null): int
    {
        $categoryIds = $this->categoryIdsWithDiscardEnabled();

        if ($categoryIds === []) {
            return 0;
        }

        $discarded = 0;

        Release::query()
            ->whereIn('categories_id', $categoryIds)
            ->whereExists(function (Builder $query): void {
                $query->select(DB::raw(1))
                    ->from('release_files')
                    ->whereColumn('release_files.releases_id', 'releases.id')
                    ->where(function (Builder $fileQuery): void {
                        // LOWER() keeps the prefilter case-insensitive even under
                        // a binary collation; extensions are already lowercased.
                        foreach ($this->extensions() as $extension) {
                            $fileQuery->orWhereRaw('LOWER(release_files.name) LIKE ?', ['%.'.$this->escapeLike($extension)]);
                        }
                    });
            })
            ->select(self::RELEASE_COLUMNS)
            ->chunkById(self::SWEEP_BATCH_SIZE, function ($releases) use (&$discarded, $onDiscard): bool {
                foreach ($releases as $release) {
                    $matchedFileName = $this->firstMatchingFileName((int) $release->id);

                    if ($matchedFileName === null) {
                        continue;
                    }

                    $this->discard($release, $matchedFileName);
                    $discarded++;

                    if ($onDiscard !== null) {
                        $onDiscard($release, $matchedFileName);
                    }
                }

                return true;
            });

        return $discarded;
    }

    private function extensionRegex(): string
    {
        if ($this->extensionRegex === null) {
            $quoted = array_map(
                static fn (string $extension): string => preg_quote($extension, '/'),
                $this->extensions()
            );

            $this->extensionRegex = '/\.(?:'.implode('|', $quoted).')$/i';
        }

        return $this->extensionRegex;
    }

    /**
     * The configured extension list, treated as literal extensions separated
     * by `|`. Falls back to the shipped default when the setting is blank.
     *
     * @return list<string>
     */
    private function extensions(): array
    {
        if ($this->extensions === null) {
            $value = Settings::settingValue(self::EXTENSIONS_SETTING);
            $raw = \is_string($value) ? $value : '';

            $parts = array_values(array_filter(
                array_map(static fn (string $part): string => strtolower(trim($part)), explode('|', $raw)),
                static fn (string $part): bool => $part !== ''
            ));

            $this->extensions = $parts === [] ? explode('|', self::DEFAULT_EXTENSIONS) : $parts;
        }

        return $this->extensions;
    }

    /**
     * @return array<int, bool>
     */
    private function rootToggles(): array
    {
        if ($this->rootToggles === null) {
            $toggles = [];

            foreach (RootCategory::query()->pluck('discard_executables', 'id') as $id => $enabled) {
                $toggles[(int) $id] = (bool) $enabled;
            }

            $this->rootToggles = $toggles;
        }

        return $this->rootToggles;
    }

    private function rootCategoryIdFor(int $categoriesId): ?int
    {
        if (! \array_key_exists($categoriesId, $this->categoryRootMap)) {
            $rootCategoryId = Category::query()->where('id', $categoriesId)->value('root_categories_id');
            $this->categoryRootMap[$categoriesId] = $rootCategoryId === null ? null : (int) $rootCategoryId;
        }

        return $this->categoryRootMap[$categoriesId];
    }

    /**
     * @return list<int>
     */
    private function categoryIdsWithDiscardEnabled(): array
    {
        $rootIds = array_keys(array_filter($this->rootToggles()));

        if ($rootIds === []) {
            return [];
        }

        return Category::query()
            ->whereIn('root_categories_id', $rootIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function firstMatchingFileName(int $releaseId): ?string
    {
        foreach (ReleaseFile::query()->where('releases_id', $releaseId)->pluck('name') as $name) {
            if ($this->matchesExecutablePattern((string) $name)) {
                return (string) $name;
            }
        }

        return null;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
