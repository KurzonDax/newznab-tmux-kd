<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * A numbered message-ID pattern: everything but the segment number is constant within a file.
 *
 * PowerPost and camelsystem posters build message-IDs like
 * `part5of211.AbC123XyZ@powerpost2000AA.local`, where only the segment number varies and the
 * token is constant per file. When a header scan missed some articles, the IDs of the missing
 * ones are therefore *derivable* from the ones we did see -- which is what makes an "incomplete"
 * release recoverable rather than garbage.
 *
 * Posters that mint random IDs per article (Nyuu, ngPost) have no such pattern, and detection
 * returns null for them rather than guessing.
 */
final readonly class MessageIdTemplate
{
    /**
     * @param  string  $prefix  Everything before the segment number.
     * @param  string  $suffix  Everything after it.
     * @param  int  $padWidth  Zero-pad the number to this width; 0 for no padding.
     */
    private function __construct(
        public string $prefix,
        public string $suffix,
        public int $padWidth,
    ) {}

    /**
     * Two segments is the minimum: a single sample cannot say which of its digit runs is the
     * segment number, and guessing writes unverifiable IDs into an NZB.
     */
    public const int MINIMUM_SAMPLES = 2;

    /**
     * Derive the template from the segments a file does have.
     *
     * @param  array<int, string>  $segments  Segment number => message-ID (no angle brackets).
     */
    public static function detect(array $segments): ?self
    {
        $segments = array_filter($segments, static fn (string $id): bool => trim($id) !== '');

        if (count($segments) < self::MINIMUM_SAMPLES) {
            return null;
        }

        ksort($segments);
        $ids = array_values($segments);

        $prefixLength = self::commonPrefixLength($ids);
        $suffixLength = self::commonSuffixLength($ids, $prefixLength);

        // Pull the boundaries back off any digits they swallowed. `part005of211` and
        // `part017of211` share the prefix `part0`, which would make the varying field `05`
        // rather than `005` and render segment 200 as `part0200of211`.
        $prefixLength = self::retreatOverDigits($ids[0], $prefixLength);
        $suffixLength = self::advanceOverDigits($ids[0], $suffixLength);

        $widths = [];

        foreach ($segments as $number => $id) {
            $middle = substr($id, $prefixLength, strlen($id) - $prefixLength - $suffixLength);

            if ($middle === '' || ltrim($middle, '0') !== (string) $number) {
                return null;
            }

            $widths[] = strlen($middle);
        }

        $padWidth = count(array_unique($widths)) === 1 && $widths[0] > strlen((string) array_key_first($segments))
            ? $widths[0]
            : 0;

        $template = new self(
            substr($ids[0], 0, $prefixLength),
            $suffixLength === 0 ? '' : substr($ids[0], -$suffixLength),
            $padWidth,
        );

        // Definitive check: the template must reproduce every ID we actually hold. Prefix and
        // suffix derivation has enough edge cases that deriving is not the same as being right.
        foreach ($segments as $number => $id) {
            if ($template->render($number) !== $id) {
                return null;
            }
        }

        return $template;
    }

    public function render(int $segmentNumber): string
    {
        $number = (string) $segmentNumber;

        if ($this->padWidth > 0) {
            $number = str_pad($number, $this->padWidth, '0', STR_PAD_LEFT);
        }

        return $this->prefix.$number.$this->suffix;
    }

    /**
     * @param  list<string>  $ids
     */
    private static function commonPrefixLength(array $ids): int
    {
        $candidate = $ids[0];
        $length = strlen($candidate);

        foreach ($ids as $id) {
            $shared = 0;
            $max = min($length, strlen($id));

            while ($shared < $max && $candidate[$shared] === $id[$shared]) {
                $shared++;
            }

            $length = $shared;
        }

        return $length;
    }

    /**
     * @param  list<string>  $ids
     */
    private static function commonSuffixLength(array $ids, int $prefixLength): int
    {
        $candidate = $ids[0];
        $length = strlen($candidate) - $prefixLength;

        foreach ($ids as $id) {
            $shared = 0;
            $max = min($length, strlen($id) - $prefixLength);

            while ($shared < $max && $candidate[strlen($candidate) - 1 - $shared] === $id[strlen($id) - 1 - $shared]) {
                $shared++;
            }

            $length = $shared;
        }

        return max(0, $length);
    }

    private static function retreatOverDigits(string $id, int $prefixLength): int
    {
        while ($prefixLength > 0 && ctype_digit($id[$prefixLength - 1])) {
            $prefixLength--;
        }

        return $prefixLength;
    }

    private static function advanceOverDigits(string $id, int $suffixLength): int
    {
        $length = strlen($id);

        while ($suffixLength > 0 && ctype_digit($id[$length - $suffixLength])) {
            $suffixLength--;
        }

        return $suffixLength;
    }
}
