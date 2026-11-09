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

    /**
     * @return array<string, array{string, int}>
     */
    public static function separatorDependentReleases(): array
    {
        return [
            'wav extension' => ['Some Artist - Some Album.wav', Category::MUSIC_LOSSLESS],
            'quoted mp3 file' => ['[1/12] - "Some Artist - Some Album.mp3" yEnc', Category::MUSIC_MP3],
            'underscored Unabridged audiobook' => ['Some_Author_-_Some_Title_Unabridged_2020', Category::MUSIC_AUDIOBOOK],
            'dotted V.A. compilation' => ['V.A. - Some Summer Hits', Category::MUSIC_OTHER],
            'film remux ending in FLAC' => ['Some.Film.1968.1080p.BluRay.Remux.AVC.FLAC', Category::MOVIE_HD],
        ];
    }

    #[DataProvider('separatorDependentReleases')]
    public function test_shapes_that_need_posted_separators_reach_their_category(string $name, int $expected): void
    {
        $this->assertSame($expected, $this->categoryFor($name));
    }

    private function categoryFor(string $releaseName): int
    {
        return (new CategorizationService)->determineCategory(0, $releaseName)['categories_id'];
    }
}
