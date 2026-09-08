<?php

declare(strict_types=1);

namespace App\Services\DTO;

/** Validated geometry of one decoded article, independent of its filename. */
final readonly class YencArticleMetadata
{
    public function __construct(
        public int $fileSize,
        public int $part,
        public int $total,
        public int $offset,
        public int $length,
    ) {}

    public static function fromArticle(string $article, int $decodedLength): ?self
    {
        if (preg_match_all('/^=ybegin ([^\r\n]+)$/im', $article, $headers) !== 1
            || preg_match_all('/^=yend ([^\r\n]+)$/im', $article, $trailers) !== 1) {
            return null;
        }
        $header = explode(' name=', $headers[1][0], 2);
        if (count($header) !== 2 || $header[1] === '') {
            return null;
        }
        $begin = self::fields($header[0]);
        $end = self::fields($trailers[1][0]);
        $size = self::positive($begin['size'] ?? '');
        if ($size === null || self::positive($end['size'] ?? '') !== $decodedLength) {
            return null;
        }
        $multipart = isset($begin['part']) || isset($begin['total']);
        $part = $multipart ? self::positive($begin['part'] ?? '') : 1;
        $total = $multipart ? self::positive($begin['total'] ?? '') : 1;
        $partHeaders = preg_match_all('/^=ypart ([^\r\n]+)$/im', $article, $parts);
        if ($part === null || $total === null || $part > $total || $partHeaders !== (int) $multipart) {
            return null;
        }
        $range = $multipart ? self::fields($parts[1][0]) : [];
        $first = $multipart ? self::positive($range['begin'] ?? '') : 1;
        $last = $multipart ? self::positive($range['end'] ?? '') : $size;
        if ($first === null || $last === null || $last > $size || $first > $last
            || $last - $first + 1 !== $decodedLength
            || ($multipart && self::positive($end['part'] ?? '') !== $part)
            || ($part < $total && $last === $size)
            || ($part === 1 && $first !== 1) || ($part === $total && $last !== $size)) {
            return null;
        }

        return new self($size, $part, $total, $first - 1, $decodedLength);
    }

    /** @return array<string, string> */
    private static function fields(string $line): array
    {
        $fields = [];
        foreach (explode(' ', trim($line)) as $field) {
            if (preg_match('/^([a-z0-9]+)=([^ ]+)$/D', $field, $match) !== 1 || isset($fields[$match[1]])) {
                return [];
            }
            $fields[$match[1]] = $match[2];
        }

        return $fields;
    }

    private static function positive(string $value): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return preg_match('/^[0-9]+$/D', $value) === 1 && is_int($number) ? $number : null;
    }
}
