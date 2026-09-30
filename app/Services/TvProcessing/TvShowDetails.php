<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Category;
use App\Models\Genre;
use App\Models\Network;
use App\Models\TvInfo;
use App\Services\MetadataProcessing\PeopleRows;
use App\Services\TmdbClient;
use App\Support\ChildRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Keeps a show's TMDB details (language, status, US rating, premiere, network, genres and
 * cast) in step with the refresh rule: fetched on the first match, and refreshed when a new
 * release matches the show unless that was done in the last 24 hours. Nothing refreshes
 * shows in the background; this app is not an authoritative source on show information.
 */
final class TvShowDetails
{
    private const int REFRESH_AFTER_HOURS = 24;

    private const int CAST_LIMIT = 12;

    /** Length of tv_info.original_language and tv_info.content_rating_us. */
    private const int CODE_LENGTH = 8;

    public const int STATUS_UNKNOWN = 0;

    public const int STATUS_RUNNING = 1;

    public const int STATUS_ENDED = 2;

    /**
     * TMDB's sixteen TV genres, by name.
     */
    private const array TMDB_TV_GENRES = [
        'Action & Adventure', 'Animation', 'Comedy', 'Crime', 'Documentary', 'Drama', 'Family',
        'Kids', 'Mystery', 'News', 'Reality', 'Sci-Fi & Fantasy', 'Soap', 'Talk',
        'War & Politics', 'Western',
    ];

    /**
     * TMDB genre names stored as other titles; every other name is stored unchanged.
     */
    private const array GENRE_MAP = [
        'Sci-Fi & Fantasy' => ['Sci-Fi', 'Fantasy'],
        'Action & Adventure' => ['Action', 'Adventure'],
        'War & Politics' => ['War'],
        'Kids' => ['Children'],
    ];

    private const array STATUS_MAP = [
        'Returning Series' => self::STATUS_RUNNING,
        'In Production' => self::STATUS_RUNNING,
        'Planned' => self::STATUS_RUNNING,
        'Pilot' => self::STATUS_RUNNING,
        'Ended' => self::STATUS_ENDED,
        'Canceled' => self::STATUS_ENDED,
    ];

    public function __construct(private readonly TmdbClient $tmdb, private readonly PeopleRows $people) {}

    /**
     * The genre titles TMDB's TV genres are stored as.
     *
     * @return list<string>
     */
    public static function tvGenreTitles(): array
    {
        $titles = [];
        foreach (self::TMDB_TV_GENRES as $name) {
            array_push($titles, ...self::genreTitles($name));
        }

        return array_values(array_unique($titles));
    }

    public function refreshIfDue(int $videosId): void
    {
        if ($videosId <= 0 || ! $this->tmdb->isConfigured()) {
            return;
        }

        $refreshedAt = TvInfo::query()->where('videos_id', $videosId)->first(['videos_id', 'details_refreshed_at'])?->details_refreshed_at;
        if ($refreshedAt !== null && $refreshedAt->gt(now()->subHours(self::REFRESH_AFTER_HOURS))) {
            return;
        }

        $video = DB::table('videos')->where('id', $videosId)->first(['tmdb', 'tvdb', 'imdb']);
        if ($video === null) {
            return;
        }

        $tmdbId = $this->resolveTmdbId($video);
        if ($tmdbId === null) {
            // Not retried on every release; retried after 24 hours like any show.
            $this->writeTvInfo($videosId, ['details_refreshed_at' => now()]);

            return;
        }

        $show = $this->tmdb->getTvShow($tmdbId, ['content_ratings', 'aggregate_credits']);
        Sleep::sleep(1);
        if ($show === null) {
            return;
        }

        // People, the network and the genres are found or added before the transaction opens (see PeopleRows).
        $this->store($videosId, $show, $this->castPersonIds($show), $this->networkId($videosId, $show));
    }

    private function resolveTmdbId(object $video): ?int
    {
        $tmdbId = (int) $video->tmdb;
        if ($tmdbId > 0) {
            return $tmdbId;
        }

        $tvdbId = (int) $video->tvdb;
        if ($tvdbId > 0 && ($found = $this->findTmdbId((string) $tvdbId, 'tvdb_id')) !== null) {
            return $found;
        }

        $imdbId = trim((string) $video->imdb);
        if ($imdbId !== '' && $imdbId !== '0') {
            return $this->findTmdbId(str_starts_with($imdbId, 'tt') ? $imdbId : 'tt'.$imdbId, 'imdb_id');
        }

        return null;
    }

