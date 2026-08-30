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

    /** Adult studios whose names are sufficiently distinctive to stand alone. */
    public const string UNAMBIGUOUS_ADULT_STUDIOS = 'Brazzers|NaughtyAmerica|RealityKings|Bangbros|BangBros18|TeenFidelity|PornPros|SexArt|WowGirls|Bellesa|Defloration|MetArt|MetArtX|TheLifeErotic|VivThomas|JoyMii|Nubiles|NubileFilms|Anilos|FamilyStrokes|X-Art|Twistys|WetAndPuffy|WowPorn|MomsTeachSex|Mofos|BangBus|DorcelClub|CherryPimps|PureTaboo|Lady[._ -]?Lyne|TeamSkeet|GirlsWay|Digital[._ -]?Playground|HardX|JulesJordan|ManuelFerrara|LesbianX|AllAnal|DarkX|PornFidelity|Kelly[._ -]?Madison|DDF[._ -]?Network|21Sextury|21Naturals|SexMex|SpankBang|PornWorld|LegalPorno|AnalVids|GonzoXXX|RoccoSiffredi|Fake[._ -]?Hub|FakeAgent|FakeTaxi|FakeHostel|PublicAgent|StrandedTeens|Property[._ -]?Sex|Dane[._ -]?Jones|Lets[._ -]?Doe[._ -]?It|Office[._ -]?Obsession|SexyHub|Massage[._ -]?Rooms|Fitness[._ -]?Rooms|Female[._ -]?Agent|MissaX|All[._ -]?Girl[._ -]?Massage|Fantasy[._ -]?Massage|Nurumassage|Soapymassage|Reality[._ -]?Junkies|Perv[._ -]?Mom|Bad[._ -]?Milfs|Milf[._ -]?Body|Step[._ -]?Siblings|Sis[._ -]?Loves[._ -]?Me|Brother[._ -]??Crush|Dad[._ -]?Crush|Mom[._ -]?Knows[._ -]?Best|Bratty[._ -]?Sis|My[._ -]?Family[._ -]?Pies|Family[._ -]?Therapy|Nubiles[._ -]?Porn|Step[._ -]?Fantasy|Caught[._ -]?Fapping|She[._ -]?Will[._ -]?Cheat|Dirty[._ -]?Wives[._ -]?Club|Big[._ -]?Tits[._ -]?Round[._ -]?Asses|Ass[._ -]?Parade|Monsters[._ -]?Of[._ -]?Cock|Brown[._ -]?Bunnies|Teens[._ -]?Love[._ -]?Huge[._ -]?Cocks|Ass[._ -]?Masterpiece|Tiny4K|POVD|Exotic4K|CastingCouch[._ -]?X|Creampie[._ -]?Angels|Digital[._ -]?Desire|Femjoy|Hegre|Joymii|Met[._ -]?Art|MPL[._ -]?Studios|Rylsky[._ -]?Art|Stunning18|Photodromm|Watch4Beauty|Wow[._ -]?Girls|Yonitale|Mommys[._ -]?Boy|AllOver30|10musume|Caribbeancom|Heyzo|Pacopacomama|1Pondo|TokyoHot|Mommy[._ -]?Blows[._ -]?Best|Milfs[._ -]?Like[._ -]?It[._ -]?Big|Mommy[._ -]?Got[._ -]?Boobs|My[._ -]?Friends[._ -]?Hot[._ -]?Mom|Seduced[._ -]?By[._ -]?A[._ -]?Cougar|Hot[._ -]?Mom[._ -]?Next[._ -]?Door|ClubSweethearts|HookupHotshot';

    /** Studio names and list entries that are ordinary title vocabulary. */
    public const string AMBIGUOUS_ADULT_TERMS = 'Tushy|Vixen|Blacked|Deeper|Babes|Passion[._ -]?HD|Evil[._ -]?Angel|Private|Hustler|Sweet[._ -]?Sinner|New[._ -]?Sensations|Wicked|Penthouse|Playboy|Kink|Arch[._ -]?Angel|Elegant[._ -]?Angel|Zero[._ -]?Tolerance|Score|Colette|Bang|Holed|Lubed|Showy[._ -]?Beauty|My[._ -]?First|Toy|Casting|Couch|Compilation|Bound';

    /** Adult vocabulary that can corroborate a separate ambiguous studio term. */
    public const string ADULT_KEYWORDS = 'Anal|Ass|BBW|BDSM|Blow|Boob|Bukkake|Casting|Couch|Cock|Compilation|Creampie|Cum|Dick|Dildo|Facial|Fetish|Fuck|Gang|Hardcore|Homemade|Horny|Interracial|Lesbian|MILF|Masturbat|Nympho|Oral|Orgasm|Penetrat|Pornstar|POV|Pussy|Riding|Seduct|Sex|Shaved|Slut|Squirt|Suck|Swallow|Threesome|Tits|Titty|Toy|Virgin|Whore';

    /** Explicit XXX tags and unambiguous studio names are always adult. */
    private const string HARD_ADULT_MARKER_REGEX = '/\b(XXX|Porn|OnlyFans|Creampie|MP4-XXX|JAV|Hentai|DivineBitches|Device[._ -]?Bondage|Hogtied|Wired[._ -]?Pussy|Fucking[._ -]?Machines|Ultimate[._ -]?Surrender|Public[._ -]?Disgrace|Sex[._ -]?And[._ -]?Submission|Bound[._ -]?Gang[._ -]?Bangs|Electro[._ -]?Sluts|Whipped[._ -]?Ass|TS[._ -]?Seduction|Infernal[._ -]?Restraints|Sexually[._ -]?Broken|'.self::UNAMBIGUOUS_ADULT_STUDIOS.')\b/i';

    private const string ADULT_RELEASE_GROUP_REGEX = '/\b(?:MP4|MOV)-(KTR|GUSH|FaiLED|SEXORS|hUSHhUSH|YAPG|WRB|NBQ|FETiSH)\b/i';

    private const string ADULT_NEWSGROUP_REGEX = '/(?:^|\.)(?:erotica|xxx)(?:\.|$)/i';

    /**
     * Unambiguous adult trigger words: always adult, no video marker required.
     *
     * "cuckold" and "deepthroat" match as substrings (SubmissiveCuckolds,
     * Deepthroating); "cum" only matches as a standalone token so that
     * "document", "circumstance" and "cumulative" stay clean.
     */
    private const string HARD_ADULT_TRIGGER_REGEX = '/cuckold|deepthroat|(?:^|[^a-z0-9])cum(?:$|[^a-z0-9])/i';

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
     * Check a name for an unambiguous adult trigger word.
     *
     * Exposed so the XXX categorizer can share the one definition without the
     * pattern itself leaking out of this class.
     */
    public static function hasHardAdultTrigger(string $name): bool
    {
        return preg_match(self::HARD_ADULT_TRIGGER_REGEX, $name) === 1;
    }

    /**
     * Check for adult vocabulary after removing every ambiguous studio term.
     */
    public static function hasIndependentAdultKeyword(string $name): bool
    {
        $nameWithoutAmbiguousTerms = preg_replace(
            '/\b(?:'.self::AMBIGUOUS_ADULT_TERMS.')\b/i',
            ' ',
            $name,
        ) ?? $name;

        return preg_match('/\b(?:'.self::ADULT_KEYWORDS.')\b/i', $nameWithoutAmbiguousTerms) === 1;
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
            || self::hasHardAdultTrigger($this->releaseName)) {
            return true;
        }

        if ($this->hasCorroboratedAmbiguousAdultTerm()) {
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

    /**
     * An ordinary-word studio/list entry is adult only with separate evidence.
     */
    public function hasCorroboratedAmbiguousAdultTerm(): bool
    {
        if (! preg_match('/\b('.self::AMBIGUOUS_ADULT_TERMS.')\b/i', $this->releaseName)) {
            return false;
        }

        if (preg_match('/\bXXX\b/i', $this->releaseName)
            || preg_match(self::ADULT_RELEASE_GROUP_REGEX, $this->releaseName)
            || preg_match(self::ADULT_NEWSGROUP_REGEX, $this->groupName)
            || $this->hasAmbiguousStudioDatePerformerShape()) {
            return true;
        }

        return self::hasIndependentAdultKeyword($this->releaseName);
    }

    private function hasAmbiguousStudioDatePerformerShape(): bool
    {
        return preg_match(
            '/^('.self::AMBIGUOUS_ADULT_TERMS.')[._ -](?:19|20)?\d{2}[._ -]\d{2}[._ -]\d{2}[._ -][A-Za-z]+[._ -][A-Za-z]+(?:[._ -]|$)/i',
            $this->releaseName,
        ) === 1;
    }
}
