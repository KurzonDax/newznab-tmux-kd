<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Category;
use App\Models\Genre;
use App\Models\Network;
use App\Models\Person;
use App\Models\TvInfo;
use App\Models\Video;
use App\Services\TmdbClient;
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

    public function __construct(private readonly TmdbClient $tmdb) {}

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

        $show = $this->tmdb->getTvShow($tmdbId, ['content_ratings', 'credits']);
        Sleep::sleep(1);
        if ($show === null) {
            return;
        }

        DB::transaction(fn () => $this->store($videosId, $show));
        Video::invalidateSeriesListCache();
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
     */
    private function store(int $videosId, array $show): void
    {
        $publisher = (string) DB::table('tv_info')->where('videos_id', $videosId)->value('publisher');

        $this->writeTvInfo($videosId, [
            'original_language' => mb_substr((string) ($show['original_language'] ?? ''), 0, self::CODE_LENGTH),
            'status' => self::STATUS_MAP[(string) ($show['status'] ?? '')] ?? self::STATUS_UNKNOWN,
            'content_rating_us' => mb_substr($this->usContentRating($show), 0, self::CODE_LENGTH),
            'premiered' => $this->premiered($show),
            'networks_id' => Network::idForName($this->firstNetworkName($show)) ?? Network::idForName($publisher),
            'details_refreshed_at' => now(),
        ]);

        DB::table('video_genres')->where('videos_id', $videosId)->delete();
        DB::table('video_genres')->insert(array_map(
            fn (int $genreId): array => ['videos_id' => $videosId, 'genres_id' => $genreId],
            $this->genreIds($show),
        ));

        $cast = $this->castPersonIds($show);
        DB::table('video_people')->where('videos_id', $videosId)->delete();
        DB::table('video_people')->insert(array_map(
            fn (int $personId, int $position): array => ['videos_id' => $videosId, 'people_id' => $personId, 'position' => $position],
            $cast,
            array_keys($cast),
        ));
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
     * The first twelve distinct TMDB people in TMDB's cast order.
     *
     * @param  array<string, mixed>  $show
     * @return list<int>
     */
    private function castPersonIds(array $show): array
    {
        $ids = [];
        $seen = [];
        foreach ($show['credits']['cast'] ?? [] as $member) {
            $tmdbId = (int) ($member['id'] ?? 0);
            if ($tmdbId <= 0 || isset($seen[$tmdbId])) {
                continue;
            }
            $seen[$tmdbId] = true;
            // Ignore-then-read: TV workers run in parallel and may add the same person at once.
            Person::query()->insertOrIgnore([
                'tmdb_id' => $tmdbId,
                'name' => mb_substr(trim((string) ($member['name'] ?? '')), 0, Person::NAME_LENGTH),
            ]);
            $ids[] = (int) Person::query()->where('tmdb_id', $tmdbId)->value('id');
            if (count($ids) === self::CAST_LIMIT) {
                break;
            }
        }

        return $ids;
    }
}
