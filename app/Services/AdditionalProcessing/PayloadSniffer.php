<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\PayloadSniffResult;
use App\Services\AdditionalProcessing\Enums\PayloadClassification;

final readonly class PayloadSniffer
{
    public function classify(string $payload): PayloadSniffResult
    {
        if (str_starts_with($payload, "Rar!\x1A\x07\x00")) {
            return new PayloadSniffResult(PayloadClassification::Rar, $this->isFirstRar4Volume($payload));
        }

        if (str_starts_with($payload, "Rar!\x1A\x07\x01\x00")) {
            return new PayloadSniffResult(PayloadClassification::Rar, $this->isFirstRar5Volume($payload));
        }

        if (str_starts_with($payload, "PK\x03\x04") || str_starts_with($payload, "PK\x05\x06") || str_starts_with($payload, "PK\x07\x08")) {
            return new PayloadSniffResult(PayloadClassification::Zip, true);
        }

        if (str_starts_with($payload, "PAR2\x00PKT")) {
            return new PayloadSniffResult(PayloadClassification::Par2);
        }

        if (str_starts_with($payload, "\x1A\x45\xDF\xA3")) {
            return new PayloadSniffResult(PayloadClassification::Matroska);
        }

        if (strlen($payload) >= 8 && substr($payload, 4, 4) === 'ftyp') {
            return new PayloadSniffResult(PayloadClassification::Mp4);
        }

        if (str_starts_with($payload, 'RIFF') && strlen($payload) >= 12 && substr($payload, 8, 4) === 'AVI ') {
            return new PayloadSniffResult(PayloadClassification::Avi);
        }

        return new PayloadSniffResult(
            $this->isText($payload) ? PayloadClassification::Text : PayloadClassification::Unknown,
        );
    }

    private function isFirstRar4Volume(string $payload): bool
    {
        if (strlen($payload) < 12 || ord($payload[9]) !== 0x73) {
            return false;
        }

        $flags = unpack('vflags', substr($payload, 10, 2));

        return is_array($flags) && (((int) $flags['flags']) & 0x0100) !== 0;
    }

    private function isFirstRar5Volume(string $payload): bool
    {
        if (strlen($payload) < 15) {
            return false;
        }

        $offset = 12;
        if ($this->readVint($payload, $offset) === null || $this->readVint($payload, $offset) !== 1) {
            return false;
        }

        $headerFlags = $this->readVint($payload, $offset);
        if ($headerFlags === null) {
            return false;
        }
        if (($headerFlags & 0x0001) !== 0 && $this->readVint($payload, $offset) === null) {
            return false;
        }
        if (($headerFlags & 0x0002) !== 0 && $this->readVint($payload, $offset) === null) {
            return false;
        }

        $archiveFlags = $this->readVint($payload, $offset);
        if ($archiveFlags === null || ($archiveFlags & 0x0001) === 0) {
            return false;
        }

        if (($archiveFlags & 0x0002) === 0) {
            return true;
        }

        return $this->readVint($payload, $offset) === 0;
    }

    private function readVint(string $payload, int &$offset): ?int
    {
        $value = 0;
        $shift = 0;

        while ($offset < strlen($payload) && $shift <= 63) {
            $byte = ord($payload[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $value;
            }
            $shift += 7;
        }

        return null;
    }

    private function isText(string $payload): bool
    {
        if ($payload === '' || str_contains($payload, "\x00")) {
            return false;
        }

        $sample = substr($payload, 0, 4096);
        $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $sample);

        return $printable !== false && $printable / strlen($sample) >= 0.85;
    }
}
