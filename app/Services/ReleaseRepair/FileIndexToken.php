<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * The `[n/N]` file index a Usenet subject carries: "this is file n of N in the post".
 *
 * The one token this whole pass turns on, and the one most easily confused with its neighbour.
 * A subject like `[27/36] - "x.part26.rar" yEnc (5/211)` carries two `n/N` pairs meaning
 * completely different things: the bracketed pair counts *files in the post*, the parenthesised
 * pair counts *segments in this file*. Reading the second as the first is what made a sampled
 * release "declare" 8,380 files.
 *
 * Keeping the pattern in one place is what stops the two drifting apart again -- the bracket form
 * is matched here and nowhere else in this namespace.
 */
final readonly class FileIndexToken
{
    /**
     * Bracket form only. The parenthesised `(n/N)` is a segment counter and must never match.
     */
    public const string PATTERN = '/\[(\d+)\/(\d+)\]/';

    private function __construct(
        public int $index,
        public int $total,
    ) {}

    /**
     * @return self|null Null when the subject carries no bracketed file index.
     */
    public static function parse(string $subject): ?self
    {
        if (! preg_match(self::PATTERN, $subject, $token)) {
            return null;
        }

        return new self((int) $token[1], (int) $token[2]);
    }
}
