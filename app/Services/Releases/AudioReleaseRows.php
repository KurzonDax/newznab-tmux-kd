<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AudioReleaseRow;
use App\Services\AudioProcessing\AudioGenres;
use Illuminate\Support\Facades\DB;

/**
 * Loads a page of Audio release ids into display rows: the shelf row (ShelfReleaseRows) plus the
 * release's tags, read by `release_audio_tags.releases_id` for the whole page in one query
 * (docs/proposals/audio-redesign/DATA-CONTRACT.md 4.5): the album, the album artist or performer,
 * the recorded year, the tag's genre value, the preview's length, and the genre names in
 * `release_audio_genres.position` order joined with ", " in SQL (a name may hold a comma, so the
 * joined text is never split). The shared loader (ReleasePreviewDataLoader) already marks a
 * release with a playable preview, with its type, track title and artist, for the Listen chip.
 */
final class AudioReleaseRows
{
    public function __construct(private readonly ShelfReleaseRows $shelf) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<AudioReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        $rows = $this->shelf->rowArguments($ids, $byAdded);
        $releaseIds = array_map(static fn (array $row): int => (int) $row['release']->id, $rows);
        $tags = $releaseIds === [] ? collect() : DB::table('release_audio_tags as t')->whereIn('t.releases_id', $releaseIds)
            ->select(['t.releases_id', 't.album', 't.album_performer', 't.performer', 't.recorded_year', 't.genre', 't.preview_seconds'])
            ->selectRaw(self::genreNamesSql('t.releases_id').' AS genre_names')->get()->keyBy('releases_id');

        return array_map(static function (array $row) use ($tags): AudioReleaseRow {
            $release = $row['release'];
            $tag = $tags->get((int) $release->id);
            $listen = (bool) ($release->has_audio_preview ?? false) ? [
                'url' => route('preview.audio', $row['facts']['guid']),
                'type' => (string) $release->audio_preview_mime,
                'title' => self::text($release->audio_preview_title ?? null),
                'artist' => self::text($release->audio_preview_artist ?? null),
                'seconds' => $tag?->preview_seconds === null ? null : (int) $tag->preview_seconds,
            ] : null;
            if ($tag === null) {
                return new AudioReleaseRow(...[...$row['facts'], 'listen' => $listen]);
            }
            $genres = (string) $tag->genre_names;

            return new AudioReleaseRow(...[...$row['facts'],
                'artist' => self::text($tag->album_performer) ?? self::text($tag->performer) ?? '',
                'album' => self::text($tag->album) ?? '',
                'year' => $tag->recorded_year === null ? '' : (string) $tag->recorded_year,
                'genres' => $genres,
                'unknownGenre' => $genres === '' && AudioGenres::readsUnknown($tag->genre === null ? null : (string) $tag->genre),
                'listen' => $listen,
            ]);
        }, $rows);
    }

    /**
     * A SQL expression for the release's genre names in `position` order joined with ", ", for
     * the release whose id is in the column; NULL with none.
     */
    private static function genreNamesSql(string $releasesIdColumn): string
    {
        $joined = DB::getDriverName() === 'sqlite'
            ? "GROUP_CONCAT(agn.name, ', ' ORDER BY agl.position)"
            : "GROUP_CONCAT(agn.name ORDER BY agl.position SEPARATOR ', ')";

        return '(SELECT '.$joined.' FROM release_audio_genres agl INNER JOIN audio_genres agn ON agn.id = agl.audio_genres_id'
            .' WHERE agl.releases_id = '.$releasesIdColumn.')';
    }

    /** A tag value as written, or null when it is missing or empty. */
    private static function text(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
