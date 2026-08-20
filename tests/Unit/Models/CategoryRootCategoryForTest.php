<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Category;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CategoryRootCategoryForTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: ?int}>
     */
    public static function categoryProvider(): array
    {
        return [
            'xxx subcategory' => [Category::XXX_CLIPHD, Category::XXX_ROOT],
            'xxx other' => [Category::XXX_OTHER, Category::XXX_ROOT],
            'movie subcategory' => [Category::MOVIE_HD, Category::MOVIE_ROOT],
            'books unknown' => [Category::BOOKS_UNKNOWN, Category::BOOKS_ROOT],
            'root id resolves to itself' => [Category::MUSIC_ROOT, Category::MUSIC_ROOT],
            'other misc' => [Category::OTHER_MISC, Category::OTHER_ROOT],
            'other hashed' => [Category::OTHER_HASHED, Category::OTHER_ROOT],
            'other root' => [Category::OTHER_ROOT, Category::OTHER_ROOT],
            'unknown id' => [9999, null],
            'zero' => [0, null],
        ];
    }

    #[DataProvider('categoryProvider')]
    public function test_root_category_is_resolved(int $categoryId, ?int $expected): void
    {
        $this->assertSame($expected, Category::rootCategoryFor($categoryId));
    }
}
