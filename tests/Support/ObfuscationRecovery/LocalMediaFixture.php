<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use Symfony\Component\Process\Process;

final class LocalMediaFixture
{
    public static function write(string $directory, string $format, bool $fastStart = true, int $duration = 5): string
    {
        if (! in_array($format, ['mkv', 'mp4'], true) || $duration < 1 || $duration > 10) {
            throw new \InvalidArgumentException('unsupported_fixture_format');
        }
        $path = $directory.'/fixture-'.($fastStart ? 'head' : 'tail').'.'.$format;
        (new Process(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30', '-t', (string) $duration, '-an',
            '-c:v', 'mpeg4', '-q:v', '2', '-threads', '1', '-fflags', '+bitexact', '-flags:v', '+bitexact',
            '-map_metadata', '-1', ...($format === 'mp4' && $fastStart ? ['-movflags', '+faststart'] : []), $path]))
            ->setTimeout(30)->mustRun();

        return $path;
    }

    /** @return array<string,int> */
    public static function mp4AtomOffsets(string $path): array
    {
        $stream = fopen($path, 'rb');
        $offset = 0;
        $size = filesize($path);
        $atoms = [];
        try {
            while ($offset + 8 <= $size) {
                fseek($stream, $offset);
                $header = fread($stream, 8);
                $length = unpack('N', substr($header, 0, 4))[1];
                if ($length === 1) {
                    $length = unpack('J', fread($stream, 8))[1];
                } elseif ($length === 0) {
                    $length = $size - $offset;
                }
                if ($length < 8 || $length > $size - $offset) {
                    throw new \RuntimeException('invalid_fixture_atom');
                }
                $atoms[substr($header, 4, 4)] = $offset;
                $offset += $length;
            }
        } finally {
            fclose($stream);
        }

        return $atoms;
    }
}
