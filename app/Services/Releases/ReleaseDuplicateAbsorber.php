<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\DuplicateAbsorbOutcome;
use App\Models\Collection;
use App\Models\Release;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\EvidenceChangedTransition;
use App\Services\ReleaseRepair\NzbRepairDocument;
use App\Support\Data\DuplicateAbsorbResult;
use Illuminate\Support\Facades\DB;

/**
 * Absorbs a more complete duplicate into its anchor release in place.
 *
 * Absorption rewrites the anchor's stored NZB, so it requires that NZB to
 * exist: an anchor whose `nzbstatus` is not yet {@see NzbService::NZB_ADDED}
 * defers the absorb ({@see DuplicateAbsorbOutcome::Deferred}) instead of
 * attempting it — for a newly created anchor the NZB-creation stage lags
 * release-row creation, and every attempt in that window would fail on the
 * missing file. A deferred incoming collection is preserved untouched and
 * absorbs naturally on a later cycle once the NZB lands.
 *
 * An absorb that is actually attempted and fails increments a durable
 * per-collection counter so the release-creation call site can settle a
 * persistently failing collection as an ordinary duplicate at
 * {@see self::MAX_ABSORB_ATTEMPTS} instead of retrying forever.
 */
class ReleaseDuplicateAbsorber
{
    /**
     * Attempted-failure cap: at this many failed attempts the incoming
     * collection settles as an ordinary duplicate. Deferrals never count.
     */
    public const int MAX_ABSORB_ATTEMPTS = 3;

    public function __construct(
        private readonly NzbService $nzb,
        private readonly NzbParserService $parser = new NzbParserService,
        private readonly EvidenceChangedTransition $evidenceChanged = new EvidenceChangedTransition,
    ) {}

    public function supportsReason(?string $reason): bool
    {
        return in_array($reason, [
            'searchname_match',
            'normalized_searchname_match',
            'name_match_fallback',
        ], true);
    }

    public function absorbCollection(
        Release $anchor,
        Collection $collection,
        float $incomingCompletion,
    ): DuplicateAbsorbResult {
        if ($incomingCompletion <= (float) $anchor->completion) {
            return DuplicateAbsorbResult::notBetter();
        }

        $freshAnchor = Release::query()->with('category.parent')->find($anchor->id);
        if ($freshAnchor === null) {
            // A race, not an attempt: nothing was rendered or replaced, and
            // the next cycle re-evaluates the collection against a fresh
            // duplicate match. Not counted.
            return DuplicateAbsorbResult::failed('The duplicate anchor disappeared before absorption.');
        }

        // Cheap pre-check so a lagging anchor NZB skips the XML render; the
        // authoritative check re-reads the locked row inside absorbXml().
        if (! $this->anchorNzbExists($freshAnchor)) {
            return DuplicateAbsorbResult::deferred();
        }

        // From here the absorb is attempted: every failure — including one
        // escaping as an exception — is converted to a counted Failed result,
        // so the attempt cap bounds all retry loops.
        try {
            $nzbXml = $this->nzb->buildNzbContentsForCollection($freshAnchor, (int) $collection->id);
            if (! \is_string($nzbXml)) {
                return $this->recordFailedAttempt($collection, 'The incoming duplicate collection could not be rendered as an NZB.');
            }

            $result = $this->absorbXml(
                $freshAnchor,
                $nzbXml,
                (int) $collection->filesize,
                (int) $collection->declaredfiles,
                $incomingCompletion,
            );
        } catch (\Throwable $exception) {
            return $this->recordFailedAttempt($collection, 'Unexpected absorb error: '.$exception->getMessage());
        }

        if ($result->outcome === DuplicateAbsorbOutcome::Failed) {
            return $this->recordFailedAttempt($collection, $result->reason);
        }

        return $result;
    }

    /**
     * Replace a lower-quality anchor's evidence while preserving its identity and history.
     */
    public function absorbXml(
        Release $anchor,
        string $nzbXml,
        int $incomingSize,
        int $incomingDeclaredFiles,
        float $incomingCompletion,
    ): DuplicateAbsorbResult {
        if ($incomingCompletion <= (float) $anchor->completion) {
            return DuplicateAbsorbResult::notBetter();
        }

        $document = NzbRepairDocument::load($nzbXml, $this->parser);
        if ($document === null) {
            return DuplicateAbsorbResult::failed('The incoming duplicate NZB could not be parsed.');
        }

        return DB::transaction(function () use (
            $anchor,
            $document,
            $incomingSize,
            $incomingDeclaredFiles,
            $incomingCompletion,
            $nzbXml,
        ): DuplicateAbsorbResult {
            $locked = Release::query()->lockForUpdate()->find($anchor->id);
            if ($locked === null) {
                return DuplicateAbsorbResult::failed('The duplicate anchor disappeared before absorption.');
            }

            if ($incomingCompletion <= (float) $locked->completion) {
                return DuplicateAbsorbResult::notBetter();
            }

            // Authoritative deferral gate: read under the row lock so a
            // concurrent NZB-creation commit cannot be missed.
            if (! $this->anchorNzbExists($locked)) {
                return DuplicateAbsorbResult::deferred();
            }

            $replaced = $this->nzb->replaceNzbContents((string) $locked->guid, $nzbXml);
            if (! $replaced->success) {
                return DuplicateAbsorbResult::failed(
                    'The incoming duplicate NZB could not replace the stored anchor NZB: '.$replaced->reason
                );
            }

            $this->evidenceChanged->apply($locked, $document, $incomingDeclaredFiles, scheduleSearchSync: false);

            $locked->forceFill([
                'size' => $incomingSize,
                'totalpart' => $document->fileCount(),
                'declaredfiles' => $incomingDeclaredFiles,
                'completion' => $document->measure($incomingDeclaredFiles)->percentage(),
            ])->saveQuietly();
            Release::syncSearchIndexAfterCommit((int) $locked->id);

            return DuplicateAbsorbResult::absorbed();
        }, 3);
    }

    private function anchorNzbExists(Release $anchor): bool
    {
        return (int) $anchor->nzbstatus === NzbService::NZB_ADDED;
    }

    /**
     * Record an attempted-and-failed absorb against the preserved collection.
     *
     * The counter survives runner cycles and processes; a collection deleted
     * concurrently simply reports zero attempts and is gone anyway.
     */
    private function recordFailedAttempt(Collection $collection, string $reason): DuplicateAbsorbResult
    {
        Collection::query()->whereKey($collection->id)->increment('absorb_attempts');
        $attempts = (int) Collection::query()->whereKey($collection->id)->value('absorb_attempts');

        return DuplicateAbsorbResult::failed($reason, $attempts);
    }
}
