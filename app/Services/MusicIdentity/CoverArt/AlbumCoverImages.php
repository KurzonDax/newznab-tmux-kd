<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

use App\Services\MusicIdentity\CurrentMusicIdentity;
use Illuminate\Support\Facades\DB;

/**
 * The stored album cover of each release's current accepted album (issue #1015): the file of its
 * lookup's stored image under the covers root's `audio/` folder, read for a whole page in one
 * query. A release without an accepted album, a stored lookup or the file keeps its placeholder.
 */
final class AlbumCoverImages
{
    /**
     * @param  array<int, CurrentMusicIdentity>  $identities  keyed by release id (CurrentMusicIdentityReader)
     * @return array<int, string> the cover URL by release id; a release with none is absent
     */
    public function urlsFor(array $identities): array
    {
        $lookups = [];
        foreach ($identities as $releaseId => $identity) {
            $lookup = $identity->coverLookup();
            if ($lookup !== null) {
                $lookups[$releaseId] = $lookup;
            }
        }
        if ($lookups === []) {
            return [];
        }

        $images = DB::table('music_cover_art_lookups')
            ->whereIn('musicbrainz_id', array_values(array_unique(array_map(static fn (array $lookup): string => $lookup[1], $lookups))))
            ->where('outcome', CoverArtOutcome::Stored->value)->whereNotNull('image_musicbrainz_id')
            ->get(['kind', 'musicbrainz_id', 'image_musicbrainz_id'])
            ->mapWithKeys(static fn (object $row): array => [$row->kind.':'.$row->musicbrainz_id => (string) $row->image_musicbrainz_id]);

        $urls = [];
        foreach ($lookups as $releaseId => [$kind, $musicBrainzId]) {
            $image = $images[$kind->value.':'.$musicBrainzId] ?? null;
            $url = $image === null ? null : getImageAssetUrl('audio', $image);
            if ($url !== null) {
                $urls[$releaseId] = $url;
            }
        }

        return $urls;
    }
}
