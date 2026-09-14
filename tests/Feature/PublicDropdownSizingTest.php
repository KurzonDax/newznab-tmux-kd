<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class PublicDropdownSizingTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_forum_selection_containers_and_shared_form_width_intent(): void
    {
        $category = file_get_contents(resource_path('forum/blade-tailwind/views/category/show.blade.php'));
        $thread = file_get_contents(resource_path('forum/blade-tailwind/views/thread/show.blade.php'));
        preg_match('/<div class="([^"\n]+max-w-\[calc\(100vw-1rem\)\][^"\n]*)">/', $category, $panel);
        $this->assertNotEmpty($panel, 'Category selection must be viewport bounded.');
        $this->assertStringContainsString($panel[0], $thread);
        preg_match('/<select name="category_id" id="category-id" class="([^"]+)">/', $thread, $native);
        $this->assertNotEmpty($native);
        Route::get('/dropdown-sizing-fixture', static fn () => Blade::render(<<<'BLADE'
            <!DOCTYPE html><html class="public-shell"><head>@vite(['resources/css/app.css', 'resources/js/app.js', 'resources/forum/blade-tailwind/css/forum.css'])</head><body class="public-ui public-shell font-sans">
            <main class="public-page">
                <div class="{{ $panel }}" data-forum-selection><div class="p-6">
                    <x-forum::select aria-label="Forum bulk action"><option>Move selected threads</option></x-forum::select>
                    <x-forum::select aria-label="Forum destination"><option>A long fictional destination category for archived discussion</option></x-forum::select>
                    <select aria-label="Native forum destination" class="{{ $native }}"><option>A long fictional destination category for archived discussion</option></select>
                </div></div>
                <div class="account-form"><x-select aria-label="Admin form field"><option>A deliberately bounded settings field</option></x-select></div>
            </main></body></html>
            BLADE, ['panel' => $panel[1], 'native' => $native[1]]));
        $this->get('/dropdown-sizing-fixture')->assertOk()->assertSee('max-w-full')->assertSee('Admin form field')->assertSee('Native forum destination');
    }
}
