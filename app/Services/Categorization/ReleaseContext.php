<?php

declare(strict_types=1);

namespace App\Services\Categorization;

/**
 * Value object containing release information for categorization.
 */
class ReleaseContext
{
    private const string STANDALONE_SEASON_TOKEN_REGEX = '/(?:^|[._ -])S\d{1,3}(?=$|[._ -])/i';

    private const string SEASON_EPISODE_TOKEN_REGEX = '/(?:^|[._ -])S\d{1,3}[._ -]?(?:E|D(?:isc)?)\d{1,4}(?:[._ -]?E\d{1,4})*(?=$|[._ -])/i';

    /** Explicit XXX tags and studio names: always adult. */
    private const string HARD_ADULT_MARKER_REGEX = '/\b(XXX|Porn|Brazzers|BangBros|Bangbros|NaughtyAmerica|RealityKings|Tushy|Vixen|Blacked|OnlyFans|MetArt|JoyMii|Creampie|MP4-XXX|PureTaboo|Lady[._ -]?Lyne|TeamSkeet|GirlsWay|EvilAngel|Kink|FakeHub|FakeTaxi|SexArt|Nubiles|Defloration|Deeper|Bellesa|Twistys|Mofos|MissaX|LegalPorno|AnalVids|JAV|Hentai|RoccoSiffredi|DivineBitches|Device[._ -]?Bondage|Hogtied|Wired[._ -]?Pussy|Fucking[._ -]?Machines|Ultimate[._ -]?Surrender|Public[._ -]?Disgrace|Sex[._ -]?And[._ -]?Submission|Bound[._ -]?Gang[._ -]?Bangs|Electro[._ -]?Sluts|Whipped[._ -]?Ass|TS[._ -]?Seduction|Infernal[._ -]?Restraints|Sexually[._ -]?Broken|ClubSweethearts|HookupHotshot)\b/i';

    /**
     * Unambiguous adult trigger words: always adult, no video marker required.
     *
     * "cuckold" and "deepthroat" match as substrings (SubmissiveCuckolds,
     * Deepthroating); "cum" only matches as a standalone token so that
     * "document", "circumstance" and "cumulative" stay clean.
     */
    public const string HARD_ADULT_TRIGGER_REGEX = '/cuckold|deepthroat|(?:^|[^a-z0-9])cum(?:$|[^a-z0-9])/i';

    /** Ambiguous keywords: adult only when combined with a resolution (likely adult clip). */
    private const string WEAK_ADULT_KEYWORD_REGEX = '/\b(Fuck|Fucked|Fucking|Cock|Dick|Pussy|Cum|Cumshot|Blowjob|Handjob|MILF|Teen|Lesbian|Threesome|Gangbang|Hardcore|Interracial)\b/i';

    /** Video markers that make an ambiguous adult keyword count, including sub-HD clips. */
    private const string VIDEO_RESOLUTION_REGEX = '/\b(360p|480p|540p|576p|720p|1080p|2160p|4k|mp4)\b/i';

    public function __construct(
        public readonly string $releaseName,
        public readonly int|string $groupId,
        public readonly string $groupName = '',
        public readonly string $poster = '',
        public readonly bool $categorizeForeign = true,
        public readonly bool $catWebDL = true,
        public readonly bool $routeObfuscatedNames = false,
        public readonly ?int $obfuscatedDefaultRootCategoryId = null,
        public readonly ?int $forcedRootCategoryId = null,
    ) {}

    /**
     * Get the release name in lowercase for case-insensitive matching.
     */
    public function getLowerReleaseName(): string
    {
        return strtolower($this->releaseName);
    }

    /**
     * Check if the release name matches a pattern.
     */
    public function matchesPattern(string $pattern): bool
    {
        return (bool) preg_match($pattern, $this->releaseName);
    }

    /**
     * Check if the release name contains a substring (case-insensitive).
     */
    public function containsString(string $needle): bool
    {
        return stripos($this->releaseName, $needle) !== false;
    }

    /**
     * Check if the group name matches a pattern.
     */
    public function groupMatchesPattern(string $pattern): bool
    {
        return (bool) preg_match($pattern, $this->groupName);
    }

    /**
     * Check whether the release name contains a delimiter-bounded season-pack token.
     */
    public function hasStandaloneSeasonToken(): bool
    {
        return preg_match(self::STANDALONE_SEASON_TOKEN_REGEX, $this->releaseName) === 1;
    }

    /**
     * Check whether the release name contains a season+episode token
     * (S01E01, S01.E01, S1D1, S11E46E47, S01E01-E02 …).
     */
    public function hasSeasonEpisodeToken(): bool
    {
        return preg_match(self::SEASON_EPISODE_TOKEN_REGEX, $this->releaseName) === 1;
    }

    /**
     * Check if this release has adult/XXX markers.
     *
     * Explicit markers (XXX tags, studio names) always win. Weak keywords
     * ("Anal", or "Teen"/"Hardcore"/… next to a resolution) are ambiguous in
     * ordinary titles, so they do not count when the name carries a clear
     * TV structure (season+episode or standalone season token).
     */
    public function hasAdultMarkers(): bool
    {
        if (preg_match(self::HARD_ADULT_MARKER_REGEX, $this->releaseName)
            || preg_match(self::HARD_ADULT_TRIGGER_REGEX, $this->releaseName)) {
            return true;
        }

        $hasWeakMarker = preg_match('/\bAnal\b/i', $this->releaseName)
            || (preg_match(self::WEAK_ADULT_KEYWORD_REGEX, $this->releaseName)
                && preg_match(self::VIDEO_RESOLUTION_REGEX, $this->releaseName));

        if (! $hasWeakMarker) {
            return false;
        }

        return ! ($this->hasSeasonEpisodeToken() || $this->hasStandaloneSeasonToken());
    }
}
