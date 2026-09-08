<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use UnexpectedValueException;

/** Validates metadata only. Never opens files, performs recovery, or mutates a release. */
final class Par2Inventory
{
    public function parse(string $data, int $maxFiles = 1024, int $maxPackets = 16384): Par2Manifest
    {
        $offset = 0;
        $packets = 0;
        $set = null;
        $main = null;
        $descriptions = [];
        while ($offset < strlen($data)) {
            if (++$packets > $maxPackets || strlen($data) - $offset < 64
                || substr($data, $offset, 8) !== "PAR2\0PKT") {
                throw new UnexpectedValueException('par2_structure');
            }
            $length = $this->unsigned(substr($data, $offset + 8, 8));
            if ($length < 64 || $length % 4 !== 0 || $length > strlen($data) - $offset) {
                throw new UnexpectedValueException('par2_length');
            }
            $packet = substr($data, $offset, $length);
            $offset += $length;
            if (! hash_equals(substr($packet, 16, 16), md5(substr($packet, 32), true))) {
                throw new UnexpectedValueException('par2_checksum');
            }
            $packetSet = substr($packet, 32, 16);
            if ($set !== null && $set !== $packetSet) {
                throw new UnexpectedValueException('par2_mixed_sets');
            }
            $set = $packetSet;
            $type = substr($packet, 48, 16);
            $body = substr($packet, 64);
            if ($type === "PAR 2.0\0Main\0\0\0\0") {
                if (($main !== null && $main !== $body) || ! hash_equals($set, md5($body, true))) {
                    throw new UnexpectedValueException('par2_main_identity');
                }
                $main = $body;
            } elseif ($type === "PAR 2.0\0FileDesc") {
                if (strlen($body) < 60) {
                    throw new UnexpectedValueException('par2_description_length');
                }
                $id = bin2hex(substr($body, 0, 16));
                $name = rtrim(substr($body, 56), "\0");
                if ($name === '' || str_contains($name, "\0") || strlen($body) - 56 - strlen($name) > 3
                    || ! hash_equals(substr($body, 0, 16), md5(substr($body, 32, 24).$name, true))) {
                    throw new UnexpectedValueException('par2_file_identity');
                }
                if (isset($descriptions[$id]) && $descriptions[$id] !== $body) {
                    throw new UnexpectedValueException('par2_conflicting_description');
                }
                $descriptions[$id] = $body;
                if (count($descriptions) > $maxFiles) {
                    throw new UnexpectedValueException('par2_file_limit');
                }
            }
        }
        if ($main === null || strlen($main) < 12 || (strlen($main) - 12) % 16 !== 0) {
            throw new UnexpectedValueException('par2_missing_main');
        }
        $slice = $this->unsigned(substr($main, 0, 8));
        $recoverable = unpack('Vcount', substr($main, 8, 4))['count'];
        $ids = str_split(substr($main, 12), 16);
        if ($slice < 4 || $slice % 4 !== 0 || $recoverable < 1 || $recoverable > count($ids)
            || count($ids) > $maxFiles || count(array_unique($ids)) !== count($ids)
            || count($descriptions) !== count($ids)) {
            throw new UnexpectedValueException('par2_inventory');
        }
        $files = [];
        foreach ($ids as $id) {
            $body = $descriptions[bin2hex($id)] ?? null;
            if ($body === null) {
                throw new UnexpectedValueException('par2_missing_description');
            }
            $name = rtrim(substr($body, 56), "\0");
            if (isset($files[$name])) {
                throw new UnexpectedValueException('par2_duplicate_filename');
            }
            $files[$name] = ['id' => bin2hex($id), 'size' => $this->unsigned(substr($body, 48, 8)),
                'full' => bin2hex(substr($body, 16, 16)), 'prefix' => bin2hex(substr($body, 32, 16))];
        }

        return new Par2Manifest(bin2hex((string) $set), $files, $packets);
    }

    private function unsigned(string $bytes): int
    {
        $value = unpack('Pvalue', $bytes)['value'];
        if (! is_int($value) || $value < 0) {
            throw new UnexpectedValueException('par2_integer_overflow');
        }

        return $value;
    }
}
