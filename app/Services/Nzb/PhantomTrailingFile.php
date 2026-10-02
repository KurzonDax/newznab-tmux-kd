<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Services\ReleaseRepair\FileIndexToken;

/**
 * A post that declares one file more than it ever posted.
 *
 * Some posters number their files `[n/N]` with N one higher than the files they post. The set is
 * complete -- the PAR2 recovery series already ends with the remainder volume par2 writes last --
 * but measured against N it reads 85-94%: {@see CompletionSignals} scales the segment ratio down
 * for a file that was never on the provider, the sweep deletes the release, and the header
 * re-scan spends its budget looking for that file.
 *
 * The shape is recognised from the held files' subjects alone, and only when every part of it
 * holds:
 *
 * 1. every subject carries a filename {@see Par2Inventory::filename()} can read;
 * 2. the `[n/N]` ordinals are exactly 1 to N-1, once each, under one N every subject agrees on;
 * 3. the file at ordinal N-1 is a PAR2 recovery volume (`.volA+B.par2`);
 * 4. the recovery volumes, ordered by start block, run contiguously from block 0, at least two of
 *    them, with counts doubling from 1 up to the last, whose count is below twice the previous
 *    one -- the remainder volume. It may equal the previous count. An even split (20, 20, ...,
 *    19, 19) never doubles, so nothing in it marks where the series ends and it never matches.
 *
 * When it holds, the files held are the files declared: N-1. When anything fails, the caller's
 * declared count stands.
 */
final class PhantomTrailingFile
{
    private const string RECOVERY_VOLUME = '/\.vol(\d+)\+(\d+)\.par2$/iD';

    /**
     * The declared file count to measure against: N-1 when the subjects carry a phantom trailing
     * file, `$declared` otherwise.
     *
     * @param  iterable<string>  $subjects  Every held file's subject.
     */
    public static function declaredFiles(iterable $subjects, int $declared): int
    {
        return self::heldCount($subjects) ?? $declared;
    }

    /**
     * {@see self::heldCount()} for a parsed NZB document's `<file>` subjects.
     */
    public static function heldCountOfNzb(\SimpleXMLElement $nzb): ?int
    {
        $subjects = [];

        foreach ($nzb->file as $file) {
            $subjects[] = (string) $file->attributes()->subject;
        }

        return self::heldCount($subjects);
    }

    /**
     * N-1 when the subjects carry a phantom trailing file, or null when they do not.
     *
     * @param  iterable<string>  $subjects  Every held file's subject.
     */
    public static function heldCount(iterable $subjects): ?int
    {
        $filenames = [];
        $total = null;

        foreach ($subjects as $subject) {
            $filename = Par2Inventory::filename($subject);
            $token = FileIndexToken::parse($subject);

            if ($filename === null || $token === null || ($total !== null && $token->total !== $total)) {
                return null;
            }

            $total = $token->total;

            if (isset($filenames[$token->index])) {
                return null;
            }

            $filenames[$token->index] = $filename;
        }

        if ($total === null || $total < 2) {
            return null;
        }

        ksort($filenames);

        if (array_keys($filenames) !== range(1, $total - 1)) {
            return null;
        }

        if (preg_match(self::RECOVERY_VOLUME, $filenames[$total - 1]) !== 1) {
            return null;
        }

        return self::endsWithRemainderVolume($filenames) ? $total - 1 : null;
    }

    /**
     * @param  array<int, string>  $filenames
     */
    private static function endsWithRemainderVolume(array $filenames): bool
    {
        $volumes = [];

        foreach ($filenames as $filename) {
            if (preg_match(self::RECOVERY_VOLUME, $filename, $volume) === 1) {
                $volumes[] = [(int) $volume[1], (int) $volume[2]];
            }
        }

        if (\count($volumes) < 2) {
            return false;
        }

        usort($volumes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        [$start, $count] = $volumes[0];

        if ($start !== 0 || $count !== 1) {
            return false;
        }

        $last = \count($volumes) - 1;

        for ($i = 1; $i <= $last; $i++) {
            [$previousStart, $previousCount] = $volumes[$i - 1];
            [$start, $count] = $volumes[$i];

            if ($start !== $previousStart + $previousCount) {
                return false;
            }

            if ($i < $last ? $count !== 2 * $previousCount : ($count < 1 || $count >= 2 * $previousCount)) {
                return false;
            }
        }

        return true;
    }
}
