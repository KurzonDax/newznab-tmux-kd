<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryRarHeaders
{
    /** @return array{first_volume:bool,contained_name:string,encrypted:bool,split_before:bool,split_after:bool,method:int} */
    public function inspect(string $prefix): array
    {
        if (! str_starts_with($prefix, "Rar!\x1a\x07\0") || strlen($prefix) > 16384) {
            throw new InvalidArgumentException('unsupported_rar_signature');
        }
        $main = $this->header($prefix, 7);
        if ($main['kind'] !== 0x73 || $main['size'] < 13 || ($main['flags'] & 1) === 0 || ($main['flags'] & 0x80) !== 0) {
            throw new InvalidArgumentException('unsupported_rar_main_header');
        }
        $offset = 7 + $main['size'];
        $file = $this->header($prefix, $offset);
        if ($file['kind'] !== 0x74 || $file['size'] < 32) {
            throw new InvalidArgumentException('unavailable_rar_file_header');
        }
        $nameLength = unpack('v', substr($file['bytes'], 26, 2))[1];
        $nameOffset = ($file['flags'] & 0x100) !== 0 ? 40 : 32;
        if ($nameLength < 1 || $nameLength > 1024 || $nameOffset + $nameLength > $file['size']) {
            throw new InvalidArgumentException('unavailable_rar_filename');
        }
        $name = substr($file['bytes'], $nameOffset, $nameLength);
        if (($file['flags'] & 0x200) !== 0) {
            $name = explode("\0", $name, 2)[0];
        }

        return ['first_volume' => ($main['flags'] & 0x100) !== 0, 'contained_name' => $name,
            'encrypted' => ($file['flags'] & 4) !== 0, 'split_before' => ($file['flags'] & 1) !== 0,
            'split_after' => ($file['flags'] & 2) !== 0, 'method' => ord($file['bytes'][25])];
    }

    /** @return array{kind:int,flags:int,size:int,bytes:string} */
    private function header(string $prefix, int $offset): array
    {
        if ($offset + 7 > strlen($prefix)) {
            throw new InvalidArgumentException('unavailable_rar_header');
        }
        $fields = unpack('vcrc/Ckind/vflags/vsize', substr($prefix, $offset, 7));
        if ($fields['size'] < 7 || $fields['size'] > strlen($prefix) - $offset) {
            throw new InvalidArgumentException('unavailable_rar_header');
        }
        $bytes = substr($prefix, $offset, $fields['size']);
        if ((crc32(substr($bytes, 2)) & 0xFFFF) !== $fields['crc']) {
            throw new InvalidArgumentException('rar_header_checksum');
        }

        return ['kind' => $fields['kind'], 'flags' => $fields['flags'], 'size' => $fields['size'], 'bytes' => $bytes];
    }
}
