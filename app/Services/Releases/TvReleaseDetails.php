<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvReleaseRow;
use App\Models\Release;
use App\Support\ReleaseCompletion;
use Illuminate\Support\Facades\DB;

/**
 * The TV release details page (docs/proposals/tv-redesign/SPEC.md 3.4): the release as a TV row,
 * its heading, "About the show", the facts grid and "All N releases of this episode". A release
 * with no matched show (videos_id = 0) gets none of the show's parts.
 */
final class TvReleaseDetails
{
    public function __construct(
        private readonly TvReleaseRows $rows,
        private readonly TvShowPage $shows,
    ) {}

    /**
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, string $category, array $exclusions): array
    {
        $id = (int) $release->id;
        $row = $this->rows->load([$id], false)[0] ?? null;
        abort_if($row === null, 404);
        $videosId = (int) $release->videos_id;
        $show = $videosId > 0 ? $this->shows->header($videosId, $exclusions) : null;
        $declared = $this->firstDeclaration($id);
        $episode = $show === null || $declared === null ? null : $this->episode($videosId, $declared['season'], $declared['episode']);
        $siblings = $show === null || $declared === null ? [] : $this->rows->load($this->shows->siblingReleaseIds($videosId, $id, $exclusions), false);

        return [
            'row' => $row,
            'show' => $show,
            'headingSuffix' => $show === null ? '' : $this->headingSuffix($declared, $episode['title'] ?? ''),
            'aired' => $episode['aired'] ?? '',
            'about' => $show?->about(count($this->shows->seasons($videosId, $exclusions))) ?? '',
            'showTags' => $show === null ? [] : [...$show->tags, ...($show->year === null ? [] : ['Premiered '.$show->year])],
            'showLink' => $show === null ? null : ['url' => $row->showUrl, 'label' => $declared === null ? 'All releases of this show' : 'All seasons and episodes'],
            'siblings' => $siblings,
            'siblingKind' => $declared !== null && $declared['episode'] === null ? 'season pack' : 'episode',
            'facts' => $this->facts($release, $row, $category),
        ];
    }

    /**
     * The first (season, episode) the release declares, in the order the TV rows use.
     *
     * @return array{season: int, episode: ?int}|null
     */
    private function firstDeclaration(int $releaseId): ?array
    {
        $row = DB::table('release_tv_episodes')->where('releases_id', $releaseId)
            ->orderBy('season')->orderByRaw('episode IS NOT NULL')->orderBy('episode')->first(['season', 'episode']);

        return $row === null ? null : ['season' => (int) $row->season, 'episode' => $row->episode === null ? null : (int) $row->episode];
    }

    /** @return array{title: string, aired: string} */
    private function episode(int $videosId, int $season, ?int $episode): array
    {
        if ($episode === null) {
            return ['title' => '', 'aired' => ''];
        }
        $known = DB::table('tv_episodes')->where('videos_id', $videosId)->where('series', $season)->where('episode', $episode)
            ->orderBy('id')->first(['title', 'firstaired']);
        $aired = (string) ($known->firstaired ?? '');

        return [
            'title' => trim((string) ($known->title ?? '')),
            'aired' => $aired === '' || str_starts_with($aired, '0000') ? '' : substr($aired, 0, 10),
        ];
    }

    /**
     * What follows the show's name (a link) in the heading: ` · S01E02 — Episode title`,
     * ` · Season 3 pack`, or nothing.
     *
     * @param  array{season: int, episode: ?int}|null  $declared
     */
    private function headingSuffix(?array $declared, string $title): string
    {
        if ($declared === null) {
            return '';
        }
        $label = $declared['episode'] === null ? 'Season '.$declared['season'].' pack' : sprintf('S%02dE%02d', $declared['season'], $declared['episode']);

        return ' · '.$label.($title === '' ? '' : ' — '.$title);
    }

    /** @return list<array{string, string}> */
    private function facts(Release $release, TvReleaseRow $row, string $category): array
    {
        $when = static fn (mixed $date): string => $date === null || $date === '' ? '—' : userDate((string) $date, 'M j, Y, g:i A');
        $status = (int) $release->passwordstatus;

        return [
            ['Category', $category],
            ['Size', $row->size],
            ['Files', (string) $row->files],
            ['Completion', ReleaseCompletion::isMeasured($release->completion) ? ReleaseCompletion::percent($release->completion).'%' : 'Not measured'],
            ['Posted', $when($release->postdate)],
            ['Added', $when($release->adddate)],
            ['Grabs', (string) $row->grabs],
            ['Group', $row->group === '' ? '—' : $row->group],
            ['Poster', $row->uploader === '' ? '—' : $row->uploader],
            ['Password status', $status < 0 ? 'Not checked' : ($row->passworded ? 'Detected' : 'None detected')],
        ];
    }
}
