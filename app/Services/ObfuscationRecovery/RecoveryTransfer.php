<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryTransfer
{
    public function __construct(
        public ?RecoveryArticle $article,
        public string $outcome,
        public ?string $reason,
        public int $plaintextReceived,
        public int $plaintextSent,
        public ?int $encryptedReceived,
        public int $connectionsOpened,
        public ?int $receiveWindow,
        public int $bufferedAtClose,
        public bool $closed,
        public int $elapsedMilliseconds,
        public int $decodedBytes = 0,
    ) {}
}
