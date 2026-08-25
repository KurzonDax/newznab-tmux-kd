<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseCleaningService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Service for updating releases with new names.
 *
 * Handles the actual database updates and category re-determination
 * when a release is renamed.
 */
class ReleaseUpdateService
{
    /**
     * @var list<string>
     */
    private const PLAUSIBILITY_TRUSTED_TYPES = [
        'UID, ',
        'PAR2 hash, ',
        'CRC32, ',
        'SRRDB, ',
        'SRR, ',
    ];

    /**
     * @var list<string>
     */
    private const DONOR_TRUSTED_TYPES = [
        'NFO, ',
        'PAR2, ',
        'UID, ',
        'Mediainfo, ',
        'PAR2 hash, ',
        'SRR, ',
        'CRC32, ',
        'SRRDB, ',
        'PreDB FT Exact, ',
        'PreDB file match, ',
        'Audio tags, ',
    ];

    /**
     * @var list<string>
     */
    private const SINGLE_UPDATE_COLUMNS = [
        'proc_nfo',
        'proc_files',
        'proc_xxx',
        'proc_media_movie',
        'proc_par2',
        'proc_uid',
        'proc_hash16k',
        'proc_srr',
        'proc_crc32',
        'proc_srrdb',
    ];

    /**
     * PreDB regex pattern for scene release names.
     */
    public const PREDB_REGEX = '/([\w.\'()\[\]-]+(?:[\s._-]+[\w.\'()\[\]-]+)+[-.][\w]+)/ui';

    // Constants for name fixing status
    public const PROC_NFO_NONE = 0;

    public const PROC_NFO_DONE = 1;

    public const PROC_FILES_NONE = 0;

    public const PROC_FILES_DONE = 1;

    public const PROC_PAR2_NONE = 0;

    public const PROC_PAR2_DONE = 1;

    public const PROC_UID_NONE = 0;

    public const PROC_UID_DONE = 1;

    public const PROC_HASH16K_NONE = 0;

    public const PROC_HASH16K_DONE = 1;

    public const PROC_SRR_NONE = 0;

    public const PROC_SRR_DONE = 1;

    public const PROC_CRC_NONE = 0;

    public const PROC_CRC_DONE = 1;

    // Constants for overall rename status
    public const IS_RENAMED_NONE = 0;

    public const IS_RENAMED_DONE = 1;

    protected CategorizationService $category;

    protected FileNameCleaner $fileNameCleaner;

    protected bool $echoOutput;

    private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator;

    /**
     * The release ID we are trying to rename.
     */
    protected int $relid = 0;

    /**
     * Has the current release found a new name?
     */
    public bool $matched = false;

    /**
     * Was the check completed?
     */
    public bool $done = false;

    /**
     * How many releases have got a new name?
     */
    public int $fixed = 0;

    /**
     * How many releases were checked.
     */
    public int $checked = 0;

    public function __construct(
        ?CategorizationService $category = null,
        ?FileNameCleaner $fileNameCleaner = null,
        ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
    ) {
        $this->category = $category ?? new CategorizationService;
        $this->fileNameCleaner = $fileNameCleaner ?? new FileNameCleaner;
        $this->searchSyncCoordinator = $searchSyncCoordinator
            ?? new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $this->echoOutput = config('nntmux.echocli');
    }

