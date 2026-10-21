<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Events\ReleaseNameFixed;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\Releases\ForcedRootPolicy;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of an existing release's name, name trust, name source and rename status.
 *
 *  - rename: a candidate through {@see NamePolicy} and its {@see NameSource} case; the rename
 *    clears the metadata links and dispatches {@see ReleaseNameFixed};
 *  - reset name: a neutral or reconciliation label, outside the name policy;
 *  - restore prior values: the exact columns a caller recorded before an earlier rename.
 */
class ReleaseNameWriter
{
    /** The metadata links a rename clears, with the value each takes. */
    private const array CLEARED_LINKS = [
        'videos_id' => 0,
        'tv_episodes_id' => 0,
        'movieinfo_id' => null,
        'imdbid' => null,
        'musicinfo_id' => null,
        'consoleinfo_id' => null,
        'bookinfo_id' => null,
        'anidbid' => null,
        'gamesinfo_id' => 0,
    ];

    private readonly ReleaseSearchSyncCoordinator $searchSync;

    public function __construct(
        ?ReleaseSearchSyncCoordinator $searchSync = null,
        private readonly NamePolicy $policy = new NamePolicy,
    ) {
        $this->searchSync = $searchSync ?? new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
    }

    /**
     * Decide the candidate and, when accepted and $write is set, write it.
     *
     * @param  object  $release  the release as the caller read it
     * @param  bool  $nameStatus  whether the source's status columns are written
     * @param  ?Closure(): array<string, int>  $storedFiles
     * @param  array<string, mixed>  $alsoWrite  other columns of the caller's, written with the rename
     */
    public function rename(
        object $release,
        NameCandidate $candidate,
        bool $nameStatus = true,
        bool $write = true,
        bool $recoveryAllowed = true,
        bool $alreadyRenamed = false,
        ?Closure $storedFiles = null,
        array $alsoWrite = [],
    ): NameWriteOutcome {
        $decision = $this->policy->decide($release, $candidate, $recoveryAllowed, $alreadyRenamed, $storedFiles);
        if (! $decision->accepted() || ! $write) {
            return new NameWriteOutcome($decision);
        }

        $refusal = $this->persist($release, $candidate, $decision, $nameStatus, $alsoWrite);

        return new NameWriteOutcome($decision, $refusal === null, $refusal);
    }

    /**
     * Give the release a label that names no evidence: untrusted and not renamed, its name source
     * left as it is. The caller's other columns are written in the same update. It opens no
     * transaction and syncs nothing: callers write it under their own row lock and re-sync.
     *
     * @param  array<string, mixed>  $columns
     */
    public function resetName(int $releaseId, string $name, array $columns = []): void
    {
        Release::query()->whereKey($releaseId)->update([
            ...$columns,
            ...Release::searchNameValues($name),
            'isrenamed' => 0,
            'is_trusted_name' => false,
        ]);
    }

    /**
     * Write the values a caller recorded before an earlier rename, verbatim, under the same
     * identity checks a rename passes. Dispatches the name event with the category the release
     * keeps, and re-syncs the release. Returns whether it wrote.
     *
     * @param  array<string, scalar|null>  $columns  prior values, the search name among them
     */
    public function restorePriorValues(int $releaseId, array $columns): bool
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
            $this->searchSync->request($releaseId);
        }

        return $written;
    }

    /**
     * Write an accepted rename: the name, its trust and source, the status columns and the
     * cleared links, re-checking the release's identity under its row lock.
     *
     * @param  array<string, mixed>  $alsoWrite
     */
    protected function persist(object $release, NameCandidate $candidate, NameDecision $decision, bool $nameStatus, array $alsoWrite = []): ?NameWriteRefusal
    {
        $releaseId = (int) ($release->releases_id ?? $release->id);
        $refusal = DB::transaction(function () use ($release, $releaseId, $candidate, $decision, $nameStatus, $alsoWrite): ?NameWriteRefusal {
            $current = Release::query()->where('id', $releaseId)->lockForUpdate()->first();
            if (! (new RecoveryIdentityPolicy)->allowsParent($releaseId, $candidate->recoveryEvidence)) {
                return NameWriteRefusal::RecoveryIdentity;
            }
            if (! BundleIdentity::allowsSingleTitle($releaseId)) {
                return NameWriteRefusal::Bundle;
            }
            $categoryId = $candidate->categoryId;
            if ($categoryId !== null && $current !== null) {
                $categoryId = (new ForcedRootPolicy)->categoryForRelease($current->groups_id, $releaseId, $categoryId);
            }
            $candidate->recoveryEvidence?->assertNamingAllowed();

            Release::query()->where('id', $releaseId)->update([...$alsoWrite, ...$this->renameColumns($candidate, $decision, $nameStatus, $categoryId)]);

            event(new ReleaseNameFixed(
                $releaseId,
                (string) $release->searchname,
                $decision->title,
                (int) $release->categories_id,
                $release->groups_id,
                (string) ($release->fromname ?? ''),
                $categoryId,
            ));

            return null;
        });

        $this->searchSync->request($releaseId);

        return $refusal;
    }

    /** @return array<string, mixed> */
    private function renameColumns(NameCandidate $candidate, NameDecision $decision, bool $nameStatus, ?int $categoryId): array
    {
        $columns = [
            ...array_diff_key(self::CLEARED_LINKS, array_flip($candidate->keepLinks)),
            'imdbid' => $candidate->imdbId,
            'predb_id' => $candidate->preDbId,
            ...Release::searchNameValues($decision->title),
            'is_trusted_name' => $decision->trust === TrustedDonor::Trusted,
            'name_source' => $candidate->source->storedName(),
        ];
        if ($decision->trust === TrustedDonor::Keep) {
            unset($columns['is_trusted_name'], $columns['predb_id']);
        }

        if ($categoryId !== null) {
            $columns['categories_id'] = $categoryId;
        }

        return $nameStatus ? [...$columns, ...$candidate->source->statusColumns()] : [...$columns, 'iscategorized' => 1];
    }
}
