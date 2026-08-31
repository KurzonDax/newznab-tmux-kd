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
            $table->string('name')->default('');
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

    /**
     * @return array<string, mixed>
     */
    private function categorize(string $releaseName): array
    {
        return (new CategorizationService)->determineCategory(0, $releaseName, debug: true);
    }
}
