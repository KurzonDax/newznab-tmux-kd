<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoveryNameEvidence
{
    /**
     * @param  list<string>  $fileIds
     * @param  (\Closure(): bool)|null  $namingPolicy
     */
    public function __construct(public int $publicationId, public string $manifestDigest, public RecoveryNameScope $scope, public array $fileIds, private ?\Closure $namingPolicy = null)
    {
        if ($publicationId < 1 || preg_match('/^[a-f0-9]{64}$/D', $manifestDigest) !== 1 || $fileIds === [] || count($fileIds) > 32) {
            throw new InvalidArgumentException('invalid_recovery_name_evidence');
        }
    }

    public function assertNamingAllowed(): void
    {
        if ($this->namingPolicy !== null && ! ($this->namingPolicy)()) {
            throw new RecoveryNamingDisabled;
        }
    }
}
