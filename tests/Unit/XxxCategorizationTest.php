<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\XxxCategorizer;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class XxxCategorizationTest extends TestCase
{
    /**
     * @return list<object>
     */
    private function buildPipes(): array
    {
        return [
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
        ];
    }

    private function runPipeline(string $releaseName, string $groupName = ''): CategorizationPassable
    {
        $context = new ReleaseContext(
            releaseName: $releaseName,
            groupId: 0,
            groupName: $groupName,
            poster: '',
        );

        $passable = new CategorizationPassable($context, debug: true);
        $pipes = $this->buildPipes();

        foreach ($pipes as $pipe) {
            $passable = $pipe->handle($passable, fn ($p) => $p);
        }

        return $passable;
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function vrReleasesProvider(): array
    {
        return [
            'StockingsVR GearVR release' => [
                'StockingsVR.com.Lady.Lyne.You.Can.Watch.with.DD.Cup.Lady.Lyne.GearVR.1080p',
                Category::XXX_VR,
                'vr_site',
            ],
            'Known VR site with VR180' => [
                'SexBabesVR.23.10.20.Virtual.Reality.VR180.3D.SBS.2160p-VRSins',
                Category::XXX_VR,
                'vr_site',
            ],
            'Generic VR site with GearVR' => [
                'SomeNewSiteVR.com.Performer.GearVR.1080p',
                Category::XXX_VR,
                'vr_site_generic',
            ],
            'VR device with known studio' => [
                'Brazzers.24.01.15.Performer.Oculus.Quest3.1080p',
                Category::XXX_VR,
                'vr_device',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function nonVrReleasesProvider(): array
    {
        return [
            'Regular movie release' => [
                'Some.Movie.2024.1080p.BluRay.x264-GROUP',
                Category::MOVIE_HD,
            ],
        ];
    }

    #[DataProvider('vrReleasesProvider')]
    public function test_vr_releases_are_categorized_as_xxx_vr(string $releaseName, int $expectedCategoryId, string $expectedMatchedBy): void
    {
        $categorizer = new XxxCategorizer;
        $context = new ReleaseContext(releaseName: $releaseName, groupId: 0);
        $result = $categorizer->categorize($context);

        $this->assertTrue($result->isSuccessful(), "Expected successful match for: {$releaseName}");
        $this->assertSame($expectedCategoryId, $result->categoryId, "Wrong category for: {$releaseName}");
        $this->assertSame($expectedMatchedBy, $result->matchedBy, "Wrong matched_by for: {$releaseName}");
    }

    #[DataProvider('vrReleasesProvider')]
    public function test_vr_releases_through_full_pipeline(string $releaseName, int $expectedCategoryId, string $expectedMatchedBy): void
    {
        $passable = $this->runPipeline($releaseName);

        $this->assertSame($expectedCategoryId, $passable->bestResult->categoryId, "Pipeline wrong category for: {$releaseName}");
        $this->assertSame($expectedMatchedBy, $passable->bestResult->matchedBy, "Pipeline wrong matched_by for: {$releaseName}");
    }

    #[DataProvider('nonVrReleasesProvider')]
    public function test_non_vr_releases_are_not_categorized_as_xxx_vr(string $releaseName, int $expectedCategoryId): void
    {
        $passable = $this->runPipeline($releaseName);

        $this->assertNotSame(Category::XXX_VR, $passable->bestResult->categoryId, "Should not be XXX VR: {$releaseName}");
        $this->assertSame($expectedCategoryId, $passable->bestResult->categoryId, "Wrong category for: {$releaseName}");
    }

    public function test_lady_lyne_dotted_name_is_recognized_as_adult(): void
    {
        $categorizer = new XxxCategorizer;
        $context = new ReleaseContext(
            releaseName: 'Lady.Lyne.24.01.15.Performer.Title.1080p',
            groupId: 0,
        );
        $result = $categorizer->categorize($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(Category::XXX_CLIPHD, $result->categoryId);
    }

    public function test_lady_and_lyne_as_separate_tokens_do_not_trigger_xxx(): void
    {
        $categorizer = new XxxCategorizer;
        $context = new ReleaseContext(
            releaseName: 'The.Lady.and.the.Lyne.2022.1080p.WEB-DL.x264-GROUP',
            groupId: 0,
        );
        $result = $categorizer->categorize($context);

        $this->assertFalse($result->isSuccessful());
    }

    /**
     * Adult releases that must never fall through to a non-XXX category (#135).
     *
     * @return array<string, array{0: string}>
     */
    public static function adultReleaseProvider(): array
    {
        return [
            'club sweethearts 540p ice cream van' => ['ClubSweethearts.2016.11.24.Alexis.fucked.in.the.back.of.an.ice.cream.van-Alexis.Love.540p'],
            'club sweethearts 540p ice cream truck' => ['ClubSweethearts.2016.12.17.Kylee.Reese.banged.in.ice.cream.truck-Kylee.Reese.540p'],
            'cuckold sessions' => ['Cuckold.Sessions.Sadie.Summers.2026.07.11.2160p.mp4'],
            'cuckold inside a larger word' => ['SubmissiveCuckolds - Lexi Noir - Update 01.08.2023 2160p'],
            'deepthroat' => ['WB.21063.Candee.Licious.Her.First.Deepthroat.Apr.12.2026.720p.mp4'],
            'standalone cum without a video marker' => ['(Porn Video) Rough Sex Cum In Pussy Compilation Erotic Porn'],
            'deepthroating substring' => ['SomeSite.24.03.02.Performer.Deepthroating.Practice.480p'],
            'hookup hotshot studio' => ['HookupHotshot - 2020 Flashback Highlight Compilation'],
        ];
    }

    #[DataProvider('adultReleaseProvider')]
    public function test_adult_releases_resolve_to_an_xxx_category(string $releaseName): void
    {
        $passable = $this->runPipeline($releaseName, 'alt.binaries.multimedia');
        $categoryId = $passable->bestResult->categoryId;

        $this->assertSame(
            Category::XXX_ROOT,
            intdiv($categoryId, 1000) * 1000,
            "'{$releaseName}' resolved to {$categoryId} via {$passable->bestResult->matchedBy}"
        );
    }

    /**
     * Names that must never be dragged into XXX by the hard trigger words (#135).
     *
     * @return array<string, array{0: string}>
     */
    public static function nonAdultReleaseProvider(): array
    {
        return [
            'classic movie' => ['The.Shawshank.Redemption.1994.1080p.BluRay.x264'],
            'cumulative windows update' => ['Cumulative.Update.Windows.11.KB5043080'],
            'circumstance movie' => ['Circumstance.2011.1080p.WEB-DL'],
            'document substring' => ['Document.Everything.2023.720p'],
        ];
    }

    #[DataProvider('nonAdultReleaseProvider')]
    public function test_non_adult_releases_never_resolve_to_xxx(string $releaseName): void
    {
        $passable = $this->runPipeline($releaseName, 'alt.binaries.multimedia');
        $categoryId = $passable->bestResult->categoryId;

        $this->assertNotSame(
            Category::XXX_ROOT,
            intdiv($categoryId, 1000) * 1000,
            "'{$releaseName}' resolved to {$categoryId} via {$passable->bestResult->matchedBy}"
        );
    }

    public function test_low_resolution_studio_clip_is_categorized_as_clip_sd(): void
    {
        $categorizer = new XxxCategorizer;
        $context = new ReleaseContext(
            releaseName: 'ClubSweethearts.2016.11.24.Alexis.fucked.in.the.back.of.an.ice.cream.van-Alexis.Love.540p',
            groupId: 0,
        );
        $result = $categorizer->categorize($context);

        $this->assertSame(
            [Category::XXX_CLIPSD, 'clip_sd_low_res'],
            [$result->categoryId, $result->matchedBy],
        );
    }

    public function test_adult_positive_name_without_a_subcategory_falls_back_to_xxx_other(): void
    {
        $categorizer = new XxxCategorizer;
        $context = new ReleaseContext(
            releaseName: 'SubmissiveCuckolds - Lexi Noir - Update 01.08.2023 2160p',
            groupId: 0,
        );
        $result = $categorizer->categorize($context);

        $this->assertSame(
            [Category::XXX_OTHER, 0.75, 'xxx_fallback'],
            [$result->categoryId, $result->confidence, $result->matchedBy],
        );
    }

    public function test_names_without_adult_signals_still_produce_no_match(): void
    {
        $categorizer = new XxxCategorizer;
        $context = new ReleaseContext(
            releaseName: 'The.Shawshank.Redemption.1994.1080p.BluRay.x264',
            groupId: 0,
        );

        $this->assertFalse($categorizer->categorize($context)->isSuccessful());
    }
}
