<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The retired admin forms.
 */
final class LegacySettingsPagesRetiredTest extends TestCase
{
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
