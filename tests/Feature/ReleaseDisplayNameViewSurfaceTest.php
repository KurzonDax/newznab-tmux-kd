<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The display name is a read-path concern spread across many list views, so the
 * surface itself is pinned here: user-facing views must go through the shared
 * fallback helper, and admin views must keep showing the raw searchname.
 */
final class ReleaseDisplayNameViewSurfaceTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function userFacingViewProvider(): array
    {
        return [
            'browse and search list rows' => ['components/release-results.blade.php'],
            'cover listings' => ['components/cover-release-list.blade.php'],
            'release details heading' => ['details/index.blade.php'],
            'details cover actions' => ['details/partials/cover-actions.blade.php'],
            'nfo viewer heading' => ['nfo/view.blade.php'],
            'cart' => ['cart/index.blade.php'],
            'adult listings' => ['xxx/index.blade.php'],
            'movie cards' => ['movies/partials/movie-card.blade.php'],
            'movie release rows' => ['movies/partials/release-item.blade.php'],
            'single movie page' => ['movies/viewmoviefull.blade.php'],
            'tv season listings' => ['series/partials/season-content.blade.php'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminViewProvider(): array
    {
        return [
            'release manager' => ['admin/releases/index.blade.php'],
            'failed releases' => ['admin/releases/failed.blade.php'],
            'release edit' => ['admin/releases/edit.blade.php'],
            'release reports' => ['admin/release-reports/index.blade.php'],
        ];
    }

    #[DataProvider('userFacingViewProvider')]
    public function test_user_facing_views_render_the_display_name_with_a_fallback(string $view): void
    {
        $blade = $this->readView($view);

        $this->assertStringContainsString('release_display_name(', $blade);
        $this->assertDoesNotMatchRegularExpression(
            '/\{\{\s*(?:strtolower\()?\$\w+(?:->\w+)*->searchname/',
            $blade,
            $view.' still prints a raw searchname; route it through release_display_name().'
        );
    }

    #[DataProvider('adminViewProvider')]
    public function test_admin_views_keep_showing_the_raw_searchname(string $view): void
    {
        $blade = $this->readView($view);

        $this->assertStringContainsString('searchname', $blade);
        $this->assertStringNotContainsString('release_display_name(', $blade);
    }

    public function test_the_helper_is_registered(): void
    {
        $this->assertTrue(function_exists('release_display_name'));
        $this->assertSame(
            'Some Release Name',
            release_display_name((object) ['searchname' => 'Some.Release.Name', 'display_name' => 'Some Release Name'])
        );
        $this->assertSame(
            'Some.Release.Name',
            release_display_name((object) ['searchname' => 'Some.Release.Name', 'display_name' => null])
        );
    }

    private function readView(string $path): string
    {
        $blade = file_get_contents(resource_path('views/'.$path));
        $this->assertIsString($blade, $path.' could not be read.');

        return $blade;
    }
}
