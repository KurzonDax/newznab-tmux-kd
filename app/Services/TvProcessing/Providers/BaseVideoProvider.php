<?php

declare(strict_types=1);

/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:
 *
 * @link      <http://www.gnu.org/licenses/>.
 *
 * @author    niel
 * @copyright 2015 nZEDb
 */

namespace App\Services\TvProcessing\Providers;

use App\Models\TvInfo;
use App\Models\Video;
use App\Models\VideoAlias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Parent class for TV/Film and any similar classes to inherit from.
 */
abstract class BaseVideoProvider
{
    private const MAX_PREMIERE_YEARS_BEFORE_RELEASE = 5;

    private const MAX_PREMIERE_YEARS_AFTER_RELEASE = 1;

    private const FOLLOWING_YEAR_TOLERANCE_MONTH = 1;

    private const MATCH_EXACT_SIBLING = 0;

    private const MATCH_EXACT_TITLE = 1;

    private const MATCH_NORMALIZED_SIBLING = 2;

    private const MATCH_NORMALIZED_TITLE = 3;

    private const MATCH_LOOSE_TITLE = 4;

    // Video Type Identifiers
    protected const TYPE_TV = 0; // Type of video is a TV Programme/Show

    protected const TYPE_FILM = 1; // Type of video is a Film/Movie

    protected const TYPE_ANIME = 2; // Type of video is a Anime

    public bool $echooutput;

    /**
     * @var array<string, mixed> sites	The sites that we have an ID columns for in our video table.
     */
    private static $sites = ['imdb', 'tmdb', 'trakt', 'tvdb', 'tvmaze', 'tvrage']; // @phpstan-ignore property.defaultValue

    /**
     * @var array<string, mixed> Temp Array of cached failed lookups
     */
    public array $titleCache;

    public function __construct()
    {
        $this->echooutput = config('nntmux.echocli');
        $this->titleCache = [];
    }

    /**
     * Main processing director function for scrapers
     * Calls work query function and initiates processing.
     */
    abstract public function processSite(int $groupID, string $guidChar, int $process, bool $local = false): void;

    /**
     * @return false|mixed
     */
    public function getSiteIDFromVideoID(string $siteColumn, int $videoID): mixed
    {
        if (\in_array($siteColumn, self::$sites, false)) {
            $result = Video::query()->where('id', $videoID)->first([$siteColumn]);

            return $result !== null ? $result[$siteColumn] : false;
        }

        return false;
    }

    /**
     * Get TV show local timezone from a Video ID.
     *
     * @return string Empty string if no query return or tz style timezone
     */
    public function getLocalZoneFromVideoID(int $videoID): string
    {
        $result = TvInfo::query()->where('videos_id', $videoID)->first(['localzone']);

        return $result !== null ? $result['localzone'] : '';
    }

    /**
     * Get video info from a Site ID and column.
     */
    protected function getVideoIDFromSiteID(string $siteColumn, int $siteID): bool|int
    {
        if ($siteID === 0) {
            return false;
        }

        $result = false;
        if (\in_array($siteColumn, self::$sites, false)) {
            $result = Video::query()->where($siteColumn, $siteID)->first();
        }
        if (! empty($result)) {
            $query = $result->toArray();

            return $query['id'];
        }

        return false;
    }

    public function getByTitle(string $title, int $type, int $source = 0): mixed
    {
        if (preg_match('/^(.+?)\s*\((\d{4})\)$/', $title, $yearMatch)) {
            $exactVideoId = $this->getTitleExact($title, $type, $source);
            if ($exactVideoId !== 0) {
                return $exactVideoId;
            }

            $titleWithoutYear = trim($yearMatch[1]);

            return $this->getYearAwareTitleMatch($titleWithoutYear, $type, $source, (int) $yearMatch[2]);
        }

        $videoId = $this->getTitleExact($title, $type, $source);
        if ($videoId !== 0) {
            return $videoId;
        }

        // Check alt. title (Strip ' and :) Maybe strip more in the future.
        $videoId = $this->getAlternativeTitleExact($title, $type, $source);
        if ($videoId !== 0) {
            return $videoId;
        }

        foreach (array_slice($this->getTitleVariants($title), 1) as $transformedTitle) {

            $videoId = $this->getTitleExact($transformedTitle, $type, $source);
            if ($videoId !== 0) {
                return $videoId;
            }

            $videoId = $this->getLooseTitleMatch($transformedTitle, $type, $source);
            if ($videoId !== 0) {
                return $videoId;
            }
        }

        // If there was not an exact title match, look for title with missing chars
        // example release name :Zorro 1990, tvrage name Zorro (1990)
        // Only search if the title contains more than one word to prevent incorrect matches
        $titleWords = explode(' ', $title);
        if (\count($titleWords) > 1) {
            $videoId = $this->getLooseTitleMatch($title, $type, $source);
            if ($videoId !== 0) {
                return $videoId;
            }
        }

        return 0;
    }

