<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AudioPreview;
use App\Data\AudioReleaseMusic;
use App\Data\AudioReleaseRow;
use App\Data\AudioTrack;
use App\Data\ConsoleGamePageFilters;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseVideoClip;
use App\Services\AudioProcessing\AudioGenres;
use App\Services\MusicIdentity\CurrentMusicIdentityReader;
use Illuminate\Support\Facades\DB;

/**
 * The details page of an Audio release (docs/proposals/audio-redesign/SPEC.md 5A, 5B and 5C;
 * DATA-CONTRACT.md 4.5), as ConsoleGameReleaseDetails is for a game: the release as an Audio list
 * row, its sub-category for the breadcrumb and the music line, its tag row and genres, the facts
 * grid (Genre after Category on the release-only page; the album page's genres are its tags), the
 * PreDB block, the complete track list of its newest audio evidence, the preview it plays in the
 * page with the spectrogram, a video clip, the release group of the current accepted MusicBrainz
 * album (CurrentMusicIdentityReader, the rule album covers share), "All N releases
 * of this album" (AudioAlbumPage) on the album page and Similar releases without the album's own
 * releases. Each read is one query; the preview reads the tag row the controller already loaded.
 */
final class AudioReleaseDetails
{
    /** MediaInfo's name for MP3, which the pages show as "MP3" (DATA-NOTES.md). */
    private const string MPEG_AUDIO = 'MPEG Audio';

    public function __construct(
        private readonly AudioReleaseRows $rows,
        private readonly AudioAlbumPage $albums,
        private readonly ReleaseSearchService $search,
        private readonly CurrentMusicIdentityReader $identities,
    ) {}

    /**
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, string $category, array $exclusions, ConsoleGamePageFilters $table, bool $pageNamed): array
    {
        $row = $this->row($release);
        $music = $this->music((int) $release->id);
        $album = $music !== null && $music->hasAlbum();
        // The breadcrumb and the music line name the sub-category alone ("MP3"); the facts read the root by its label ("Audio > MP3").
        $subCategory = (string) (Category::query()->whereKey((int) $release->categories_id)->value('title') ?? '');
        $category = $subCategory === '' ? $category : BrowseRoot::Audio->label().' > '.$subCategory;
        $facts = ReleaseDetailsFacts::grid($release, $row, $category);
        if (! $album) {
            // The release-only page lists its Genre right after Category (the prototype's facts); the album page's genres are its tags.
            array_splice($facts, 1, 0, [['Genre', $music?->genreFact() ?? '—']]);
        }
        $evidence = $this->newestEvidence((int) $release->id);
        $albumIds = $music !== null && $album ? $this->albums->ids($music->album, self::artistKey($music), $exclusions) : [];

        return [
            'row' => $row,
            'music' => $music,
            'album' => $album,
            'category' => $category,
            'subCategory' => $subCategory,
            'facts' => $facts,
            'predb' => ReleaseDetailsFacts::predb((int) $release->predb_id),
            'tracks' => $evidence === null ? [] : $this->tracks($evidence),
            'preview' => $this->preview($release, $music),
            'clip' => $this->clip($release),
            'musicBrainzUrl' => $this->identities->forRelease((int) $release->id)?->releaseGroupUrl() ?? '',
            'similar' => $this->similar($release, $albumIds, $exclusions),
            ...($album ? $this->table($row, $music, $exclusions, $table, $pageNamed) : []),
        ];
    }

    /**
     * "All N releases of this album" alone: the fragment a sort change or another page loads. A
     * release with no album has no table.
     *
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function releasesTable(Release $release, array $exclusions, ConsoleGamePageFilters $table, bool $pageNamed): array
    {
        $row = $this->row($release);
        $music = $this->music((int) $release->id);

        return ['row' => $row, ...$this->table($row, $music, $exclusions, $table, $pageNamed)];
    }

    private function row(Release $release): AudioReleaseRow
    {
        $row = $this->rows->load([(int) $release->id], false)[0] ?? null;
        abort_if($row === null, 404);

        return $row;
    }

    /**
     * Read 1: the release's tag row with its genres in `position` order, in one query; null
     * without a tag row.
     */
    private function music(int $releaseId): ?AudioReleaseMusic
    {
        $rows = DB::table('release_audio_tags as t')
            ->leftJoin('release_audio_genres as rag', 'rag.releases_id', '=', 't.releases_id')
            ->leftJoin('audio_genres as g', 'g.id', '=', 'rag.audio_genres_id')
            ->where('t.releases_id', $releaseId)->orderBy('rag.position')
            ->get(['t.album', 't.album_performer', 't.performer', 't.recorded_year', 't.genre', 't.audio_format', 't.track_name', 'g.id as genre_id', 'g.name as genre_name']);
        $tag = $rows->first();
        if ($tag === null) {
            return null;
        }
        $genres = [];
        foreach ($rows as $genre) {
            if ($genre->genre_id !== null) {
                $genres[(int) $genre->genre_id] = (string) $genre->genre_name;
            }
        }
        $albumArtist = self::text($tag->album_performer);
        $performer = self::text($tag->performer);
        $format = self::text($tag->audio_format) ?? '';

        return new AudioReleaseMusic(
            album: self::text($tag->album) ?? '',
            artist: $albumArtist ?? $performer ?? '',
            performedBy: $albumArtist !== null && $performer !== null && $albumArtist !== $performer ? $performer : '',
            year: $tag->recorded_year === null ? '' : (string) $tag->recorded_year,
            genres: $genres,
            unknownGenre: $genres === [] && AudioGenres::readsUnknown($tag->genre === null ? null : (string) $tag->genre),
            format: $format === self::MPEG_AUDIO ? 'MP3' : $format,
            trackTitle: self::text($tag->track_name) ?? '',
        );
    }

