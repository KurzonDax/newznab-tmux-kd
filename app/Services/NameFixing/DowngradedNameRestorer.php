<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Models\Predb;
use App\Models\Release;
use App\Services\ReleaseCleaningService;

final readonly class DowngradedNameRestorer
{
    private const BATCH_SIZE = 250;

    public function __construct(
        private FileNameCleaner $fileNameCleaner,
        private ReleaseUpdateService $releaseUpdateService,
        private ReleaseCleaningService $releaseCleaningService = new ReleaseCleaningService,
    ) {}

    public function run(bool $dryRun, ?int $limit = null): DowngradedNameRestoreResult
    {
        if ($limit !== null && $limit <= 0) {
            return new DowngradedNameRestoreResult(0, 0, 0, [], $dryRun);
        }

        $scanned = 0;
        $restored = 0;
        $skipped = 0;
        $pairs = [];

        $releases = Release::query()
            ->select([
                'id',
                'name',
                'searchname',
                'groups_id',
                'categories_id',
                'fromname',
            ])
            ->where('searchname', 'like', '%-%')
            ->orderBy('id')
            ->lazyById(self::BATCH_SIZE);

        foreach ($releases as $release) {
            $currentName = (string) $release->searchname;
            if (! $this->fileNameCleaner->isAbbreviationStub($currentName)) {
                continue;
            }

            if ($limit !== null && $scanned >= $limit) {
                break;
            }

            $scanned++;
            $candidate = $this->candidateFromSubject((string) $release->name, $currentName);
            if ($candidate === null) {
                $skipped++;

                continue;
            }

            $restoration = [
                'release_id' => (int) $release->id,
                'old' => $currentName,
                'new' => $candidate,
            ];

            if ($dryRun) {
                $pairs[] = $restoration;

                continue;
            }

            $preDbId = (int) (Predb::query()->where('title', $candidate)->value('id') ?? 0);
            $this->releaseUpdateService->reset();
            $this->releaseUpdateService->updateRelease(
                $release,
                $candidate,
                $preDbId > 0 ? 'preDB: Restored original subject' : 'Original subject repair',
                true,
                'Filenames, ',
                true,
                false,
                $preDbId,
            );

            if (! $this->releaseUpdateService->matched) {
                $skipped++;

                continue;
            }

            $restored++;
            $pairs[] = $restoration;
        }

        return new DowngradedNameRestoreResult($scanned, $restored, $skipped, $pairs, $dryRun);
    }

    private function candidateFromSubject(string $subject, string $currentName): ?string
    {
        preg_match_all('/\[\s*([^\[\]]+?)\s*\]/u', $subject, $bracketed);
        preg_match_all('/["“]\s*([^"”]+?)\s*["”]/u', $subject, $quoted);

        $subjectFragments = array_unique([...$bracketed[1], ...$quoted[1]]);
        if ($subjectFragments !== []) {
            $fragmentCandidate = $this->bestCandidate($subjectFragments, $currentName);
            if ($fragmentCandidate !== null) {
                return $fragmentCandidate;
            }
        }

        return $this->bestCandidate(
            [$this->releaseCleaningService->releaseCleanerHelper($subject)],
            $currentName,
        );
    }

    /**
     * @param  list<string>  $rawCandidates
     */
    private function bestCandidate(array $rawCandidates, string $currentName): ?string
    {
        $bestCandidate = null;
        foreach ($rawCandidates as $rawCandidate) {
            if ($this->isSubjectMetadata($rawCandidate)) {
                continue;
            }

            $normalized = $this->fileNameCleaner->normalizeCandidateTitle((string) $rawCandidate);
            $candidate = $this->fileNameCleaner->formatSearchName((string) $rawCandidate, $normalized);
            if (! $this->fileNameCleaner->isLessInformativeThan($currentName, $candidate)) {
                continue;
            }

            if ($bestCandidate === null
                || $this->fileNameCleaner->isLessInformativeThan($bestCandidate, $candidate)) {
                $bestCandidate = $candidate;
            }
        }

        return $bestCandidate;
    }

    private function isSubjectMetadata(string $candidate): bool
    {
        $normalized = strtolower(trim($candidate, " \t\n\r\0\x0B.-_"));

        return $normalized === ''
            || (bool) preg_match('/^\d+(?:[\/~:-]\d+)?$/', $normalized)
            || (bool) preg_match('/^(?:full|reup|repost|re-repost|part|sample|xtr)$/', $normalized)
            || (bool) preg_match('/(?:^|\.)binaries(?:\.|$)/', $normalized)
            || str_contains($normalized, 'efnet');
    }
}
