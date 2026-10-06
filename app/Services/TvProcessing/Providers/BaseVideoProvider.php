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

use App\Models\TvEpisode;
use App\Models\TvInfo;
use App\Models\Video;
use App\Models\VideoAlias;
use App\Services\TvProcessing\TvArtifactIdentityResolver;
use App\Services\TvProcessing\TvShowResolution;
use Illuminate\Database\Eloquent\Builder;

/**
 * Parent class for TV/Film and any similar classes to inherit from.
 */
abstract class BaseVideoProvider
{
    protected const EPISODE_TITLE_SIMILARITY_THRESHOLD = 85.0;

    /**
     * @var array<string, int> Roman numerals accepted in episode-title part notation.
     */
    private const PART_ROMAN_NUMERALS = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5];

    private const MAX_PREMIERE_YEARS_BEFORE_RELEASE = 5;

    private const MAX_PREMIERE_YEARS_AFTER_RELEASE = 1;

    private const FOLLOWING_YEAR_TOLERANCE_MONTH = 1;

    /** The length of videos_aliases.title. */
    private const MAX_ALIAS_LENGTH = 180;

    private const MATCH_EXACT_SIBLING = 0;

    private const MATCH_EXACT_TITLE = 1;

    private const MATCH_NORMALIZED_SIBLING = 2;

    private const MATCH_NORMALIZED_TITLE = 3;

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
     * @return array<string, mixed>|false
     */
    abstract public function parseInfo(string $relname): bool|array;

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
        }

        return 0;
    }

    /**
     * Resolve a local show using the episode evidence parsed from a release name.
     *
     * @param  array<string, mixed>  $showInfo
     */
    public function getByRelease(array $showInfo, int $type, int $source = 0, int $fallbackVideoId = 0): int
    {
        return $this->resolveByRelease($showInfo, $type, $source, $fallbackVideoId)->videoId;
    }

    /**
     * Resolve a local show without making an irreversible choice between ambiguous title siblings.
     *
     * @param  array<string, mixed>  $showInfo
     */
    public function resolveByRelease(
        array $showInfo,
        int $type,
        int $source = 0,
        int $fallbackVideoId = 0,
        int $releaseId = 0,
    ): TvShowResolution {
        $title = (string) ($showInfo['cleanname'] ?? '');
        $existingVideoId = $fallbackVideoId;

        if ($title === '') {
            return $existingVideoId > 0
                ? TvShowResolution::matched($existingVideoId)
                : TvShowResolution::notFound();
        }

        $candidateVideoIds = $this->getEpisodeAwareCandidateVideoIds($title, $type, $source);
        if ($candidateVideoIds === []) {
            return $existingVideoId > 0
                ? TvShowResolution::matched($existingVideoId)
                : TvShowResolution::notFound();
        }

        if (count($candidateVideoIds) === 1) {
            return TvShowResolution::matched($candidateVideoIds[0]);
        }

        $titleVideoId = (int) $this->getByTitle($title, $type, $source);
        $evidenceVideoId = $this->resolveDiscriminatingEvidence(
            $showInfo,
            $candidateVideoIds,
            $titleVideoId,
        );
        if ($evidenceVideoId > 0) {
            return TvShowResolution::matched($evidenceVideoId);
        }

        if ($releaseId > 0) {
            $artifactVideoId = (new TvArtifactIdentityResolver)->resolve(
                $releaseId,
                $candidateVideoIds,
                function (string $artifactName) use ($type, $source, $candidateVideoIds): int {
                    $artifactInfo = $this->parseInfo($artifactName);
                    if (! is_array($artifactInfo)) {
                        return 0;
                    }

                    $resolution = $this->resolveByRelease($artifactInfo, $type, $source);

                    return in_array($resolution->videoId, $candidateVideoIds, true)
                        ? $resolution->videoId
                        : 0;
                },
            );

            if ($artifactVideoId !== null) {
                return TvShowResolution::matched($artifactVideoId);
            }
        }

        return TvShowResolution::ambiguous();
    }

    /**
     * @param  array<string, mixed>  $showInfo
     * @param  list<int>  $candidateVideoIds
     */
    private function resolveDiscriminatingEvidence(
        array $showInfo,
        array $candidateVideoIds,
        int $fallbackVideoId,
    ): int {
        $title = (string) ($showInfo['cleanname'] ?? '');
        if ($this->resolveReleaseYear($title) !== null
            && in_array($fallbackVideoId, $candidateVideoIds, true)) {
            return $fallbackVideoId;
        }

        $season = (int) ($showInfo['season'] ?? 0);
        $episode = (int) ($showInfo['episode'] ?? 0);
        $releaseEpisodeTitle = (string) ($showInfo['episode_title'] ?? '');
        if ($season <= 0 || $episode <= 0 || $releaseEpisodeTitle === '') {
            return 0;
        }

        $episodes = TvEpisode::query()
            ->whereIn('videos_id', $candidateVideoIds)
            ->where('series', $season)
            ->where('episode', $episode)
            ->get(['videos_id', 'title']);

        if ($episodes->isEmpty()) {
            return 0;
        }

        /** @var array<int, float> $similarityByVideoId */
        $similarityByVideoId = [];

        foreach ($episodes as $candidateEpisode) {
            $videoId = (int) $candidateEpisode->videos_id;
            $similarityByVideoId[$videoId] = max(
                $similarityByVideoId[$videoId] ?? 0.0,
                $this->episodeTitleSimilarity($releaseEpisodeTitle, (string) $candidateEpisode->title),
            );
        }

        $bestSimilarity = max($similarityByVideoId);
        $bestTitleMatches = array_keys(array_filter(
            $similarityByVideoId,
            static fn (float $similarity): bool => $similarity >= self::EPISODE_TITLE_SIMILARITY_THRESHOLD
                && $similarity === $bestSimilarity,
        ));

        return count($bestTitleMatches) === 1 ? (int) $bestTitleMatches[0] : 0;
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

    /**
     * The comparison key of a show name: two names denote the same show only when their keys are equal.
     * Accents, case, apostrophes, dots, punctuation, spacing and one leading article are ignored;
     * "&" reads as "and".
     */
    protected function showTitleKey(string $title): string
    {
        $key = \Normalizer::normalize($title, \Normalizer::FORM_D);
        $key = (string) preg_replace('/\p{Mn}+/u', '', $key === false ? $title : $key);
        $key = mb_strtolower($key);
        $key = str_replace(["'", '’', 'ʼ', '`', '.'], '', $key);
        $key = str_replace('&', ' and ', $key);
        $key = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key));
        $key = (string) preg_replace('/^(?:the|a|an) /u', '', $key);

        return str_replace(' ', '', $key);
    }

    /**
     * How a release's show name matches the names a show is known by: 0 when one is identical,
     * 1 when one is identical once its trailing parenthetical is removed ("Castle (2009)"), else null.
     *
     * @param  array<array-key, string>  $names
     */
    protected function showTitleMatchTier(string $releaseTitle, array $names): ?int
    {
        $releaseKey = $this->showTitleKey($this->stripReleaseYear($releaseTitle));
        if ($releaseKey === '') {
            return null;
        }

        foreach ($names as $name) {
            if ($this->showTitleKey($name) === $releaseKey) {
                return 0;
            }
        }

        foreach ($names as $name) {
            $baseName = $this->withoutTrailingParenthetical($name);
            if ($baseName !== null && $this->showTitleKey($baseName) === $releaseKey) {
                return 1;
            }
        }

        return null;
    }

    /**
     * The name without its trailing parenthetical, or null when it has none.
     */
    private function withoutTrailingParenthetical(string $name): ?string
    {
        $baseName = preg_replace('/\s*\([^)]*\)\s*$/u', '', $name, 1, $count);

        return $count === 1 && is_string($baseName) ? $baseName : null;
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
        [$matchQualityByVideoId, $preferredSiblingByVideoId] = $this->getTitleCandidateMatchData($title, $type, $source);

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
     * @return list<int>
     */
    private function getEpisodeAwareCandidateVideoIds(string $title, int $type, int $source): array
    {
        $releaseYear = $this->resolveReleaseYear($title);
        [$matchQualityByVideoId] = $this->getTitleCandidateMatchData(
            $this->stripReleaseYear($title),
            $type,
            $source,
        );
        $candidateVideoIds = array_keys($matchQualityByVideoId);

        if ($releaseYear === null || $candidateVideoIds === []) {
            return $candidateVideoIds;
        }

        return Video::query()
            ->whereKey($candidateVideoIds)
            ->get(['id', 'started'])
            ->filter(fn (Video $video): bool => $this->isPremiereYearPlausible($video->started, $releaseYear))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{array<int, int>, array<int, bool>}
     */
    private function getTitleCandidateMatchData(string $title, int $type, int $source): array
    {
        $titleVariants = $this->getTitleVariants($title);
        $likePatterns = array_map($this->getCandidateLikePattern(...), $titleVariants);

        $videoCandidates = $this->constrainCandidateTitles(
            $this->getVideoCandidateQuery($type, $source)
                ->select(['videos.id', 'videos.title as candidate_title']),
            'videos.title',
            $likePatterns,
        )->get();

        $aliasCandidates = $this->constrainCandidateTitles(
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

            $matchQuality = $this->getTitleMatchQuality($candidateTitle, $titleVariants);
            if ($matchQuality === null) {
                continue;
            }

            $videoId = (int) $candidate->id;
            $matchQualityByVideoId[$videoId] = min($matchQualityByVideoId[$videoId] ?? PHP_INT_MAX, $matchQuality);
            $preferredSiblingByVideoId[$videoId] = ($preferredSiblingByVideoId[$videoId] ?? false)
                || $this->isPreferredTitleSibling($candidateTitle, $titleVariants);
        }

        return [$matchQualityByVideoId, $preferredSiblingByVideoId];
    }

    /**
     * Compare a release's episode-title segment against a stored episode title.
     */
    protected function episodeTitleSimilarity(string $releaseTitle, string $candidateTitle): float
    {
        $normalizedReleaseTitle = $this->normalizeEpisodeTitle($releaseTitle);
        $normalizedCandidateTitle = $this->normalizeEpisodeTitle($candidateTitle);
        if ($normalizedReleaseTitle === '' || $normalizedCandidateTitle === '') {
            return 0.0;
        }

        // Multi-part episodes differ only in their part number, which similar_text barely notices.
        // Confirm rather than guess: the two sides must agree on the part they name, or on naming none.
        if ($this->episodeTitlePartNumber($normalizedReleaseTitle)
            !== $this->episodeTitlePartNumber($normalizedCandidateTitle)) {
            return 0.0;
        }

        similar_text($normalizedReleaseTitle, $normalizedCandidateTitle, $similarity);

        return $similarity;
    }

    /**
     * Trailing part number of an already-normalized episode title, or null when it names none.
     */
    private function episodeTitlePartNumber(string $normalizedTitle): ?int
    {
        return preg_match('/ (\d{1,2})$/u', $normalizedTitle, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function normalizeEpisodeTitle(string $title): string
    {
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($title));
        $normalized = preg_replace('/\s+/', ' ', trim($normalized ?? '')) ?? '';

        return $this->normalizePartNotation($normalized);
    }

    /**
     * Reduce trailing part notation to a bare number so "Part 2", "Pt. II" and "(2)" compare equal.
     */
    private function normalizePartNotation(string $normalizedTitle): string
    {
        return (string) preg_replace_callback(
            '/\b(?:part|pt) (\d{1,2}|iv|i{1,3}|v)$/u',
            static fn (array $matches): string => (string) (ctype_digit($matches[1])
                ? (int) $matches[1]
                : self::PART_ROMAN_NUMERALS[$matches[1]]),
            $normalizedTitle,
        );
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
    private function constrainCandidateTitles(Builder $query, string $titleColumn, array $likePatterns): Builder
    {
        return $query->where(function (Builder $query) use ($likePatterns, $titleColumn): void {
            foreach ($likePatterns as $likePattern) {
                $query->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($titleColumn, ?, ''), ?, ''), ?, ''), ?, ''), ?, ''), ?, ''), ?, '') LIKE ?",
                    ["'", '’', ':', '!', '.', '-', ' ', $likePattern],
                );
            }
        });
    }

    /**
     * A LIKE pattern no narrower than showTitleKey() equality: the variant's words in order, without
     * one leading article, matched against a title stripped of the punctuation and spaces the key ignores.
     */
    private function getCandidateLikePattern(string $title): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $title, $matches);
        $words = $matches[0];
        if (count($words) > 1 && in_array(mb_strtolower($words[0]), ['the', 'a', 'an'], true)) {
            array_shift($words);
        }

        return '%'.implode('%', $words).'%';
    }

    /**
     * @param  list<string>  $titleVariants
     */
    private function getTitleMatchQuality(string $candidateTitle, array $titleVariants): ?int
    {
        $candidateKey = $this->showTitleKey($candidateTitle);
        $candidateBaseName = $this->withoutTrailingParenthetical($candidateTitle);
        $candidateBaseKey = $candidateBaseName === null ? null : $this->showTitleKey($candidateBaseName);
        $bestQuality = null;

        foreach ($titleVariants as $titleVariant) {
            if (preg_match('/^'.preg_quote($titleVariant, '/').'\s+\([^)]+\)$/iu', $candidateTitle) === 1) {
                return self::MATCH_EXACT_SIBLING;
            }

            if (strcasecmp($candidateTitle, $titleVariant) === 0) {
                return self::MATCH_EXACT_TITLE;
            }

            $variantKey = $this->showTitleKey($titleVariant);
            if ($variantKey === '') {
                continue;
            }

            if ($candidateBaseKey === $variantKey) {
                $bestQuality = min($bestQuality ?? PHP_INT_MAX, self::MATCH_NORMALIZED_SIBLING);
            }

            if ($candidateKey === $variantKey) {
                $bestQuality = min($bestQuality ?? PHP_INT_MAX, self::MATCH_NORMALIZED_TITLE);
            }
        }

        return $bestQuality;
    }

    /**
     * @param  list<string>  $titleVariants
     */
    private function isPreferredTitleSibling(string $candidateTitle, array $titleVariants): bool
    {
        $normalizedCandidate = $this->normalizeTitleForMatching($candidateTitle);

        foreach ($titleVariants as $titleVariant) {
            if (preg_match('/^'.preg_quote($titleVariant, '/').'\s+\((?:\d{4}|[a-z]{2,3})\)$/iu', $candidateTitle) === 1) {
                return true;
            }

            $normalizedVariant = $this->normalizeTitleForMatching($titleVariant);
            if (preg_match('/^'.preg_quote($normalizedVariant, '/').'\s+\([a-z]{2,3}\)$/iu', $normalizedCandidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTitleForMatching(string $title): string
    {
        return preg_replace('/\s+/', ' ', trim(str_replace(["'", ':', '!'], '', $title))) ?? '';
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
     * Stores every distinct alias of a video; aliases it already has are ignored.
     *
     * @param  array<array-key, mixed>  $aliases  alias strings, or TVMaze-style ['name' => ...] entries
     */
    public function addAliases(mixed $videoId, array $aliases = []): void
    {
        if ($videoId <= 0) {
            return;
        }

        $titles = [];
        foreach ($aliases as $title) {
            // Check for tvmaze style aka
            if (\is_array($title) && ! empty($title['name'])) {
                $title = $title['name'];
            }
            if (! \is_string($title)) {
                continue;
            }

            $title = trim($title);
            if ($title !== '' && mb_strlen($title) <= self::MAX_ALIAS_LENGTH) {
                $titles[$title] = true;
            }
        }

        if ($titles === []) {
            return;
        }

        $now = now();
        VideoAlias::insertOrIgnore(array_map(
            static fn (string|int $title): array => ['videos_id' => $videoId, 'title' => (string) $title, 'created_at' => $now, 'updated_at' => $now],
            array_keys($titles),
        ));
    }
}
