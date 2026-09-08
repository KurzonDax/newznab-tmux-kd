<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

final class Par2Fixture
{
    /** @param array<string, string> $files */
    public static function metadata(array $files): string
    {
        $descriptions = [];
        foreach ($files as $name => $data) {
            $prefix = md5(substr($data, 0, 16384), true);
            $length = pack('P', strlen($data));
            $id = md5($prefix.$length.$name, true);
            $descriptions[$id] = $id.md5($data, true).$prefix.$length.str_pad($name, (int) (ceil(strlen($name) / 4) * 4), "\0");
        }
        ksort($descriptions, SORT_STRING);
        $main = pack('P', 64).pack('V', count($files)).implode('', array_keys($descriptions));
        $set = md5($main, true);
        $result = self::packet($set, "PAR 2.0\0Main\0\0\0\0", $main);
        foreach ($descriptions as $description) {
            $result .= self::packet($set, "PAR 2.0\0FileDesc", $description);
        }

        return $result;
    }

    public static function packet(string $set, string $type, string $body): string
    {
        return "PAR2\0PKT".pack('P', 64 + strlen($body)).md5($set.$type.$body, true).$set.$type.$body;
    }

    /** @return array<string, string> */
    public static function course(): array
    {
        $files = [];
        for ($i = 1; $i <= 22; $i++) {
            $name = $i <= 15 ? sprintf('%03d-lesson-%s.mkv', $i, chr(96 + $i)) : ($i <= 21 ? 'bundle.r'.($i - 6) : 'bundle.sfv');
            $files[$name] = str_repeat(chr($i), 64);
        }

        return $files;
    }
}
