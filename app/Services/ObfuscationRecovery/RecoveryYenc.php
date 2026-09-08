<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryYenc
{
    private string $state = 'begin';

    private string $filename = '';

    private int $fileSize = 0;

    private int $part = 1;

    private int $total = 1;

    private int $begin = 1;

    private int $end = 0;

    private string $data = '';

    private bool $multipart = false;

    private bool $crcPresent = false;

    private bool $crcVerified = false;

    private ?string $fullFileCrc32 = null;

    public function __construct(private readonly int $maximumDecoded, private readonly bool $prefixOnly = false, private readonly bool $declarationOnly = false)
    {
        if ($maximumDecoded < 1 || $maximumDecoded > 1048576 || ($declarationOnly && ! $prefixOnly)) {
            throw new InvalidArgumentException('invalid_decoded_article_cap');
        }
    }

    public function line(string $line): bool
    {
        if (strlen($line) > 8192 || str_contains($line, "\r") || str_contains($line, "\n")) {
            throw new InvalidArgumentException('invalid_yenc_line');
        }
        if ($this->state === 'begin') {
            $this->header($line);

            return $this->declarationReady();
        }
        if ($this->state === 'part') {
            $fields = $this->fields($line, '=ypart');
            $this->begin = $this->integer($fields['begin'] ?? '', $this->fileSize);
            $this->end = $this->integer($fields['end'] ?? '', $this->fileSize);
            if ($this->end < $this->begin) {
                throw new InvalidArgumentException('invalid_yenc_range');
            }
            $this->checkDecodedCap();
            $this->state = 'data';

            return $this->declarationReady();
        }
        if ($this->state === 'ended' || $this->state === 'prefix') {
            throw new InvalidArgumentException('trailing_yenc_data');
        }
        if (str_starts_with($line, '=yend ')) {
            $this->footer($line);

            return false;
        }
        if (str_starts_with($line, '=ybegin ') || str_starts_with($line, '=ypart ')) {
            throw new InvalidArgumentException('multiple_yenc_payloads');
        }
        for ($index = 0, $length = strlen($line); $index < $length; $index++) {
            $value = ord($line[$index]);
            if ($value === 61) {
                if (++$index === $length) {
                    throw new InvalidArgumentException('invalid_yenc_escape');
                }
                $value = (ord($line[$index]) - 64 + 256) % 256;
            }
            if (strlen($this->data) >= $this->maximumDecoded || strlen($this->data) >= $this->end - $this->begin + 1) {
                throw new InvalidArgumentException('decoded_article_cap');
            }
            $this->data .= chr(($value - 42 + 256) % 256);
            if ($this->prefixOnly && strlen($this->data) === $this->maximumDecoded
                && $this->end - $this->begin + 1 > $this->maximumDecoded) {
                $this->state = 'prefix';

                return true;
            }
        }

        return false;
    }

    public function finish(): RecoveryArticle
    {
        if ($this->state !== 'ended') {
            throw new InvalidArgumentException('incomplete_yenc_article');
        }

        return $this->article(true);
    }

    public function prefix(): RecoveryArticle
    {
        if (! $this->prefixOnly || $this->state !== 'prefix') {
            throw new InvalidArgumentException('incomplete_yenc_prefix');
        }

        return $this->article(false);
    }

    public function decodedBytes(): int
    {
        return strlen($this->data);
    }

    private function declarationReady(): bool
    {
        if ($this->declarationOnly && $this->state === 'data') {
            $this->state = 'prefix';

            return true;
        }

        return false;
    }

    private function header(string $line): void
    {
        $offset = strpos($line, ' name=');
        if ($offset === false || strlen($line) - $offset > 1030) {
            throw new InvalidArgumentException('invalid_yenc_filename');
        }
        $this->filename = substr($line, $offset + 6);
        if ($this->filename === '' || str_contains($this->filename, "\0")) {
            throw new InvalidArgumentException('invalid_yenc_filename');
        }
        $fields = $this->fields(substr($line, 0, $offset), '=ybegin');
        $this->fileSize = $this->integer($fields['size'] ?? '', PHP_INT_MAX);
        $this->integer($fields['line'] ?? '', 8192);
        $this->multipart = isset($fields['part']) || isset($fields['total']);
        if ($this->multipart) {
            $this->total = $this->integer($fields['total'] ?? '', 100000);
            $this->part = $this->integer($fields['part'] ?? '', $this->total);
            $this->state = 'part';
        } else {
            $this->end = $this->fileSize;
            $this->checkDecodedCap();
            $this->state = 'data';
        }
    }

    private function footer(string $line): void
    {
        $fields = $this->fields($line, '=yend');
        $size = $this->integer($fields['size'] ?? '', 1048576);
        if ($size !== strlen($this->data) || $size !== $this->end - $this->begin + 1
            || ($this->multipart && $this->integer($fields['part'] ?? '', $this->total) !== $this->part)) {
            throw new InvalidArgumentException('yenc_size_mismatch');
        }
        foreach (['pcrc32', 'crc32'] as $key) {
            if (! isset($fields[$key])) {
                continue;
            }
            $this->crcPresent = true;
            $crc = strtolower($fields[$key]);
            if (! preg_match('/^[a-f0-9]{8}$/D', $crc)) {
                throw new InvalidArgumentException('invalid_yenc_crc');
            }
            if ($key === 'crc32') {
                $this->fullFileCrc32 = $crc;
            }
            if ($key === 'pcrc32' || ($this->begin === 1 && $this->end === $this->fileSize)) {
                if (! hash_equals(hash('crc32b', $this->data), $crc)) {
                    throw new InvalidArgumentException('yenc_crc_mismatch');
                }
                $this->crcVerified = true;
            }
        }
        $this->state = 'ended';
    }

    private function checkDecodedCap(): void
    {
        if (! $this->prefixOnly && $this->end - $this->begin + 1 > $this->maximumDecoded) {
            throw new InvalidArgumentException('decoded_article_cap');
        }
    }

    /** @return array<string,string> */
    private function fields(string $line, string $marker): array
    {
        if (! str_starts_with($line, $marker.' ')) {
            throw new InvalidArgumentException('invalid_yenc_framing');
        }
        $fields = [];
        foreach (explode(' ', substr($line, strlen($marker) + 1)) as $field) {
            if (preg_match('/^([a-z0-9]+)=([^ ]+)$/D', $field, $match) !== 1 || isset($fields[$match[1]])) {
                throw new InvalidArgumentException('invalid_yenc_fields');
            }
            $fields[$match[1]] = $match[2];
        }

        return $fields;
    }

    private function integer(string $value, int $maximum): int
    {
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_yenc_number');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
        if ($number === false) {
            throw new InvalidArgumentException('invalid_yenc_number');
        }

        return $number;
    }

    private function article(bool $complete): RecoveryArticle
    {
        return new RecoveryArticle($this->filename, $this->fileSize, $this->part, $this->total, $this->begin, $this->end,
            $this->data, $complete, $this->crcPresent, $this->crcVerified, $this->fullFileCrc32);
    }
}
