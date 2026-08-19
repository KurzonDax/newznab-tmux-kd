<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\ConsolePipe;
use App\Services\Categorization\Pipes\GroupNamePipe;
use App\Services\Categorization\Pipes\MiscPipe;
use App\Services\Categorization\Pipes\MiscSafetyNetPipe;
use App\Services\Categorization\Pipes\MoviePipe;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\Pipes\PcPipe;
use App\Services\Categorization\Pipes\TvPipe;
use App\Services\Categorization\Pipes\XxxPipe;
use App\Services\Categorization\ReleaseContext;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\NzbSplitUnwrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Full-pipeline regressions for production releases that were misfiled into
 * Other/Hashed or Other/Misc (GitHub issues #59, #60, #61, #62).
 *
 * Names are pre-processed exactly as CategorizationPipeline::categorize() does
 * (NZB-split unwrapping + obfuscated-subject extraction) so the pipes see the
 * same input as production.
 */
class CategorizationFalsePositiveRegressionTest extends TestCase
{
    private function runPipeline(string $releaseName, string $groupName): CategorizationPassable
    {
        $releaseName = (new NzbSplitUnwrapper)->unwrap($releaseName) ?? $releaseName;
        $releaseName = (new ObfuscatedSubjectExtractor)->extract($releaseName) ?? $releaseName;

        $context = new ReleaseContext(
            releaseName: $releaseName,
            groupId: 0,
            groupName: $groupName,
        );

        $passable = new CategorizationPassable($context, debug: true);

        foreach ([
            new MiscPipe,
            new GroupNamePipe,
            new XxxPipe,
            new TvPipe,
            new MoviePipe,
            new BookPipe,
            new MusicPipe,
            new PcPipe,
            new ConsolePipe,
            new MiscSafetyNetPipe,
        ] as $pipe) {
            $passable = $pipe->handle($passable, fn (CategorizationPassable $p): CategorizationPassable => $p);
        }

        return $passable;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function yearOnlyMovieReleaseProvider(): array
    {
        return [
            'production subject' => ['[1/3] - "Demon.Slayer.Kimetsu.no.Yaiba.Infinity.Castle.2025.1.mkv" yEnc'],
            'normalized release name' => ['Demon.Slayer.Kimetsu.no.Yaiba.Infinity.Castle.2025.1'],
        ];
    }

    #[DataProvider('yearOnlyMovieReleaseProvider')]
    public function test_year_only_movie_release_uses_low_confidence_fallback(string $name): void
    {
        $result = $this->runPipeline($name, 'alt.binaries.multimedia')->bestResult;

        $this->assertSame(
            [Category::MOVIE_OTHER, 'year_only_movie', 0.5],
            [$result->categoryId, $result->matchedBy, $result->confidence],
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function yearOnlyMovieTitleProvider(): array
    {
        return [
            'dot-separated title and year' => ['Some.Film.Title.2024'],
            'space-separated title with parenthesized year' => ['Some Film Title (2024)'],
            'dot-separated title with part number' => ['Some.Film.Title.2025.12'],
        ];
    }

    #[DataProvider('yearOnlyMovieTitleProvider')]
    public function test_year_only_movie_fallback_accepts_supported_title_shapes(string $name): void
    {
        $result = $this->runPipeline($name, 'alt.binaries.multimedia')->bestResult;

        $this->assertSame(
            [Category::MOVIE_OTHER, 'year_only_movie', 0.5],
            [$result->categoryId, $result->matchedBy, $result->confidence],
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function yearOnlyMovieExclusionProvider(): array
    {
        return [
            'season and episode after year' => ['Some.Show.2024.S01E03'],
            'episode after year' => ['Some.Show.2024.E12'],
            'season before year' => ['Some.Show.S02.2024'],
            'single title token' => ['Video.2024'],
            'year only' => ['2024'],
            'adult marker' => ['Brazzers.Some.Title.2024'],
        ];
    }

    #[DataProvider('yearOnlyMovieExclusionProvider')]
    public function test_year_only_movie_fallback_rejects_excluded_names(string $name): void
    {
        $result = $this->runPipeline($name, 'alt.binaries.multimedia')->bestResult;

        $this->assertNotSame('year_only_movie', $result->matchedBy);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function yearOnlyMovieGroupPrecedenceProvider(): array
    {
        return [
            'music group' => ['Some.Album.Title.2024', 'alt.binaries.mp3', 'group_name_music'],
            'movie group' => ['Some.Film.Title.2024', 'alt.binaries.movies', 'group_name_movie'],
        ];
    }

    #[DataProvider('yearOnlyMovieGroupPrecedenceProvider')]
    public function test_group_name_match_outranks_year_only_movie_fallback(
        string $name,
        string $groupName,
        string $expectedMatchedBy,
    ): void {
        $result = $this->runPipeline($name, $groupName)->bestResult;

        $this->assertSame([0.6, $expectedMatchedBy], [$result->confidence, $result->matchedBy]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function expectedCategoryProvider(): array
    {
        return [
            // #129 — music-video keywords must be complete tokens
            'tourist bluray' => ['The.Tourist.2010.1080p.BluRay.x264-OFT', 'alt.binaries.multimedia', Category::MOVIE_HD],
            'tourist web-dl' => ['The.Tourist.2010.1080p.AMZN.WEB-DL.Multi.DDP.5.1.H.265-JeRi', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'mv underscore handle' => ['[1/3] - "@MV_WRLD ~ Shanghai Noon (2000) 1080p 10bit Bluray x265 HEVC [DD 2.0 Tamil + DD 5.1 English] ESubs.mkv"', 'alt.binaries.multimedia', Category::MOVIE_HD],
            'mv release group suffix' => ['Tanner.Hall.Forever.1080p.WEB-DL.H.264-MV', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],

            // #130 — audio bitrate tags on video releases are not MP3 signals
            'asianet movie audio bitrate' => ['Chess.2006.AsianetMoviesHD.WEB.DL.H264.AAC2.0.192k-DDH', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'yearless asianet movie audio bitrate' => ['Snehaveedu.1080p.AsianetMoviesHD.WEB.DL.H264.AAC2.0.192k-DDH', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'dsnp movie 192k audio' => ['Kaathuvaakula.Rendu.Kaadhal.2022.HQ.1080.DSNP.WEB.DL.H264.DDP5.1.192k-DDH', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'dsnp movie 768k audio' => ['Sookshmadarshini.2024.1080p.DSNP.WEB.DL.H264.DDP5.1.Atmos.768k-DDH', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],

            // #131 — strong movie formats take precedence over music keywords
            'grand tour film' => ['Grand.Tour.2024.1080p.MUBI.WEB-DL.DDP5.1.H.264-PMi', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'end of the tour film' => ['The.End.of.the.Tour.2015.1080p.NF.WEB-DL.DDP5.1.H.264-PrimeFix', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'foreign tour film' => ['La.Tour.Montparnasse.Infernale.2001.FRENCH.1080p.HDLight.x264.AC3-EXTREME', 'alt.binaries.multimedia', Category::MOVIE_FOREIGN],
            'festival dvdrip film' => ['Reckless.2014.FESTiVAL.DVDRip.x264-EXViD', 'alt.binaries.multimedia', Category::MOVIE_HD],
            'film festival web-dl' => ['Dhuin.2022.1080p.Mumbai.Film.Festival.WEB-DL.AAC2.0.x264-SPECT3R', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'eras tour concert film' => ['Taylor.Swift.The.Eras.Tour.2023.EXTENDED.1080p.WEBRip.x264.AAC5.1-YTS', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'guts tour concert film' => ['Olivia.Rodrigo.GUTS.World.Tour.2024.720p.NF.WEB-DL.DDP5.1.Atmos.H264-Telly', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'mtv unplugged dvd film' => ['Pearl.Jam.MTV.Unplugged.1992.NTSC.DVD.REMUX.DD5.1.mkv', 'alt.binaries.multimedia', Category::MOVIE_DVD],

            // #59 — hyphen-chained tag suffix is not a base64 hash
            'hyphen chain 2160p movie' => ['Predator.Badlands.2025.2160p.AMZN.HDR.WEB-DL.DDP.5.1.H.265-poke--ZINKMOVIES-English-ZINKMOVIES', 'alt.binaries.multimedia', Category::MOVIE_UHD],
            'hyphen chain 1080p movie' => ['Trending.2025.UNCUT.1080p.WEB-DL..2.0-.5.1.ESub.x264--Hindi-Tamil-ZINKMOVIES', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],
            'hyphen chain spaced 1080p movie' => ['Tavvai 2026 1080p JIOHS WEB-DL DDP5 1 ESub x264---ZINKMOVIES-Hindi-ZINKMOVIES', 'alt.binaries.multimedia', Category::MOVIE_WEBDL],

            // #60 — Title Case names are not gibberish
            'title case erotica filename' => ['My Wife Is In Heat.avi', 'alt.binaries.multimedia.erotica.amateur', Category::XXX_OTHER],
            'title case erotica subject 1' => ['ABMEA JHO A TTP Watching My Wife Getting Fucked 29 [00/71] - "Watching My Wife Getting Fucked 29.nzb"', 'alt.binaries.multimedia.erotica.amateur', Category::XXX_OTHER],
            'title case erotica subject 2' => ['ABMEA JHO A TTP Giving My Wife Cock [00/47] - "Giving My Wife Cock.nzb"', 'alt.binaries.multimedia.erotica.amateur', Category::XXX_OTHER],
            'title case plain words erotica' => ['Some Random Title Here', 'alt.binaries.multimedia.erotica', Category::XXX_OTHER],
            'title case tv subject' => ['[1/3] - "Csi Cyber - S02E15 - .mkv"', 'alt.binaries.multimedia', Category::TV_OTHER],

            // #61 — a weak adult keyword must not veto a clear TV structure
            'south park anal probe' => ['South.Park.S01E01.Cartman.Gets.an.Anal.Probe.1080p.TrueHD.5.1.AVC.REMUX-FraMeSToR', 'alt.binaries.multimedia', Category::TV_HD],
            'teen titans 1080p' => ['Teen.Titans.S02E03.Terra.1080p.BluRay.REMUX.AVC.DTS-HD.MA.2.0-EPSiLON', 'alt.binaries.multimedia', Category::TV_HD],
            'teen titans 720p' => ['Teen.Titans.S02E03.Terra.720p.BluRay-EPSiLON', 'alt.binaries.multimedia', Category::TV_HD],
            'studio name still xxx' => ['Brazzers.24.01.01.Name.XXX.1080p.MP4-XXX', 'alt.binaries.multimedia', Category::XXX_CLIPHD],

            // #68 — hard adult markers must survive an episodic token
            'adult studio episodic release' => ['Brazzers.S01E01.Name.XXX.1080p.MP4-XXX', 'alt.binaries.multimedia', Category::XXX_CLIPHD],
            'studio-less episodic control' => ['Show.Name.S01E01.1080p.WEB-DL-GROUP', 'alt.binaries.multimedia', Category::TV_WEBDL],
            'ambiguous adult studio word remains tv' => ['Private.Eyes.S01E01.1080p.WEB-DL-GROUP', 'alt.binaries.multimedia', Category::TV_WEBDL],

            // #62 — chained episode numbers
            'multi episode S11E46E47' => ['SpongeBob.SquarePants.S11E46E47.The.Grill.is.Gone.The.Night.Patty.1080p.AMZN.WEB-DL.DDP2.0.H.264-TVSmash', 'alt.binaries.multimedia', Category::TV_WEBDL],
            'multi episode S01E01-E02' => ['Show.Name.S01E01-E02.Pilot.1080p.WEB-DL.H.264-GROUP', 'alt.binaries.multimedia', Category::TV_WEBDL],
            'multi episode S01E01E02E03' => ['Show.Name.S01E01E02E03.720p.HDTV.x264-GROUP', 'alt.binaries.multimedia', Category::TV_HD],
            'multi episode plain' => ['Show.Name.S01E01E02.Some.Title-GROUP', 'alt.binaries.multimedia', Category::TV_OTHER],
        ];
    }

    #[DataProvider('expectedCategoryProvider')]
    public function test_release_resolves_to_expected_category(string $name, string $groupName, int $expected): void
    {
        $passable = $this->runPipeline($name, $groupName);

        $this->assertFalse($passable->lockedToMisc, "'$name' should not be locked to misc");
        $this->assertSame(
            $expected,
            $passable->bestResult->categoryId,
            "'$name' resolved to {$passable->bestResult->categoryId} via {$passable->bestResult->matchedBy}"
        );
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function musicPrecedenceGuardProvider(): array
    {
        return [
            'year-only movie versus compilation' => ['VA-Walk On By-2012', Category::MUSIC_OTHER],
            'year-only movie versus greatest hits' => ['MAGIC VA-More Greatest Hits Of The 80s -KMA-8CD-2000', Category::MUSIC_OTHER],
            'unformatted live festival' => ['Madeleine Peyroux - Live at Cully Jazz Festival 2012', Category::MUSIC_VIDEO],
            'single-track video' => ['Artist - Song Title 1080p', Category::MUSIC_VIDEO],
        ];
    }

    #[DataProvider('musicPrecedenceGuardProvider')]
    public function test_weak_movie_matches_do_not_take_precedence_over_music(string $name, int $expected): void
    {
        $passable = $this->runPipeline($name, 'alt.binaries.multimedia');

        $this->assertSame($expected, $passable->bestResult->categoryId);
    }

    public function test_suppressed_music_result_remains_visible_in_debug_output(): void
    {
        $passable = $this->runPipeline(
            'Grand.Tour.2024.1080p.MUBI.WEB-DL.DDP5.1.H.264-PMi',
            'alt.binaries.multimedia',
        );

        $this->assertSame(
            [Category::MUSIC_VIDEO, 0.9, 'music_video', 'movie_precedence'],
            [
                $passable->allResults['Music']['category_id'],
                $passable->allResults['Music']['confidence'],
                $passable->allResults['Music']['matched_by'],
                $passable->allResults['Music']['suppressed_by'],
            ],
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function xxxGroupNameProvider(): array
    {
        return [
            'title case erotica filename' => ['My Wife Is In Heat.avi', 'alt.binaries.multimedia.erotica.amateur'],
            'title case plain words' => ['Some Random Title Here', 'alt.binaries.multimedia.erotica'],
        ];
    }

    #[DataProvider('xxxGroupNameProvider')]
    public function test_erotica_group_names_match_via_group_name(string $name, string $groupName): void
    {
        $passable = $this->runPipeline($name, $groupName);

        $this->assertSame('group_name_xxx', $passable->bestResult->matchedBy);
    }

    /**
     * Genuine gibberish must still be caught after the Title-Case fix.
     *
     * @return array<string, array{0: string}>
     */
    public static function stillGibberishProvider(): array
    {
        return [
            'single mixed token' => ['aB3kD9zQ1mN7pR2sT5vW8x'],
            'dotted random transitions' => ['aB3c.D4eF.5gH6i.J7kL8m'],
        ];
    }

    #[DataProvider('stillGibberishProvider')]
    public function test_genuine_gibberish_is_still_hashed(string $name): void
    {
        $passable = $this->runPipeline($name, 'alt.binaries.multimedia');

        $this->assertTrue($passable->lockedToMisc);
        $this->assertSame(Category::OTHER_HASHED, $passable->bestResult->categoryId);
    }
}