    private function findTmdbId(string $externalId, string $source): ?int
    {
        $show = $this->tmdb->findTvByExternalId($externalId, $source);
        Sleep::sleep(1);
        $id = (int) ($show['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Overwrites the show's details with what TMDB returned.
     *
     * @param  array<string, mixed>  $show
     * @param  list<int>  $cast
     */
    private function store(int $videosId, array $show, array $cast, ?int $networkId): void
    {
        $values = [
            'original_language' => mb_substr((string) ($show['original_language'] ?? ''), 0, self::CODE_LENGTH),
            'status' => self::STATUS_MAP[(string) ($show['status'] ?? '')] ?? self::STATUS_UNKNOWN,
            'content_rating_us' => mb_substr($this->usContentRating($show), 0, self::CODE_LENGTH),
            'premiered' => $this->premiered($show),
            'networks_id' => $networkId,
            'details_refreshed_at' => now(),
        ];

        ChildRows::replace('videos', $videosId, 'videos_id', [
            'video_genres' => array_map(static fn (int $genreId): array => ['genres_id' => $genreId], $this->genreIds($show)),
            'video_people' => array_map(
                static fn (int $personId, int $position): array => ['people_id' => $personId, 'position' => $position],
                $cast,
                array_keys($cast),
            ),
        ], fn () => $this->writeTvInfo($videosId, $values));
    }

    /**
     * The show's first TMDB network, else its publisher. Outside a transaction, the read after
     * insertOrIgnore sees a name another worker has just added; inside one it may not.
     *
     * @param  array<string, mixed>  $show
     */
    private function networkId(int $videosId, array $show): ?int
    {
        $publisher = (string) DB::table('tv_info')->where('videos_id', $videosId)->value('publisher');

        return Network::idForName($this->firstNetworkName($show)) ?? Network::idForName($publisher);
    }

    /**
     * Updates the show's tv_info row, inserting it first when a show has none.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeTvInfo(int $videosId, array $values): void
    {
        if (DB::table('tv_info')->where('videos_id', $videosId)->exists()) {
            DB::table('tv_info')->where('videos_id', $videosId)->update($values);

            return;
        }

        DB::table('tv_info')->insert(['videos_id' => $videosId, 'summary' => '', 'publisher' => ''] + $values);
    }

    /**
     * @param  array<string, mixed>  $show
     */
    private function usContentRating(array $show): string
    {
        foreach ($show['content_ratings']['results'] ?? [] as $rating) {
            if (($rating['iso_3166_1'] ?? '') === 'US') {
                return trim((string) ($rating['rating'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $show
     */
    private function premiered(array $show): ?string
    {
        $date = (string) ($show['first_air_date'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $show
     */
    private function firstNetworkName(array $show): string
    {
        return (string) ($show['networks'][0]['name'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $show
     * @return list<int>
     */
    private function genreIds(array $show): array
    {
        $ids = [];
        foreach ($show['genres'] ?? [] as $genre) {
            foreach (self::genreTitles(trim((string) ($genre['name'] ?? ''))) as $title) {
                $ids[] = $this->genreId($title);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private static function genreTitles(string $tmdbName): array
    {
        if ($tmdbName === '') {
            return [];
        }

        return self::GENRE_MAP[$tmdbName] ?? [$tmdbName];
    }

    private function genreId(string $title): int
    {
        $id = Genre::query()->where('type', Category::TV_ROOT)->where('title', $title)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) Genre::query()->insertGetId(['title' => $title, 'type' => Category::TV_ROOT, 'disabled' => 0]);
    }

    /**
     * The twelve distinct TMDB people in the most episodes of the show's all-seasons cast
     * (`aggregate_credits`), ties in TMDB's order, found or added as a film's TMDB credit
     * is, so a person in films and shows is one row. A person with an empty name is linked
     * only when a row already holds the TMDB id; else the next one takes the place.
     *
     * @param  array<string, mixed>  $show
     * @return list<int>
     */
    private function castPersonIds(array $show): array
    {
        $cast = $show['aggregate_credits']['cast'] ?? [];
        $cast = array_filter(is_array($cast) ? $cast : [], is_array(...));
        // usort is stable, so people with equal episode counts keep TMDB's order.
        usort($cast, static fn (array $a, array $b): int => self::episodeCount($b) <=> self::episodeCount($a));

        $ids = [];
        $seen = [];
        foreach ($cast as $member) {
            $tmdbId = (int) ($member['id'] ?? 0);
            if ($tmdbId <= 0 || isset($seen[$tmdbId])) {
                continue;
            }
            $seen[$tmdbId] = true;
            $id = $this->people->findOrAdd((string) ($member['name'] ?? ''), $tmdbId);
            if ($id === null) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) === self::CAST_LIMIT) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @param  array<mixed>  $member
     */
    private static function episodeCount(array $member): int
    {
        return (int) ($member['total_episode_count'] ?? 0);
    }
}
