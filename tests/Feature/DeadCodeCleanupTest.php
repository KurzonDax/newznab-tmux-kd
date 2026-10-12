<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ReleaseBrowserState;
use App\Enums\ReleaseSort;
use App\Http\Controllers\Api\RSS;
use App\Http\Controllers\BasePageController;
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

    public function test_section_b_scripts_no_page_loads_are_gone_and_the_eager_index_imports_only_what_stays(): void
    {
        // B1
        foreach ([
            'resources/js/alpine/components/tab-switcher.js', 'resources/js/alpine/components/dropdown.js',
            'resources/js/alpine/components/auth-page.js', 'tests/js/frontend-active-states.test.js',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path));
        }
        // a control whose script never loads is a bug for a later issue: its script is untouched
        $password = $this->source('resources/js/alpine/components/password-toggle.js');
        foreach (["Alpine.data('passwordToggle'", '.password-toggle-btn', 'window.togglePasswordVisibility'] as $kept) {
            $this->assertStringContainsString($kept, $password);
        }

        $index = $this->source('resources/js/alpine/index.js');
        preg_match_all('/^import\s+(?:[^\'"]+\s+from\s+)?[\'"]([^\'"]+)[\'"];/m', $index, $imports);
        $this->assertSame([
            '@alpinejs/csp',
            './stores/theme.js', './stores/toast.js', './stores/cart.js',
            './components/theme-toggle.js', './components/back-to-top.js', './components/public-navigation.js',
            './components/confirm-modal.js', './components/confirm-link.js', './components/toast-notification.js',
            './components/dismissible.js', './lazy-loader.js',
        ], $imports[1]);
        $this->assertSame(preg_match_all('/^import\b/m', $index), count($imports[1]));
        $this->assertStringNotContainsString('escapeHtml', $index);
    }

    public function test_section_b_script_loader_maps_exactly_the_components_a_page_mounts(): void
    {
        // B2, together with sections 1 and 2
        $expected = [
            'accountPage', 'accountRss', 'searchFeed', 'searchFilters', 'watchlistPicker', 'watchlistPage', 'adminSubmenu', 'passwordToggle',
            'nfoModal', 'mediainfoModal', 'contentToggle', 'blacklistSweep', 'settingsCard', 'posterIdentityBlacklist', 'posterSweepStatus',
            'adminReleaseReports', 'adminReleaseList', 'recoveredReleases', 'showAddForm', 'copyToClipboard', 'tvReleases', 'homeShelves',
            'checkboxMenu', 'yearMenu', 'tvShows', 'tvSearch', 'tvEpisodeList', 'tvReleaseDetails', 'movieFilm', 'movieReleaseDetails',
            'tvFilesDialog', 'tvImageDialog', 'tvListenDialog', 'loginMode', 'passkeyLogin', 'passkeyManage', 'adminUserPasskeys',
            'adminDashboard', 'adminGroups', 'adminUserEdit', 'adminUserList', 'adminDeletedUsers', 'richTextEditor', 'verifyUser',
        ];
        $this->assertCount(44, $expected);

        $loader = $this->source('resources/js/alpine/lazy-loader.js');
        $this->assertSame(1, preg_match('/const lazyComponentMap = \{\n(.*?)\n\};/s', $loader, $map));
        preg_match_all('/^\s*\'([^\']+)\'\s*:\s*\(\)\s*=>\s*import\(/m', $map[1], $entries);
        $keys = $entries[1];
        $this->assertSame(substr_count($map[1], 'import('), count($keys));
        $this->assertCount(44, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertEqualsCanonicalizing($expected, $keys);

        $views = '';
        foreach (File::allFiles(resource_path('views')) as $file) {
            $views .= $file->getContents()."\n";
        }
        foreach ($keys as $key) {
            if ($key === 'passwordToggle') {
                continue;
            }
            $this->assertMatchesRegularExpression('/x-data\s*=\s*["\']?\s*'.preg_quote($key, '/').'(?![\w])/', $views, $key.' has no x-data root');
        }
    }

    public function test_section_b_admin_features_script_keeps_its_two_components_and_the_handlers_left_for_a_later_issue(): void
    {
        // B3
        $features = $this->source('resources/js/alpine/components/admin/features.js');
        $this->assertSame(['adminUserEdit', 'adminDeletedUsers'], $this->componentNames($features));
        foreach ([
            '[data-action]',
            "case 'show-reset-modal'", "case 'hide-reset-modal'", "case 'show-purge-modal'", "case 'hide-purge-modal'",
            "case 'show-reset-selected-modal'", "case 'hide-reset-selected-modal'", "case 'select-all-groups'", "case 'toggle-group-status'",
            "case 'toggle-backfill'", "case 'reset-group'", "case 'delete-group'", "case 'purge-group'", "case 'reset-all'", "case 'purge-all'",
            "case 'reset-selected'",
            '.restore-user-btn', '.delete-user-btn', '.promotion-toggle-btn', '.promotion-delete-btn', '[data-delete-regex]',
            '[data-delete-blacklist]', 'img[data-fallback-src], img[data-hide-on-error]', "getElementById('select_all')", '.invitation-checkbox',
            "getElementById('selectAll')", "getElementById('bulkActionForm')",
        ] as $kept) {
            $this->assertStringContainsString($kept, $features);
        }
        $this->assertSame(15, preg_match_all('/^\s*case \'/m', $features));
        foreach ([
            'escapeHtml', 'data-toggle-submenu', 'verify-modal', 'data-submit-verify-form', 'content-toggle-status', 'content-delete', 'season-tab',
            'data-redirect-on-change', 'group-checkbox', 'crap_types_container', 'fix_crap_opt', "document.addEventListener('change'",
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $features);
        }
    }

    public function test_section_b_globals_nothing_reads_and_the_dead_parts_of_live_scripts_are_gone(): void
    {
        // B4
        $groups = $this->source('resources/js/alpine/components/admin/groups-component.js');
        foreach ([
            'ajax_group_status', 'ajax_backfill_status', 'ajax_group_reset', 'confirmGroupDelete', 'confirmGroupPurge', 'ajax_group_reset_all',
            'ajax_group_purge_all', 'ajax_group_reset_selected', 'showResetAllModal', 'hideResetAllModal', 'showPurgeAllModal', 'hidePurgeAllModal',
            'showResetSelectedModal', 'hideResetSelectedModal', 'toggleSelectAllGroups', 'updateSelectionUI',
        ] as $global) {
            $this->assertStringContainsString('window.'.$global.' =', $groups);
        }
        $this->assertSame(16, preg_match_all('/window\.\w+ =/', $groups));
        $this->assertContainsNone(['showEditSelectedModal', 'hideEditSelectedModal', 'getSelectedGroups', '_getSelected'], $groups);

        $verify = $this->source('resources/js/alpine/components/admin/verify-user.js');
        $this->assertStringContainsString('window.showVerifyModal', $verify);
        $this->assertContainsNone(['hideVerifyModal', 'submitVerifyForm'], $verify);

        // B5
        $profile = $this->source('resources/js/alpine/components/profile-edit.js');
        $this->assertSame(['copyToClipboard'], $this->componentNames($profile));
        foreach (['.copy-btn', '#copyApiToken', 'apiTokenInput', 'data-copy-target'] as $kept) {
            $this->assertStringContainsString($kept, $profile);
        }
        $this->assertContainsNone(['profileEdit', 'profilePage', '2fa', 'progress-bar'], $profile);

        $toggle = $this->source('resources/js/alpine/components/theme-toggle.js');
        $this->assertSame(['themeToggle'], $this->componentNames($toggle));
        $this->assertStringContainsString("getElementById('theme-toggle')", $toggle);
        $this->assertContainsNone(['themeRadio', 'theme_preference', '_updatingThemeUI', 'theme-btn'], $toggle);
        $this->assertStringNotContainsString('theme-btn', $this->source('resources/js/alpine/stores/theme.js'));

        $confirm = $this->source('resources/js/alpine/components/confirm-modal.js');
        foreach (['window.showConfirm', '[data-logout]', '[data-confirm-delete]', '[data-confirm]'] as $kept) {
            $this->assertStringContainsString($kept, $confirm);
        }
        $this->assertContainsNone([
            'closeConfirmationModal', 'confirmConfirmationModal', 'data-close-confirmation-modal', 'data-confirm-confirmation-modal',
            'sidebar-logout-form',
        ], $confirm);

        $nfo = $this->source('resources/js/alpine/components/nfo-modal.js');
        $this->assertStringContainsString('.nfo-badge', $nfo);
        $this->assertContainsNone(['openNfoModal', 'closeNfoModal', 'data-open-nfo', 'data-close-nfo-modal', 'app-url'], $nfo);

        // B6
        $this->assertContainsNone(['data-preserve-modal', 'data-full-size-layer'], $this->source('resources/js/alpine/components/modal-lifecycle.js'));

        foreach ([
            'resources/js/alpine/components/mediainfo-modal-component.js' => ['showMediainfo', 'closeMediainfoModal'],
            'resources/js/alpine/components/release-report.js' => ['toggleAll', 'allChecked'],
            'resources/js/alpine/components/admin/release-list.js' => ['allChecked'],
            'resources/js/alpine/components/passkey-manage.js' => ['createPasskey('],
            'resources/js/alpine/components/passkey-login.js' => ['browserPasskeyPending'],
            'resources/js/alpine/components/poster-identity-blacklist-component.js' => ['closeConfirmation'],
            'resources/js/alpine/components/content-toggle.js' => ['contentDelete'],
        ] as $path => $gone) {
            $this->assertContainsNone($gone, $this->source($path), $path);
        }
    }

    public function test_section_b_npm_packages_nothing_imports_have_left_the_manifest_and_the_lockfile(): void
    {
        // B7
        $manifest = $this->source('package.json');
        foreach ([$manifest, $this->source('package-lock.json')] as $file) {
            $this->assertStringNotContainsStringIgnoringCase('lodash', $file);
            $this->assertStringNotContainsStringIgnoringCase('laravel-echo', $file);
        }
        // the retained forum preset imports these three
        foreach (['"date-fns":', '"@melloware/coloris":', '"nested-sort":'] as $kept) {
            $this->assertStringContainsString($kept, $manifest);
        }
    }

    public function test_section_c_view_files_and_layout_classes_nothing_renders_are_gone(): void
    {
        // C1
        foreach ([
            'resources/views/auth/passwords/confirm.blade.php',
            'resources/views/components/admin/stat-card.blade.php', 'resources/views/components/application-logo.blade.php',
            'resources/views/components/button-secondary.blade.php',
            'resources/views/partials/back-to-top-forum.blade.php', 'resources/views/partials/foreign-language-links.blade.php',
            'resources/views/partials/theme-switcher.blade.php',
            'app/View/Components/AppLayout.php', 'app/View/Components/GuestLayout.php',
            // C2
            'resources/views/vendor/pagination/bootstrap-4.blade.php', 'resources/views/vendor/pagination/bootstrap-5.blade.php',
            'resources/views/vendor/pagination/default.blade.php', 'resources/views/vendor/pagination/semantic-ui.blade.php',
            'resources/views/vendor/pagination/simple-bootstrap-4.blade.php', 'resources/views/vendor/pagination/simple-bootstrap-5.blade.php',
            'resources/views/vendor/pagination/simple-default.blade.php', 'resources/views/vendor/pagination/simple-tailwind.blade.php',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path));
        }
        $this->assertSame(
            ['tailwind.blade.php'],
            array_map(static fn (\SplFileInfo $file): string => $file->getFilename(), File::allFiles(resource_path('views/vendor/pagination'))),
        );
        // what stays beside them: the guest layout is reached by @extends, the forum has its own button
        $this->assertFileExists(base_path('resources/views/layouts/guest.blade.php'));
        $this->assertFileExists(base_path('resources/forum/blade-tailwind/views/components/button-secondary.blade.php'));

        $provider = $this->source('app/Providers/AppServiceProvider.php');
        $this->assertStringNotContainsString('layouts.app', $provider);
        $this->assertStringContainsString("'layouts.main',\n            'layouts.guest',\n            'search.*',\n        ], GlobalDataComposer::class);", $provider);
        $this->assertStringNotContainsString('AppLayout', $this->source('phpstan-baseline.neon'));
    }

    public function test_section_c_components_hold_none_of_the_props_slots_and_branches_no_caller_reaches(): void
    {
        // C3
        foreach ([
            'modal' => ['backdrop', '$subtitle', '$overlay'],
            'watch-button' => ['$kind', '$size', '$wording', 'release-action-muted'],
            'chip' => ['pill'],
            'checkbox-menu' => ['$summary', '$fixed', 'is-fixed'],
            'admin/card' => ['@props'],
            'admin/data-table' => ['dense', 'striped'],
            'admin/pagination' => ['position'],
            'admin/badge' => ['$icon'],
            'badge' => ['$size'],
            'select' => ['controlSize'],
            'segmented' => ['$label'],
            'mail/button' => ['$align'],
            'year-menu' => ['$first', '$last'],
            'mail/layout' => ['$header', '$footer'],
        ] as $component => $gone) {
            $this->assertContainsNone($gone, $this->source('resources/views/components/'.$component.'.blade.php'), $component);
        }
        // the input keeps the size one caller passes
        $this->assertStringContainsString('controlSize', $this->source('resources/views/components/input.blade.php'));
    }

    public function test_section_c_views_hold_no_block_a_request_cannot_reach_and_the_layouts_keep_only_their_live_stacks(): void
    {
        // C4
        $views = '';
        foreach (File::allFiles(resource_path('views')) as $file) {
            $views .= $file->getContents()."\n";
        }
        $this->assertContainsNone(["@section('title'", 'suppress_layout_flash', 'isset($error)', 'isset($msg)', '@if($front)'], $views);

        $this->assertStringNotContainsString('@stack(', $this->source('resources/views/layouts/admin.blade.php'));
        $guest = $this->source('resources/views/layouts/guest.blade.php');
        $this->assertSame(1, substr_count($guest, '@stack('));
        $this->assertStringContainsString("@stack('scripts')", $guest);
        // the main layout keeps its four
        preg_match_all('/@stack\(\'(\w+)\'\)/', $this->source('resources/views/layouts/main.blade.php'), $stacks);
        $this->assertSame(['meta', 'styles', 'modals', 'scripts'], $stacks[1]);
    }

    public function test_section_c_admin_preferences_and_the_rss_help_lookup_are_gone(): void
    {
        // C5
        $this->assertFalse(method_exists(BasePageController::class, 'setAdminPrefs'));
        foreach (File::allFiles(app_path()) as $file) {
            $this->assertStringNotContainsString('setAdminPrefs', $file->getContents(), $file->getRelativePathname());
        }
        // C6
        $this->assertFalse(method_exists(RSS::class, 'getFirstInstance'));
        // C8: the invitations page still uses the class
        $this->assertStringContainsString('.account-view-preferences', $this->source('resources/css/csp-safe.css'));
        $this->assertStringContainsString('account-view-preferences', $this->source('resources/views/invitations/index.blade.php'));
    }

    /**
     * The distinct names that follow Alpine.data(' in a script, its header comment included.
     *
     * @return list<string>
     */
    private function componentNames(string $script): array
    {
        preg_match_all('/Alpine\.data\(\'([^\']*)/', $script, $names);

        return array_values(array_unique($names[1]));
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertContainsNone(array $needles, string $source, string $message = ''): void
    {
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $source, trim($message.' '.$needle));
        }
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source, $path);

        return $source;
    }
}
