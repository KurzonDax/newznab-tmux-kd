<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoveryStorageBatch
{
    /** @param list<RecoverySegment> $segments */
    public function __construct(
        public RecoveryPlan $plan,
        public int $groupId,
        public string $poster,
        public int $postTimestamp,
        public array $segments,
        public int $offset = 0,
    ) {
        if ($offset < 0 || $offset + count($segments) > $plan->plannedParts() || $groupId < 1 || strlen($poster) > 255 || ! mb_check_encoding($poster, 'UTF-8')
            || $postTimestamp < 1 || count($segments) < 1 || count($segments) > 500) {
            throw new InvalidArgumentException('invalid_recovery_batch');
        }
        $files = array_column($plan->files, null, 'identity');
        $seen = [];
        foreach ($segments as $segment) {
            $file = $files[$segment->fileIdentity] ?? null;
            $key = $segment->fileIdentity.':'.$segment->ordinal;
            if ($file === null || $segment->ordinal > $file->totalParts || isset($seen[$key])
                || ($file->role === RecoveryFileRole::Index && $segment->messageId !== $file->identity)) {
                throw new InvalidArgumentException('invalid_recovery_batch_membership');
            }
            $seen[$key] = true;
        }
    }
}
