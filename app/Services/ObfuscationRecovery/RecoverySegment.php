<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoverySegment
{
    public function __construct(
        public string $fileIdentity,
        public int $ordinal,
        public int $articleNumber,
        public string $messageId,
        public int $advertisedBytes,
    ) {
        if ($fileIdentity === '' || $ordinal < 1 || $ordinal > 500000 || $articleNumber < 1
            || $advertisedBytes < 1 || (new RecoveryIdentity)->messageId($messageId) !== $messageId) {
            throw new InvalidArgumentException('invalid_recovery_segment');
        }
    }

    /** @param array<string,mixed> $record */
    public static function fromRecord(array $record): self
    {
        return new self($record['file'], $record['ordinal'], $record['article_number'], $record['message_id'], $record['advertised_bytes']);
    }
}
