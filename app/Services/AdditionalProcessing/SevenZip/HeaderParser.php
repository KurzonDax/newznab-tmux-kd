<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

use RuntimeException;

/**
 * Parses the supported inline 7z header grammar, without reading payload streams.
 * Unsupported external tables and coder graphs remain incomplete.
 *
 * @phpstan-type Coder array{method: string, properties: string}
 * @phpstan-type Folder array{coders: list<Coder>, size: int, crc: ?string, encrypted: bool, sizes: list<int>}
 * @phpstan-type Streams array{offset: int, packed: list<int>, crcs: list<?string>, folders: list<Folder>}
 */
final class HeaderParser
{
    /** @return Streams */
    public function streams(MetadataReader $reader): array
    {
        $reader->expect(6);
        $offset = $reader->number();
        $count = $reader->count();
        if ($count === 0) {
            throw new RuntimeException('empty-stream-table');
        }
        $reader->expect(9);
        $packed = [];
        for ($i = 0; $i < $count; $i++) {
            $packed[] = $reader->number();
        }
        $id = $reader->byte();
        $crcs = array_fill(0, $count, null);
        if ($id === 10) {
            $crcs = $reader->digests($count);
            $id = $reader->byte();
        }
        if ($id !== 0) {
            throw new RuntimeException('invalid-pack-table');
        }
        $reader->expect(7);
        $reader->expect(11);
        $foldersCount = $reader->count();
        if ($foldersCount !== $count) {
            throw new RuntimeException('unsupported-stream-graph');
        }
        $reader->expect(0);
        $folders = [];
        for ($f = 0; $f < $foldersCount; $f++) {
            $codersCount = $reader->count();
            if ($codersCount < 1 || $codersCount > 4) {
                throw new RuntimeException('unsupported-coder-graph');
            }
            $coders = [];
            $encrypted = false;
            for ($c = 0; $c < $codersCount; $c++) {
                $flags = $reader->byte();
                if (($flags & 0xC0) !== 0 || ($flags & 15) === 0) {
                    throw new RuntimeException('invalid-coder-flags');
                }
                $method = bin2hex($reader->bytes($flags & 15));
                if (($flags & 16) !== 0 && ($reader->number() !== 1 || $reader->number() !== 1)) {
                    throw new RuntimeException('unsupported-complex-coder');
                }
                $properties = ($flags & 32) !== 0 ? $reader->bytes($reader->count()) : '';
                $this->validateCoder($method, $properties);
                $encrypted = $encrypted || $method === '06f10701';
                $coders[] = ['method' => $method, 'properties' => $properties];
            }
            for ($c = 1; $c < $codersCount; $c++) {
                if ($reader->number() !== $c || $reader->number() !== $c - 1) {
                    throw new RuntimeException('unsupported-coder-binding');
                }
            }
            $folders[] = ['coders' => $coders, 'size' => 0, 'crc' => null, 'encrypted' => $encrypted, 'sizes' => []];
        }
        $reader->expect(12);
        foreach ($folders as &$folder) {
            foreach ($folder['coders'] as $coder) {
                $folder['size'] = $reader->number();
            }
            $folder['sizes'] = [$folder['size']];
        }
        unset($folder);
        $id = $reader->byte();
        if ($id === 10) {
            foreach ($reader->digests($count) as $index => $crc) {
                $folders[$index]['crc'] = $crc;
            }
            $id = $reader->byte();
        }
        if ($id !== 0) {
            throw new RuntimeException('invalid-unpack-table');
        }
        $id = $reader->byte();
        if ($id === 8) {
            $id = $reader->byte();
            $counts = array_fill(0, $count, 1);
            if ($id === 13) {
                foreach ($counts as &$n) {
                    $n = $reader->count();
                }
                unset($n);
                if (array_sum($counts) > 4096) {
                    throw new RuntimeException('substream-budget');
                }
                $id = $reader->byte();
            }
            foreach ($folders as $index => &$folder) {
                $remaining = $folder['size'];
                $sizes = [];
                for ($i = 1; $i < $counts[$index]; $i++) {
                    if ($id !== 9) {
                        throw new RuntimeException('missing-substream-sizes');
                    }
                    $size = $reader->number();
                    if ($size > $remaining) {
                        throw new RuntimeException('invalid-substream-size');
                    }
                    $sizes[] = $size;
                    $remaining -= $size;
                }
                $folder['sizes'] = $counts[$index] === 0 ? [] : [...$sizes, $remaining];
            }
            unset($folder);
            if ($id === 9) {
                $id = $reader->byte();
            }
            if ($id === 10) {
                $digests = 0;
                foreach ($folders as $index => $folder) {
                    $digests += $counts[$index] === 1 && $folder['crc'] !== null ? 0 : $counts[$index];
                }
                $reader->digests($digests);
                $id = $reader->byte();
            }
            if ($id !== 0) {
                throw new RuntimeException('invalid-substreams');
            }
            $id = $reader->byte();
        }
        if ($id !== 0) {
            throw new RuntimeException('invalid-streams-end');
        }

        return ['offset' => $offset, 'packed' => $packed, 'crcs' => $crcs, 'folders' => $folders];
    }

