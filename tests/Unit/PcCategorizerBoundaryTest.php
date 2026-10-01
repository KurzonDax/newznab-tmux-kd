<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class PcCategorizerBoundaryTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->default('')->unique();
            $table->boolean('route_obfuscated_names')->default(false);
            $table->unsignedInteger('obfuscated_default_root_categories_id')->nullable();
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_terminal_system_indicator_categorizes_as_pc_0day(): void
    {
        $result = $this->categorize('Wondershare PDFelement Professional12 1 26 4340 Multilingual');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_leading_system_indicator_categorizes_as_pc_0day(): void
    {
        $result = $this->categorize('Windows Canva 17 1 (x64) Multilingual');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_leading_patch_does_not_override_an_equal_confidence_movie_match(): void
    {
        $result = $this->categorize('Patch.Adams.1998.MULTi.VFF.1080p.AC3.5.1.x264-Serpico');

        $this->assertSame(Category::MOVIE_HD, $result['categories_id']);
        $this->assertNotSame(Category::PC_0DAY, $result['categories_id']);
    }

    public function test_system_indicator_requires_a_complete_token(): void
    {
        $result = $this->categorize('Patchwork.Quilting.For.Beginners.2024.EPUB');

        $this->assertSame(Category::BOOKS_EBOOK, $result['categories_id']);
        $this->assertNotSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_mid_name_system_indicator_behavior_is_unchanged(): void
    {
        $result = $this->categorize('Some.Tool.v2.multilingual.incl.stuff');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_parenthesised_architecture_token_categorizes_as_pc_0day(): void
    {
        $result = $this->categorize('[1/7] - "Steinberg Cubase Elements 11.0.40 (x64).rar" yEnc');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
        $this->assertSame(0.85, $result['debug']['final_confidence']);
    }

    public function test_parenthesised_architecture_token_mid_name_categorizes_as_pc_0day(): void
    {
        $result = $this->categorize('Aiarty Video Enhancer 3.0 (x64) Multilingual');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_parenthesised_linux_stays_out_of_pc(): void
    {
        $result = $this->categorize('Jay & The Americans (Posted using Linux) Test [1/27] - "jay.and.the.americans.01.mp3" yEnc');

        $this->assertNotSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertNotSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_audio_bit_depth_beside_a_lossless_format_is_not_pc_evidence(): void
    {
        $result = $this->categorize('George_Michael-Older-2LP-32BIT-WAVPACK-1996-REETKEVER');

        $this->assertSame(Category::MUSIC_LOSSLESS, $result['categories_id']);
    }

    public function test_64bit_beside_flac_is_not_pc_evidence(): void
    {
        $result = $this->categorize('Artist-Album-64BIT-FLAC-2020-GRP');

        $this->assertSame(Category::MUSIC_LOSSLESS, $result['categories_id']);
    }

    public function test_mp3_vbr_preset_is_not_a_software_version(): void
    {
        $result = $this->categorize('Macronympha-Psych Rot-V2-TAPE-2011 MP3');

        $this->assertSame(Category::MUSIC_MP3, $result['categories_id']);
    }

    public function test_bit_token_without_an_audio_format_still_categorizes_as_pc_0day(): void
    {
        $result = $this->categorize('Wondershare.UniConverter.v15.32bit');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_version_token_without_an_audio_format_still_categorizes_as_pc_0day(): void
    {
        $result = $this->categorize('Some.Tool.v2.Setup');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_software', $result['debug']['matched_by']);
    }

    public function test_other_pc_evidence_wins_beside_an_audio_format(): void
    {
        $result = $this->categorize('MP3.Audio.Converter.v2.x64');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
    }

    public function test_audio_software_with_a_system_token_stays_pc_0day(): void
    {
        $result = $this->categorize('dBpoweramp.Music.Converter.FLAC.Edition.32bit');

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertSame('0day_system', $result['debug']['matched_by']);
    }

    /**
     * @return array<string, mixed>
     */
    private function categorize(string $releaseName): array
    {
        return (new CategorizationService)->determineCategory(0, $releaseName, debug: true);
    }
}
