<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Support\Facades\DB;

/**
 * The one rule for a release's current music identity, shared by album covers and the details
 * pages' MusicBrainz link (issue #1015): the completed decision for the newest evidence revision's
 * hash under the configured algorithm version; when that target has no row or only an unfinished
 * attempt (pending, retryable error), the release's newest completed decision by id. The decision
 * is chosen before asking whether it accepts an album, so a completed non-album decision withdraws
 * an older accepted one. Reads any number of releases in two queries.
 */
final class CurrentMusicIdentityReader
{
    /**
     * @param  list<int>  $releaseIds
     * @return array<int, CurrentMusicIdentity> keyed by release id; a release with no completed decision is absent
     */
    public function forReleases(array $releaseIds): array
    {
        if ($releaseIds === []) {
            return [];
        }

        $newestHashes = DB::table('release_audio_evidence as e')->whereIn('e.releases_id', $releaseIds)
            ->whereRaw('e.revision = (SELECT MAX(e2.revision) FROM release_audio_evidence e2 WHERE e2.releases_id = e.releases_id)')
            ->pluck('e.evidence_hash', 'e.releases_id');
        $completed = DB::table('release_music_identifications')->whereIn('releases_id', $releaseIds)
            ->whereIn('state', self::completedStates())->orderByDesc('id')
            ->get(['id', 'releases_id', 'evidence_hash', 'algorithm_version', 'state', 'musicbrainz_release_id', 'musicbrainz_release_group_id']);
        $version = (string) config('music-identity.algorithm_version', 'music-identity-v1');

        $current = [];
        foreach ($completed as $row) {
            $releaseId = (int) $row->releases_id;
            $isTarget = (string) $row->algorithm_version === $version && (string) $row->evidence_hash === (string) ($newestHashes[$releaseId] ?? '');
            // Rows arrive newest first: the first one is the fallback, a completed target replaces it.
            if (isset($current[$releaseId]) && ! $isTarget) {
                continue;
            }
            $current[$releaseId] = CurrentMusicIdentity::fromRow($row);
        }

        return $current;
    }

    public function forRelease(int $releaseId): ?CurrentMusicIdentity
    {
        return $this->forReleases([$releaseId])[$releaseId] ?? null;
    }

    /** @return list<string> */
    private static function completedStates(): array
    {
        return array_values(array_map(
            static fn (IdentificationStatus $status): string => $status->value,
            array_filter(IdentificationStatus::cases(), static fn (IdentificationStatus $status): bool => $status->isTerminal()),
        ));
    }
}
