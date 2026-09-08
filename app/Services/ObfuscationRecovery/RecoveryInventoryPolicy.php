<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryInventoryPolicy
{
    /** @return list<array{file:RecoveryProtectedFile,expected_total:int,declared_format:?string,display_name:string}> */
    public function media(RecoveryInventory $inventory): array
    {
        $files = $this->bounded($inventory);
        $mapped = [];
        foreach ($files as $file) {
            $extension = strtolower(pathinfo(str_replace('\\', '/', $file->filename), PATHINFO_EXTENSION));
            if (in_array($extension, ['exe', 'com', 'scr', 'msi', 'dll', 'bat', 'cmd', 'ps1', 'sh', 'jar', 'app', 'dmg',
                'rar', '7z', 'zip', 'gz', 'tar', 'par2', 'nfo', 'sfv', 'srt', 'sub', 'idx', 'txt', 'pdf', 'epub',
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'flac', 'aac', 'wav', 'ogg', 'avi', 'wmv', 'mov', 'webm', 'm2ts', 'ts'], true)) {
                throw new InvalidArgumentException('unsupported_media_inventory_member');
            }
            $mapped[] = ['file' => $file, 'expected_total' => self::parts($file->size),
                'declared_format' => in_array($extension, ['mkv', 'mp4'], true) ? $extension : null,
                'display_name' => $this->safeName($file)];
        }
        $totals = array_column($mapped, 'expected_total');
        if (count(array_unique($totals)) !== count($totals)) {
            throw new InvalidArgumentException('unsupported_equal_total_media');
        }
        $names = array_count_values(array_map(static fn (array $file): string => strtolower($file['display_name']), $mapped));
        foreach ($mapped as &$file) {
            if ($names[strtolower($file['display_name'])] > 1) {
                $extension = pathinfo($file['display_name'], PATHINFO_EXTENSION);
                $stem = pathinfo($file['display_name'], PATHINFO_FILENAME);
                $file['display_name'] = substr($stem, 0, 190).'-'.bin2hex($file['file']->id).($extension === '' ? '' : '.'.$extension);
            }
        }
        unset($file);
        for ($pass = 0; $pass < count($mapped); $pass++) {
            $counts = array_count_values(array_map(static fn (array $file): string => strtolower($file['display_name']), $mapped));
            if (max($counts) === 1) {
                break;
            }
            foreach ($mapped as &$file) {
                if ($counts[strtolower($file['display_name'])] > 1) {
                    $file['display_name'] = 'file-'.bin2hex($file['file']->id).'.'.($file['declared_format'] ?? 'bin');
                }
            }
            unset($file);
        }

        return $mapped;
    }

    /** @return list<array{file:RecoveryProtectedFile,expected_total:int,declared_format:string,display_name:string,archive_ordinal:int}> */
    public function rar(RecoveryInventory $inventory, int $length, int $train, int $q): array
    {
        $files = $this->bounded($inventory);
        if ($train < 3 || $train > 31 || $length < 2 || count($files) !== $train + 1
            || $q <= $train * $length || $q >= ($train + 1) * $length) {
            throw new InvalidArgumentException('rar_inventory_boundary_mismatch');
        }
        $mapped = $prefixes = [];
        $root = $width = null;
        foreach ($files as $file) {
            if ($this->safeName($file) !== $file->filename
                || preg_match('/^(.+)\.part([0-9]{2,5})\.rar$/iD', $file->filename, $match) !== 1) {
                throw new InvalidArgumentException('unsupported_rar_volume_name');
            }
            $ordinal = (int) $match[2];
            $root ??= $match[1];
            $width ??= strlen($match[2]);
            if ($root !== $match[1] || $width !== strlen($match[2]) || $ordinal < 1 || $ordinal > count($files)
                || isset($mapped[$ordinal])) {
                throw new InvalidArgumentException('inconsistent_rar_volume_sequence');
            }
            $prefix = bin2hex($file->prefixMd5);
            if (isset($prefixes[$prefix])) {
                throw new InvalidArgumentException('duplicate_rar_prefix_identity');
            }
            $prefixes[$prefix] = true;
            $mapped[$ordinal] = ['file' => $file, 'expected_total' => self::parts($file->size),
                'declared_format' => 'rar4', 'display_name' => $file->filename, 'archive_ordinal' => $ordinal];
        }
        ksort($mapped, SORT_NUMERIC);
        $size = $mapped[1]['file']->size;
        foreach ($mapped as $ordinal => $file) {
            if ($ordinal <= $train && ($file['file']->size !== $size || $file['expected_total'] !== $length)) {
                throw new InvalidArgumentException('rar_nonfinal_geometry_mismatch');
            }
            if ($ordinal === $train + 1 && ($file['file']->size >= $size || $file['expected_total'] !== $q - $train * $length)) {
                throw new InvalidArgumentException('rar_final_geometry_mismatch');
            }
        }
        if (array_sum(array_column($mapped, 'expected_total')) !== $q) {
            throw new InvalidArgumentException('rar_payload_count_mismatch');
        }

        return array_values($mapped);
    }

    public static function parts(int $bytes): int
    {
        if ($bytes < 1 || $bytes > 716800 * 100000) {
            throw new InvalidArgumentException('protected_file_size_cap');
        }

        return intdiv($bytes - 1, 716800) + 1;
    }

    /** @return list<RecoveryProtectedFile> */
    private function bounded(RecoveryInventory $inventory): array
    {
        $files = $inventory->files;
        if (count($files) < 1 || count($files) > 32) {
            throw new InvalidArgumentException('unsupported_inventory_count');
        }
        $parts = 1;
        $ids = $destinations = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->filename);
            $destination = mb_strtolower(rtrim(basename($path), ' .'));
            if ($destination === '' || str_starts_with($path, '/') || preg_match('~^[a-zA-Z]:|(?:^|/)\.\.(?:/|$)~', $path) === 1
                || isset($destinations[$destination])) {
                throw new InvalidArgumentException('unsafe_inventory_paths');
            }
            $destinations[$destination] = true;
            if (strlen($file->id) !== 16 || isset($ids[bin2hex($file->id)])) {
                throw new InvalidArgumentException('duplicate_inventory_file_id');
            }
            $ids[bin2hex($file->id)] = true;
            $parts += self::parts($file->size);
        }
        if ($parts > 500000) {
            throw new InvalidArgumentException('planned_part_count_cap');
        }
        usort($files, static fn (RecoveryProtectedFile $a, RecoveryProtectedFile $b): int => strcmp($a->id, $b->id));

        return $files;
    }

    private function safeName(RecoveryProtectedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->filename));
        $name = preg_replace('/[^\x20-\x7e]|[<>:"|?*]/', '_', $name);
        $name = trim($name, " .\t");
        if ($name === '') {
            $name = 'file-'.bin2hex($file->id);
        }
        if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name) === 1) {
            $name = '_'.$name;
        }
        if (strlen($name) > 240) {
            $extension = substr(pathinfo($name, PATHINFO_EXTENSION), 0, 10);
            $name = substr(pathinfo($name, PATHINFO_FILENAME), 0, 190).'-'.bin2hex($file->id).($extension === '' ? '' : '.'.$extension);
        }

        return $name;
    }
}