    /** @return array{encrypted: bool, files: list<array<string, mixed>>, streams: ?Streams} */
    public function manifest(string $data): array
    {
        $reader = new MetadataReader($data);
        $reader->expect(1);
        $id = $reader->byte();
        if ($id === 2) {
            while ($reader->byte() !== 0) {
                $reader->bytes($reader->number());
            }
            $id = $reader->byte();
        }
        $streams = null;
        if ($id === 4) {
            $streams = $this->streams($reader);
            $id = $reader->byte();
        }
        $files = [];
        if ($id === 5) {
            $files = $this->files($reader, $streams);
            $id = $reader->byte();
        }
        if ($id !== 0) {
            throw new RuntimeException('unsupported-header-section');
        }
        $reader->end();
        $encrypted = false;
        foreach ($streams['folders'] ?? [] as $folder) {
            $encrypted = $encrypted || $folder['encrypted'];
        }

        return ['encrypted' => $encrypted, 'files' => $files, 'streams' => $streams];
    }

    /**
     * @param  Streams|null  $streams
     * @return list<array<string, mixed>>
     */
    private function files(MetadataReader $reader, ?array $streams): array
    {
        $count = $reader->count();
        $empty = array_fill(0, $count, false);
        $emptyFiles = [];
        $names = [];
        $seen = [];
        while (($id = $reader->byte()) !== 0) {
            if ($id !== 25 && isset($seen[$id])) {
                throw new RuntimeException('duplicate-file-property');
            }
            $seen[$id] = true;
            $propertyData = $reader->bytes($reader->number());
            $property = new MetadataReader($propertyData);
            if ($id === 14) {
                $empty = $property->bits($count);
            } elseif ($id === 15 || $id === 16) {
                $bits = $property->bits(count(array_filter($empty)));
                if ($id === 15) {
                    $emptyFiles = $bits;
                } elseif (in_array(true, $bits, true)) {
                    throw new RuntimeException('unsupported-anti-file');
                }
            } elseif ($id === 17) {
                $property->expect(0);
                for ($i = 0; $i < $count; $i++) {
                    $name = '';
                    while (($char = $property->bytes(2)) !== "\0\0") {
                        $name .= $char;
                    }
                    if ($name === '' || ! mb_check_encoding($name, 'UTF-16LE')) {
                        throw new RuntimeException('invalid-file-name');
                    }
                    $names[] = mb_convert_encoding($name, 'UTF-8', 'UTF-16LE');
                }
            } elseif ($id === 25) {
                if (trim($property->bytes(strlen($propertyData)), "\0") !== '') {
                    throw new RuntimeException('invalid-header-padding');
                }
            } elseif (in_array($id, [18, 19, 20, 21], true)) {
                $all = $property->byte();
                if ($all > 1) {
                    throw new RuntimeException('invalid-property-flags');
                }
                $defined = $all === 1 ? array_fill(0, $count, true) : $property->bits($count);
                $property->expect(0);
                $property->bytes(count(array_filter($defined)) * ($id === 21 ? 4 : 8));
            } else {
                throw new RuntimeException('unsupported-file-property');
            }
            $property->end();
        }
        if (count($names) !== $count) {
            throw new RuntimeException('missing-file-names');
        }
        $sizes = [];
        foreach ($streams['folders'] ?? [] as $folder) {
            foreach ($folder['sizes'] as $size) {
                $sizes[] = ['size' => $size, 'pass' => (int) $folder['encrypted']];
            }
        }
        if (count($sizes) !== $count - count(array_filter($empty))) {
            throw new RuntimeException('file-stream-count-mismatch');
        }
        $files = [];
        $streamIndex = $emptyIndex = 0;
        foreach ($names as $index => $name) {
            $file = ['name' => $name, 'date' => 0];
            if ($empty[$index]) {
                $file += ['size' => 0, 'pass' => 0, 'is_dir' => (int) ! ($emptyFiles[$emptyIndex++] ?? false)];
            } else {
                $file += $sizes[$streamIndex++];
            }
            $files[] = $file;
        }

        return $files;
    }

    private function validateCoder(string $method, string $properties): void
    {
        $valid = match ($method) {
            '00' => $properties === '',
            '030101' => strlen($properties) === 5 && ord($properties[0]) < 225,
            '21' => strlen($properties) === 1 && ord($properties[0]) <= 40,
            '06f10701' => $this->validAesProperties($properties),
            default => false,
        };
        if (! $valid) {
            throw new RuntimeException('unsupported-coder');
        }
    }

    private function validAesProperties(string $properties): bool
    {
        if (strlen($properties) < 1) {
            return false;
        }
        $first = ord($properties[0]);
        if (($first & 192) === 0) {
            return strlen($properties) === 1;
        }
        if (strlen($properties) < 2) {
            return false;
        }
        $second = ord($properties[1]);

        return strlen($properties) === 2 + (($first >> 7) & 1) + ($second >> 4)
            + (($first >> 6) & 1) + ($second & 15);
    }
}
