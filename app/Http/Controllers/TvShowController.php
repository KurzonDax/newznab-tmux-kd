<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\TvReleaseFilters;
use App\Services\Releases\TvReleaseRows;
use App\Services\Releases\TvShowPage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The show page: GET /tv/show/{videos_id}/{season?} (docs/proposals/tv-redesign/SPEC.md section 3.3). */
final class TvShowController extends BasePageController
{
    /** Where the back link leads, kept while the user moves between this show's seasons. */
    private const BACK_KEY = 'tv_show_back';

    public function show(Request $request, TvShowPage $page, TvReleaseRows $rows, string $videosId, ?string $season = null): View
    {
        $id = (int) $videosId;
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
        $header = $page->header($id, $exclusions);
        abort_if($header === null, 404);
        $filters = TvReleaseFilters::fromRequest($request, [], null);
        $seasons = $page->seasons($id, $exclusions);
        $current = $season !== null && in_array((int) $season, $seasons, true) ? (int) $season : $page->newestSeason($id, $exclusions);
        $fragment = $request->query('_fragment');
        $load = static fn (array $ids): array => $rows->load($ids, false);

        if ($fragment === 'episode') {
            $episode = $request->query('episode');
            abort_unless($current !== null && is_string($episode) && ctype_digit($episode), 404);

            return view('tv.show.releases', ['rows' => $load($page->episodeReleaseIds($id, $current, (int) $episode, $filters, $exclusions)), 'pick' => true, 'parts' => false]);
        }

        $episodes = $current === null ? [] : $page->episodes($id, $current, $filters, $exclusions);
        $open = [];
        foreach (array_intersect(self::opened($request), array_map(static fn ($episode): int => $episode->number, $episodes)) as $number) {
            $open[$number] = $load($page->episodeReleaseIds($id, (int) $current, $number, $filters, $exclusions));
        }
        $data = array_merge($this->viewData, [
            'meta_title' => $header->title,
            'show' => $header,
            'seasons' => $seasons,
            'season' => $current,
            'filters' => $filters,
            'episodes' => $episodes,
            'open' => $open,
            'packs' => $current === null ? [] : $load($page->episodeReleaseIds($id, $current, null, $filters, $exclusions)),
            'others' => $load($page->otherReleaseIds($id, $filters, $exclusions)),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $this->userdata->api_token,
        ]);
        if ($fragment === 'list') {
            return view('tv.show.list', $data);
        }

        return view('tv.show.index', [...$data, 'back' => $this->back($request)]);
    }

    /**
     * The episodes to render open: `?open=N` from the releases screen's show line, or the list
     * the page sends back when a filter reloads the episode list.
     *
     * @return list<int>
     */
    private static function opened(Request $request): array
    {
        $values = array_filter((array) $request->query('open', []), static fn (mixed $value): bool => is_string($value) && ctype_digit($value));

        return array_values(array_unique(array_map('intval', $values)));
    }

    /**
     * The TV list the user came from: the shows wall when the referrer is the wall, else the
     * releases screen. Moving between this show's seasons keeps the first answer.
     *
     * @return array{url: string, label: string}
     */
    private function back(Request $request): array
    {
        $releases = route('tv.releases');
        $wall = route('tv.shows');
        $referrer = (string) $request->headers->get('referer', '');
        $from = parse_url($referrer);
        $sameSite = ($from['host'] ?? null) === $request->getHost();
        $path = $sameSite ? '/'.ltrim((string) ($from['path'] ?? ''), '/') : '';
        $url = match (true) {
            $path === parse_url($wall, PHP_URL_PATH), $path === parse_url($releases, PHP_URL_PATH) => $referrer,
            str_starts_with($path, parse_url(url('/tv/show'), PHP_URL_PATH).'/') => (string) $request->session()->get(self::BACK_KEY, $releases),
            default => $releases,
        };
        $request->session()->put(self::BACK_KEY, $url);

        return ['url' => $url, 'label' => parse_url($url, PHP_URL_PATH) === parse_url($wall, PHP_URL_PATH) ? 'Shows' : 'Releases'];
    }
}
