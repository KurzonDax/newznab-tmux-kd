<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryPar2
{
    public function parse(string $bytes): RecoveryInventory
    {
        $length = strlen($bytes);
        if ($length < 64 || $length > 1048576) {
            throw new InvalidArgumentException('par2_index_size');
        }
        $main = $set = null;
        $descriptions = $checks = [];
        $packets = 0;
        for ($offset = 0; $offset < $length;) {
            if (++$packets > 4096 || $length - $offset < 64 || substr($bytes, $offset, 8) !== "PAR2\0PKT") {
                throw new InvalidArgumentException('par2_packet_framing');
            }
            $size = $this->uint64(substr($bytes, $offset + 8, 8));
            if ($size < 64 || $size > $length - $offset || $size % 4 !== 0) {
                throw new InvalidArgumentException('par2_packet_length');
            }
            $tail = substr($bytes, $offset + 32, $size - 32);
            if (! hash_equals(substr($bytes, $offset + 16, 16), md5($tail, true))) {
                throw new InvalidArgumentException('par2_packet_checksum');
            }
            $packetSet = substr($tail, 0, 16);
            $set ??= $packetSet;
            if ($set !== $packetSet) {
                throw new InvalidArgumentException('multiple_par2_sets');
            }
            $type = substr($tail, 16, 16);
            $body = substr($tail, 32);
            if ($type === str_pad("PAR 2.0\0Main", 16, "\0")) {
                if ($main !== null && $main !== $body) {
                    throw new InvalidArgumentException('conflicting_par2_main');
                }
                $main = $body;
            } elseif ($type === str_pad("PAR 2.0\0FileDesc", 16, "\0")) {
                $this->packetForFile($descriptions, $body);
            } elseif ($type === str_pad("PAR 2.0\0IFSC", 16, "\0")) {
                $this->packetForFile($checks, $body);
            }
            $offset += $size;
        }
        if ($main === null || strlen($main) < 28 || (strlen($main) - 12) % 16 !== 0) {
            throw new InvalidArgumentException('incomplete_par2_inventory');
        }
        if (md5($main, true) !== $set) {
            throw new InvalidArgumentException('par2_set_identity');
        }
        $sliceSize = $this->uint64(substr($main, 0, 8));
        if ($sliceSize < 4 || $sliceSize % 4 !== 0) {
            throw new InvalidArgumentException('invalid_par2_slice_size');
        }
        $recoverable = unpack('V', substr($main, 8, 4))[1];
        $count = intdiv(strlen($main) - 12, 16);
        if ($count > 256 || $recoverable > $count || $recoverable < 1) {
            throw new InvalidArgumentException('invalid_par2_main_inventory');
        }
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $id = substr($main, 12 + $index * 16, 16);
            $key = bin2hex($id);
            if (isset($ids[$key])) {
                throw new InvalidArgumentException('duplicate_par2_file_id');
            }
            $ids[$key] = $id;
        }
        if (count($descriptions) !== $count || count($checks) !== $count
            || array_diff_key($ids, $descriptions) !== [] || array_diff_key($ids, $checks) !== []) {
            throw new InvalidArgumentException('incomplete_par2_inventory');
        }
        $files = [];
        foreach ($ids as $key => $id) {
            $files[] = $this->file($id, $descriptions[$key], $checks[$key], $sliceSize);
        }
        if ($recoverable !== $count) {
            throw new InvalidArgumentException('unsupported_nonrecoverable_payloads');
        }
        if ($count > 32) {
            throw new InvalidArgumentException('unsupported_par2_inventory_count');
        }

        return new RecoveryInventory($set, $sliceSize, $files);
    }

    /** @param array<string,string> $packets */
    private function packetForFile(array &$packets, string $body): void
    {
        if (strlen($body) < 16 || count($packets) > 256) {
            throw new InvalidArgumentException('invalid_par2_file_packet');
        }
        $key = bin2hex(substr($body, 0, 16));
        if (isset($packets[$key]) && $packets[$key] !== $body) {
            throw new InvalidArgumentException('conflicting_par2_file_packet');
        }
        $packets[$key] = $body;
    }

    private function file(string $id, string $description, string $checks, int $sliceSize): RecoveryProtectedFile
    {
        if (strlen($description) < 60 || strlen($description) > 1084) {
            throw new InvalidArgumentException('invalid_par2_description');
        }
        $md5 = substr($description, 16, 16);
        $prefix = substr($description, 32, 16);
        $sizeBytes = substr($description, 48, 8);
        $size = $this->uint64($sizeBytes);
        $paddedName = substr($description, 56);
        $filename = rtrim($paddedName, "\0");
        if ($filename === '' || strlen($paddedName) - strlen($filename) > 3 || str_contains($filename, "\0")
            || strlen($filename) > 1024 || $size < 1) {
            throw new InvalidArgumentException('invalid_par2_file_metadata');
        }
        if (! hash_equals($id, md5($prefix.$sizeBytes.$filename, true))) {
            throw new InvalidArgumentException('par2_file_identity');
        }
        if ($size <= 16384 && $prefix !== $md5) {
            throw new InvalidArgumentException('inconsistent_par2_small_file_hash');
        }
        $slices = intdiv($size - 1, $sliceSize) + 1;
        if ((strlen($checks) - 16) % 20 !== 0 || intdiv(strlen($checks) - 16, 20) !== $slices) {
            throw new InvalidArgumentException('invalid_par2_slice_inventory');
        }
        $sliceChecks = [];
        for ($index = 0; $index < $slices; $index++) {
            $sliceChecks[] = ['md5' => substr($checks, 16 + $index * 20, 16), 'crc32' => substr($checks, 32 + $index * 20, 4)];
        }

        return new RecoveryProtectedFile($id, $filename, $size, $md5, $prefix, $sliceChecks);
    }

    private function uint64(string $bytes): int
    {
        if (strlen($bytes) !== 8 || ord($bytes[7]) >= 128) {
            throw new InvalidArgumentException('unrepresentable_par2_integer');
        }

        return unpack('P', $bytes)[1];
    }
}
