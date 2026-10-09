<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

use App\Services\MusicIdentity\DTO\AcceptedMusicText;
use Illuminate\Support\Facades\DB;

/**
 * A MusicBrainz release's track list (issue #313, section B), stored once per release as
 * `musicbrainz_release_tracks` rows: the only store of accepted track data, read by the details
 * page and the release search through the decision's musicbrainz_release_id. Written only with an
 * accepted album decision, inside its transaction; no other code writes the table.
 *
 * @phpstan-import-type AcceptedTrack from AcceptedMusicText
 */
final readonly class ReleaseTrackListStore
{
    /**
     * Replaces the release's stored list with the response's: rows no longer listed are removed
     * (only when some are stored, since a DELETE matching nothing locks the index gap and deadlocks
     * concurrent writers, ChildRows), the rest are upserted.
     *
     * @param  list<AcceptedTrack>  $tracks
     * @return bool whether the stored list changed
     */
    public function replace(string $releaseId, array $tracks): bool
    {
        $rows = [];
        foreach ($tracks as $track) {
            // A position listed twice keeps its last entry, as the upsert would.
            $rows[$track['mediumPosition'].':'.$track['trackPosition']] = [
                'musicbrainz_release_id' => $releaseId,
                'medium_position' => $track['mediumPosition'],
                'track_position' => $track['trackPosition'],
                'title' => $track['title'],
                'length_ms' => $track['lengthMs'],
                'artist_credit' => $track['artistCredit'],
            ];
        }
        ksort($rows, SORT_NATURAL);

        $stored = [];
        foreach (self::stored($releaseId) as $row) {
            $stored[$row['medium_position'].':'.$row['track_position']] = $row;
        }
        ksort($stored, SORT_NATURAL);
        if ($stored === $rows) {
            return false;
        }

        $table = DB::table('musicbrainz_release_tracks');
        $removed = array_diff_key($stored, $rows);
        if ($removed !== []) {
            $table->clone()->where('musicbrainz_release_id', $releaseId)->where(static function ($query) use ($removed): void {
                foreach ($removed as $row) {
                    $query->orWhere(static fn ($key) => $key->where('medium_position', $row['medium_position'])->where('track_position', $row['track_position']));
                }
            })->delete();
        }
        if ($rows !== []) {
            $table->clone()->upsert(
                array_values($rows),
                ['musicbrainz_release_id', 'medium_position', 'track_position'],
                ['title', 'length_ms', 'artist_credit'],
            );
        }

        return true;
    }

    /**
     * The release's stored tracks in medium and track order.
     *
     * @return list<array{musicbrainz_release_id: string, medium_position: int, track_position: int, title: string, length_ms: int|null, artist_credit: string|null}>
     */
    public static function stored(string $releaseId): array
    {
        return DB::table('musicbrainz_release_tracks')->where('musicbrainz_release_id', $releaseId)
            ->orderBy('medium_position')->orderBy('track_position')
            ->get(['musicbrainz_release_id', 'medium_position', 'track_position', 'title', 'length_ms', 'artist_credit'])
            ->map(static fn (object $row): array => [
                'musicbrainz_release_id' => (string) $row->musicbrainz_release_id,
                'medium_position' => (int) $row->medium_position,
                'track_position' => (int) $row->track_position,
                'title' => (string) $row->title,
                'length_ms' => $row->length_ms === null ? null : (int) $row->length_ms,
                'artist_credit' => $row->artist_credit === null ? null : (string) $row->artist_credit,
            ])->values()->all();
    }
}
