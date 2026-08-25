<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\NfoService;
use App\Support\ReleaseSearchIndexDocument;

/**
 * Returns consumers to pending after a rewritten NZB gains useful evidence.
 */
final class EvidenceChangedTransition
{
    /** @var list<string> */
    private const array NAME_SOURCE_STATUS_COLUMNS = [
        'proc_nfo',
        'proc_files',
        'proc_srr',
        'proc_crc32',
        'proc_uid',
        'proc_hash16k',
        'proc_par2',
        'proc_srrdb',
        'proc_xxx',
        'proc_media_movie',
    ];

    /**
     * Apply one transition after a caller has added files or segments to the NZB.
     */
    public function apply(
        Release $release,
        NzbRepairDocument $document,
        ?int $declaredFiles = null,
        bool $scheduleSearchSync = true,
    ): bool {
        $values = [
            'totalpart' => $document->fileCount(),
            'completion' => $document->measure($declaredFiles)->percentage(),
        ];

        foreach (self::NAME_SOURCE_STATUS_COLUMNS as $column) {
            $values[$column] = 0;
        }

        if ((int) $release->nfostatus <= NfoService::NFO_NONFO) {
            $values['nfostatus'] = NfoService::NFO_UNPROC;
        }

        $requeuedForAdditionalProcessing = ! $release->hasProcessingArtifacts();

        if ($requeuedForAdditionalProcessing) {
            $values = array_merge($values, ReleaseClaimant::rependValues());
        }

        $indexedValues = array_intersect_key($values, array_flip(ReleaseSearchIndexDocument::fields()));
        $storedIndexedValues = Release::query()
            ->where('id', $release->id)
            ->first(array_keys($indexedValues));
        $indexedFieldsChanged = $storedIndexedValues === null;

        foreach ($indexedValues as $field => $value) {
            if ($storedIndexedValues?->getAttribute($field) != $value) {
                $indexedFieldsChanged = true;
                break;
            }
        }

        Release::query()->where('id', $release->id)->update($values);

        if ($indexedFieldsChanged && $scheduleSearchSync) {
            Release::syncSearchIndexAfterCommit((int) $release->id);
        }

        return $requeuedForAdditionalProcessing;
    }
}
