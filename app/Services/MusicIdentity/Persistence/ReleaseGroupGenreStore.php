<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

use App\Services\AudioProcessing\AudioGenres;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use Illuminate\Support\Facades\DB;

/**
 * A MusicBrainz release group's genres (issue #313, section A), stored once per group as
 * `musicbrainz_release_group_genres` rows on the `audio_genres` lookup: vote count highest first,
 * then name A to Z, names as MusicBrainz writes them. Written only with an accepted album
 * decision, inside its transaction; no other code writes the table.
 *
 * @phpstan-import-type MusicGenre from CandidateMetadata
 */
final readonly class ReleaseGroupGenreStore
{
    /** `position` is a tinyint unsigned. */
    private const int MAX_GENRES = 256;

    public function __construct(private AudioGenres $audioGenres = new AudioGenres) {}

    /**
     * Replaces the group's rows with the lookup's genres; none removes them. Deletes only when the
     * group holds rows: a DELETE matching nothing locks the index gap and deadlocks concurrent
     * writers (ChildRows).
     *
     * @param  list<MusicGenre>  $genres
     * @return bool whether the group's rows changed
     */
    public function replace(string $releaseGroupId, array $genres): bool
    {
        usort($genres, static fn (array $left, array $right): int => $right['count'] <=> $left['count']
            ?: strcmp(mb_strtolower($left['name']), mb_strtolower($right['name']))
            ?: strcmp($left['name'], $right['name']));
        $ids = array_slice($this->audioGenres->ids(array_column($genres, 'name')), 0, self::MAX_GENRES);

        $stored = self::stored($releaseGroupId);
        if ($ids === $stored) {
            return false;
        }

        $table = DB::table('musicbrainz_release_group_genres');
        if ($stored !== []) {
            $table->clone()->where('musicbrainz_release_group_id', $releaseGroupId)->delete();
        }
        $rows = [];
        foreach ($ids as $position => $audioGenreId) {
            $rows[] = ['musicbrainz_release_group_id' => $releaseGroupId, 'position' => $position, 'audio_genres_id' => $audioGenreId];
        }
        if ($rows !== []) {
            $table->clone()->upsert($rows, ['musicbrainz_release_group_id', 'position'], ['audio_genres_id']);
        }

        return true;
    }

    /**
     * The group's `audio_genres` ids in position order.
     *
     * @return list<int>
     */
    public static function stored(string $releaseGroupId): array
    {
        return DB::table('musicbrainz_release_group_genres')->where('musicbrainz_release_group_id', $releaseGroupId)
            ->orderBy('position')->pluck('audio_genres_id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
}
