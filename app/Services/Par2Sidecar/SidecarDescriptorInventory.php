<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

/** Metadata from already fetched bytes. A truncated recovery volume needs no further download. */
final class SidecarDescriptorInventory
{
    /** @param array{reason:?string} $inventory */
    public static function ambiguous(array $inventory): bool
    {
        return ! in_array($inventory['reason'], [null, 'incomplete_inventory', 'missing_main', 'incomplete_metadata'], true);
    }

    /**
     * @return array{complete:bool,reason:?string,sets:array<string,list<string>>,descriptors:list<array{set_id:string,file_id:string,hash16k:string,full_hash:string,raw_size:int,filename:string}>}
     */
    public function parse(string $data): array
    {
        $sets = $descriptors = $identities = [];
        $reason = null;
        $offset = $packets = 0;
        while ($offset < strlen($data)) {
            if (++$packets > 16384 || strlen($data) - $offset < 64 || substr($data, $offset, 8) !== "PAR2\0PKT") {
                $reason = 'invalid_packet';
                break;
            }
            $length = unpack('Plength', substr($data, $offset + 8, 8))['length'];
            $type = substr($data, $offset + 48, 16);
            if (! is_int($length) || $length < 64 || $length % 4 !== 0) {
                $reason = 'invalid_packet_length';
                break;
            }
            if ($length > strlen($data) - $offset) {
                if ($type !== "PAR 2.0\0RecvSlic") {
                    $reason = 'incomplete_metadata';
                }
                break;
            }
            $packet = substr($data, $offset, $length);
            $offset += $length;
            if (! hash_equals(substr($packet, 16, 16), md5(substr($packet, 32), true))) {
                $reason = 'packet_checksum';
                break;
            }
            $set = bin2hex(substr($packet, 32, 16));
            $body = substr($packet, 64);
            if ($type === "PAR 2.0\0Main\0\0\0\0") {
                if (strlen($body) < 12 || (strlen($body) - 12) % 16 !== 0 || md5($body) !== $set) {
                    $reason = 'invalid_main';
                    break;
                }
                $slice = unpack('Pslice', substr($body, 0, 8))['slice'];
                $protected = unpack('Vcount', substr($body, 8, 4))['count'];
                $ids = array_map(bin2hex(...), str_split(substr($body, 12), 16));
                if (! is_int($slice) || $slice < 4 || $slice % 4 !== 0 || $protected < 1 || $protected > count($ids)
                    || count($ids) > 1024 || count(array_unique($ids)) !== count($ids)
                    || (isset($sets[$set]) && $sets[$set] !== $ids)) {
                    $reason = 'invalid_main_inventory';
                    break;
                }
                $sets[$set] = $ids;
            } elseif ($type === "PAR 2.0\0FileDesc") {
                $filename = rtrim(substr($body, 56), "\0");
                $size = strlen($body) >= 60 ? unpack('Psize', substr($body, 48, 8))['size'] : null;
                if (! is_int($size) || $size <= 0 || $filename === '' || strlen($filename) > 1024
                    || ! mb_check_encoding($filename, 'UTF-8') || str_contains($filename, "\0")
                    || strlen($body) - 56 - strlen($filename) > 3
                    || ! hash_equals(substr($body, 0, 16), md5(substr($body, 32, 24).$filename, true))) {
                    $reason = 'invalid_descriptor';
                    break;
                }
                $id = bin2hex(substr($body, 0, 16));
                $descriptor = ['set_id' => $set, 'file_id' => $id, 'hash16k' => bin2hex(substr($body, 32, 16)),
                    'full_hash' => bin2hex(substr($body, 16, 16)), 'raw_size' => $size, 'filename' => $filename];
                $key = hash('sha256', $packet);
                if (isset($identities[$set.$id]) && $identities[$set.$id] !== $key) {
                    $reason = 'conflicting_descriptor';
                }
                $identities[$set.$id] = $key;
                $descriptors[$key] = $descriptor;
                if (count($descriptors) > 1024) {
                    $reason = 'descriptor_overflow';
                    break;
                }
            }
        }
        if ($sets === [] || $descriptors === []) {
            $reason ??= 'incomplete_inventory';
        }
        foreach ($sets as $set => $ids) {
            $found = array_column(array_filter($descriptors, static fn (array $row): bool => $row['set_id'] === $set), 'file_id');
            sort($found);
            sort($ids);
            if ($ids !== $found) {
                $reason ??= 'incomplete_inventory';
            }
        }
        foreach ($descriptors as $descriptor) {
            if (! isset($sets[$descriptor['set_id']])) {
                $reason ??= 'missing_main';
            }
        }

        return ['complete' => $reason === null, 'reason' => $reason, 'sets' => $sets, 'descriptors' => array_values($descriptors)];
    }
}
