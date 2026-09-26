/**
 * Alpine.js CSP-safe lazy component loader.
 *
 * Scans the DOM for x-data attributes that match known lazy components.
 * Only imports the JS modules for components actually present on the page.
 * Once every needed module has loaded or failed, calls Alpine.start(); roots of a
 * component whose module failed are marked x-ignore first so Alpine skips them.
 *
 * Dynamic import() is CSP-compliant (no eval / new Function).
 */
import Alpine from '@alpinejs/csp';

/**
 * Map of Alpine component name → dynamic import function.
 * Each module registers itself via Alpine.data() as a side effect.
 */
const lazyComponentMap = {
    'accountPage': () => import('./components/account.js'),
    'accountRss': () => import('./components/account.js'),
    'searchFeed': () => import('./components/search-filters.js'),
    'searchFilters': () => import('./components/search-filters.js'),
    'watchlistPicker': () => import('./components/watchlist.js'),
    'watchlistPage': () => import('./components/watchlist.js'),
    'trailerModal': () => import('./components/trailer-modal.js'),
    'releaseDetails': () => import('./components/release-details.js'),
    'titleOverview': () => import('./components/title-overview.js'),
    // --- Components only needed on specific pages ---
    'adminSubmenu':    () => import('./components/admin-submenu.js'),
    'passwordToggle':  () => import('./components/password-toggle.js'),

    // --- Modal components (only on release pages) ---
    'nfoModal':        () => import('./components/nfo-modal.js'),
    'filelistModal':   () => import('./components/filelist-modal.js'),
    'previewModal':    () => import('./components/preview-modal.js'),
    'mediainfoModal':  () => import('./components/mediainfo-modal.js'),
    'imageModal':      () => import('./components/image-modal.js'),

    // --- Page-specific components ---
    'contentToggle':   () => import('./components/content-toggle.js'),
    'blacklistSweep':  () => import('./components/admin/blacklist-sweep.js'),
    'settingsCard':    () => import('./components/admin/settings-card.js'),
    'posterIdentityBlacklist': () => import('./components/poster-identity-blacklist.js'),
    'contentDelete':   () => import('./components/content-toggle.js'),  // same file
    'releaseReport':   () => import('./components/release-report.js'),
    'adminReleaseReports': () => import('./components/release-report.js'),  // same file
    'adminReleaseList': () => import('./components/admin/release-list.js'),
    'recoveredReleases': () => import('./components/admin/recovered-releases.js'),
    'showAddForm':     () => import('./components/admin/show-add.js'),
    'copyToClipboard': () => import('./components/profile-edit.js'),    // same file
    'cartPage':        () => import('./components/cart-page.js'),
    'releaseMultiOps': () => import('./components/cart-page.js'),  // same file
    'releaseBrowser':  () => import('./components/release-browser.js'),
    'tvReleases':      () => import('./components/tv-releases.js'),
    'checkboxMenu':    () => import('./components/checkbox-menu.js'),
    'tvShows':         () => import('./components/tv-shows.js'),
    'tvSearch':        () => import('./components/tv-search.js'),
    'tvEpisodeList':   () => import('./components/tv-episode-list.js'),
    'tvReleaseDetails': () => import('./components/tv-release-details.js'),
    'tvFilesDialog':   () => import('./components/tv-dialogs.js'),
    'tvImageDialog':   () => import('./components/tv-dialogs.js'),  // same file
    'authPage':        () => import('./components/auth-page.js'),
    'loginMode':       () => import('./components/login-mode.js'),
    'passkeyLogin':    () => import('./components/passkey-login.js'),
    'passkeyManage':   () => import('./components/passkey-manage.js'),
    'adminUserPasskeys': () => import('./components/admin-user-passkeys.js'),

    // --- Admin components (includes Chart.js — heavy) ---
    'adminDashboard':  () => import('./components/admin/dashboard.js'),
    'adminGroups':     () => import('./components/admin/groups.js'),
    'adminFeatures':   () => import('./components/admin/features.js'),
    'adminUserEdit':   () => import('./components/admin/features.js'),  // same file
    'adminUserList':   () => import('./components/admin/user-list.js'),
    'adminDeletedUsers': () => import('./components/admin/features.js'), // same file
    'adminInvitations': () => import('./components/admin/features.js'), // same file
    'adminRegexForm':  () => import('./components/admin/features.js'),  // same file
    'richTextEditor':  () => import('./components/admin/rich-text-editor.js'),
    'verifyUser':      () => import('./components/admin/verify-user.js'),
    'tmuxEdit':        () => import('./components/admin/features.js'),  // same file
};

/**
 * The Alpine component name an x-data root uses, or null.
 * Handles: x-data="componentName", x-data="componentName()", x-data="{ ... }" (null).
 */
function componentName(el) {
    // Extract identifier before ( or whitespace; inline objects like { show: true } don't match
    const match = (el.getAttribute('x-data') || '').trim().match(/^([a-zA-Z_$][a-zA-Z0-9_$]*)/);
    return match ? match[1] : null;
}

/**
 * Extract Alpine component names from all x-data attributes in the DOM.
 */
function getUsedComponentNames() {
    const names = new Set();
    document.querySelectorAll('[x-data]').forEach(el => {
        const name = componentName(el);
        if (name) names.add(name);
    });
    return names;
}

/**
 * Mark every root of a component whose module failed to load with x-ignore, so
 * Alpine skips it (and its subtree) instead of evaluating an undefined component
 * and removing the x-cloak that keeps its dialog frame hidden.
 */
function ignoreRoots(name) {
    document.querySelectorAll('[x-data]').forEach(el => {
        if (componentName(el) === name) el.setAttribute('x-ignore', '');
    });
}

/**
 * Load only the lazy components found on the current page, then start Alpine.
 * A component whose module fails to load stays uninitialised; the rest start normally.
 * Returns a promise that settles once Alpine has started (awaited by the tests).
 */
export function loadAndStart() {
    const names = [...getUsedComponentNames()].filter(name => lazyComponentMap[name]);

    if (names.length === 0) {
        Alpine.start();
        enableTransitions();
        return Promise.resolve();
    }

    // Load all needed modules in parallel, then start Alpine
    return Promise.allSettled(names.map(name => lazyComponentMap[name]()))
        .then(results => {
            results.forEach((result, i) => {
                if (result.status === 'rejected') {
                    console.error(`[lazy-loader] Failed to load component ${names[i]}:`, result.reason);
                    ignoreRoots(names[i]);
                }
            });
            Alpine.start();
            enableTransitions();
        });
}

function enableTransitions() {
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            document.documentElement.removeAttribute('data-loading');
        });
    });
}
