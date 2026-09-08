<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

final class SyntheticPosting
{
    public const int ARTICLE_BYTES = 716800;

    public static function bytes(string $label, int $size): string
    {
        $block = '';
        for ($index = 0; $index < 256; $index++) {
            $block .= hash('sha256', $label.pack('V', $index), true);
        }

        return substr(str_repeat($block, intdiv($size + strlen($block) - 1, strlen($block))), 0, $size);
    }

    /** @param array<string,string> $files */
    public static function par2(array $files, int $sliceSize = 1048576): string
    {
        $entries = [];
        foreach ($files as $name => $data) {
            $prefix = md5(substr($data, 0, 16384), true);
            $id = md5($prefix.pack('P', strlen($data)).$name, true);
            $entries[bin2hex($id)] = compact('id', 'name', 'data', 'prefix');
        }
        ksort($entries, SORT_STRING);
        $main = pack('PV', $sliceSize, count($entries)).implode('', array_column($entries, 'id'));
        $set = md5($main, true);
        $packets = self::packet($set, 'Main', $main);
        foreach ($entries as $entry) {
            $packets .= self::packet($set, 'FileDesc', $entry['id'].md5($entry['data'], true).$entry['prefix'].pack('P', strlen($entry['data'])).$entry['name']);
            $checks = $entry['id'];
            for ($offset = 0, $size = strlen($entry['data']); $offset < $size; $offset += $sliceSize) {
                $slice = str_pad(substr($entry['data'], $offset, $sliceSize), $sliceSize, "\0");
                $checks .= md5($slice, true).pack('V', crc32($slice));
            }
            $packets .= self::packet($set, 'IFSC', $checks);
        }

        return $packets;
    }

    public static function packet(string $set, string $type, string $body): string
    {
        $body = str_pad($body, intdiv(strlen($body) + 3, 4) * 4, "\0");
        $tail = $set.str_pad("PAR 2.0\0".$type, 16, "\0").$body;

        return "PAR2\0PKT".pack('P', 32 + strlen($tail)).md5($tail, true).$tail;
    }

    /** @param list<int> $sizes
     * @return array{content:string,volumes:list<string>}
     */
    public static function rar(array $sizes, string $innerName = 'fixture.bin', ?string $innerContent = null): array
    {
        $overhead = 59 + strlen($innerName);
        $content = $innerContent ?? self::bytes('archive-inner', array_sum($sizes) - count($sizes) * $overhead);
        if (strlen($content) !== array_sum($sizes) - count($sizes) * $overhead) {
            throw new \InvalidArgumentException('Invalid fixture content length.');
        }
        $cursor = 0;
        $volumes = [];
        foreach ($sizes as $index => $size) {
            $piece = substr($content, $cursor, $size - $overhead);
            $cursor += strlen($piece);
            $last = $index === count($sizes) - 1;
            $main = self::rarHeader(0x73, 0x0011 | ($index === 0 ? 0x0100 : 0), str_repeat("\0", 6));
            $flags = 0x8000 | ($index === 0 ? 0 : 1) | ($last ? 0 : 2);
            $extra = pack('VVCVVCCvV', strlen($piece), strlen($content), 3, crc32($last ? $content : $piece),
                0x00210000, 20, 0x30, strlen($innerName), 0x81A4).$innerName;
            $volumes[] = "Rar!\x1a\x07\0".$main.self::rarHeader(0x74, $flags, $extra).$piece.self::rarHeader(0x7B, $last ? 0 : 1, '');
        }

        return compact('content', 'volumes');
    }

    private static function rarHeader(int $kind, int $flags, string $extra): string
    {
        $data = pack('Cvv', $kind, $flags, 7 + strlen($extra)).$extra;

        return pack('v', crc32($data) & 0xFFFF).$data;
    }
}
