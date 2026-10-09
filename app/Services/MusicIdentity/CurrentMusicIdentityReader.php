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
 * an older accepted one. Reads any number of releases in two queries; currentIdentificationSql()
 * states the same rule for SQL reads.
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
        $version = MusicIdentityConfiguration::algorithmVersion();

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

    /**
     * The same rule as one correlated SQL expression, for a SQL read such as the release search
     * projection: the current decision's id for the release in the given column, NULL without a
     * completed decision.
     *
     * @return array{0: string, 1: list<string>} the expression and its bindings
     */
    public static function currentIdentificationSql(string $releaseIdColumn): array
    {
        $states = self::completedStates();
        $placeholders = implode(', ', array_fill(0, count($states), '?'));
        $sql = '(COALESCE('
            ."(SELECT target.id FROM release_music_identifications target WHERE target.releases_id = {$releaseIdColumn}"
            ." AND target.algorithm_version = ? AND target.state IN ({$placeholders})"
            ." AND target.evidence_hash = (SELECT newest.evidence_hash FROM release_audio_evidence newest WHERE newest.releases_id = {$releaseIdColumn} ORDER BY newest.revision DESC LIMIT 1)), "
            ."(SELECT MAX(fallback.id) FROM release_music_identifications fallback WHERE fallback.releases_id = {$releaseIdColumn} AND fallback.state IN ({$placeholders}))"
            .'))';

        return [$sql, [MusicIdentityConfiguration::algorithmVersion(), ...$states, ...$states]];
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
