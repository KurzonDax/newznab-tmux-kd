<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Models\Settings;

final readonly class MusicIdentityConfiguration
{
    /**
     * The algorithm version, the one place it is written: config/music-identity.php reads it, and
     * every fallback goes through algorithmVersion(). Raising it re-resolves every eligible audio
     * release (v2: accepted search text, #308; v3: album genres, track lists and artists, #313;
     * v4: release-name album matching, #1033; v5: album tags as a reading of the album, #1276;
     * v6: a later look at releases without an album, #1277; v7: the decision model's album pick,
     * #1275).
     */
    public const string DEFAULT_ALGORITHM_VERSION = 'music-identity-v7';

    public bool $enabled;

    public int $workerParallelism;

    public function __construct()
    {
        $this->enabled = (int) (Settings::settingValue('music_identity_enabled') ?? 1) !== 0;
        $workers = (int) (Settings::settingValue('music_identity_workers') ?: 1);
        $maximum = max(1, (int) config('music-identity.worker_parallelism_max', 8));
        $this->workerParallelism = min($maximum, max(1, $workers));
    }

    public function active(): bool
    {
        return $this->enabled && trim((string) config('music-identity.musicbrainz.endpoint_url')) !== '';
    }

    /** The configured `music-identity.algorithm_version`. */
    public static function algorithmVersion(): string
    {
        return (string) config('music-identity.algorithm_version', self::DEFAULT_ALGORITHM_VERSION);
    }
}
