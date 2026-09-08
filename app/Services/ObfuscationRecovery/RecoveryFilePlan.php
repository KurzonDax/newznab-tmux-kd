<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoveryFilePlan
{
    public const int CHUNK_BYTES = 716800;

    public function __construct(
        public string $identity,
        public RecoveryFileRole $role,
        public int $decodedBytes,
        public int $totalParts,
        public string $displayName,
        public string $format,
    ) {
        if ($decodedBytes < 1 || $totalParts < 1 || $totalParts > 100000
            || ($role !== RecoveryFileRole::Index && $totalParts !== intdiv($decodedBytes - 1, self::CHUNK_BYTES) + 1)
            || $displayName === '' || strlen($displayName) > 240
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $displayName) === 1) {
            throw new InvalidArgumentException('invalid_file_plan');
        }
        if (str_contains($displayName, '"') || ! in_array($format, match ($role) {
            RecoveryFileRole::Media => ['mkv', 'mp4'], RecoveryFileRole::RarVolume => ['rar4'], RecoveryFileRole::Index => ['par2'],
        }, true) || strtolower(pathinfo($displayName, PATHINFO_EXTENSION)) !== ($format === 'rar4' ? 'rar' : $format)) {
            throw new InvalidArgumentException('invalid_file_plan_format');
        }
        if ($role === RecoveryFileRole::Index) {
            (new RecoveryIdentity)->messageId($identity);
            if ($totalParts !== 1 || $format !== 'par2' || $decodedBytes > 1048576) {
                throw new InvalidArgumentException('invalid_index_plan');
            }
        } elseif (preg_match('/^[a-f0-9]{32}$/D', $identity) !== 1) {
            throw new InvalidArgumentException('invalid_file_id');
        }
    }

    /** @return array{identity: string, role: string, decoded_bytes: int, total_parts: int, display_name: string, format: string} */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity, 'role' => $this->role->value,
            'decoded_bytes' => $this->decodedBytes, 'total_parts' => $this->totalParts,
            'display_name' => $this->displayName, 'format' => $this->format,
        ];
    }
}
