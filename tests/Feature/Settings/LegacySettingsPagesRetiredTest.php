<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The retired admin forms.
 *
 * Bookmarks and muscle memory outlive a page, so the two URLs stay routable and send the admin
 * to the hub instead of 404ing. They are permanent redirects: the forms are not coming back.
 */
final class LegacySettingsPagesRetiredTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function retiredPaths(): array
    {
        return [
            'site settings' => ['admin/site-edit'],
            'tmux settings' => ['admin/tmux-edit'],
        ];
    }

    #[DataProvider('retiredPaths')]
    public function test_a_retired_page_permanently_redirects_to_the_hub(string $path): void
    {
        // Without the admin middleware, because what is under test is the route the admin
        // reaches once past it, not the guard in front of it.
        $response = $this->withoutMiddleware()->get($path);

        $response->assertStatus(301);
        $response->assertRedirect('/admin/settings');
    }

    #[DataProvider('retiredPaths')]
    public function test_a_stale_form_posting_to_a_retired_page_is_redirected_rather_than_405(string $path): void
    {
        $response = $this->withoutMiddleware()->post($path, ['action' => 'submit']);

        $response->assertStatus(301);
        $response->assertRedirect('/admin/settings');
    }

    public function test_no_route_points_at_the_deleted_controllers(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();

            $this->assertStringNotContainsString('AdminTmuxController', $action);
            $this->assertStringNotContainsString('AdminSiteController@edit', $action);
        }
    }

    public function test_no_view_references_a_deleted_blade(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/admin/site/sections'));
        $this->assertFileDoesNotExist(resource_path('views/admin/site/edit.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/site/tmux-edit.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/site/tmux-edit-content.blade.php'));

        // Blade names the deleted views were included or returned by. Prose mentioning
        // "site-edit" is not a reference; an `admin.site.edit` view name is.
        $viewNames = ['admin.site.edit', 'admin.site.sections.', 'admin.site.tmux-edit'];
        $offenders = [];

        foreach ([resource_path('views'), app_path()] as $root) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach ($viewNames as $viewName) {
                    if (str_contains($contents, $viewName)) {
                        $offenders[] = $file->getPathname().' -> '.$viewName;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'A template or controller still names a deleted blade.');
    }
}
