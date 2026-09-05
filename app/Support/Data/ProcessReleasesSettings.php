<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Models\Settings;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Configuration settings for ProcessReleases operations.
 *
 * Hydrate from raw {@see Settings} rows via {@see self::forDatabase()}
 * (renamed from `fromDatabase` to avoid spatie/laravel-data's magical-creation
 * recursion on `self::from()`).
 */
#[TypeScript]
final class ProcessReleasesSettings extends Data
{
    /**
     * Batch size the release-creation loop falls back to when the stored
     * `maxnzbsprocessed` says nothing usable.
     */
    private const DEFAULT_RELEASE_CREATION_LIMIT = 1000;

    public function __construct(
        public int $collectionDelayTime = 2,
        public int $crossPostTime = 2,
        public int $releaseCreationLimit = self::DEFAULT_RELEASE_CREATION_LIMIT,
        public int $completion = 0,
        public int $collectionTimeout = 48,
        public int $maxSizeToFormRelease = 0,
        public int $minSizeToFormRelease = 0,
        public int $minFilesToFormRelease = 0,
        public int $releaseRetentionDays = 0,
        public bool $deletePasswordedRelease = false,
        public int $miscOtherRetentionHours = 0,
        public int $miscHashedRetentionHours = 0,
    ) {
        // Clamp completion to a sane upper bound (legacy `min(100, …)`).
        if ($this->completion > 100) {
            $this->completion = 100;
        }

        // The creation loop iterates while an iteration fills its batch, so a
        // non-positive limit makes `0 >= 0` true forever against a drained queue.
        // Resolve to the coded default rather than 1: a one-release-per-iteration
        // crawl is no more the operator's intent than an endless spin.
        if ($this->releaseCreationLimit < 1) {
            $this->releaseCreationLimit = self::DEFAULT_RELEASE_CREATION_LIMIT;
        }
    }

    /**
     * Build settings from a raw Settings table array (mixed snake-case keys,
     * stringly-typed values, possible nulls/empty strings).
     *
     * @param  array<string, mixed>  $dbSettings
     */
    public static function forDatabase(array $dbSettings): self
    {
        $getInt = static fn (string $key, int $default): int => (isset($dbSettings[$key]) && $dbSettings[$key] !== '')
                ? (int) $dbSettings[$key]
                : $default;

        return new self(
            collectionDelayTime: $getInt('delaytime', 2),
            crossPostTime: $getInt('crossposttime', 2),
            releaseCreationLimit: $getInt('maxnzbsprocessed', self::DEFAULT_RELEASE_CREATION_LIMIT),
            completion: $getInt('completionpercent', 0),
            collectionTimeout: $getInt('collection_timeout', 48),
            maxSizeToFormRelease: $getInt('maxsizetoformrelease', 0),
            minSizeToFormRelease: $getInt('minsizetoformrelease', 0),
            minFilesToFormRelease: $getInt('minfilestoformrelease', 0),
            releaseRetentionDays: $getInt('releaseretentiondays', 0),
            deletePasswordedRelease: ((int) ($dbSettings['deletepasswordedrelease'] ?? 0)) === 1,
            miscOtherRetentionHours: $getInt('miscotherretentionhours', 0),
            miscHashedRetentionHours: $getInt('mischashedretentionhours', 0),
        );
    }

    public function hasValidCompletion(): bool
    {
        return $this->completion >= 0 && $this->completion <= 100;
    }

    public function hasRetentionCleanup(): bool
    {
        return $this->releaseRetentionDays > 0;
    }

    public function hasCrossPostDetection(): bool
    {
        return $this->crossPostTime > 0;
    }

    public function hasCompletionCleanup(): bool
    {
        return $this->completion > 0;
    }
}
