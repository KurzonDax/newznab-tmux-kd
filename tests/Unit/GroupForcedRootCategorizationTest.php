<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\ConsolePipe;
use App\Services\Categorization\Pipes\GroupForcedRootPipe;
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

/**
 * Per-group forced root category (#136).
 */
class GroupForcedRootCategorizationTest extends TestCase
{
    private function runPipeline(string $releaseName, ?int $forcedRootCategoryId): CategorizationPassable
    {
        $context = new ReleaseContext(
            releaseName: $releaseName,
            groupId: 0,
            groupName: 'alt.binaries.erotica',
            forcedRootCategoryId: $forcedRootCategoryId,
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
            new GroupForcedRootPipe,
            new MiscSafetyNetPipe,
        ] as $pipe) {
            $passable = $pipe->handle($passable, fn (CategorizationPassable $p): CategorizationPassable => $p);
        }

        return $passable;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forcedReleaseProvider(): array
    {
        return [
            'obvious movie' => ['The.Matrix.1999.1080p.BluRay.x264'],
            'music video' => ['Artist - Song Title 1080p'],
            'tv episode' => ['Show.Name.S01E01.1080p.WEB-DL-GROUP'],
        ];
    }

    #[DataProvider('forcedReleaseProvider')]
    public function test_every_release_in_a_forced_group_lands_in_the_forced_root(string $releaseName): void
    {
        $result = $this->runPipeline($releaseName, Category::XXX_ROOT)->bestResult;

        $this->assertSame(
            [Category::XXX_OTHER, 0.95, 'group_forced_root'],
            [$result->categoryId, $result->confidence, $result->matchedBy],
        );
    }

    #[DataProvider('forcedReleaseProvider')]
    public function test_the_same_releases_are_untouched_without_a_forced_root(string $releaseName): void
    {
        $result = $this->runPipeline($releaseName, null)->bestResult;

        $this->assertNotSame('group_forced_root', $result->matchedBy);
        $this->assertNotSame(Category::XXX_ROOT, Category::rootCategoryFor($result->categoryId));
    }

    public function test_a_more_specific_category_in_the_forced_root_is_kept(): void
    {
        $result = $this->runPipeline('Brazzers.24.01.01.Name.XXX.1080p.MP4-XXX', Category::XXX_ROOT)->bestResult;

        $this->assertSame(
            [Category::XXX_CLIPHD, 'clip_hd_studio_date'],
            [$result->categoryId, $result->matchedBy],
        );
    }

    public function test_hashed_names_keep_the_hashed_behaviour(): void
    {
        $passable = $this->runPipeline('d41d8cd98f00b204e9800998ecf8427e', Category::XXX_ROOT);

        $this->assertTrue($passable->lockedToMisc);
        $this->assertSame(Category::OTHER_HASHED, $passable->bestResult->categoryId);
    }

    /**
     * Low-signal names the misc safety net locks down stay locked: like hashed
     * names, they belong to the obfuscated-routing path, not the forced root.
     */
    public function test_misc_locked_low_signal_names_keep_the_misc_lock(): void
    {
        $passable = $this->runPipeline('Zx4Qw9Lm2Pk7Rt', Category::XXX_ROOT);

        $this->assertTrue($passable->lockedToMisc);
        $this->assertSame(Category::OTHER_MISC, $passable->bestResult->categoryId);
    }

    public function test_debug_output_reports_both_the_organic_and_the_forced_result(): void
    {
        $passable = $this->runPipeline('The.Matrix.1999.1080p.BluRay.x264', Category::XXX_ROOT);

        $this->assertSame(Category::MOVIE_HD, $passable->allResults['Movie']['category_id']);
        $this->assertSame(
            [Category::XXX_OTHER, 'group_forced_root', Category::MOVIE_HD],
            [
                $passable->allResults['GroupForcedRoot']['category_id'],
                $passable->allResults['GroupForcedRoot']['matched_by'],
                $passable->allResults['GroupForcedRoot']['overrode']['category_id'],
            ],
        );
    }

    public function test_an_unknown_forced_root_is_ignored(): void
    {
        $result = $this->runPipeline('The.Matrix.1999.1080p.BluRay.x264', 9999)->bestResult;

        $this->assertSame(Category::MOVIE_HD, $result->categoryId);
    }
}
