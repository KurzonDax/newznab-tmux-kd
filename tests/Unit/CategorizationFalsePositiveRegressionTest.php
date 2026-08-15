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
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function expectedCategoryProvider(): array
    {
        return [
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
