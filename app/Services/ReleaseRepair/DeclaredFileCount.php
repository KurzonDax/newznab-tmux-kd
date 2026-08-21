<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Models\Release;
use App\Services\Nzb\NzbParserService;

/**
 * How many files a release said it had, for releases created before anything recorded it.
 *
 * `releases.declaredfiles` is written at creation from the header token, but only for releases
 * created since that column existed. For everything older the only surviving witness is the
 * stored NZB: the writer keeps each file's original subject, and a subject that arrived as
 * `[27/36] - "x.part26.rar" yEnc (5/211)` still carries its `[27/36]`.
 *
 * Reading it is fiddlier than it looks. The writer appends a synthesized ` (1/211)` segment
 * counter to every subject, and a regex that accepts parenthesised `n/N` reads that as a file
 * count -- on a 30-release sample that mistook 10 releases for whole-missing ones, one of them
 * "declaring" 8,380 files. Only the bracket form counts files, which is exactly what
 * {@see NzbParserService::extractFilesTotal()} matches and nothing else does.
 *
 * The answer is the *mode* of the totals rather than the max: a subject whose bracket happens to
 * hold something else (a season marker, an episode range) is one vote against dozens.
 */
final class DeclaredFileCount
{
    public function __construct(private readonly NzbParserService $parser = new NzbParserService) {}

    /**
     * The release's declared file count, deriving and persisting it on first sight.
     *
     * `0` means "no usable declaration" and is persisted as such, so the release is not re-derived
     * on every pass. It is a real answer, not a failure: nothing in the NZB claims a file count,
     * or what it claims is not more than what we hold.
     */
    public function resolve(Release $release, NzbRepairDocument $document, bool $persist = true): int
    {
        if ($release->declaredfiles !== null) {
            return (int) $release->declaredfiles;
        }

        $derived = $this->derive($document->subjects());

        if ($persist) {
            Release::query()->where('id', $release->id)->update(['declaredfiles' => $derived]);
            $release->declaredfiles = $derived;
        }

        return $derived;
    }

    /**
     * The file count the given `<file subject>` attributes agree on, or `0` where none is usable.
     *
     * @param  array<int, string>  $subjects  Every `<file>` subject the NZB holds.
     */
    public function derive(array $subjects): int
    {
        $votes = [];

        foreach ($subjects as $subject) {
            // Bracket-only by construction: the writer's trailing `(1/N)` counts segments, and
            // reading it as a file count is the mistake this whole resolver exists to avoid.
            $total = $this->parser->extractFilesTotal($subject);

            if ($total > 0) {
                $votes[$total] = ($votes[$total] ?? 0) + 1;
            }
        }

        if ($votes === []) {
            return 0;
        }

        $declared = 0;
        $bestVote = 0;

        foreach ($votes as $total => $vote) {
            // Ties break to the larger total: under-declaring would hide a missing file, which is
            // the failure this is trying to detect.
            if ($vote > $bestVote || ($vote === $bestVote && $total > $declared)) {
                $declared = $total;
                $bestVote = $vote;
            }
        }

        // Not more than we hold is not a declaration worth acting on. A collection may legitimately
        // carry one file past its declared total (the par2 volume), so equality is not a shortfall.
        return $declared > \count($subjects) ? $declared : 0;
    }
}
