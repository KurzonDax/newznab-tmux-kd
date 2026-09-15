<?php

declare(strict_types=1);

namespace App\Support;

use Generator;

final class ReverseLineReader
{
    private const int BLOCK_BYTES = 65536;

    private const int MAX_LINE_BYTES = 131072;

    /**
     * Read the size observed at open, newest line first. A null marks an oversized line.
     * Renames retain the open inode; truncation or a failed read ends this snapshot.
     *
     * @return Generator<int, string|null>
     */
    public function lines(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            $stat = @fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                return;
            }
            $snapshotSize = $stat['size'];
            $position = $snapshotSize;
            $partial = '';
            $oversized = false;

            while ($position > 0) {
                $length = min(self::BLOCK_BYTES, $position);
                $position -= $length;
                if (@fseek($handle, $position) !== 0) {
                    return;
                }
                $block = '';
                while (strlen($block) < $length) {
                    $chunk = @fread($handle, $length - strlen($block));
                    if ($chunk === false || $chunk === '') {
                        return;
                    }
                    $block .= $chunk;
                }
                $stat = @fstat($handle);
                if ($stat === false || $stat['size'] < $snapshotSize) {
                    return;
                }

                unset($chunk);
                $end = strlen($block);
                while ($end > 0) {
                    $newline = strrpos($block, "\n", $end - strlen($block) - 1);
                    $start = $newline === false ? 0 : $newline + 1;
                    $pieceLength = $end - $start;
                    if (! $oversized) {
                        // One extra byte permits CRLF without reducing the content limit.
                        if ($pieceLength + strlen($partial) > self::MAX_LINE_BYTES + 1) {
                            $oversized = true;
                            $partial = '';
                        } else {
                            $partial = substr($block, $start, $pieceLength).$partial;
                        }
                    }
                    if ($newline === false) {
                        break;
                    }

                    $line = str_ends_with($partial, "\r") ? substr($partial, 0, -1) : $partial;
                    $partial = '';
                    yield $oversized || strlen($line) > self::MAX_LINE_BYTES ? null : $line;
                    unset($line);
                    $oversized = false;
                    $end = $newline;
                }
            }

            $line = str_ends_with($partial, "\r") ? substr($partial, 0, -1) : $partial;
            yield $oversized || strlen($line) > self::MAX_LINE_BYTES ? null : $line;
        } finally {
            fclose($handle);
        }
    }
}
