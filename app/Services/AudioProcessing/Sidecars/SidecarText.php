<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\Sidecars;

/**
 * The text of a CUE sheet or rip log read during capture (issue #313, section C). A body over the
 * NFO size bound is never read. A UTF-16 byte-order mark (LE or BE) decodes as UTF-16, a UTF-8 one
 * is stripped, valid UTF-8 is used as it is and anything else decodes as Windows-1252; a result
 * that holds a NUL byte or is not valid UTF-8 is ignored.
 */
final class SidecarText
{
    /** The NFO bound (NfoService::MAX_NFO_SIZE): a larger body is not read. */
    public const int MAX_BYTES = 65535;

    public static function decode(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $text = match (true) {
            str_starts_with($bytes, "\xFF\xFE") => mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE'),
            str_starts_with($bytes, "\xFE\xFF") => mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE'),
            str_starts_with($bytes, "\xEF\xBB\xBF") => substr($bytes, 3),
            mb_check_encoding($bytes, 'UTF-8') => $bytes,
            default => mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'),
        };

        return str_contains($text, "\0") || ! mb_check_encoding($text, 'UTF-8') ? null : $text;
    }
}
