<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\GenericReleaseRow;
use App\Data\ReleaseEntityData;
use App\Data\ReleaseRowData;
use App\Enums\BrowseRoot;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads a page of generic-list release ids into display rows (docs/proposals/generic-release-lists/SPEC.md
 * 5.4 and 5.5): ReleaseRowFacts supplies the facts every release list shows, the shared row data
 * supplies the entity (ReleaseEntityDataLoader), the report counts and the Follow state, and this
 * adds the Category cell (the sub-category titles read for the whole page in one query), the entity
 * line and the video clip. Two further reads are made only for the kinds on the page, each once
 * for the whole page: the Audio rows' tags (the Audio list's music line and the Listen chip's
 * length) and the first-aired date of a show's stored episode that has no number. Nothing is read
 * per row.
 */
final class GenericReleaseRows
{
    public function __construct(private readonly ReleaseRowFacts $facts) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<GenericReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        $releases = $this->facts->load($ids);
        if ($releases === []) {
            return [];
        }
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $titles = DB::table('categories')->whereIn('id', array_values(array_unique(array_map(static fn (object $release): int => (int) $release->categories_id, $releases))))
            ->pluck('title', 'id');
        $tags = $this->audioTags($releases);
        $aired = $this->firstAired($releases);

        return array_map(function (object $release) use ($byAdded, $now, $titles, $tags, $aired): GenericReleaseRow {
            /** @var ReleaseRowData $row */
            $row = $release->row_data;
            $facts = $this->facts->facts($release, $byAdded, $now);
            $categoryId = (int) $release->categories_id;
            $title = (string) ($titles[$categoryId] ?? '');
            $root = BrowseRoot::fromCategoryId($categoryId);
            $tag = $tags->get((int) $release->id);
            // the Listen chip, as the Audio list prints it (AudioReleaseRows): the playable preview the shared loader marks
            $listen = (bool) ($release->has_audio_preview ?? false) ? [
                'url' => route('preview.audio', $facts['guid']),
                'type' => (string) $release->audio_preview_mime,
                'title' => self::text($release->audio_preview_title ?? null),
                'artist' => self::text($release->audio_preview_artist ?? null),
                'seconds' => $tag?->preview_seconds === null ? null : (int) $tag->preview_seconds,
            ] : null;

            return new GenericReleaseRow(...[
                ...$facts,
                'category' => $title,
                'categoryPath' => $root === BrowseRoot::All || $title === '' ? $row->category : $root->label().' > '.$title,
                'categoryId' => $categoryId,
                ...$this->entity($row->entity, $categoryId, $tag, $aired->get((int) ($release->tv_episodes_id ?? 0))),
                'reports' => $row->reports,
                'publicResponses' => $row->public_responses,
                'clip' => ReleaseRowFacts::clip($release, $facts['guid']),
                'listen' => $listen,
            ]);
        }, $releases);
    }

    /**
     * The entity line (SPEC 5.5): a film's "Title · Year" linking to the film page (plain text when the
     * release names no film page) with Follow,
     * a show's "Show · S01E07" (or the stored episode's first-aired date when it has no number,
     * never a tag read from the name) linking to the show page with Follow, a tagged audio
     * release's music line as plain text, a console release's game line as plain text.
     *
     * @return array{entityKind: ?string, entityLine: string, entityUrl: ?string, followRoot: ?string, followId: string, followTitle: string}
     */
    private function entity(?ReleaseEntityData $entity, int $categoryId, ?object $tag, ?string $firstAired): array
    {
        $none = ['entityKind' => null, 'entityLine' => '', 'entityUrl' => null, 'followRoot' => null, 'followId' => '', 'followTitle' => ''];
        if ($tag !== null && Category::rootCategoryFor($categoryId) === Category::MUSIC_ROOT) {
            $line = self::musicLine($tag);
            if ($line !== '') {
                return [...$none, 'entityKind' => 'album', 'entityLine' => $line];
            }
        }
        if ($entity === null) {
            return $none;
        }
        if ($entity->root === 'movies' && $entity->id !== '') {
            // Follow by the matched film's imdbid, as today's rows; the line links to the film page only when the release names its film
            return ['entityKind' => 'film', 'entityLine' => self::joined([$entity->title, (string) $entity->year]), 'entityUrl' => $entity->titleUrl(),
                'followRoot' => 'movies', 'followId' => $entity->id, 'followTitle' => $entity->title];
        }
        if ($entity->root === 'tv' && $entity->id !== '') {
            $url = route('tv.show', ['videosId' => $entity->id]);
            $episodeTag = '';
            if ($entity->season !== null && $entity->episode !== null && ($entity->season > 0 || $entity->episode > 0)) {
                $episodeTag = sprintf('S%02dE%02d', $entity->season, $entity->episode);
                $url .= '/'.$entity->season.($entity->episode > 0 ? '?open='.$entity->episode : '');
            } elseif ($entity->season !== null) {
                $episodeTag = (string) $firstAired;
            }

            return ['entityKind' => 'show', 'entityLine' => self::joined([$entity->title, $episodeTag]), 'entityUrl' => $url,
                'followRoot' => 'tv', 'followId' => $entity->id, 'followTitle' => $entity->title];
        }
        if ($entity->root === 'console') {
            return [...$none, 'entityKind' => 'game', 'entityLine' => self::joined([$entity->title, (string) $entity->year])];
        }

        return $none;
    }

    /**
     * The Audio rows' tags, read once for the page (the Audio list's music line, AudioReleaseRows).
     *
     * @param  list<object>  $releases
     * @return Collection<int|string, \stdClass>
     */
    private function audioTags(array $releases): Collection
    {
        $ids = [];
        foreach ($releases as $release) {
            if (Category::rootCategoryFor((int) $release->categories_id) === Category::MUSIC_ROOT) {
                $ids[] = (int) $release->id;
            }
        }
        if ($ids === []) {
            return collect();
        }

        return DB::table('release_audio_tags')->whereIn('releases_id', $ids)
            ->get(['releases_id', 'album', 'album_performer', 'performer', 'recorded_year', 'preview_seconds'])->keyBy('releases_id');
    }

    /**
     * The first-aired date of each show's stored episode that has no season and episode number,
     * read once for the page; '' when none is stored.
     *
     * @param  list<object>  $releases
     * @return Collection<int, string>
     */
    private function firstAired(array $releases): Collection
    {
        $ids = [];
        foreach ($releases as $release) {
            /** @var ReleaseRowData $row */
            $row = $release->row_data;
            $entity = $row->entity;
            if ($entity !== null && $entity->root === 'tv' && $entity->season === 0 && $entity->episode === 0 && (int) ($release->tv_episodes_id ?? 0) > 0) {
                $ids[] = (int) $release->tv_episodes_id;
            }
        }
        if ($ids === []) {
            return collect();
        }

        return DB::table('tv_episodes')->whereIn('id', $ids)->pluck('firstaired', 'id')
            ->map(static fn (mixed $date): string => $date === null ? '' : substr((string) $date, 0, 10));
    }

    /** The Audio list's music line (AudioReleaseRow::musicLine()): "Artist – Album · Year", the parts the tags lack left out. */
    private static function musicLine(object $tag): string
    {
        $artist = self::text($tag->album_performer) ?? self::text($tag->performer) ?? '';
        $line = implode(' – ', array_filter([$artist, self::text($tag->album) ?? ''], static fn (string $part): bool => $part !== ''));
        $year = self::text($tag->recorded_year);

        return $line === '' || $year === null ? $line : $line.' · '.$year;
    }

    /** A tag value as written, or null when it is missing or empty. */
    private static function text(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }

    /** @param list<string> $parts */
    private static function joined(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