    /** The newest audio evidence revision of the release (the highest `revision`), or null with none. */
    private function newestEvidence(int $releaseId): ?object
    {
        return DB::table('release_audio_evidence')->where('releases_id', $releaseId)->orderByDesc('revision')
            ->first(['id', 'evidence_hash', 'archive_manifest_complete']);
    }

    /**
     * Read 2: the revision's complete track list, one source: the archive listing when the revision
     * marks it complete, else the NZB's audio files; nothing otherwise. A partial archive listing,
     * the release's own files and the sampled file are never shown (SPEC 5C.2).
     *
     * @return list<AudioTrack>
     */
    private function tracks(object $evidence): array
    {
        $archive = (bool) $evidence->archive_manifest_complete;
        $rows = DB::table('release_audio_evidence_tracks')->where('release_audio_evidence_id', (int) $evidence->id)
            ->whereIn('source_kind', $archive ? ['archive', 'nzb'] : ['nzb'])
            // 'archive' sorts before 'nzb': a complete archive listing comes first, and each source in its own order.
            ->orderBy('source_kind')->orderBy('source_ordinal')
            ->get(['source_kind', 'track_number', 'disc_number', 'title', 'raw_filename', 'whole_duration_seconds']);
        if ($archive && $rows->contains('source_kind', 'archive')) {
            $rows = $rows->where('source_kind', 'archive');
        }
        $tracks = [];
        foreach ($rows->values() as $place => $track) {
            $number = $track->track_number === null ? $place + 1 : (int) $track->track_number;
            $tracks[] = new AudioTrack(
                number: $number,
                title: self::trackTitle(self::text($track->title) ?? self::fileTitle((string) $track->raw_filename), $number),
                seconds: $track->whole_duration_seconds === null ? null : (int) round((float) $track->whole_duration_seconds),
                disc: $track->disc_number === null ? null : (int) $track->disc_number,
            );
        }

        return $tracks;
    }

    /** A file name without its folders (either slash) and its extension. */
    private static function fileTitle(string $path): string
    {
        $name = (string) preg_replace('#^.*[/\\\\]#', '', $path);
        $dot = strrpos($name, '.');

        return $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
    }

    /**
     * The title without a leading track number the # column already shows (leading zeros allowed)
     * and the spaces, `-`, `.` or `_` after it: "104 - Catapult" with number 104 → "Catapult". No
     * other cleaning.
     */
    private static function trackTitle(string $title, int $number): string
    {
        $stripped = (string) preg_replace('/^0*'.$number.'[ ._-]+/', '', $title);

        return $stripped === '' ? $title : $stripped;
    }