    /**
     * Update the release with the new information.
     *
     * @param  object|array<string, mixed>  $release  The release to update
     * @param  string  $name  The new name
     * @param  string  $method  The method that found the name
     * @param  bool  $echo  Whether to actually update the database
     * @param  string  $type  The type string for logging
     * @param  bool  $nameStatus  Whether to update status columns
     * @param  bool  $show  Whether to show output
     * @param  int|null  $preId  PreDB ID if matched
     * @param  bool  $descriptiveTitleCandidate  Whether this came from the guarded video-file fallback
     *
     * @throws \Exception
     */
    public function updateRelease(
        object|array $release,
        string $name,
        string $method,
        bool $echo,
        string $type,
        bool $nameStatus,
        bool $show,
        ?int $preId = 0,
        bool $descriptiveTitleCandidate = false,
    ): void {
        $preId = $preId ?? 0;
        if (is_array($release)) {
            $release = (object) $release;
        }
        $imdbId = $type === 'SRRDB, ' && isset($release->srrdb_imdbid)
            ? (string) $release->srrdb_imdbid
            : null;

        // If $release does not have a releases_id, we should add it.
        if (! isset($release->releases_id)) {
            $release->releases_id = $release->id;
        }

        if ($this->relid !== $release->releases_id) {
            $cleanedName = (new ReleaseCleaningService)->fixerCleaner($name);
            // Normalize and sanity-check candidate for non-trusted sources
            $normalizedName = $this->fileNameCleaner->normalizeCandidateTitle($cleanedName);
            $newName = $this->fileNameCleaner->formatSearchName($cleanedName, $normalizedName);

            // Determine if the source is trusted enough to bypass plausibility checks
            $sourceTrust = $this->sourceTrustPolicy($type, $method, $preId);
            $trustedSource = $sourceTrust['bypass_plausibility'];
            $acceptedDescriptiveTitle = $descriptiveTitleCandidate
                && $this->fileNameCleaner->isDescriptiveTitle($name)
                && $this->fileNameCleaner->currentNameLooksObfuscated(
                    (string) $release->searchname,
                    (int) ($release->categories_id ?? $release->categoryid ?? 0),
                    isset($release->matchedBy)
                        ? (string) $release->matchedBy
                        : (isset($release->matched_by) ? (string) $release->matched_by : null),
                );

            if (! $trustedSource
                && ! $acceptedDescriptiveTitle
                && ! $this->fileNameCleaner->isPlausibleReleaseTitle($normalizedName)) {
                // Skip low-quality rename candidates for untrusted sources
                $this->done = true;

                return;
            }

            if (strtolower($newName) !== strtolower($release->searchname)) {
                $this->matched = true;
                $this->relid = (int) $release->releases_id;

                if ($type === 'PAR2, ') {
                    $newName = ucwords($newName);
                    if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $hit)) {
                        $newName = $hit[1];
                    }
                }

                $this->fixed++;

                // Split on path separator backslash to strip any path
                $newName = explode('\\', $newName);
                $newName = preg_replace(['/^[=_.:\s-]+/', '/[=_.:\s-]+$/'], '', $newName[0]);

                $newTitle = substr($newName, 0, 299);

                $determinedCategory = null;
                if ($this->echoOutput && $show) {
                    $determinedCategory = $this->category->determineCategory(
                        $release->groups_id,
                        $newTitle,
                        ! empty($release->fromname) ? $release->fromname : '',
                        releaseId: (int) $release->releases_id,
                    );

                    $this->echoReleaseInfo($release, $newTitle, $determinedCategory, $type, $method);
                }

                if ($echo === true) {
                    $this->performDatabaseUpdate(
                        $release,
                        $newTitle,
                        $type,
                        $method,
                        $nameStatus,
                        $preId,
                        $imdbId,
                    );
                }
            }
        }
        $this->done = true;
    }

    /**
     * Classify the evidence once for plausibility and donor-trust decisions.
     *
     * @return array{bypass_plausibility: bool, trusted_donor: bool}
     */
    protected function sourceTrustPolicy(string $type, string $method, int $preId): array
    {
        $normalizedMethod = strtolower($method);
        $sharedTrustedMethod = str_contains($normalizedMethod, 'title match')
            || str_contains($normalizedMethod, 'file matched source')
            || str_contains($normalizedMethod, 'predb');
        $preDbType = str_starts_with($type, 'PreDB') || str_starts_with($type, 'PreDb');

        return [
            'bypass_plausibility' => $preId > 0
                || $preDbType
                || in_array($type, self::PLAUSIBILITY_TRUSTED_TYPES, true)
                || $sharedTrustedMethod,
            'trusted_donor' => $preId > 0
                || in_array($type, self::DONOR_TRUSTED_TYPES, true)
                || $sharedTrustedMethod
                || str_contains($normalizedMethod, 'nzbsplit wrapper')
                || str_contains($normalizedMethod, 'rarinfo filename match')
                || str_contains($normalizedMethod, 'rarinfo predb match'),
        ];
    }

    /**
     * Echo release information to CLI.
     *
     * @param  array<string, mixed>  $determinedCategory
     */
    public function echoReleaseInfo(
        object $release,
        string $newName,
        array $determinedCategory,
        string $type,
        string $method
    ): void {
        $groupName = UsenetGroup::getNameByID($release->groups_id);
        $oldCatName = Category::getNameByID($release->categories_id);
        $newCatName = Category::getNameByID($determinedCategory['categories_id']);

        if ($type === 'PAR2, ') {
            echo PHP_EOL;
        }

        echo PHP_EOL;

        cli()->primary('Release Information:');

        echo '  '.cli()->headerOver('New name:   ').cli()->primary(substr($newName, 0, 100)).PHP_EOL;
        echo '  '.cli()->headerOver('Old name:   ').cli()->primary(substr((string) $release->searchname, 0, 100)).PHP_EOL;
        echo '  '.cli()->headerOver('Use name:   ').cli()->primary(substr((string) $release->name, 0, 100)).PHP_EOL;
        echo PHP_EOL;

        echo '  '.cli()->headerOver('New cat:    ').cli()->primary($newCatName).PHP_EOL;
        echo '  '.cli()->headerOver('Old cat:    ').cli()->primary($oldCatName).PHP_EOL;
        echo '  '.cli()->headerOver('Group:      ').cli()->primary($groupName).PHP_EOL;
        echo PHP_EOL;

        echo '  '.cli()->headerOver('Method:     ').cli()->primary($type.$method).PHP_EOL;
        echo '  '.cli()->headerOver('Release ID: ').cli()->primary((string) $release->releases_id).PHP_EOL;

        if (! empty($release->filename)) {
            echo '  '.cli()->headerOver('Filename:   ').cli()->primary(substr((string) $release->filename, 0, 100)).PHP_EOL;
        }

        if ($type !== 'PAR2, ') {
            echo PHP_EOL;
        }
    }

    /**
     * Perform the actual database update.
     */
    protected function performDatabaseUpdate(
        object $release,
        string $newTitle,
        string $type,
        string $method,
        bool $nameStatus,
        int $preId,
        ?string $imdbId,
        ?int $categoryOverride = null,
        bool $preserveBookInfo = false,
    ): void {
        $releaseId = (int) ($release->releases_id ?? $release->id);
        $trustedDonorName = $this->sourceTrustPolicy($type, $method, $preId)['trusted_donor'];

        DB::transaction(function () use ($release, $releaseId, $newTitle, $type, $nameStatus, $preId, $trustedDonorName, $imdbId, $categoryOverride, $preserveBookInfo): void {
            if ($nameStatus === true) {
                $status = $this->getStatusColumnsForType($type);

                $updateColumns = [
                    'videos_id' => 0,
                    'tv_episodes_id' => 0,
                    'movieinfo_id' => null,
                    'imdbid' => $imdbId,
                    'musicinfo_id' => null,
                    'consoleinfo_id' => null,
                    'bookinfo_id' => null,
                    'anidbid' => null,
                    'gamesinfo_id' => 0,
                    'predb_id' => $preId,
                    'searchname' => $newTitle,
                    'is_trusted_name' => $trustedDonorName,
                ];

                if ($preserveBookInfo) {
                    unset($updateColumns['bookinfo_id']);
                }

                if ($categoryOverride !== null) {
                    $updateColumns['categories_id'] = $categoryOverride;
                }

                if (! empty($status)) {
                    foreach ($status as $key => $stat) {
                        $updateColumns = Arr::add($updateColumns, $key, $stat);
                    }
                }

                Release::query()
                    ->where('id', $releaseId)
                    ->update($updateColumns);
            } else {
                Release::query()
                    ->where('id', $releaseId)
                    ->update(array_filter([
                        'videos_id' => 0,
                        'tv_episodes_id' => 0,
                        'movieinfo_id' => null,
                        'imdbid' => $imdbId,
                        'musicinfo_id' => null,
                        'consoleinfo_id' => null,
                        'bookinfo_id' => null,
                        'anidbid' => null,
                        'gamesinfo_id' => 0,
                        'predb_id' => $preId,
                        'searchname' => $newTitle,
                        'is_trusted_name' => $trustedDonorName,
                        'iscategorized' => 1,
                        'categories_id' => $categoryOverride,
                    ], static fn (mixed $value, string $key): bool => $key !== 'categories_id' || $value !== null, ARRAY_FILTER_USE_BOTH));
            }

            event(new ReleaseNameFixed(
                $releaseId,
                (string) $release->searchname,
                $newTitle,
                (int) $release->categories_id,
                $release->groups_id,
                (string) ($release->fromname ?? ''),
                $categoryOverride,
            ));
        });

        $this->searchSyncCoordinator->request($releaseId);
    }

    /**
     * Get the status columns to update for a given type.
     *
     * @return array<string, mixed>
     */
    protected function getStatusColumnsForType(string $type): array
    {
        return match ($type) {
            'NFO, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_nfo' => 1],
            'PAR2, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_par2' => 1],
            'Filenames, ', 'file matched source: ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_files' => 1],
            'XXX filenames, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_xxx' => 1],
            'PreDB FT Exact, ' => ['isrenamed' => 1, 'iscategorized' => 1],
            'PreDB exact, ' => ['isrenamed' => 1, 'iscategorized' => 1],
            'Audio tags, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_pp' => 1],
            'Book title, ' => ['isrenamed' => 1, 'iscategorized' => 1],
            'sorter, ' => ['isrenamed' => 1, 'iscategorized' => 1],
            'UID, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_uid' => 1],
            'Mediainfo, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_media_movie' => 1],
            'PAR2 hash, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_hash16k' => 1],
            'SRR, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_srr' => 1],
            'CRC32, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_crc32' => 1],
            'SRRDB, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_srrdb' => 1],
            default => [],
        };
    }

    /**
     * Update a single column in releases.
     */
    public function updateSingleColumn(string $column, int $status, int $id): void
    {
        if (! in_array($column, self::SINGLE_UPDATE_COLUMNS, true)) {
            throw new InvalidArgumentException("Unsupported release status column [{$column}].");
        }

        if ($id !== 0) {
            DB::update("UPDATE releases SET {$column} = ? WHERE id = ?", [$status, $id]);
        }
    }

    public function attachPredbId(int $releaseId, int $predbId): void
    {
        if ($releaseId === 0 || $predbId === 0) {
            return;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return;
        }

        $this->performDatabaseUpdate(
            $release,
            (string) $release->searchname,
            'PreDB exact, ',
            'Exact title match',
            true,
            $predbId,
            null,
        );
        $this->relid = $releaseId;
        $this->matched = true;
        $this->done = true;
    }

    public function renameFromAudioTags(int $releaseId, string $newTitle, int $categoryId): void
    {
        if ($releaseId === 0 || $newTitle === '') {
            return;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return;
        }

        $this->performDatabaseUpdate(
            $release,
            $newTitle,
            'Audio tags, ',
            'Embedded media tags',
            true,
            0,
            null,
            $categoryId,
        );
    }

    public function renameFromBookMetadata(int $releaseId, string $newTitle): void
    {
        if ($releaseId === 0 || $newTitle === '') {
            return;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return;
        }

        $this->performDatabaseUpdate(
            $release,
            $newTitle,
            'Book title, ',
            'Parsed book metadata',
            true,
            0,
            null,
            preserveBookInfo: true,
        );
    }

    public function attachSrrdbMatch(int $releaseId, int $predbId, ?string $imdbId): void
    {
        if ($releaseId === 0 || $predbId === 0) {
            return;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return;
        }

        $this->performDatabaseUpdate(
            $release,
            (string) $release->searchname,
            'SRRDB, ',
            'Verified archive CRC match',
            true,
            $predbId,
            $imdbId,
        );
        $this->relid = $releaseId;
        $this->matched = true;
        $this->done = true;
    }

    /**
     * Check if a release matches a PreDB entry.
     *
     * @return array<string, mixed>
     */
    public function checkPreDbMatch(object $release, string $textstring): ?array
    {
        if (! preg_match_all(self::PREDB_REGEX, $textstring, $hits) || preg_match('/Source\s:/i', $textstring)) {
            return null;
        }

        $candidates = [];
        foreach ($hits as $hit) {
            foreach ($hit as $value) {
                $title = trim($value);
                if ($title !== '') {
                    $candidates[$title] = $title;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        $candidateValues = array_values($candidates);
        $rows = DB::select(
            'SELECT id, title FROM predb WHERE title IN ('.implode(',', array_fill(0, count($candidateValues), '?')).')',
            $candidateValues
        );
        $matches = [];
        foreach ($rows as $row) {
            $matches[(string) $row->title] = ['title' => (string) $row->title, 'id' => (int) $row->id];
        }

        foreach ($candidateValues as $candidate) {
            if (isset($matches[$candidate])) {
                return $matches[$candidate];
            }
        }

        return null;
    }

    /**
     * Reset status variables for new processing.
     */
    public function reset(): void
    {
        $this->done = $this->matched = false;
    }

    /**
     * Increment the checked counter.
     */
    public function incrementChecked(): void
    {
        $this->checked++;
    }

    /**
     * Get the current statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'fixed' => $this->fixed,
            'checked' => $this->checked,
            'matched' => $this->matched,
            'done' => $this->done,
        ];
    }
}
