<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ReleaseRowData;
use App\Enums\BrowseRoot;
use App\Models\Country;
use App\Models\DnzbFailure;
use App\Models\Predb;
use App\Models\Release;
use App\Models\ReleaseComment;
use App\Models\ReleaseRegex;
use App\Models\Settings;
use App\Models\Video;
use App\Services\AnidbService;
use App\Services\BookService;
use App\Services\ConsoleService;
use App\Services\GamesService;
use App\Services\MovieService;
use App\Services\MusicService;
use App\Services\PopulateAniListService;
use App\Services\Releases\RelatedReleaseBrowser;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseReportPresentation;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Releases\TitleMetadataLoader;
use App\Services\Releases\TvReleaseDetails;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DetailsController extends BasePageController
{
    public function __construct(
        private readonly ReleaseSearchService $releaseSearchService,
        private readonly MovieService $movieService,
        private readonly ReleaseBrowseService $releaseBrowseService,
    ) {
        parent::__construct();
    }

    public function show(Request $request, string $guid): mixed
    {
        $data = Release::getByGuid($guid);
        // Audio tag/preview metadata is only rendered here, so it is loaded on
        // this path rather than for every getByGuid() caller.
        $data?->loadMissing('audioTags');
        $releaseRegex = '';
        if (! empty($data)) {
            $releaseRegex = ReleaseRegex::query()->where('releases_id', '=', $data['id'])->first();
        }

        if (! $data) {
            return redirect()->back();
        }

        if ($this->isPostBack($request)) {
            $validated = $request->validate(['txtAddComment' => ['required', 'string', 'max:2000']]);
            ReleaseComment::addComment((int) $data['id'], $validated['txtAddComment'], (int) $this->userdata->id, $request->ip());

            return redirect(route('details', ['guid' => $guid]).'#comments')->with('success', 'Comment posted successfully!');
        }

        $comments = ReleaseComment::getComments($data['id']);
        if (BrowseRoot::fromCategoryId((int) $data['categories_id']) === BrowseRoot::Tv) {
            return $this->showTv($data, $comments);
        }
        $similars = $this->releaseSearchService->searchSimilar($data['id'], $data['searchname'], (array) $this->userdata->categoryexclusions);
        $failed = DnzbFailure::getFailedCount($data['id']);
        $reportPresentation = app(ReleaseReportPresentation::class)->forRelease(
            (int) $data['id'], $request->integer('reports_page', 1), $request->integer('responses_page', 1)
        );
        $showInfo = '';
        if ($data['videos_id'] > 0) {
            $showInfo = Video::getByVideoID($data['videos_id']);
        }

        $mov = '';
        $movieTrailerUrl = null;
        if (imdb_id_is_valid($data['imdbid'])) {
            $mov = $this->movieService->getMovieInfo($data['imdbid']);
            if (! empty($mov['title'])) {
                $mov['title'] = str_replace(['/', '\\'], '', $mov['title']);
                if (! empty($mov['actors'])) {
                    $mov['actors'] = makeFieldLinks($mov, 'actors', 'movies');
                }
                if (! empty($mov['genre'])) {
                    $mov['genre'] = makeFieldLinks($mov, 'genre', 'movies');
                }
                if (! empty($mov['director'])) {
                    $mov['director'] = makeFieldLinks($mov, 'director', 'movies');
                }
                if (Settings::settingValue('trailers_display')) {
                    $trailer = empty($mov['trailer']) ? $this->movieService->getTrailer($data['imdbid']) : $mov['trailer'];
                    if ($trailer) {
                        $movieTrailerUrl = app(TitleMetadataLoader::class)->trailerUrl($trailer);
                    }
                }
            }
        }

        $game = '';
        if ((int) $data['gamesinfo_id'] > 0) {
            $game = (new GamesService)->getGamesInfoById($data['gamesinfo_id']);
        }

        $mus = '';
        if ((int) $data['musicinfo_id'] > 0) {
            $mus = (new MusicService)->getMusicInfo($data['musicinfo_id']);
        }

        $book = '';
        if ((int) $data['bookinfo_id'] > 0) {
            $book = (new BookService)->getBookInfo($data['bookinfo_id']);
        }

        $con = '';
        if ((int) $data['consoleinfo_id'] > 0) {
            $con = (new ConsoleService)->getConsoleInfo($data['consoleinfo_id']);
        }

        $AniDBAPIArray = '';
        if ($data['anidbid'] > 0) {
            $AniDBAPIArray = (new AnidbService)->getAnimeInfo($data['anidbid']);

            // If we have anilist_id but missing details, fetch from AniList
            if ($AniDBAPIArray && ! empty($AniDBAPIArray->anilist_id)) {
                $anilistId = $AniDBAPIArray->anilist_id;
                if (empty($AniDBAPIArray->country) && empty($AniDBAPIArray->media_type)) {
                    // Fetch fresh data from AniList if country/media_type is missing
                    try {
                        $palist = new PopulateAniListService;
                        $palist->populateTable('info', $anilistId);
                        // Refresh the data
                        $AniDBAPIArray = (new AnidbService)->getAnimeInfo($data['anidbid']);
                    } catch (\Exception $e) {
                        // Silently fail, use existing data
                    }
                }
            }
        }

        $pre = Predb::getOne($data['predb_id'])?->toArray();

        // Resolve AniDB country code to a Country model/name here so the view stays query-free
        $anidbCountryCode = null;
        if (is_object($AniDBAPIArray)) {
            $anidbCountryCode = $AniDBAPIArray->country ?? null;
        } elseif (is_array($AniDBAPIArray)) {
            $anidbCountryCode = $AniDBAPIArray['country'] ?? null;
        }
        $anidbCountryModel = null;
        $anidbCountryName = null;
        if (! empty($anidbCountryCode)) {
            $anidbCountryModel = Country::query()->find($anidbCountryCode);
            $anidbCountryName = $anidbCountryModel->name ?? $anidbCountryCode;
        }

        $this->releaseBrowseService->loadReleaseRows([$data]);

        $this->viewData = array_merge($this->viewData, app(RelatedReleaseBrowser::class)->forRelease($data, $this->userdata), [
            'release' => $data,
            'show' => $showInfo,
            'movie' => $mov,
            'movieTrailerUrl' => $movieTrailerUrl,
            'anidb' => $AniDBAPIArray,
            'anidbCountryModel' => $anidbCountryModel,
            'anidbCountryName' => $anidbCountryName,
            'music' => $mus,
            'con' => $con,
            'game' => $game,
            'book' => $book,
            'predb' => $pre,
            'comments' => $comments,
            'searchname' => getSimilarName($data['searchname']),
            'similars' => $similars !== false ? $similars : [],
            'privateprofiles' => config('nntmux_settings.private_profiles'),
            'failed' => $failed,
            ...$reportPresentation,
            'regex' => $releaseRegex,
            'meta_title' => 'View NZB',
            'meta_keywords' => 'view,nzb,description,details',
            'meta_description' => 'View NZB for '.$data['searchname'],
        ]);

        return view('details.index', $this->viewData);
    }

    /** TV releases get their own page (docs/proposals/tv-redesign/SPEC.md 3.4); comments post back here as before. */
    private function showTv(Release $release, mixed $comments): View
    {
        $this->releaseBrowseService->loadReleaseRows([$release]);
        /** @var ReleaseRowData $row */
        $row = $release->getAttribute('row_data');
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));

        return view('details.tv.index', array_merge($this->viewData, app(TvReleaseDetails::class)->forRelease($release, $row->category, $exclusions), [
            'release' => $release,
            'comments' => $comments,
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $this->userdata->api_token,
            'meta_title' => 'View NZB',
            'meta_keywords' => 'view,nzb,description,details',
            'meta_description' => 'View NZB for '.$release['searchname'],
        ]));
    }
}
