<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

use RuntimeException;

/** A bounded cursor for the 7z header grammar. */
final class MetadataReader
{
    private int $position = 0;

    public function __construct(private readonly string $data) {}

    public function bytes(int $length): string
    {
        if ($length < 0 || $length > strlen($this->data) - $this->position) {
            throw new RuntimeException('truncated-header');
        }
        $result = substr($this->data, $this->position, $length);
        $this->position += $length;

        return $result;
    }

    public function byte(): int
    {
        return ord($this->bytes(1));
    }

    public function expect(int $value): void
    {
        if ($this->byte() !== $value) {
            throw new RuntimeException('unsupported-header');
        }
    }

    /** @phpstan-impure */
    public function number(): int
    {
        $first = $this->byte();
        $value = 0;
        for ($i = 0, $mask = 128; $i < 8; $i++, $mask >>= 1) {
            if (($first & $mask) === 0) {
                return $value | (($first & ($mask - 1)) << ($i * 8));
            }
            $byte = $this->byte();
            if ($i === 7 && $byte > 127) {
                throw new RuntimeException('integer-overflow');
            }
            $value |= $byte << ($i * 8);
        }

        return $value;
    }

    public function count(): int
    {
        $count = $this->number();
        if ($count > 4096) {
            throw new RuntimeException('header-count-budget');
        }

        return $count;
    }

    /** @return list<bool> */
    public function bits(int $count): array
    {
        $result = [];
        $byte = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($i % 8 === 0) {
                $byte = $this->byte();
            }
            $result[] = ($byte & (128 >> ($i % 8))) !== 0;
        }

        return $result;
    }

    /** @return list<string|null> Little-endian CRC bytes. */
    public function digests(int $count): array
    {
        $all = $this->byte();
        if ($all > 1) {
            throw new RuntimeException('invalid-digest-flags');
        }
        $defined = $all === 1 ? array_fill(0, $count, true) : $this->bits($count);

        return array_map(fn (bool $present): ?string => $present ? $this->bytes(4) : null, $defined);
    }

    public function end(): void
    {
        if ($this->position !== strlen($this->data)) {
            throw new RuntimeException('trailing-header-data');
        }
    }
}
