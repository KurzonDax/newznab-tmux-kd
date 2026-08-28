<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Services\Nzb\NzbService;

/**
 * Outcome of {@see NzbService::replaceNzbContents()}.
 *
 * Follows the {@see NzbCreationResult} shape: a success flag, a failure
 * classification, and a human-readable reason. The missing-NZB mode is
 * deterministic — the release has no stored NZB to replace, so retrying
 * cannot succeed until one is created; the filesystem modes are transient.
 */
final readonly class NzbReplaceResult
{
    public const string FAILURE_NONE = 'none';

    public const string FAILURE_MISSING_NZB = 'missing-nzb';

    public const string FAILURE_TEMP_OPEN = 'temp-open';

    public const string FAILURE_WRITE = 'write';

    public const string FAILURE_RENAME = 'rename';

    private function __construct(
        public bool $success,
        public string $failureType,
        public string $reason,
    ) {}

    public static function success(): self
    {
        return new self(true, self::FAILURE_NONE, '');
    }

    public static function missingNzb(string $reason): self
    {
        return new self(false, self::FAILURE_MISSING_NZB, $reason);
    }

    public static function tempFileOpenFailure(string $reason): self
    {
        return new self(false, self::FAILURE_TEMP_OPEN, $reason);
    }

    public static function writeFailure(string $reason): self
    {
        return new self(false, self::FAILURE_WRITE, $reason);
    }

    public static function renameFailure(string $reason): self
    {
        return new self(false, self::FAILURE_RENAME, $reason);
    }

    public function isDeterministicFailure(): bool
    {
        return $this->failureType === self::FAILURE_MISSING_NZB;
    }

    public function isTransientFailure(): bool
    {
        return ! $this->success && ! $this->isDeterministicFailure();
    }
}
