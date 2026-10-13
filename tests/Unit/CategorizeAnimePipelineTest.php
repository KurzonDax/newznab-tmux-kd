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
 * Anime categorization through the whole pipeline, where every release name
 * is cleaned by the obfuscated-subject extractor before the categorizers run.
 */
final class CategorizeAnimePipelineTest extends TestCase
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
    public static function fansubGroupTaggedEpisodes(): array
    {
        return [
            'Erai-raws episode with subtitle tag and hash' => [
                '[Erai-raws] Jujutsu Kaisen 2nd Season - 14 [1080p][Multiple Subtitle][3967D3CA]',
            ],
            'Erai-raws episode with a dash in the title' => [
                '[Erai-raws] Uma Musume - Pretty Derby Season 3 - 13 [1080p][Multiple Subtitle][B921C4CB]',
            ],
            'SubsPlease episode with file extension' => [
                '[SubsPlease] Jujutsu Kaisen - 47 (1080p) [ABC12345].mkv',
            ],
        ];
    }

    #[DataProvider('fansubGroupTaggedEpisodes')]
    public function test_episode_with_leading_fansub_group_tag_is_tv_anime(string $name): void
    {
        $this->assertSame(Category::TV_ANIME, $this->categoryFor($name));
    }

    public function test_known_fansub_group_tag_alone_is_enough_without_a_hash(): void
    {
        $this->assertSame(Category::TV_ANIME, $this->categoryFor('[Erai-raws] Hoshi Tabibito - 08 [1080p]'));
    }

    public function test_fansub_hash_shape_with_an_unlisted_group_is_tv_anime(): void
    {
        $this->assertSame(
            Category::TV_ANIME,
            $this->categoryFor('[Kumo-Fansubs] Hoshi Tabibito - 05 [720p][89ABCDEF]'),
        );
    }

    public function test_artist_title_video_without_a_bracket_tag_stays_music_video(): void
    {
        $this->assertSame(Category::MUSIC_VIDEO, $this->categoryFor('Some Artist - Some Title 1080p'));
    }

    private function categoryFor(string $releaseName): mixed
    {
        return (new CategorizationService)->determineCategory(0, $releaseName)['categories_id'];
    }
}
