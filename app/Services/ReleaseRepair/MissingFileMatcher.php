<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Support\Utf8;

/**
 * Decides whether an overview line belongs to one particular release.
 *
 * The window a re-scan reads is a slice of a busy group, so most of what comes back is other
 * people's posts. Matching has to be strict in a way the indexer's own collection matching does
 * not: the indexer groups articles that arrived together, while this is trying to attach an
 * article to a release formed hours or years ago, and a wrong attachment writes a message-ID into
 * an NZB that fails at download time.
 *
 * Three independent things must agree, and none of them alone is enough:
 *
 * 1. **Poster.** Cheap, and it eliminates almost everything.
 * 2. **The declared file total.** `[n/36]` against a release that declared 36 files. A repost with
 *    a different file count is a different post.
 * 3. **The masked subject.** The per-file counters -- the `[n/N]` index, `.partNN`, `.rNN`,
 *    `.volNNN+NNN`, and the trailing segment counter -- are exactly what differs between the files
 *    of one post, so masking them turns a release's files into one shared string. Anything whose
 *    masked subject differs is a different release, however similarly named.
 *
 * Finally the line's file index must be one the NZB does not already hold: a file we have is not
 * a file we are missing, and appending it again would give the release two of it.
 */
final class MissingFileMatcher
{
    /** @var array<string, true> */
    private array $heldMasks;

    /** @var array<int, true> */
    private array $heldIndices;

    private string $poster;

    /**
     * @param  list<string>  $heldSubjects  Every `<file>` subject the NZB holds.
     * @param  list<int>  $heldIndices  The `[n/N]` index of each of those files.
     */
    public function __construct(
        string $poster,
        private readonly int $declaredFiles,
        array $heldSubjects,
        array $heldIndices,
    ) {
        $this->poster = self::normalizePoster($poster);
        $this->heldMasks = array_fill_keys(array_map([self::class, 'mask'], $heldSubjects), true);
        $this->heldIndices = array_fill_keys($heldIndices, true);
    }

    public function matches(OverviewLine $line): bool
    {
        return $line->fileTotal === $this->declaredFiles
            && ! isset($this->heldIndices[$line->fileIndex])
            && $line->fileIndex >= 1
            && $line->fileIndex <= $this->declaredFiles
            && self::normalizePoster($line->poster) === $this->poster
            && isset($this->heldMasks[self::mask($line->nzbSubject())]);
    }

    /**
     * The `n` of a subject's `[n/N]` file index, or null when it carries none.
     */
    public static function fileIndexOf(string $subject): ?int
    {
        return preg_match('/\[(\d+)\/(\d+)\]/', $subject, $token) ? (int) $token[1] : null;
    }

    /**
     * A subject with everything that varies between the files of one post replaced by a marker.
     */
    public static function mask(string $subject): string
    {
        $masked = (string) preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $subject);
        $masked = (string) preg_replace('/\[\d+\/\d+\]/', '[#]', $masked);
        $masked = (string) preg_replace('/\.vol\d+\+\d+/i', '.vol#', $masked);
        $masked = (string) preg_replace('/\.part\d+/i', '.part#', $masked);
        $masked = (string) preg_replace('/\.[rstz]\d{2,3}\b/i', '.x#', $masked);
        $masked = (string) preg_replace('/\s+/u', ' ', $masked);

        return trim($masked);
    }

    private static function normalizePoster(string $poster): string
    {
        return trim(Utf8::clean($poster), " '\"");
    }
}
