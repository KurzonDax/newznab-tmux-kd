<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ReleaseBrowserState;
use App\Enums\ReleaseSort;
use App\Services\MovieService;
use App\Services\Releases\ReleaseBrowserMetadata;
use App\Services\Releases\ReleaseBrowserQuery;
use App\Services\Releases\TitleMetadataLoader;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The dead-code cleanup (issue #1056): what each section deleted stays deleted, and the live
 * neighbours it names are still there.
 */
final class DeadCodeCleanupTest extends TestCase
{
    public function test_section_a_deleted_files_are_gone_and_the_script_loaders_no_longer_name_them(): void
    {
        // sections 1 to 4 and 8, with the tests that only exercised them
        foreach ([
            'resources/js/alpine/components/filelist-modal.js', 'resources/js/alpine/components/filelist-modal-component.js',
            'resources/js/alpine/components/preview-modal.js', 'resources/js/alpine/components/preview-modal-component.js',
            'resources/js/alpine/components/image-modal.js', 'resources/js/alpine/components/image-modal-component.js',
            'resources/js/alpine/components/trailer-modal.js', 'resources/js/alpine/components/trailer-modal-component.js',
            'resources/js/alpine/components/fullscreen-stage.js', 'resources/js/alpine/components/file-summary-pages.js',
            'resources/js/alpine/components/sort-dropdown.js',
            'resources/views/components/report-button.blade.php', 'resources/views/components/sort-dropdown.blade.php',
            'resources/views/components/image-fullscreen-control.blade.php', 'resources/views/components/image-fullscreen-layer.blade.php',
            'resources/views/components/audio-preview-player.blade.php', 'resources/views/components/button-group.blade.php',
            'resources/views/details/files.blade.php',
            'app/View/Components/SortDropdown.php',
            'tests/js/filelist-modal.test.js', 'tests/js/image-modal.test.js', 'tests/js/preview-modal.test.js', 'tests/js/trailer-modal.test.js',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path));
        }
        // the forum's own button group is not the deleted component
        $this->assertFileExists(base_path('resources/forum/blade-tailwind/views/components/button-group.blade.php'));

        $loader = $this->source('resources/js/alpine/lazy-loader.js');
        foreach (['trailerModal', 'filelistModal', 'previewModal', 'imageModal', "'releaseReport'"] as $component) {
            $this->assertStringNotContainsString($component, $loader);
        }
        $this->assertStringContainsString("'adminReleaseReports': () => import('./components/release-report.js')", $loader);
        $this->assertStringNotContainsString('sort-dropdown', $this->source('resources/js/alpine/index.js'));

        // section 2: the file keeps the admin component alone
        $reports = $this->source('resources/js/alpine/components/release-report.js');
        $this->assertStringNotContainsString("Alpine.data('releaseReport'", $reports);
        $this->assertStringNotContainsString('modalLifecycle', $reports);
        $this->assertStringContainsString("Alpine.data('adminReleaseReports'", $reports);
    }

    public function test_section_a_stylesheets_hold_none_of_the_deleted_selectors_and_keep_the_media_info_messages(): void
    {
        $styles = '';
        foreach (File::files(resource_path('css')) as $file) {
            $styles .= $file->getContents();
        }
        foreach ([
            // section 3
            '.ui-dropdown-panel',
            // section 6
            '.movie-detail-page',
            // section 7
            '.mediainfo-codec', '.mediainfo-count', '.mediainfo-detail-group', '.mediainfo-details', '.mediainfo-eyebrow', '.mediainfo-fact',
            '.mediainfo-fact-label', '.mediainfo-facts', '.mediainfo-flags', '.mediainfo-forced-yes', '.mediainfo-id', '.mediainfo-identity',
            '.mediainfo-music-tags', '.mediainfo-section', '.mediainfo-summary', '.mediainfo-title', '.mediainfo-track', '.mediainfo-track-head',
            '.mediainfo-track-title',
            // section 8
            '.details-files',
            // section 10
            '.public-modal-lg', '.public-modal-xl', '.public-modal-preview', '.public-modal-nfo', '.public-modal-image-frame', '.public-modal-audio',
            '.public-modal-audio-art', '.release-action-download', '.release-action-primary',
        ] as $selector) {
            $this->assertStringNotContainsString($selector, $styles);
        }

        $safe = $this->source('resources/css/csp-safe.css');
        $this->assertStringContainsString("\n.mediainfo-error {", $safe);
        $this->assertStringContainsString("\n.mediainfo-message {", $safe);
        // the grouped dark-colour rule keeps its one live selector and its declaration
        $this->assertStringContainsString("\n.dark .mediainfo-message {\n    color: var(--text-muted-dark);\n}", $safe);
    }

    public function test_section_a_methods_and_the_trailer_helper_nothing_called_are_gone(): void
    {
        // section 5
        foreach ([
            [ReleaseBrowserQuery::class, 'sortOptions'], [ReleaseBrowserMetadata::class, 'sorts'], [ReleaseSort::class, 'options'],
            [ReleaseBrowserState::class, 'availableViews'], [ReleaseBrowserState::class, 'hasFilters'],
            [ReleaseBrowserState::class, 'queryParameters'], [ReleaseBrowserState::class, 'pageUrl'],
            // section 6
            [MovieService::class, 'getTrailer'], [TitleMetadataLoader::class, 'trailerUrl'], [TitleMetadataLoader::class, 'webUrl'],
        ] as [$class, $method]) {
            $this->assertFalse(method_exists($class, $method), $class.'::'.$method);
        }
        $this->assertFalse(function_exists('imdb_trailers'));
        // what the sort of a release list still needs
        $this->assertSame(ReleaseSort::AddedNewest, ReleaseSort::resolve('no-such-sort'));
    }

    public function test_section_a_unread_private_profiles_key_is_gone_from_configuration(): void
    {
        // section 9
        $this->assertNull(config('nntmux_settings.private_profiles'));
        $this->assertStringNotContainsString('private_profiles', $this->source('config/nntmux_settings.php'));
        $this->assertStringNotContainsString('PRIVATE_PROFILES', $this->source('.env.example'));
        $this->assertStringNotContainsString('PRIVATE_PROFILES', $this->source('.env.dist'));
        // its neighbour is still read
        $this->assertStringContainsString("\nSTORE_USER_IP=false\n", $this->source('.env.example'));
    }

    public function test_section_a_buttons_render_each_remaining_size_as_before_and_no_longer_name_the_unused_one(): void
    {
        // section 12: the HTML each remaining size rendered before icon-sm left the two components
        $base = 'inline-flex items-center justify-center gap-2 rounded-lg border font-semibold transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900';
        $primary = 'border-accent-surface bg-accent-surface text-accent-on hover:bg-primary-800 dark:border-accent-surface-dark dark:bg-accent-surface-dark dark:hover:bg-primary-400 dark:text-accent-on-dark';
        foreach ([
            'sm' => 'ui-control ui-control-sm px-3 py-1.5 text-xs',
            'md' => 'ui-control ui-control-md px-4 py-2 text-sm',
            'lg' => 'ui-control ui-control-md px-6 py-3 text-base',
            'icon' => 'ui-control ui-control-md ui-control-icon h-9 w-9 p-0 text-sm',
        ] as $size => $sized) {
            $this->assertSame(
                '<button type="button" class="'.$base.' disabled:cursor-not-allowed disabled:opacity-60 '.$sized.' '.$primary."\">\n        Go\n</button>\n",
                Blade::render('<x-button size="'.$size.'">Go</x-button>'),
                'x-button '.$size,
            );
            $this->assertSame(
                '<a class="'.$base.' '.$sized.' '.$primary."\" href=\"/x\">\n        Go\n</a>\n",
                Blade::render('<x-button-link size="'.$size.'" href="/x">Go</x-button-link>'),
                'x-button-link '.$size,
            );
        }
        foreach (['resources/views/components/button.blade.php', 'resources/views/components/button-link.blade.php'] as $component) {
            $this->assertStringNotContainsString('icon-sm', $this->source($component));
        }
    }

    public function test_section_a_checkbox_menu_placement_no_longer_names_the_fixed_width_class(): void
    {
        // section 13
        $script = $this->source('resources/js/alpine/components/checkbox-menu-component.js');
        $this->assertStringNotContainsString('is-fixed', $script);
        $this->assertStringContainsString('is-cell', $script);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source, $path);

        return $source;
    }
}
