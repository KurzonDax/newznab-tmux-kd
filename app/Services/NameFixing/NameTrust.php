<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Models\Release;

/**
 * The questions the code asks about how far a release's name is trusted. Each answers its own
 * question; they are kept apart on purpose.
 */
final class NameTrust
{
    /** SQL over a `releases r` row: the release may donate its name to an identical copy. */
    public const string TRUSTED_DONOR_SQL = "(r.predb_id > 0 OR COALESCE(NULLIF(r.anidbid, ''), 0) > 0 OR r.is_trusted_name = 1)";

    /** The release has an identity a bundle of independent videos must not overwrite. */
    public static function hasTrustedIdentity(Release $release): bool
    {
        return (bool) $release->is_trusted_name || (int) $release->predb_id > 0 || (int) $release->movieinfo_id > 0
            || (int) $release->imdbid > 0 || (int) $release->videos_id > 0 || (int) $release->tv_episodes_id > 0;
    }

    /** Name fixing set or trusts the name, so a reconciliation label must not replace it. */
    public static function isRenamedOrTrusted(Release $release): bool
    {
        return $release->is_trusted_name || (int) $release->isrenamed === 1;
    }

    /** The release has a name other than the neutral one recovery gave it. */
    public static function hasRecoveryExistingName(Release $release, string $publicationIdentity): bool
    {
        return (int) $release->isrenamed !== 0 || (bool) $release->is_trusted_name || (int) $release->predb_id > 0
            || $release->searchname !== 'Recovered.'.substr($publicationIdentity, 0, 24);
    }

    /**
     * A name set by audio tags is provisional; an untrusted name is no protection. Any other
     * trusted name protects the release, including one whose source was never recorded, and so
     * does a name an admin typed.
     *
     * @param  array<string, scalar|null>  $release
     */
    public static function isReplaceableByMusicIdentity(array $release): bool
    {
        $source = $release['name_source'] ?? null;
        if ($source === Release::MANUAL_NAME_SOURCE) {
            return false;
        }
        if ($source === ReleaseUpdateService::nameSource(ReleaseUpdateService::AUDIO_TAGS_TYPE)) {
            return true;
        }

        return (int) ($release['is_trusted_name'] ?? 0) === 0;
    }
}
