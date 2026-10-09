<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

use App\Services\MusicIdentity\DTO\CandidateMetadata;
use Illuminate\Support\Facades\DB;

/**
 * An accepted album's credited artists (issue #313, section D): each MusicBrainz artist's canonical
 * name and its "Artist name" and "Search hint" aliases, stored once per artist, and the decision's
 * links to its album artists in credit order. Written only with an accepted album decision, inside
 * its transaction; no other code writes these tables.
 *
 * Each artist row is the lock for that artist's rows. lock() takes them first in the decision's
 * transaction, in MBID order so concurrent decisions sharing artists wait instead of deadlocking,
 * and before the transaction's first plain read, so the reads write() makes see whatever the
 * previous holder committed (the ChildRows rule: lock the parent, then read).
 *
 * @phpstan-import-type MusicCreditedArtist from CandidateMetadata
 */
final readonly class CreditedArtistStore
{
    /**
     * Locks each artist's row, storing it first when it is new.
     *
     * @param  list<MusicCreditedArtist>  $artists
     * @return array<string, bool> whether each artist was stored before this call, by MBID
     */
    public function lock(array $artists): array
    {
        $byId = self::unique($artists);
        ksort($byId, SORT_STRING);

        $existed = [];
        foreach ($byId as $artistId => $artist) {
            $inserted = DB::table('musicbrainz_artists')->insertOrIgnore(['musicbrainz_artist_id' => $artistId, 'name' => $artist['name']]);
            DB::table('musicbrainz_artists')->where('musicbrainz_artist_id', $artistId)->lockForUpdate()->value('musicbrainz_artist_id');
            $existed[(string) $artistId] = $inserted === 0;
        }

        return $existed;
    }

    /**
     * Refreshes each locked artist's name, replaces its aliases with the response's and writes the
     * decision's link rows in credit order. Deletes only where rows are stored: a DELETE matching
     * nothing locks the index gap and deadlocks concurrent writers (ChildRows).
     *
     * @param  list<MusicCreditedArtist>  $artists
     * @param  array<string, bool>  $existed  lock()'s answer for the same artists
     * @return list<string> the artists stored before whose name or aliases this write changed
     */
    public function write(int $identificationId, array $artists, array $existed): array
    {
        $byId = self::unique($artists);
        $sorted = $byId;
        ksort($sorted, SORT_STRING);

        $changed = [];
        foreach ($sorted as $artistId => $artist) {
            $artistId = (string) $artistId;
            // Compared here, not in SQL: the column's collation would call a change of case equal.
            $renamed = DB::table('musicbrainz_artists')->where('musicbrainz_artist_id', $artistId)->value('name') !== $artist['name'];
            if ($renamed) {
                DB::table('musicbrainz_artists')->where('musicbrainz_artist_id', $artistId)->update(['name' => $artist['name']]);
            }

            $aliases = array_map(static fn (array $alias): array => [$alias['name'], $alias['type']], array_values($artist['aliases']));
            $storedAliases = DB::table('musicbrainz_artist_aliases')->where('musicbrainz_artist_id', $artistId)->orderBy('position')
                ->get(['name', 'type'])->map(static fn (object $row): array => [(string) $row->name, (string) $row->type])->all();
            $realiased = $aliases !== $storedAliases;
            if ($realiased) {
                if ($storedAliases !== []) {
                    DB::table('musicbrainz_artist_aliases')->where('musicbrainz_artist_id', $artistId)->delete();
                }
                $rows = [];
                foreach ($aliases as $position => [$name, $type]) {
                    $rows[] = ['musicbrainz_artist_id' => $artistId, 'position' => $position, 'name' => $name, 'type' => $type];
                }
                DB::table('musicbrainz_artist_aliases')->insert($rows);
            }

            if (($existed[$artistId] ?? false) && ($renamed || $realiased)) {
                $changed[] = $artistId;
            }
        }

        $links = DB::table('release_music_identification_artists')->where('release_music_identifications_id', $identificationId);
        if ($links->clone()->exists()) {
            $links->clone()->delete();
        }
        $rows = [];
        foreach (array_keys($byId) as $position => $artistId) {
            $rows[] = ['release_music_identifications_id' => $identificationId, 'position' => $position, 'musicbrainz_artist_id' => (string) $artistId];
        }
        DB::table('release_music_identification_artists')->insert($rows);

        return $changed;
    }

    /**
     * Each artist once, in credit order.
     *
     * @param  list<MusicCreditedArtist>  $artists
     * @return array<string, MusicCreditedArtist>
     */
    private static function unique(array $artists): array
    {
        $byId = [];
        foreach ($artists as $artist) {
            $byId[$artist['artistId']] ??= $artist;
        }

        return $byId;
    }
}
