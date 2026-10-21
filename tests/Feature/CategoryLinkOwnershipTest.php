<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\CategoryLinkOwnership;
use App\Services\Categorization\ReleaseRecategorizer;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NameWriteTables;
use Tests\TestCase;

/** A category move clears only the links the category it leaves owns (#1120, decision 5). */
class CategoryLinkOwnershipTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use NameWriteTables;

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createNameWriteTables();
        Search::shouldReceive('updateRelease')->andReturnTrue();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return iterable<string, array{int, int, array<string, int|null>}> */
    public static function moves(): iterable
    {
        yield 'TV HD to TV Other keeps the show' => [Category::TV_HD, Category::TV_OTHER, []];
        yield 'Movies to Other Misc drops the movie' => [Category::MOVIE_HD, Category::OTHER_MISC, ['movieinfo_id' => null, 'imdbid' => null]];
        yield 'TV HD to TV Anime drops the show and keeps AniDB' => [Category::TV_HD, Category::TV_ANIME, ['videos_id' => 0, 'tv_episodes_id' => 0]];
        yield 'TV Anime to TV HD drops AniDB' => [Category::TV_ANIME, Category::TV_HD, ['anidbid' => null]];
        yield 'audiobook to books keeps the book and drops the music' => [Category::MUSIC_AUDIOBOOK, Category::BOOKS_EBOOK, ['musicinfo_id' => null]];
        yield 'PC games to Other drops the game' => [Category::PC_GAMES, Category::OTHER_MISC, ['gamesinfo_id' => 0]];
        yield 'console to Other drops the console game' => [Category::GAME_OTHER, Category::OTHER_MISC, ['consoleinfo_id' => null]];
    }

    /** @param array<string, int|null> $cleared */
    #[DataProvider('moves')]
    public function test_recategorizing_clears_the_links_the_old_category_owned(int $from, int $to, array $cleared): void
    {
        $id = $this->linkedRelease(1, ['categories_id' => $from]);

        $this->assertSame(1, $this->recategorizer($to)->refileCategory($from));

        $this->assertSame($to, (int) $this->releaseRow($id)['categories_id']);
        $this->assertSame($this->links($cleared), $this->linksOf($id));
        $this->assertSame($cleared, CategoryLinkOwnership::clearedLinks($from, $to));
    }

    private function recategorizer(int $pipelineCategory): ReleaseRecategorizer
    {
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldReceive('determineCategory')->andReturn(['categories_id' => $pipelineCategory]);
        $recategorizer = new ReleaseRecategorizer;
        (new ReflectionProperty(ReleaseRecategorizer::class, 'categorization'))->setValue($recategorizer, $categorization);

        return $recategorizer;
    }
}