    /** The preview the Overview plays, with the spectrogram under the player; null without a playable preview. */
    private function preview(Release $release, ?AudioReleaseMusic $music): ?AudioPreview
    {
        $tag = $release->audioTags;
        if ($tag === null || $tag->playablePreviewMimeType() === null) {
            return null;
        }
        $guid = (string) $release->guid;

        return new AudioPreview(
            url: route('preview.audio', $guid),
            seconds: $tag->preview_seconds === null ? null : (int) $tag->preview_seconds,
            trackTitle: $music->trackTitle ?? '',
            spectrogramUrl: $tag->has_spectrogram ? getImageAssetUrl('audiosample', $guid.'_spectrum', null, [], ['png']) : null,
        );
    }

    /**
     * A music video release's clip, as AdultReleaseRows builds it: the Preview chip opens it in the
     * image dialog's player with its poster.
     *
     * @return array{url: string, type: string, poster: ?string}|null
     */
    private function clip(Release $release): ?array
    {
        return (bool) ($release->has_video_preview ?? false) ? [
            'url' => route('preview.video', (string) $release->guid),
            'type' => (string) ($release->video_preview_mime ?? ReleaseVideoClip::VIDEO_MIME_TYPES['ogv']),
            'poster' => ReleaseRowFacts::clipPoster((string) $release->guid),
        ] : null;
    }

    /**
     * The table opens on the page holding this release unless the URL names a page; a release the
     * viewer may not see (a hidden password status) opens on page 1.
     *
     * @param  list<int>  $exclusions
     * @return array{table: ConsoleGamePageFilters|null, tableRows: list<AudioReleaseRow>, tableTotal: int, tableLastPage: int}
     */
    private function table(AudioReleaseRow $row, ?AudioReleaseMusic $music, array $exclusions, ConsoleGamePageFilters $table, bool $pageNamed): array
    {
        $artist = $music === null ? null : self::artistKey($music);
        $total = $music !== null && $music->hasAlbum() ? $this->albums->count($music->album, $artist, $exclusions) : 0;
        if ($music === null || $total === 0) {
            return ['table' => null, 'tableRows' => [], 'tableTotal' => 0, 'tableLastPage' => 1];
        }
        $lastPage = max(1, (int) ceil($total / ConsoleGamePageFilters::PER_PAGE));
        $page = $pageNamed ? $table->page : ($this->albums->pageHolding($music->album, $artist, $row->id, $table, $exclusions) ?? 1);
        $table = new ConsoleGamePageFilters(sort: $table->sort, ascending: $table->ascending, page: min($page, $lastPage));

        return [
            'table' => $table,
            'tableRows' => $this->rows->load($this->albums->pageIds($music->album, $artist, $table, $exclusions), false),
            'tableTotal' => $total,
            'tableLastPage' => $lastPage,
        ];
    }

    /** The album's artist key: the album artist, else the performer; null when the tags name neither. */
    private static function artistKey(AudioReleaseMusic $music): ?string
    {
        return $music->artist === '' ? null : $music->artist;
    }

    /**
     * Similar releases: today's search (ReleaseSearchService::searchSimilar()) without this release
     * and, on the album page, without the album's releases (the table above lists them), newest
     * posted first as the table's Posted heading says (ties: the newer id first).
     *
     * @param  list<int>  $albumIds
     * @param  list<int>  $exclusions
     * @return list<AudioReleaseRow>
     */
    private function similar(Release $release, array $albumIds, array $exclusions): array
    {
        $found = $this->search->searchSimilar((int) $release->id, (string) $release->searchname, $exclusions);
        if (! is_array($found)) {
            return [];
        }
        $leftOut = array_flip([(int) $release->id, ...$albumIds]);
        $ids = array_values(array_filter(array_map(static fn (mixed $match): int => (int) $match['id'], array_values($found)), static fn (int $id): bool => ! isset($leftOut[$id])));
        if ($ids === []) {
            return [];
        }
        $rows = $this->rows->load($ids, false);
        usort($rows, static fn (AudioReleaseRow $a, AudioReleaseRow $b): int => [$b->postedAt, $b->id] <=> [$a->postedAt, $a->id]);

        return $rows;
    }

    /** A tag value as written, or null when it is missing or empty. */
    private static function text(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
