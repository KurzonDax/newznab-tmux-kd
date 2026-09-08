<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final class RecoveryFormats
{
    public function detect(string $prefix): ?string
    {
        $prefix = substr($prefix, 0, 16384);
        foreach (['MZ' => 'executable', "\x7fELF" => 'executable', "Rar!\x1a\x07\x01\0" => 'rar5',
            "Rar!\x1a\x07\0" => 'rar4', "7z\xbc\xaf\x27\x1c" => '7z', "PK\x03\x04" => 'zip'] as $magic => $format) {
            if (str_starts_with($prefix, $magic)) {
                return $format;
            }
        }
        if (str_starts_with($prefix, "\x1a\x45\xdf\xa3")) {
            return $this->ebml($prefix);
        }
        for ($offset = 0; $offset + 8 <= strlen($prefix);) {
            $size = unpack('N', substr($prefix, $offset, 4))[1];
            $kind = substr($prefix, $offset + 4, 4);
            if ($size < 8 || $size > strlen($prefix) - $offset) {
                return null;
            }
            if ($kind === 'ftyp' && $size >= 16 && $size % 4 === 0) {
                $brand = substr($prefix, $offset + 8, 4);

                return match (true) {
                    in_array($brand, ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'iso7', 'iso8', 'iso9', 'mp41', 'mp42', 'avc1', 'M4V ', 'M4VH', 'dash'], true) => 'mp4',
                    $brand === 'qt  ' => 'mov',
                    default => null,
                };
            }
            if (! in_array($kind, ['free', 'skip', 'wide'], true)) {
                return null;
            }
            $offset += $size;
        }

        return null;
    }

    private function ebml(string $bytes): ?string
    {
        $offset = 4;
        $size = $this->vint($bytes, $offset, false);
        if ($size === null || $size > strlen($bytes) - $offset) {
            return null;
        }
        $end = $offset + $size;
        while ($offset < $end) {
            $id = $this->vint($bytes, $offset, true);
            $length = $this->vint($bytes, $offset, false);
            if ($id === null || $length === null || $length > $end - $offset) {
                return null;
            }
            if ($id === 0x4282) {
                return match (substr($bytes, $offset, $length)) {
                    'matroska' => 'mkv',
                    'webm' => 'webm',
                    default => null,
                };
            }
            $offset += $length;
        }

        return null;
    }

    private function vint(string $bytes, int &$offset, bool $identifier): ?int
    {
        if (! isset($bytes[$offset])) {
            return null;
        }
        $first = ord($bytes[$offset]);
        $mask = 128;
        $length = 1;
        while ($mask > 0 && ($first & $mask) === 0) {
            $mask >>= 1;
            $length++;
        }
        if ($mask === 0 || $length > ($identifier ? 4 : 8) || $offset + $length > strlen($bytes)) {
            return null;
        }
        $value = $identifier ? $first : ($first & ($mask - 1));
        for ($index = 1; $index < $length; $index++) {
            $value = ($value << 8) | ord($bytes[$offset + $index]);
        }
        $offset += $length;

        if (! $identifier && $value === (1 << (7 * $length)) - 1) {
            return null;
        }

        return $value;
    }
}
