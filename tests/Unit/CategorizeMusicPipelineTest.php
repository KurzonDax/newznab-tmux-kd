<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Music categorization through the whole pipeline, where every release name
 * is cleaned by the obfuscated-subject extractor before the categorizers run:
 * the dots and underscores of a scene name reach them as spaces.
 */
final class CategorizeMusicPipelineTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        ProductionTables::fromAuthority()->create('usenet_groups', [
            'id',
            'name',
            'route_obfuscated_names',
            'obfuscated_default_root_categories_id',
            'forced_root_categories_id',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spamSubjectsWithAYearLikeNumber(): array
    {
        return [
            'alldebrid spam subject' => ['alldebrid-1907-Darkibox for Hydracker.com'],
            'debridlink spam subject' => ['debridlink-2008-Darkibox for Hydracker.com'],
        ];
    }

    #[DataProvider('spamSubjectsWithAYearLikeNumber')]
    public function test_spam_subject_with_a_year_like_number_is_not_in_the_music_root(string $name): void
    {
        $this->assertNotSame(Category::MUSIC_ROOT, Category::rootCategoryFor($this->categoryFor($name)));
    }

    public function test_scene_shaped_name_ending_in_a_domain_is_not_in_the_music_root(): void
    {
        $this->assertNotSame(
            Category::MUSIC_ROOT,
            Category::rootCategoryFor($this->categoryFor('sharehost-2008-Mirrorbox.Filedepot.com')),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wholeNameSceneReleases(): array
    {
        return [
            'one-word artist and album' => ['artist-album-2019-group'],
            'underscores between words' => ['Some_Artist-Some_Album-2019-GRP'],
            'dots between words' => ['Some.Artist-Some.Album-2019-GRP'],
        ];
    }

    #[DataProvider('wholeNameSceneReleases')]
    public function test_whole_name_scene_release_is_still_mp3(string $name): void
    {
        $this->assertSame(Category::MUSIC_MP3, $this->categoryFor($name));
    }

    private function categoryFor(string $releaseName): int
    {
        return (new CategorizationService)->determineCategory(0, $releaseName)['categories_id'];
    }
}
