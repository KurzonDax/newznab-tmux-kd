<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\ObfuscationRecovery\RecoveryNameEvidence;
use App\Services\Releases\ForcedRootPolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Service for updating releases with new names.
 *
 * Handles the actual database updates and category re-determination
 * when a release is renamed.
 */
class ReleaseUpdateService
{
    public const DESCRIPTIVE_MEDIA_TITLE_METHOD = 'MediaInfo: Descriptive title';

    /** The rename type of a name built from a release's own audio tags. */
    public const AUDIO_TAGS_TYPE = 'Audio tags, ';

    /** The rename type of the canonical name of an accepted MusicBrainz album (issue #309). */
    public const MUSICBRAINZ_TYPE = 'MusicBrainz, ';

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

    private ?NamePolicy $namePolicy = null;

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
     * @param  bool  $descriptiveTitleCandidate  Whether this came from a guarded descriptive-title fallback
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
        ?RecoveryNameEvidence $recoveryEvidence = null,
    ): void {
        $preId = $preId ?? 0;
        if (is_array($release)) {
            $release = (object) $release;
        }

        // If $release does not have a releases_id, we should add it.
        if (! isset($release->releases_id)) {
            $release->releases_id = $release->id;
        }
        $releaseId = (int) $release->releases_id;
        $candidate = new NameCandidate(
            $releaseId,
            $name,
            NameSource::fromLabels($type, $method),
            $method,
            $preId,
            $type === 'SRRDB, ' && isset($release->srrdb_imdbid) ? (string) $release->srrdb_imdbid : null,
            descriptiveTitle: $descriptiveTitleCandidate,
            recoveryEvidence: $recoveryEvidence,
        );

        $decision = $this->namePolicy()->decide(
            $release,
            $candidate,
            ! $echo || (new RecoveryIdentityPolicy)->allowsParent($releaseId, $recoveryEvidence),
            $this->relid === $release->releases_id,
            static fn (): array => self::storedFileSizes($releaseId),
        );
        if (! $decision->accepted()) {
            if ($decision->rejection === NameRejection::RecoveryRefused) {
                $this->matched = false;
            }
            $this->done = true;

            return;
        }

        $newTitle = $decision->title;
        $this->matched = true;
        $this->relid = $releaseId;

        $this->fixed++;

        if ($echo === true) {
            $this->performDatabaseUpdate(
                $release,
                $newTitle,
                $type,
                $method,
                $nameStatus,
                $preId,
                $candidate->imdbId,
                recoveryEvidence: $recoveryEvidence,
            );
        }

        if ($this->echoOutput && $show) {
            // A written rename was categorized by the rename listener inside
            // the update; show what it stored rather than categorizing again.
            $determinedCategory = $echo === true
                ? ['categories_id' => Release::query()->whereKey($releaseId)->value('categories_id') ?? $release->categories_id]
                : $this->category->determineCategory(
                    $release->groups_id,
                    $newTitle,
                    ! empty($release->fromname) ? $release->fromname : '',
                    releaseId: $releaseId,
                );

            $this->echoReleaseInfo($release, $newTitle, $determinedCategory, $type, $method);
        }
        $this->done = true;
    }

    /** Built on first use: test doubles skip the constructor and set the cleaner themselves. */
    private function namePolicy(): NamePolicy
    {
        return $this->namePolicy ??= new NamePolicy($this->fileNameCleaner);
    }

    /**
     * The release's stored file names and sizes, for the episode guard.
     *
     * @return array<string, int>
     */
    private static function storedFileSizes(int $releaseId): array
    {
        $sizes = [];
        if (Schema::hasTable('release_files')) {
            foreach (ReleaseFile::query()->where('releases_id', $releaseId)->get() as $file) {
                $sizes[(string) $file->name] = (int) ($file->size ?? 0);
            }
        }

        return $sizes;
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
        ?RecoveryNameEvidence $recoveryEvidence = null,
        bool $preserveMusicInfo = false,
    ): void {
        $releaseId = (int) ($release->releases_id ?? $release->id);
        $source = NameSource::fromLabels($type, $method);
        $trustedDonorName = NamePolicy::trust($source, $preId)[1] === TrustedDonor::Trusted;
        $nameSource = $source->storedName();
        DB::transaction(function () use ($source, $release, $releaseId, $newTitle, $nameStatus, $preId, $trustedDonorName, $nameSource, $imdbId, $categoryOverride, $preserveBookInfo, $preserveMusicInfo, $recoveryEvidence): void {
            $current = Release::query()->where('id', $releaseId)->lockForUpdate()->first();
            if ((! (new RecoveryIdentityPolicy)->allowsParent($releaseId, $recoveryEvidence) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
                return;
            }
            if ($categoryOverride !== null && $current !== null) {
                $categoryOverride = (new ForcedRootPolicy)->categoryForRelease($current->groups_id, $releaseId, $categoryOverride);
            }
            $recoveryEvidence?->assertNamingAllowed();
            if ($nameStatus === true) {
                $status = $source->statusColumns();

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
                    ...Release::searchNameValues($newTitle),
                    'is_trusted_name' => $trustedDonorName,
                    'name_source' => $nameSource,
                ];

                if ($preserveBookInfo) {
                    unset($updateColumns['bookinfo_id']);
                }

                if ($preserveMusicInfo) {
                    unset($updateColumns['musicinfo_id']);
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
                        ...Release::searchNameValues($newTitle),
                        'is_trusted_name' => $trustedDonorName,
                        'name_source' => $nameSource,
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
     * The `releases.name_source` a rename of this type records: the type without its trailing
     * separator ("Audio tags, " records "Audio tags"); null for an empty type.
     */
    public static function nameSource(string $type): ?string
    {
        $source = trim(rtrim($type, ' ,:'));

        return $source === '' ? null : substr($source, 0, 64);
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

    public function renameRecoveredInventory(Release $release, string $candidate, RecoveryNameEvidence $evidence): bool
    {
        if (! (new RecoveryIdentityPolicy)->allowsDescriptiveCandidate((int) $release->id, $candidate, $evidence)) {
            return false;
        }
        $this->performDatabaseUpdate($release, $candidate, 'Recovery inventory, ', 'Consistent protected inventory', true,
            0, null, Category::TV_OTHER, recoveryEvidence: $evidence);

        return true;
    }

    public function attachPredbId(int $releaseId, int $predbId, ?RecoveryNameEvidence $recoveryEvidence = null): void
    {
        if ($releaseId === 0 || $predbId === 0 || (! (new RecoveryIdentityPolicy)->allowsParent($releaseId, $recoveryEvidence) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
            return;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return;
        }

        $this->performDatabaseUpdate(
            $release,
            $this->namePolicy()->preserveEvidence($release, (string) $release->searchname),
            'PreDB exact, ',
            'Exact title match',
            true,
            $predbId,
            null,
            recoveryEvidence: $recoveryEvidence,
        );
        $this->relid = $releaseId;
        $this->matched = true;
        $this->done = true;
    }

    public function renameFromAudioTags(int $releaseId, string $newTitle, int $categoryId): ?string
    {
        if ($releaseId === 0 || $newTitle === '') {
            return null;
        }

        if ((! (new RecoveryIdentityPolicy)->allowsParent($releaseId) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
            return null;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return null;
        }

        $newTitle = $this->namePolicy()->preserveEvidence($release, $newTitle);

        $this->performDatabaseUpdate(
            $release,
            $newTitle,
            self::AUDIO_TAGS_TYPE,
            'Embedded media tags',
            true,
            0,
            null,
            $categoryId,
        );

        return $newTitle;
    }

    /**
     * The canonical name of the release's accepted MusicBrainz album (issue #309). The release keeps
     * its category and its legacy `musicinfo_id`; the name becomes trusted and sets `proc_pp`, so a
     * later tag rename never replaces it. Returns the written name, or null when refused.
     */
    public function renameFromMusicIdentity(int $releaseId, string $newTitle): ?string
    {
        if ($releaseId === 0 || $newTitle === '') {
            return null;
        }

        if ((! (new RecoveryIdentityPolicy)->allowsParent($releaseId) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
            return null;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return null;
        }

        // The name is whole as built: carrying a token over from the outgoing name would guess
        // a component (a format, a year) the accepted identity and observed audio do not give.
        $this->performDatabaseUpdate(
            $release,
            $newTitle,
            self::MUSICBRAINZ_TYPE,
            'Accepted MusicBrainz album',
            true,
            0,
            null,
            (int) $release->categories_id,
            preserveMusicInfo: true,
        );

        return $newTitle;
    }

    /**
     * Gives a release back the values a reverted MusicBrainz rename replaced (issue #309), as a
     * guarded name write like the rename itself: the same identity policy checks, the name event
     * with the category the release keeps, and the search re-sync. Returns whether it wrote.
     *
     * @param  array<string, scalar|null>  $columns  prior values, the search name among them
     */
    public function restoreFromMusicIdentity(int $releaseId, array $columns): bool
    {
        if ($releaseId === 0 || ! isset($columns['searchname'])) {
            return false;
        }
        $columns = [...$columns, ...Release::searchNameValues((string) $columns['searchname'])];

        $written = DB::transaction(function () use ($releaseId, $columns): bool {
            $current = Release::query()->where('id', $releaseId)->lockForUpdate()->first();
            if ($current === null || ! (new RecoveryIdentityPolicy)->allowsParent($releaseId) || ! BundleIdentity::allowsSingleTitle($releaseId)) {
                return false;
            }
            $category = (int) ($columns['categories_id'] ?? $current->categories_id);
            Release::query()->where('id', $releaseId)->update($columns);

            event(new ReleaseNameFixed(
                $releaseId,
                (string) $current->searchname,
                (string) $columns['searchname'],
                (int) $current->categories_id,
                $current->groups_id,
                (string) ($current->fromname ?? ''),
                $category,
            ));

            return true;
        });
        if ($written) {
            $this->searchSyncCoordinator->request($releaseId);
        }

        return $written;
    }

    public function renameFromBookMetadata(int $releaseId, string $newTitle): void
    {
        if ($releaseId === 0 || $newTitle === '') {
            return;
        }

        if ((! (new RecoveryIdentityPolicy)->allowsParent($releaseId) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
            return;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return;
        }

        $this->performDatabaseUpdate(
            $release,
            $this->namePolicy()->preserveEvidence($release, $newTitle),
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

        if ((! (new RecoveryIdentityPolicy)->allowsParent($releaseId) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
            return;
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return;
        }

        $this->performDatabaseUpdate(
            $release,
            $this->namePolicy()->preserveEvidence($release, (string) $release->searchname),
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