    protected function resolveReleaseYear(string $title, ?int $releaseYear = null): ?int
    {
        if ($releaseYear !== null) {
            return $releaseYear;
        }

        return preg_match('/\((\d{4})\)$/', $title, $yearMatch) === 1
            ? (int) $yearMatch[1]
            : null;
    }

    protected function stripReleaseYear(string $title): string
    {
        return trim((string) preg_replace('/\s*\(\d{4}\)$/', '', $title));
    }

    protected function isPremiereYearPlausible(mixed $premiereDate, ?int $releaseYear): bool
    {
        if ($releaseYear === null) {
            return true;
        }

        if (! is_string($premiereDate)
            || preg_match('/^(\d{4})-(\d{2})/', $premiereDate, $dateMatch) !== 1) {
            return false;
        }

        $premiereYear = (int) $dateMatch[1];
        $premiereMonth = (int) $dateMatch[2];
        $isEarlyFollowingYear = $premiereYear === $releaseYear + self::MAX_PREMIERE_YEARS_AFTER_RELEASE
            && $premiereMonth === self::FOLLOWING_YEAR_TOLERANCE_MONTH;

        return $premiereYear >= $releaseYear - self::MAX_PREMIERE_YEARS_BEFORE_RELEASE
            && ($premiereYear <= $releaseYear || $isEarlyFollowingYear);
    }

    private function getYearAwareTitleMatch(string $title, int $type, int $source, int $releaseYear): int
    {
        $titleVariants = $this->getTitleVariants($title);
        $likePatterns = array_map($this->getYearAwareLikePattern(...), $titleVariants);

        $videoCandidates = $this->constrainYearAwareTitles(
            $this->getVideoCandidateQuery($type, $source)
                ->select(['videos.id', 'videos.title as candidate_title']),
            'videos.title',
            $likePatterns,
        )->get();

        $aliasCandidates = $this->constrainYearAwareTitles(
            $this->getVideoCandidateQuery($type, $source)
                ->select(['videos.id', 'videos_aliases.title as candidate_title'])
                ->join('videos_aliases', 'videos.id', '=', 'videos_aliases.videos_id'),
            'videos_aliases.title',
            $likePatterns,
        )->get();

        /** @var array<int, int> $matchQualityByVideoId */
        $matchQualityByVideoId = [];

        /** @var array<int, bool> $preferredSiblingByVideoId */
        $preferredSiblingByVideoId = [];

        foreach ($videoCandidates->concat($aliasCandidates) as $candidate) {
            $candidateTitle = $candidate->getAttribute('candidate_title');
            if (! is_string($candidateTitle)) {
                continue;
            }

            $matchQuality = $this->getYearAwareTitleMatchQuality($candidateTitle, $titleVariants);
            if ($matchQuality === null) {
                continue;
            }

            $videoId = (int) $candidate->id;
            $matchQualityByVideoId[$videoId] = min($matchQualityByVideoId[$videoId] ?? PHP_INT_MAX, $matchQuality);
            $preferredSiblingByVideoId[$videoId] = ($preferredSiblingByVideoId[$videoId] ?? false)
                || $this->isPreferredYearAwareSibling($candidateTitle, $titleVariants);
        }

        if ($matchQualityByVideoId === []) {
            return 0;
        }

        $bestVideoId = 0;
        $bestRank = null;

        foreach (Video::query()->whereKey(array_keys($matchQualityByVideoId))->get(['id', 'started']) as $candidate) {
            if (! $this->isPremiereYearPlausible($candidate->started, $releaseYear)
                || ! preg_match('/^(\d{4})-/', $candidate->started, $dateMatch)) {
                continue;
            }

            $premiereYear = (int) $dateMatch[1];

            $rank = [
                $preferredSiblingByVideoId[(int) $candidate->id] ? 0 : 1,
                $premiereYear > $releaseYear ? 1 : 0,
                abs($releaseYear - $premiereYear),
                $matchQualityByVideoId[(int) $candidate->id],
                (int) $candidate->id,
            ];

            if ($bestRank === null || $rank < $bestRank) {
                $bestVideoId = (int) $candidate->id;
                $bestRank = $rank;
            }
        }

        return $bestVideoId;
    }

