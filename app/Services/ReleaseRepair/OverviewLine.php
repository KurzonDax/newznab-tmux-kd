<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Services\Binaries\HeaderParser;
use App\Services\NNTP\NNTPService;

/**
 * One XOVER line, read the way the header scan reads it.
 *
 * The split into base name and `(segment/total)` counter is deliberately the same regex
 * {@see HeaderParser} uses, because the base name is what became
 * `binaries.name` and therefore what the stored NZB's `subject` attribute is built from. A
 * different split here would compare two subjects that were never the same string.
 */
final readonly class OverviewLine
{
    private function __construct(
        public string $baseName,
        public int $segmentNumber,
        public int $segmentTotal,
        public int $fileIndex,
        public int $fileTotal,
        public string $poster,
        public string $messageId,
        public int $bytes,
    ) {}

    /**
     * @param  array<string, mixed>  $header  One entry from {@see NNTPService::getXOVER()}.
     * @return self|null Null for anything without the yEnc subject shape, a file index, or a
     *                   message-ID -- none of which can belong to a collection we indexed.
     */
    public static function parse(array $header): ?self
    {
        $subject = \is_scalar($header['Subject'] ?? null) ? (string) $header['Subject'] : '';
        $messageId = self::unwrapMessageId(\is_scalar($header['Message-ID'] ?? null) ? (string) $header['Message-ID'] : '');

        if ($messageId === '' || ! preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', $subject, $matches)) {
            return null;
        }

        $baseName = $matches[1];

        if (stripos($subject, 'yEnc') === false) {
            $baseName .= ' yEnc';
        }

        if (! preg_match('/\[(\d+)\/(\d+)\]/', $baseName, $fileToken)) {
            return null;
        }

        return new self(
            baseName: $baseName,
            segmentNumber: (int) $matches[2],
            segmentTotal: (int) $matches[3],
            fileIndex: (int) $fileToken[1],
            fileTotal: (int) $fileToken[2],
            poster: \is_scalar($header['From'] ?? null) ? (string) $header['From'] : '',
            messageId: $messageId,
            bytes: (int) ($header['Bytes'] ?? $header[':bytes'] ?? 0),
        );
    }

    /**
     * The subject as the NZB writer would have stored it, had the header been seen.
     */
    public function nzbSubject(): string
    {
        return rtrim($this->baseName).' (1/'.$this->segmentTotal.')';
    }

    private static function unwrapMessageId(string $messageId): string
    {
        $messageId = trim($messageId);

        if (str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            $messageId = substr($messageId, 1, -1);
        }

        return trim($messageId);
    }
}
