<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Collection;
use App\Models\Release;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\EvidenceChangedTransition;
use App\Services\ReleaseRepair\NzbRepairDocument;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReleaseDuplicateAbsorber
{
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

    /**
     * @throws RuntimeException
     */
    public function absorbCollection(
        Release $anchor,
        Collection $collection,
        float $incomingCompletion,
    ): bool {
        if ($incomingCompletion <= (float) $anchor->completion) {
            return false;
        }

        $freshAnchor = Release::query()->with('category.parent')->find($anchor->id);
        if ($freshAnchor === null) {
            throw new RuntimeException('The duplicate anchor disappeared before absorption.');
        }

        $nzbXml = $this->nzb->buildNzbContentsForCollection($freshAnchor, (int) $collection->id);
        if (! \is_string($nzbXml)) {
            throw new RuntimeException('The incoming duplicate collection could not be rendered as an NZB.');
        }

        return $this->absorbXml(
            $freshAnchor,
            $nzbXml,
            (int) $collection->filesize,
            (int) $collection->declaredfiles,
            $incomingCompletion,
        );
    }

    /**
     * Replace a lower-quality anchor's evidence while preserving its identity and history.
     *
     * @throws RuntimeException
     */
    public function absorbXml(
        Release $anchor,
        string $nzbXml,
        int $incomingSize,
        int $incomingDeclaredFiles,
        float $incomingCompletion,
    ): bool {
        if ($incomingCompletion <= (float) $anchor->completion) {
            return false;
        }

        $document = NzbRepairDocument::load($nzbXml, $this->parser);
        if ($document === null) {
            throw new RuntimeException('The incoming duplicate NZB could not be parsed.');
        }

        return DB::transaction(function () use (
            $anchor,
            $document,
            $incomingSize,
            $incomingDeclaredFiles,
            $incomingCompletion,
            $nzbXml,
        ): bool {
            $locked = Release::query()->lockForUpdate()->find($anchor->id);
            if ($locked === null || $incomingCompletion <= (float) $locked->completion) {
                return false;
            }

            if (! $this->nzb->replaceNzbContents((string) $locked->guid, $nzbXml)) {
                throw new RuntimeException('The incoming duplicate NZB could not replace the stored anchor NZB.');
            }

            $this->evidenceChanged->apply($locked, $document, $incomingDeclaredFiles);

            $locked->forceFill([
                'size' => $incomingSize,
                'totalpart' => $document->fileCount(),
                'declaredfiles' => $incomingDeclaredFiles,
                'completion' => $document->measure($incomingDeclaredFiles)->percentage(),
            ])->save();

            return true;
        }, 3);
    }
}