    /**
     * @return list<string>
     */
    private function getTitleVariants(string $title): array
    {
        return array_values(array_unique([
            $title,
            str_ireplace(' and ', ' & ', $title),
            str_ireplace('er', 're', $title),
        ]));
    }

    /**
     * @return Builder<Video>
     */
    private function getVideoCandidateQuery(int $type, int $source): Builder
    {
        return Video::query()
            ->where('videos.type', $type)
            ->when($source > 0, fn (Builder $query): Builder => $query->where('videos.source', $source));
    }

    /**
     * @param  Builder<Video>  $query
     * @param  list<string>  $likePatterns
     * @return Builder<Video>
     */
    private function constrainYearAwareTitles(Builder $query, string $titleColumn, array $likePatterns): Builder
    {
        return $query->where(function (Builder $query) use ($likePatterns, $titleColumn): void {
            foreach ($likePatterns as $likePattern) {
                $query->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE($titleColumn, ?, ''), ?, ''), ?, '') LIKE ?",
                    ["'", ':', '!', $likePattern],
                );
            }
        });
    }

    private function getYearAwareLikePattern(string $title): string
    {
        $normalizedTitle = $this->normalizeYearAwareTitle($title);

        return '%'.str_replace(' ', '%', $normalizedTitle).'%';
    }

    /**
     * @param  list<string>  $titleVariants
     */
    private function getYearAwareTitleMatchQuality(string $candidateTitle, array $titleVariants): ?int
    {
        $normalizedCandidate = $this->normalizeYearAwareTitle($candidateTitle);
        $bestQuality = null;

        foreach ($titleVariants as $titleVariant) {
            if (preg_match('/^'.preg_quote($titleVariant, '/').'\s+\([^)]+\)$/iu', $candidateTitle) === 1) {
                return self::MATCH_EXACT_SIBLING;
            }

            if (strcasecmp($candidateTitle, $titleVariant) === 0) {
                return self::MATCH_EXACT_TITLE;
            }

            $normalizedVariant = $this->normalizeYearAwareTitle($titleVariant);
            if (preg_match('/^'.preg_quote($normalizedVariant, '/').'\s+\([^)]+\)$/iu', $normalizedCandidate) === 1) {
                $bestQuality = min($bestQuality ?? PHP_INT_MAX, self::MATCH_NORMALIZED_SIBLING);
            }

            if (strcasecmp($normalizedCandidate, $normalizedVariant) === 0) {
                $bestQuality = min($bestQuality ?? PHP_INT_MAX, self::MATCH_NORMALIZED_TITLE);
            }

            $words = explode(' ', $normalizedVariant);
            $wordPattern = implode('.*', array_map(
                static fn (string $word): string => preg_quote($word, '/'),
                $words,
            ));

            if (preg_match('/'.$wordPattern.'(?:\s+\([^)]+\))?$/iu', $normalizedCandidate) === 1) {
                $bestQuality = min($bestQuality ?? PHP_INT_MAX, self::MATCH_LOOSE_TITLE);
            }
        }

        return $bestQuality;
    }

    /**
     * @param  list<string>  $titleVariants
     */
    private function isPreferredYearAwareSibling(string $candidateTitle, array $titleVariants): bool
    {
        $normalizedCandidate = $this->normalizeYearAwareTitle($candidateTitle);

        foreach ($titleVariants as $titleVariant) {
            if (preg_match('/^'.preg_quote($titleVariant, '/').'\s+\((?:\d{4}|[a-z]{2,3})\)$/iu', $candidateTitle) === 1) {
                return true;
            }

            $normalizedVariant = $this->normalizeYearAwareTitle($titleVariant);
            if (preg_match('/^'.preg_quote($normalizedVariant, '/').'\s+\([a-z]{2,3}\)$/iu', $normalizedCandidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeYearAwareTitle(string $title): string
    {
        return preg_replace('/\s+/', ' ', trim(str_replace(["'", ':', '!'], '', $title))) ?? '';
    }

    private function getLooseTitleMatch(string $title, int $type, int $source): mixed
    {
        $looseTitle = '%';
        foreach (explode(' ', $title) as $titleWord) {
            $looseTitle .= str_ireplace(["'", '!'], '', $titleWord).'%';
        }

        return $this->getTitleLoose($looseTitle, $type, $source);
    }

    public function getTitleExact(string $title, int $type, int $source = 0): int
    {
        $return = 0;
        if (! empty($title)) {
            $sql = Video::query()->where(['title' => $title, 'type' => $type]);
            if ($source > 0) {
                $sql->where('source', $source);
            }
            $query = $sql->first();
            if (! empty($query)) {
                $result = $query->toArray();
                $return = $result['id'];
            }
            // Try for an alias
            if (empty($return)) {
                $sql = Video::query()
                    ->join('videos_aliases', 'videos.id', '=', 'videos_aliases.videos_id')
                    ->where(['videos_aliases.title' => $title, 'videos.type' => $type]);
                if ($source > 0) {
                    $sql->where('videos.source', $source);
                }
                $query = $sql->first();
                if (! empty($query)) {
                    $result = $query->toArray();
                    $return = $result['id'];
                }
            }
        }

        return $return;
    }

    /**
     * @return int|mixed
     */
    public function getTitleLoose(mixed $title, mixed $type, int $source = 0): mixed
    {
        $return = 0;

        if (! empty($title)) {
            $sql = Video::query()
                ->where('title', 'like', rtrim((string) $title, '%'))
                ->where('type', $type);
            if ($source > 0) {
                $sql->where('source', $source);
            }
            $query = $sql->first();
            if (! empty($query)) {
                $result = $query->toArray();
                $return = $result['id'];
            }
            // Try for an alias
            if (empty($return)) {
                $sql = Video::query()
                    ->join('videos_aliases', 'videos.id', '=', 'videos_aliases.videos_id')
                    ->where('videos_aliases.title', '=', rtrim((string) $title, '%'))
                    ->where('type', $type);
                if ($source > 0) {
                    $sql->where('videos.source', $source);
                }
                $query = $sql->first();
                if (! empty($query)) {
                    $result = $query->toArray();
                    $return = $result['id'];
                }
            }
        }

        return $return;
    }

    /**
     * @return int|mixed
     */
    public function getAlternativeTitleExact(string $title, int $type, int $source = 0): mixed
    {
        $return = 0;
        if (! empty($title)) {
            if ($source > 0) {
                $query = Video::query()
                    ->whereRaw("REPLACE(title, ?, '') = ?", ["'", $title])
                    ->orWhereRaw("REPLACE(title,':','') = ?", $title)
                    ->where('type', '=', $type)
                    ->where('source', '=', $source)
                    ->first();
            } else {
                $query = Video::query()
                    ->whereRaw("REPLACE(title, ?, '') = ?", ["'", $title])
                    ->orWhereRaw("REPLACE(title,':','') = ?", $title)
                    ->where('type', '=', $type)
                    ->first();
            }
            if (! empty($query)) {
                $result = $query->toArray();

                return $result['id'];
            }
        }

        return $return;
    }

    /**
     * Inserts aliases for videos.
     *
     * @param  array<string, mixed>  $aliases
     */
    public function addAliases(mixed $videoId, array $aliases = []): void
    {
        if (! empty($aliases) && $videoId > 0) {
            foreach ($aliases as $key => $title) {
                // Check for tvmaze style aka
                if (\is_array($title) && ! empty($title['name'])) {
                    $title = $title['name'];
                }
                // Check if we have the AKA already
                $check = $this->getAliases($videoId, $title);

                if ($check === false) {
                    VideoAlias::insertOrIgnore(['videos_id' => $videoId, 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    /**
     * Retrieves all aliases for given VideoID or VideoID for a given alias.
     *
     *
     * @return VideoAlias[]|bool|Builder[]|Collection|mixed
     */
    public function getAliases(int $videoId, string $alias = ''): mixed
    {
        $return = false;
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));

        if ($videoId > 0 || $alias !== '') {
            $aliasCache = Cache::get(md5($videoId.$alias));
            if ($aliasCache !== null) {
                $return = $aliasCache;
            } else {
                $sql = VideoAlias::query();
                if ($videoId > 0) {
                    $sql->where('videos_id', $videoId);
                } elseif ($alias !== '') {
                    $sql->where('title', $alias);
                }
                $return = $sql->get();
                Cache::put(md5($videoId.$alias), $return, $expiresAt);
            }
        }

        return $return->isEmpty() ? false : $return;
    }
}
