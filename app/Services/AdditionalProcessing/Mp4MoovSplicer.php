<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\Mp4MoovSpliceResult;
use App\Services\AdditionalProcessing\Enums\Mp4MoovSpliceStatus;

final readonly class Mp4MoovSplicer
{
    public function needsTail(string $head): bool
    {
        return $this->mdatHeader($head) !== null;
    }

    public function splice(string $head, string $tail, bool $atSegmentCap = false): Mp4MoovSpliceResult
    {
        $mdat = $this->mdatHeader($head);
        if ($mdat === null) {
            return new Mp4MoovSpliceResult(Mp4MoovSpliceStatus::Missing);
        }

        $moov = $this->locateMoov($tail);
        if ($moov === null) {
            return new Mp4MoovSpliceResult(
                $atSegmentCap ? Mp4MoovSpliceStatus::Missing : Mp4MoovSpliceStatus::NeedMore,
            );
        }

        $head = $this->rewriteMdatSize($head, $mdat['offset'], $mdat['headerSize']);

        return new Mp4MoovSpliceResult(Mp4MoovSpliceStatus::Spliced, $head.$moov);
    }

    /**
     * Find the first top-level mdat only when no moov precedes it.
     *
     * @return array{offset: int, headerSize: int}|null
     */
    private function mdatHeader(string $head): ?array
    {
        $length = strlen($head);
        $offset = 0;

        while ($offset + 8 <= $length) {
            $size = $this->readUInt32($head, $offset);
            $type = substr($head, $offset + 4, 4);
            $headerSize = 8;

            if ($size === 1) {
                if ($offset + 16 > $length) {
                    return null;
                }
                $size = $this->readUInt64($head, $offset + 8);
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $length - $offset;
            }

            if ($type === 'moov') {
                return null;
            }
            if ($type === 'mdat') {
                return ['offset' => $offset, 'headerSize' => $headerSize];
            }
            if ($size < $headerSize || $size > $length - $offset) {
                return null;
            }

            $offset += $size;
        }

        return null;
    }

    private function locateMoov(string $tail): ?string
    {
        $length = strlen($tail);
        $searchOffset = 0;

        while (($typeOffset = strpos($tail, 'moov', $searchOffset)) !== false) {
            $searchOffset = $typeOffset + 4;
            $start = $typeOffset - 4;
            if ($start < 0) {
                continue;
            }

            $size = $this->readUInt32($tail, $start);
            $headerSize = 8;
            if ($size === 1) {
                if ($start + 16 > $length) {
                    continue;
                }
                $size = $this->readUInt64($tail, $start + 8);
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $length - $start;
            }

            if ($size < $headerSize + 8 || $size > $length - $start) {
                continue;
            }

            $firstChildOffset = $start + $headerSize;
            $firstChildSize = $this->readUInt32($tail, $firstChildOffset);
            if (substr($tail, $firstChildOffset + 4, 4) !== 'mvhd'
                || $firstChildSize < 8
                || $firstChildSize > ($start + $size) - $firstChildOffset
            ) {
                continue;
            }

            return substr($tail, $start, $size);
        }

        return null;
    }

    private function rewriteMdatSize(string $head, int $offset, int $headerSize): string
    {
        $downloadedMdatSize = strlen($head) - $offset;

        if ($headerSize === 16) {
            return substr_replace($head, $this->packUInt64($downloadedMdatSize), $offset + 8, 8);
        }

        return substr_replace($head, pack('N', $downloadedMdatSize), $offset, 4);
    }

    private function readUInt32(string $data, int $offset): int
    {
        $unpacked = unpack('Nvalue', substr($data, $offset, 4));

        return (int) ($unpacked['value'] ?? 0);
    }

    private function readUInt64(string $data, int $offset): int
    {
        $unpacked = unpack('Nhigh/Nlow', substr($data, $offset, 8));
        $high = (int) ($unpacked['high'] ?? 0);
        if ($high > intdiv(PHP_INT_MAX, 4_294_967_296)) {
            return PHP_INT_MAX;
        }

        return ($high * 4_294_967_296) + (int) ($unpacked['low'] ?? 0);
    }

    private function packUInt64(int $value): string
    {
        return pack('N2', intdiv($value, 4_294_967_296), $value % 4_294_967_296);
    }
}
