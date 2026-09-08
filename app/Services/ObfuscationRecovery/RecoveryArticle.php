<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoveryArticle
{
    public function __construct(
        public string $filename,
        public int $fileSize,
        public int $part,
        public int $total,
        public int $begin,
        public int $end,
        public string $data,
        public bool $complete,
        public bool $crcPresent,
        public bool $crcVerified,
        public ?string $fullFileCrc32,
    ) {
        if ($filename === '' || strlen($filename) > 1024 || $fileSize < 1 || $part < 1 || $part > $total || $total > 100000
            || $begin < 1 || $end < $begin || $end > $fileSize || strlen($data) > 1048576
            || strlen($data) > $end - $begin + 1 || ($complete && strlen($data) !== $end - $begin + 1)
            || ($crcVerified && (! $complete || ! $crcPresent))
            || ($fullFileCrc32 !== null && preg_match('/^[a-f0-9]{8}$/D', $fullFileCrc32) !== 1)) {
            throw new InvalidArgumentException('invalid_article_evidence');
        }
    }

    /** @return array<string,int|string|bool|null> */
    public function metadata(): array
    {
        return ['filename' => base64_encode($this->filename), 'file_size' => $this->fileSize, 'part' => $this->part,
            'total' => $this->total, 'begin' => $this->begin, 'end' => $this->end, 'complete' => $this->complete,
            'crc_present' => $this->crcPresent, 'crc_verified' => $this->crcVerified, 'full_file_crc32' => $this->fullFileCrc32];
    }

    /** @param array<string,mixed> $metadata */
    public static function fromMetadata(array $metadata, string $data): self
    {
        if (array_diff(['filename', 'file_size', 'part', 'total', 'begin', 'end', 'complete', 'crc_present', 'crc_verified', 'full_file_crc32'], array_keys($metadata)) !== []) {
            throw new InvalidArgumentException('incomplete_article_metadata');
        }
        $filename = base64_decode($metadata['filename'], true);
        if ($filename === false) {
            throw new InvalidArgumentException('invalid_article_filename_encoding');
        }

        return new self($filename, $metadata['file_size'], $metadata['part'], $metadata['total'], $metadata['begin'],
            $metadata['end'], $data, $metadata['complete'], $metadata['crc_present'], $metadata['crc_verified'], $metadata['full_file_crc32']);
    }
}
