<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AdultBrowseNavigationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->withoutVite();
        Cache::flush();
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);

        Schema::create('content', function (Blueprint $table): void {
            $table->id();
            $table->integer('status');
            $table->integer('contenttype');
            $table->integer('ordinal');
        });
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_adult_browse_does_not_offer_a_cover_view_that_is_a_table(): void
    {
        $this->blade('<x-view-toggle current-view="list" covgroup="xxx" category="All" />')
            ->assertDontSee('Covers')
            ->assertSee('List');

        $this->blade('<x-view-toggle current-view="list" covgroup="movies" category="All" />')
            ->assertSee('Covers')
            ->assertSee('href="'.url('/Movies').'"', false);
    }

    /** @return array<string, array{string, string}> */
    public static function categoryProvider(): array
    {
        return ['root' => ['All', '/browse/XXX'], 'subcategory' => ['HD', '/browse/XXX/HD']];
    }

    #[DataProvider('categoryProvider')]
    public function test_adult_table_links_back_to_its_release_category(string $categoryName, string $path): void
    {
        request()->query->replace(['ob' => 'size_desc', 'page' => '3', 'view' => 'covers', 't' => '6040']);

        $this->view('xxx.index', [
            'categorytitle' => '',
            'catname' => $categoryName,
            'category' => 6040,
            'results' => new LengthAwarePaginator([], 0, 25),
        ])
            ->assertSee('Browse releases')
            ->assertSee('href="'.url($path).'?ob=size_desc&amp;page=3"', false);
    }
}
