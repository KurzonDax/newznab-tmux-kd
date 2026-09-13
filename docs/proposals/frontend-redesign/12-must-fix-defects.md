# 12 · Must-fix defects (independent of the redesign)

Found in the review (`prototype/review.html`, "Broken today"). Each is a small PR and none depends on which design options were chosen. Fix these first; several will otherwise mask redesign work.

| # | Defect | Where |
|---|--------|-------|
| 1 | **Audio and Games covers never load.** `musicinfo.cover` / `gamesinfo.cover` are 0/1 flags (the query filters `cover = 1`) but the views build `/covers/music/1`. Use `getReleaseCover()` (Console already does). | `resources/views/music/index.blade.php:120`, `games/index.blade.php:123`, `app/Services/MusicService.php:196`, `GamesService.php:245` |
| 2 | **Every My Movies title link lands on "Movie details not available."** Links go to `/Movies?imdb=…`, whose branch of `showMovies()` never loads `$movie`. Working route is `movie.view`. | `app/Http/Controllers/MovieController.php:118`, `resources/views/mymovies/index.blade.php:92,186`, `mymovies/add.blade.php:152,213` |
| 3 | **Site root returns a JSON 404** when no front-page content row exists. | `app/Http/Controllers/ContentController.php:67` |
| 4 | **My Shows / My Movies headers invisible in light mode**: `header-gradient`, `table-header-gradient`, `show-avatar`, `empty-state-bg`, `col-width-*` exist in no stylesheet. | `resources/views/myshows/index.blade.php:4-11`, `mymovies/index.blade.php` |
| 5 | **Details page emits markup before `<!DOCTYPE>`**: `@include('details.partials.image-modal')` sits after `@endsection`; the partial and its CSS are dead. | `resources/views/details/index.blade.php:55`, `resources/css/csp-safe.css:477-500` |
| 6 | **Series page has two unmatched `</div>`** (48 open / 50 close); the episodes card renders outside the page panel. | `resources/views/series/viewseries.blade.php:200-203` |
| 7 | **JS paints `blue-*` over `primary-*`** and never removes the server class, leaving two active states and breaking the emerald/violet schemes. | `resources/js/alpine/components/tab-switcher.js:97-113`, `quality-filter.js:55-77`, `series-season-loader.js:99-102` |
| 8 | **Trending TV rank badges escape their cards**: the TV clone lost `relative`. | `resources/views/series/trending.blade.php:38` |
| 9 | **Adult browse is a dead end**: `x-view-toggle` labels the XXX link "Covers" but it renders a table with no way back. | `resources/views/components/view-toggle.blade.php:21`, `xxx/index.blade.php` |
| 10 | **My Movies "add" page renders a second, empty My Movies list** under the form. | `resources/views/mymovies/add.blade.php:89-255` |
| 11 | **Flash messages render twice** (layout banner + toast store). | `resources/views/layouts/main.blade.php:66-84,138`, `resources/js/alpine/stores/toast.js:46` |
| 12 | **Hidden comments are shown and the count disagrees**: `getComments()` ignores `isvisible`; the counter does not. | `app/Models/ReleaseComment.php:83-86,160-167`, `details/partials/comments.blade.php:5` |
| 13 | **Media-info signal not fetched for covers queries**; the chip exists only in `release-results`. | `app/Services/MovieBrowseService.php:196`, `ReleasePreviewDataLoader.php:84` |
| 14 | **Names that don't exist**: FA4 icons `fa-hdd-o`, `fa-clock-o`, `fa-external-link`; Tailwind-v2 `aspect-w-16 aspect-h-9`; `w-30 h-30`; `contact-page-container`. | `series/partials/season-content:30,33`, `browsegroup/index:100`, `mymovies/index:163`, `movies/viewmovietrailer:16`, `profile/index:48`, `contact/index:5` |
| 15 | **Dead or orphaned**: `x-search-autocomplete` (0 uses), `partials/cart-script`, `home/index`, `welcome`, `auth/verify`, `auth/google2fa`, unlinked `auth/2fa`, routes `movie` and `movietrailers`, seven orphan 2FA routes, `viewmovietrailer` + `trailer-modal`, `errors/layout`, Alpine `dropdown` / `submenu`, `$similars` computed and discarded on every details load. | various; see review |

Also extend `scripts/check-design-system.sh` to fail on `bg-white` / `bg-gray-*` surfaces outside components, `indigo-*` / `purple-*` accents, FA4 icon names, and `blue-*` inside `resources/js` — the documented rules it does not check today.
