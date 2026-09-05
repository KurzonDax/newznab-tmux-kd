<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\PcCategorizer;
use App\Services\Categorization\ReleaseContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CategorizePcGameTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function installerPackageProvider(): array
    {
        return [
            'msix bundle' => ['Adobe Express Photos.Msixbundle'],
            'appx bundle' => ['Microsoft.To.Do.appxbundle'],
            'msi package' => ['Utility Installer.msi'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function parenthesisedSystemTokenProvider(): array
    {
        return [
            'trailing parenthesised architecture' => ['Steinberg Cubase Elements 11.0.40 (x64)'],
            'parenthesised architecture mid name' => ['Aiarty Video Enhancer 3.0 (x64) Multilingual'],
            'fully parenthesised keyword' => ['Some Tool 1.0 (Multilingual)'],
            'parenthesised keyword mid name' => ['Some Tool (Portable) 2.1'],
            'architecture token closing a parenthetical' => ['(MAGIX Movie Studio 2025 Platinum-x64) [07/12] - "magix.movie.studio.2025.platinum.part06.rar" yEnc'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unparenthesisedSystemTokenProvider(): array
    {
        return [
            'spaced architecture token' => ['Foo x64 Bar'],
            'dotted portable keyword' => ['Foo.Portable.2.1'],
            'trailing multilingual keyword' => ['PicPick Professional 7.4 Multilingual.rar'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPcNameProvider(): array
    {
        return [
            'movie with x264 codec tag' => ['Movie.Title.2024.1080p.BluRay.x264-GRP'],
            'episode with x264 codec tag' => ['Show.S01E01.720p.HDTV.x264-GRP'],
            'music album with a parenthesised year' => ['Artist - Album (2024) [FLAC]'],
            'parenthesised linux in prose' => ['Jay & The Americans (Posted using Linux) Test'],
        ];
    }

    private PcCategorizer $categorizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categorizer = new PcCategorizer;
    }

    public function test_pc_game_detects_common_scene_groups(): void
    {
        $samples = [
            'Starfield-RUNE',
            'Baldurs.Gate.3-TENOKE',
            'ELDEN.RING-EMPRESS',
            'Horizon.Zero.Dawn-CODEX',
            'Cyberpunk.2077-GOG',
            'Forza.Horizon.5-ElAmigos',
            'The.Witcher.3.Wild.Hunt-PLaza',
            'Resident.Evil.4.Remake-FITGIRL',
            'Red.Dead.Redemption.2-DODI',
            'Some.Game-SKiDROW',
        ];

        foreach ($samples as $name) {
            $context = new ReleaseContext(
                releaseName: $name,
                groupId: 0,
                groupName: '',
                poster: ''
            );
            $result = $this->categorizer->categorize($context);
            $this->assertTrue($result->isSuccessful(), "Expected PC game match for: $name");
            $this->assertSame(Category::PC_GAMES, $result->categoryId, "Expected PC_GAMES category for: $name");
        }
    }

    public function test_pc_game_detects_keywords_and_platform_cues(): void
    {
        $samples = [
            'Awesome.Game.SteamRip',
            'Great.Game.Repack-FitGirl',
            'Indie.Title.DRM-Free-GOG',
            'Cool.Game.PC.Game.2024',
            'Title-[PC]-Game',
        ];

        foreach ($samples as $name) {
            $context = new ReleaseContext(
                releaseName: $name,
                groupId: 0,
                groupName: '',
                poster: ''
            );
            $result = $this->categorizer->categorize($context);
            $this->assertTrue($result->isSuccessful(), "Expected PC game keyword match for: $name");
            $this->assertSame(Category::PC_GAMES, $result->categoryId, "Expected PC_GAMES category for: $name");
        }
    }

    public function test_console_and_mac_not_misclassified_as_pc_game(): void
    {
        $negatives = [
            'The.Last.of.Us.Part.I.PS5',
            'Gran.Turismo.7.PS4.CUSA12345',
            'Mario.Kart.8.Deluxe.NSW.NSP',
            'Zelda.Breath.of.the.Wild.Switch.XCI',
            'Halo.Infinite.Xbox.Series.X',
            'Gears.5.XBOXONE',
            'Some.Game.macOS.13',
        ];

        foreach ($negatives as $name) {
            $context = new ReleaseContext(
                releaseName: $name,
                groupId: 0,
                groupName: '',
                poster: ''
            );
            $result = $this->categorizer->categorize($context);
            // Either not matched, or matched to a non-PC_GAMES category (like PC_MAC)
            if ($result->isSuccessful()) {
                $this->assertNotSame(Category::PC_GAMES, $result->categoryId, "Did not expect PC_GAMES for console/Mac: $name");
            }
        }
    }

    #[DataProvider('installerPackageProvider')]
    public function test_installer_packages_are_classified_as_pc_0day(string $name): void
    {
        $context = new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: '',
            poster: ''
        );

        $result = $this->categorizer->categorize($context);

        $this->assertTrue($result->isSuccessful(), "Expected PC 0day match for installer package: $name");
        $this->assertSame(Category::PC_0DAY, $result->categoryId, "Expected PC_0DAY for installer package: $name");
        $this->assertSame('0day_msix_installer', $result->matchedBy);
    }

    #[DataProvider('parenthesisedSystemTokenProvider')]
    public function test_parenthesised_system_tokens_are_classified_as_pc_0day(string $name): void
    {
        $result = $this->categorizeName($name);

        $this->assertNotNull($result, "Expected a PC match for: $name");
        $this->assertSame(Category::PC_0DAY, $result->categoryId, "Expected PC_0DAY for: $name");
        $this->assertSame('0day_system', $result->matchedBy);
        $this->assertSame(0.85, $result->confidence);
    }

    #[DataProvider('unparenthesisedSystemTokenProvider')]
    public function test_unparenthesised_system_tokens_are_unchanged(string $name): void
    {
        $result = $this->categorizeName($name);

        $this->assertNotNull($result, "Expected a PC match for: $name");
        $this->assertSame(Category::PC_0DAY, $result->categoryId, "Expected PC_0DAY for: $name");
        $this->assertSame('0day_system', $result->matchedBy);
    }

    #[DataProvider('nonPcNameProvider')]
    public function test_parenthesis_delimiters_do_not_pull_non_pc_names_into_pc(string $name): void
    {
        $result = $this->categorizeName($name);

        $this->assertNull($result, "Did not expect any PC match for: $name");
    }

    /**
     * Run one name through the categorizer, honouring its own skip gate.
     */
    private function categorizeName(string $name): ?CategorizationResult
    {
        $context = new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: '',
            poster: ''
        );

        if ($this->categorizer->shouldSkip($context)) {
            return null;
        }

        $result = $this->categorizer->categorize($context);

        return $result->isSuccessful() ? $result : null;
    }
}
