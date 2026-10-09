<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

use App\Services\MusicIdentity\CurrentMusicIdentity;
use App\Services\MusicIdentity\CurrentMusicIdentityReader;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The releases whose current music identity (CurrentMusicIdentityReader) accepts an album that
 * shares a MusicBrainz fact stored once for every release of that album (issue #313): a release
 * group's genres, a release's track list, an artist's names. Candidates are the releases with an
 * album decision on the fact's key, read through that key's index; only those whose current
 * decision still accepts that album are kept, so a release withdrawn by a later completed
 * decision is left alone.
 */
final readonly class CurrentAlbumReleases
{
    /** Candidate releases are read in chunks of this many. */
    private const int CHUNK = 500;

    public function __construct(private CurrentMusicIdentityReader $identities = new CurrentMusicIdentityReader) {}

    /** @return list<int> */
    public function acceptingReleaseGroup(string $releaseGroupId, int $exceptReleaseId): array
    {
        $current = $this->currentAlbums(
            DB::table('release_music_identifications as i')->where('i.musicbrainz_release_group_id', $releaseGroupId),
            $exceptReleaseId,
        );

        return $this->releaseIds(array_filter($current, static fn (CurrentMusicIdentity $identity): bool => $identity->musicBrainzReleaseGroupId === $releaseGroupId));
    }

    /** @return list<int> */
    public function namingRelease(string $releaseId, int $exceptReleaseId): array
    {
        $current = $this->currentAlbums(
            DB::table('release_music_identifications as i')->where('i.musicbrainz_release_id', $releaseId),
            $exceptReleaseId,
        );

        return $this->releaseIds(array_filter($current, static fn (CurrentMusicIdentity $identity): bool => $identity->musicBrainzReleaseId === $releaseId));
    }

    /**
     * @param  list<string>  $artistIds
     * @return list<int>
     */
    public function creditingArtists(array $artistIds, int $exceptReleaseId): array
    {
        if ($artistIds === []) {
            return [];
        }
        $links = static fn (): Builder => DB::table('release_music_identification_artists')->whereIn('musicbrainz_artist_id', $artistIds);
        $current = $this->currentAlbums(
            DB::table('release_music_identifications as i')->whereIn('i.id', $links()->select('release_music_identifications_id')),
            $exceptReleaseId,
        );
        if ($current === []) {
            return [];
        }
        $linked = [];
        $decisionIds = array_map(static fn (CurrentMusicIdentity $identity): int => $identity->identificationId, array_values($current));
        foreach (array_chunk($decisionIds, self::CHUNK) as $chunk) {
            foreach ($links()->whereIn('release_music_identifications_id', $chunk)->distinct()->pluck('release_music_identifications_id') as $id) {
                $linked[(int) $id] = true;
            }
        }

        return $this->releaseIds(array_filter($current, static fn (CurrentMusicIdentity $identity): bool => isset($linked[$identity->identificationId])));
    }

    /**
     * The current identity of each release with an album decision among the candidates, when it
     * still accepts an album.
     *
     * @return array<int, CurrentMusicIdentity>
     */
    private function currentAlbums(Builder $decisions, int $exceptReleaseId): array
    {
        $releaseIds = $decisions
            ->whereIn('i.state', IdentificationStatus::albumValues())
            ->where('i.releases_id', '!=', $exceptReleaseId)
            ->distinct()->pluck('i.releases_id')
            ->map(static fn (mixed $id): int => (int) $id)->values()->all();

        $current = [];
        foreach (array_chunk($releaseIds, self::CHUNK) as $chunk) {
            foreach ($this->identities->forReleases($chunk) as $id => $identity) {
                if ($identity->acceptsAlbum()) {
                    $current[$id] = $identity;
                }
            }
        }

        return $current;
    }

    /**
     * @param  array<int, CurrentMusicIdentity>  $current
     * @return list<int>
     */
    private function releaseIds(array $current): array
    {
        $releaseIds = array_keys($current);
        sort($releaseIds);

        return $releaseIds;
    }
}
