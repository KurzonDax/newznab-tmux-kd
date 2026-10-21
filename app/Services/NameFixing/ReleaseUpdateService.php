<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\Categorization\CategorizationService;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\ObfuscationRecovery\RecoveryNameEvidence;
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

    private ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null;

    private ?NamePolicy $namePolicy = null;

    private ?ReleaseNameWriter $writer = null;

    /**
     * The releases this instance renamed: each is renamed once per instance.
     *
     * @var array<int, true>
     */
    private array $renamedReleaseIds = [];

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
        $this->searchSyncCoordinator = $searchSyncCoordinator;
        $this->echoOutput = config('nntmux.echocli');
    }

    /**
     * Update the release with the new information.
     *
     * @param  object|array<string, mixed>  $release  The release to update
     * @param  string  $name  The new name
     * @param  string  $method  The method that found the name
     * @param  bool  $echo  Whether to actually update the database
     * @param  NameSource  $source  The source and evidence kind of the name
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
        NameSource $source,
        bool $nameStatus,
        bool $show,
        ?int $preId = 0,
        bool $descriptiveTitleCandidate = false,
        ?RecoveryNameEvidence $recoveryEvidence = null,
    ): NameUpdateResult {
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
            $source,
            $method,
            $preId,
            $source === NameSource::SrrdbArchiveCrc && isset($release->srrdb_imdbid) ? (string) $release->srrdb_imdbid : null,
            descriptiveTitle: $descriptiveTitleCandidate,
            recoveryEvidence: $recoveryEvidence,
        );

        $decision = $this->writer()->rename(
            $release,
            $candidate,
            $nameStatus,
            $echo === true,
            ! $echo || (new RecoveryIdentityPolicy)->allowsParent($releaseId, $recoveryEvidence),
            isset($this->renamedReleaseIds[$releaseId]),
            static fn (): array => self::storedFileSizes($releaseId),
        )->decision;
        if (! $decision->accepted()) {
            return NameUpdateResult::declined($decision->rejection);
        }

        $newTitle = $decision->title;
        $this->renamedReleaseIds[$releaseId] = true;

        $this->fixed++;

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

            $this->echoReleaseInfo($release, $newTitle, $determinedCategory, $source->type(), $method);
        }

        return NameUpdateResult::renamed($newTitle);
    }

    /** Built on first use: test doubles skip the constructor and set the cleaner themselves. */
    private function namePolicy(): NamePolicy
    {
        return $this->namePolicy ??= new NamePolicy($this->fileNameCleaner);
    }

    private function writer(): ReleaseNameWriter
    {
        return $this->writer ??= new ReleaseNameWriter($this->searchSyncCoordinator, $this->namePolicy());
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
        $this->writer()->rename($release, new NameCandidate((int) $release->id, $candidate, NameSource::RecoveryInventory,
            'Consistent protected inventory', categoryId: Category::TV_OTHER, recoveryEvidence: $evidence));

        return true;
    }

    /** Gives a release whose name equals a PreDB title that PreDB entry, and trusts the name. */
    public function attachPredbId(int $releaseId, int $predbId, ?RecoveryNameEvidence $recoveryEvidence = null): NameUpdateResult
    {
        if ($releaseId === 0 || $predbId === 0 || (! (new RecoveryIdentityPolicy)->allowsParent($releaseId, $recoveryEvidence) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
            return NameUpdateResult::declined(NameRejection::RecoveryRefused);
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return NameUpdateResult::declined();
        }

        $title = $this->namePolicy()->preserveEvidence($release, (string) $release->searchname);
        $this->writer()->rename($release, new NameCandidate(
            $releaseId,
            $title,
            NameSource::PreDbExactAttach,
            'Exact title match',
            $predbId,
            recoveryEvidence: $recoveryEvidence,
        ));
        $this->renamedReleaseIds[$releaseId] = true;

        return NameUpdateResult::renamed($title);
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

        $this->writer()->rename($release, new NameCandidate($releaseId, $newTitle, NameSource::AudioTags, 'Embedded media tags', categoryId: $categoryId));

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
        $this->writer()->rename($release, new NameCandidate(
            $releaseId,
            $newTitle,
            NameSource::MusicBrainz,
            'Accepted MusicBrainz album',
            categoryId: (int) $release->categories_id,
            keepLinks: ['musicinfo_id'],
        ));

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
        return $this->writer()->restorePriorValues($releaseId, $columns);
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

        $this->writer()->rename($release, new NameCandidate(
            $releaseId,
            $this->namePolicy()->preserveEvidence($release, $newTitle),
            NameSource::BookTitle,
            'Parsed book metadata',
            keepLinks: ['bookinfo_id'],
        ));
    }

    /** Gives a release a verified SRRDB match's PreDB entry and IMDb id, and trusts its name. */
    public function attachSrrdbMatch(int $releaseId, int $predbId, ?string $imdbId): NameUpdateResult
    {
        if ($releaseId === 0 || $predbId === 0) {
            return NameUpdateResult::declined();
        }

        if ((! (new RecoveryIdentityPolicy)->allowsParent($releaseId) || ! BundleIdentity::allowsSingleTitle($releaseId))) {
            return NameUpdateResult::declined(NameRejection::RecoveryRefused);
        }

        $release = Release::query()->find($releaseId);
        if ($release === null) {
            return NameUpdateResult::declined();
        }

        $title = $this->namePolicy()->preserveEvidence($release, (string) $release->searchname);
        $this->writer()->rename($release, new NameCandidate($releaseId, $title, NameSource::SrrdbAttach, 'Verified archive CRC match', $predbId, $imdbId));
        $this->renamedReleaseIds[$releaseId] = true;

        return NameUpdateResult::renamed($title);
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
     * Increment the checked counter.
     */
    public function incrementChecked(): void
    {
        $this->checked++;
    }

    /**
     * Get the current statistics.
     *
     * @return array{fixed: int, checked: int}
     */
    public function getStats(): array
    {
        return [
            'fixed' => $this->fixed,
            'checked' => $this->checked,
        ];
    }
}
