# Movies screens: feature inventory (master @ b592a02a9, 2026-09-26)

Scope: what a user can see and do on every Movies screen today, with `path:line` refs.
All paths are relative to the repository root. Read on master `b592a02a9`; line numbers may drift as the code changes. Read-only survey; nothing edited.

This is the input for the Movies screens still to design (`SPEC.md` section 6 records what is kept and dropped).

Notes up front:
- The old dedicated movie pages are gone. `trending-movies`, `movie/{imdbid}`, `Movies/{id?}`, `mymovies` and `mymovies/browse` are now **redirect-only** (section 4/5).
- Movies browsing uses the generic release browser (`BrowseController` + `x-release-browser`) with Movies-specific branches. TV no longer uses it (`app/Http/Controllers/BrowseController.php:37-39` redirects TV to `tv.releases`).
- Web screens join releases to movies on **`releases.imdbid = movieinfo.imdbid`**. `releases.movieinfo_id` exists too, but only API, RSS and the search index use it (see "How a release links to a movie").

---

## 1. Movies browse: `browse/movies/{id?}` (BrowseController::show)

Route: `routes/web.php:200` (GET|POST, `clearance` middleware). Controller: `app/Http/Controllers/BrowseController.php:25-95`. View: `resources/views/browse/index.blade.php`. Component: `resources/views/components/release-browser.blade.php` (+ `components/release-browser/*`). Alpine: `resources/js/alpine/components/release-browser-component.js` + `release-cover-browser.js`.

### Page frame
- `{id}` can be a sub-category id or title (e.g. `HD`) inside the Movies root. Excluded categories get a 403. `BrowseController.php:30-36`
- Access: the user needs the `view movies` permission. `app/Http/Middleware/ClearanceMiddleware.php:38-70`
- Breadcrumb: "Browse › Movies", plus "› Watching" when `watching=1`. `browse/index.blade.php:9`
- Page title: "Movies", "Movies · {subcat}", "Trending Movies" (covers + trending), "Movies you follow" (watching), "Releases in {group}", "Posts by {poster}". `BrowseController.php:66-78`
- Header button "Only titles I follow" (**Movies only**) links to `?watching=1`. `browse/index.blade.php:15-17`
- Header button "Clear filter" appears when a group or poster filter is on. `browse/index.blade.php:12-14`
- Page modals: file list, NFO, preview, media info, image, report. `browse/index.blade.php:3-5`, `resources/views/partials/release-modals.blade.php:2-7`. The watchlist picker is global. `resources/views/layouts/main.blade.php:51`

### View modes (Movies root allows table / cards / covers)
- Allowed views for Movies: `['table','cards','covers']`. `app/Enums/BrowseRoot.php:84-91`. Allowed cover sizes: S/L/XL. `BrowseRoot.php:94-97`
- Default preferences: `view=table, size=s, per=48, thumbs=false`, stored per root in `users.view_prefs` JSON. `app/Models/User.php:209-215`
- A group or poster filter forces table-only. `app/Data/ReleaseBrowserState.php:96`
- Count unit: "releases" in table/cards, "titles" in covers. `ReleaseBrowserState.php:43-57`

### Toolbar (`components/release-browser/toolbar.blade.php`)
- Search box "Search in Movies", 180 ms debounce, reloads the page. `toolbar.blade.php:3`; `release-browser-component.js:113-115`
  - Table/cards view: LIKE on the release display name or searchname. `app/Services/Releases/ReleaseBrowserQuery.php:123-134`
  - Covers view (**Movies only**): movie search. It first tries the Manticore `movies` index, then falls back to LIKE on `movieinfo.title/actors/director/plot`. It also honours field params `title`, `actors`, `director`, `plot`. `ReleaseBrowserQuery.php:121-122`; `app/Services/Releases/ReleaseBrowserMetadata.php:95-111`
- Year picker: All years, decades, single years, or a custom from–to range (`year`, `year_from`, `year_to`). Filters on `movieinfo.year`. `toolbar.blade.php:5-6`; `resources/views/components/year-picker.blade.php:1-30`; `ReleaseBrowserMetadata.php:22,62-73`
- Genre select: options are the distinct `movieinfo.genre` values split on `,` and `|`. The filter matches one genre token. `toolbar.blade.php:7-17`; `ReleaseBrowserMetadata.php:83-87,114-133`
- Rating filter `rating=1..9` (minimum `movieinfo.rating`) works **by URL only**. It has no control because the options skip rating. `ReleaseBrowserMetadata.php:79-82,118`
- "Clear" button appears when any filter is active. `toolbar.blade.php:19-21`. It clears q, title, year*, genre, actors, director, plot, rating, letter, watching, group, poster, page, minc. `release-browser-component.js:135-139`
- Result count ("N releases" or "N titles"). `toolbar.blade.php:24`
- Sort select: Posted newest/oldest, Added newest/oldest, Name A–Z, Grabs most. Changing sort drops `trending`. `toolbar.blade.php:25-29`; `app/Enums/ReleaseSort.php:22-29`; `release-browser-component.js:101,131-133`
  - In covers view the grouped sort uses MAX/MIN of release columns. "Name A–Z" therefore sorts by the **release name**, not `movieinfo.title`. `ReleaseSort.php:32-46`; `app/Services/Releases/CoverBrowseScope.php:34-41`; `app/Services/MovieBrowseService.php:65`
- View segment: Table / Cards ("Renamed, post-processed releases only") / Covers. `toolbar.blade.php:30-38`
- Thumbnails toggle (table view only). `toolbar.blade.php:39-40`
- Cover size S / L / XL (covers view only). `toolbar.blade.php:41-48`
- Each preference change POSTs `/profile/update-view` (saved per root), then reloads. `release-browser-component.js:75-84`; `routes/web.php:257`; `app/Http/Controllers/ReleaseViewPreferencesController.php:15-31`
- Not present: no sub-category select in the toolbar (sub-categories are only reachable from the header mega-menu or the URL) and no min-completion control (`minc` works by URL only). `ReleaseBrowserState.php:119`; `ReleaseBrowserQuery.php:77-79`

### Hidden URL-only filters
- `watching=1`: releases of followed movies, honouring each follow's `user_movies.categories`. `ReleaseBrowserState.php:117`; `ReleaseBrowserQuery.php:94-119`
- `trending=1` (only with `sort=grabs`): movies grabbed in the last 7 days (`user_downloads`). Applies in every view, but only covers view gets the title and rank. `ReleaseBrowserState.php:122`; `ReleaseBrowserQuery.php:60-64`; `CoverBrowseScope.php:48-58`
- `group=`, `poster=`, `minc=`. `ReleaseBrowserState.php:93-96,119`

### Table view (`components/release-browser/table.blade.php`, `row.blade.php`)
- Columns: select-all checkbox, Release, Category, Size, Files, Added, Posted, Stats, Actions. `table.blade.php:5-7`
- Row checkbox. `row.blade.php:5`
- Thumbnail when thumbs are on: movie poster from `covers/movies/{imdbid}-cover.*`, otherwise a film icon. `row.blade.php:8-10`; `artwork.blade.php:1-23`
- Release name links to the details page. `row.blade.php:13`
- Facts chips (shared), see "Chips" below. `row.blade.php:15`
- Origin chips: **entity chip** (film icon, "Title · Year") links to the movie title page; group chip links to `browse/all?group=`; poster chip links to `browse/all?poster=`. `origin.blade.php:1-10`; `resources/views/components/entity-chip.blade.php:14-16`
- Category pill (not a link). `row.blade.php:20`
- Size. `row.blade.php:21`
- Files count button opens the file-list modal. `row.blade.php:23`
- Added and Posted dates. `row.blade.php:25-26`
- Grabs and comment counts. `row.blade.php:28-29`
- Row actions: Download NZB, Details, Basket toggle, Report, **Watch heart** (movies/TV only; opens the watchlist picker). `actions.blade.php:2-8`; `resources/views/components/watch-button.blade.php:6-7`

### Cards view (`components/release-browser/card.blade.php`)
- Shows only renamed and post-processed releases. The pager says "renamed and post-processed only (N not shown)". `ReleaseBrowserQuery.php:26-30`; `pager.blade.php:8-13`
- Card contents: checkbox, artwork (always shown), name link, facts chips, origin chips, Size, Added, Posted, Grabs, and the row actions. `card.blade.php:5-16`

### Covers view: one tile per movie, grouping releases (`covers.blade.php`, `cover-art.blade.php`, `cover-detail.blade.php`)
- Data: `MovieBrowseService::getMovieRange` groups releases by `imdbid`. It counts only movies with a non-empty `movieinfo.title` and imdbid, so releases whose imdbid has no movieinfo row appear in table view but not here. It attaches the top 2 releases per movie (newest `postdate`). `app/Services/Releases/ReleaseCoverBrowser.php:63-86`; `app/Services/MovieBrowseService.php:41-222` (baseWhere `:90-97`, movie select `:149-163`, top-2 `:175-196`)
- Tile id = imdbid. `ReleaseCoverBrowser.php:114`
- **S and L tiles**: clicking the whole tile expands it in place. It shows the poster (or a film icon if the image is missing or fails), a release-count badge, the title and the line "Year · ★ rating". `covers.blade.php:7-14`; `cover-art.blade.php:1-12`; `ReleaseCoverBrowser.php:95`
  - L size adds a footer with a genre chip and "N releases". `covers.blade.php:15-20`
  - Heart button (**Movies only** among cover roots) opens the watchlist picker. `covers.blade.php:23-25`; `ReleaseCoverBrowser.php:126`
- **XL tile (cover-detail)**: poster; title linking to the title page; year; metadata chips (★ rating, genre); **IMDb chip** (**Movies only**, opens imdb.com); Watch button; "N releases"; inline list of up to 2 releases (name link, facts chips, size, files, added, grabs, comments, row actions); button "View all N releases" that expands. `cover-detail.blade.php:1-26`; `panel.blade.php:1-16`; `ReleaseCoverBrowser.php:107`
  - Dead branch: the `genres` line in cover-detail never shows because no code sets `ReleaseCoverItem::genres`. `cover-detail.blade.php:6`; `app/Data/ReleaseCoverItem.php:29`
- **Trending rank badge "#N"** (**Movies + trending only**). `covers.blade.php:27-29`
- The Movies `size` and `per` preferences apply. The per-page choice is 24 / 48 / 100 movies.

### Expanded cover panel (`expanded-cover.blade.php`, `release-cover-browser.js`)
- Opens inline under the tile's row, with a pointer. On screens 640 px wide or less it becomes a modal dialog with a backdrop. `release-cover-browser.js:33-44,103-116`; `covers.blade.php:32-33`
- Fetches `?_fragment=cover&cover={imdbid}&release_page&release_per`. The server returns every matching release for `r.imdbid`, honouring the current filters. `release-cover-browser.js:46-81`; `BrowseController.php:52-58`; `ReleaseCoverBrowser.php:41-60`
- Header: movie title, "· N releases", "Select all on this page", "Download selected", **"Title page"** button, and a close (×) button. `expanded-cover.blade.php:7-16`
- Body: the full release table (same columns and chips as table view). `expanded-cover.blade.php:17`
- Own pager: Page X of Y, Previous/Next, releases per page 24/48/100. `expanded-cover.blade.php:18-27`; `release-cover-browser.js:83-92`
- Keyboard: Esc closes; ←/→ moves to the neighbouring tile. `release-cover-browser.js:133-150`
- Error state with "Try again" and "Close". `covers.blade.php:34-40`
- A broken poster image falls back to an icon. `release-cover-browser.js:152-156`

### Selection / bulk (all views)
- Select-all header checkbox (with an indeterminate state) and per-row checkboxes. `release-browser-component.js:15-34`
- Floating bar "N selected": Add to basket, Download N NZBs (a single zip POSTed to `/getnzb`), Clear. `release-browser.blade.php:51-57`; `release-browser-component.js:41-60,160-172`

### Pagination (`pager.blade.php`, top and bottom)
- "Page X of Y · N releases/titles"; Per page 24/48/100; ‹ ›; numbered window of ±2 pages with first and last page; "Go to page" input with validation. `pager.blade.php:6-44`; `release-browser.blade.php:19,47-49`; `release-browser-component.js:62-73`
- A page number past the end redirects to the last page. `BrowseController.php:63-65`

### Empty state
- Film icon, "No releases match." and "Clear filters" when filters are active. `release-browser.blade.php:33-46`

---

## 2. Movie title page: `title/movies/{imdbid}` (TitleController::show)

Route: `routes/web.php:213`. Controller: `app/Http/Controllers/TitleController.php:16-65`. Services: `app/Services/Releases/TitleMetadataLoader.php`, `TitleReleaseBrowser.php`. Views: `resources/views/title/index.blade.php`, `title/partials/releases.blade.php`. Alpine: `resources/js/alpine/components/title-overview-component.js`.

- Access: needs the `view movies` permission (403). Accepts an optional `tt` prefix; the id must be numeric. `TitleController.php:18-23`
- Returns 404 when no `movieinfo` row exists for the imdbid. `TitleMetadataLoader.php:30-34`
- Breadcrumb: "Movies › {title}". `title/index.blade.php:5`
- Poster from `covers/movies/{imdbid}-cover.*`, with a placeholder (icon + title) when missing or failed. `title/index.blade.php:7-10`; `TitleMetadataLoader.php:63-64`; `title-overview-component.js:12-14`
- H1 title with a year subtitle. `title/index.blade.php:12`; `TitleMetadataLoader.php:65`
- Watch button (Watch / Watching ▾) opens the picker. `title/index.blade.php:14-16`
- External link buttons **IMDb**, **TMDB**, **Trakt** (shown only when the id is > 0), routed through the site dereferrer and opened in a new tab. `title/index.blade.php:17-19`; `TitleMetadataLoader.php:85-101`
- **Trailer** button opens the trailer modal (YouTube-nocookie iframe, or TrailerAddict). It appears only if `movieinfo.trailer` parses as a supported URL. `title/index.blade.php:20,53`; `TitleMetadataLoader.php:68,122-145`; `resources/views/partials/trailer-modal.blade.php:1-7`; `trailer-modal-component.js:12-25`
- "On your My Movies for: {category chips}" with **Edit** and **Remove** buttons (hidden when not followed). `title/index.blade.php:22-28`; `TitleController.php:39-65`
- Metadata list: Year, Rating, Genre, Director. **Runtime** is coded but never shows (movieinfo has no `runtime` column). `title/index.blade.php:29-31`; `TitleMetadataLoader.php:43-44`
- Plot paragraph (HTML stripped). `title/index.blade.php:32`; `TitleMetadataLoader.php:57,67`
- Cast line (raw `movieinfo.actors`, not linked). `title/index.blade.php:33`
- Stats: Releases (count), Latest (relative date of the newest release), **Best** (highest resolution found in release names; Movies and Audio only). `title/index.blade.php:35-39`; `TitleReleaseBrowser.php:55-60`
- "Releases" heading, a loading notice and an error line. `title/index.blade.php:42-44`
- **Quality filter chips**: All, 2160p, 1080p, 720p … parsed from release names. Multi-select, AJAX reload, URL updated with `quality[]`. Shown only when more than one quality exists. `title/partials/releases.blade.php:2-7`; `TitleReleaseBrowser.php:38-44`; `title-overview-component.js:15-62`
- Release list: the shared release browser in table mode, without toolbar or pager, 100 per page, newest added first, so the same row features as section 1 apply. `title/partials/releases.blade.php:9`; `TitleReleaseBrowser.php:33-35`
- Own pager (‹ Previous, numbered ±2, Next ›) loads via AJAX. `title/partials/releases.blade.php:10-16`
- URL-only `?t={subcat id}` narrows releases to one Movies sub-category. `TitleReleaseBrowser.php:27-32`
- URL-only `?watch=1` auto-opens the watchlist picker (the target of the legacy `mymovies?id=add`). `watchlist-component.js:24-29`
- Modals: release modals and the trailer modal. `title/index.blade.php:51-54`

---

## 3. Release details: `details/{guid}` for a movie release (DetailsController::show)

Route: `routes/web.php:218`. Controller: `app/Http/Controllers/DetailsController.php:43-188`. View: `resources/views/details/index.blade.php` + `details/partials/*`. Alpine: `resources/js/alpine/components/release-details-component.js`.

The shell is shared by every non-TV root. TV releases go to `details.tv.index`. `DetailsController.php:66-68`

### Header / breadcrumb
- Breadcrumb: "Movies › {movie title (link to title page)} › {sub-category}". `details/index.blade.php:11-15`
- Artwork: movie poster or icon (swaps to an icon on error). `details/partials/header.blade.php:2`; `release-details-component.js:66-77`
- H1: release name. `header.blade.php:4`
- Facts chips (completion, repair, password, media info, NFO, preview/clip, sample, reported, response) plus a group chip. `header.blade.php:5`
- Subline: category pill, size, files, "Completion not measured", added, posted date, grabs, comments link. `header.blade.php:6-11`
- Actions: **Download NZB**, **Add/Remove basket**, NFO, Media info, Files (N) (these jump to tabs), **Watch** (movies/TV), **Report**, **Edit release** (Admin/Moderator only). `header.blade.php:12-23`; `release-details-component.js:42-65`
- "N users reported download failure" (from DnzbFailure). `header.blade.php:24`; `DetailsController.php:70`

### Tabs (hash-driven; files, media and NFO load lazily)
- Overview · Files (N) · Media info · NFO · Comments (N). `details/index.blade.php:19-23`; `release-details-component.js:4,16-41`
- **Overview**, in this order: preview/sample images and the video preview button (`partials/preview-images.blade.php:24-80`); audio preview (music only); **Movie Information** block; the other roots' blocks (TV, music, game, console, book, anime); PreDB info (`partials/predb-info.blade.php:2-34`); password block (`partials/password-info.blade.php:2-46`); Group / Poster / Password status list (`details/index.blade.php:36-40`); original report and staff response (paginated) (`partials/reports.blade.php:1-48`).
- **Files**: paged file list (Prev/Next, 24/48/100 per page) with a retry button. `details/index.blade.php:43-48`; `resources/views/partials/file-summary-controls.blade.php:1-10`
- **Media info**: loaded from `/release/{id}/mediainfo`. `details/index.blade.php:49-53`; `release-details-component.js:96-124`
- **NFO**: loaded from `/nfo/{guid}?modal=1`. `details/index.blade.php:54-58`
- **Comments**: list, pagination, post form (max 2000 characters, POST back to the same route). `partials/comments.blade.php:1-29`; `DetailsController.php:58-63`

### Movie Information block (`details/partials/movie-info.blade.php`) (**Movies-only partial**)
- Loaded for **any** non-TV release with a valid `releases.imdbid` through `MovieService::getMovieInfo` (`movieinfo WHERE imdbid`). `DetailsController.php:79-101`; `app/Services/MovieService.php:131-139`
- Fields: Title (`/` and `\` stripped) (`movie-info.blade.php:20-25`), Year (`:26-31`), Tagline in quotes (`:32-37`), "IMDB Rating" ★ x/10 (`:38-48`), Plot Synopsis (`:49-54`), Genre (`:55-60`), Director (`:61-66`), Cast (`:67-72`), Language (`:73-78`).
- Genre, Director and Cast are rendered as **links**, each value → `/Movies?{field}={value}` (at most 8 per field). That legacy route redirects to a covers search on Movies browse (section 4). `DetailsController.php:85-93`; `app/Extensions/helper/helpers.php:78-101`
- **Trailer** button. Shown only when the admin setting `trailers_display` is on. If `movieinfo.trailer` is empty the controller fetches one **live** (Trakt, then IMDb) and saves it. `movie-info.blade.php:80-82`; `DetailsController.php:94-99`; `MovieService.php:147-172`; trailer modal `details/index.blade.php:70`
- Not shown here: IMDb/TMDB/Trakt links, backdrop, RT rating, poster (the poster appears in the header).

### Sidebar (`details/partials/related.blade.php`)
- "Other releases of this title": same imdbid, newest first, 10 per page. Each shows a quality/source label (e.g. "1080p · BluRay") linking to details, plus size and a completion chip. `related.blade.php:2-9`; `app/Services/Releases/RelatedReleaseBrowser.php:20-41`
- "View all N other releases" links to the title page (when more exist). `related.blade.php:7`
- "Similar releases" (name-similarity search). `related.blade.php:10-14`; `DetailsController.php:69`
- Modals: release modals and the trailer modal. `details/index.blade.php:68-71`

---

## 4. Legacy movie routes (all redirects now)

| Route | Line | Behaviour | Linked from in-app? |
|---|---|---|---|
| `trending-movies` (name `trending-movies`) | `routes/web.php:234` | Redirects to `browse/movies?view=covers&sort=grabs&trending=1` (other query kept). `app/Http/Controllers/MovieController.php:38-44` | **Yes**: header mega-menu "Trending Movies" `resources/views/partials/header-menu.blade.php:18`; top nav "Trending" `header-menu.blade.php:31`; home "Movies" link `resources/views/content/home.blade.php:23`. (RSS `/rss/trending-movies` is a separate RSS route: `routes/rss.php:30-31`, `resources/views/rss/rssdesc.blade.php:178-181`.) |
| `movie/{imdbid}` (name `movie.view`) | `routes/web.php:235` | Redirects to `title/movies/{id}` (strips `tt`). `MovieController.php:30-36` | **No view/JS link.** Only reached through `Movies?imdb=…`. `MovieController.php:18-20` |
| `Movies/{id?}` (name `Movies`) | `routes/web.php:236` | `?imdb=` redirects to `movie.view`. Otherwise `LegacyCoverRedirect`: `{id}` = sub-category title or `t=` id; `ob=` mapped to `sort`; `view=covers` by default; `title`, `actors`, `director`, `genre` pass through. Target: `browse/movies/{catid}`. `MovieController.php:16-23`; `app/Services/Releases/LegacyCoverRedirect.php:15-53` | **Yes, indirectly**: the details-page Genre/Director/Cast links build `url('/Movies?field=value')`. `helpers.php:96`, `DetailsController.php:86-92`. No `route('Movies')` calls. |

The `clearance` middleware still guards these paths with `view movies`. `ClearanceMiddleware.php:73-81`

---

## 5. My Movies / Watchlist

### `mymovies`, `mymovies/browse` (MyMoviesController): redirects only
- `mymovies?id=browse` or `mymovies/browse` redirect to `browse/movies?watching=1`. `app/Http/Controllers/MyMoviesController.php:11-16,25-31`; `routes/web.php:249-250`
- `mymovies?id=add|edit|doadd|doedit|delete&imdb=N` redirects to `title/movies/N?watch=1`, which auto-opens the picker. `MyMoviesController.php:18-20`
- Anything else redirects to `watchlist?tab=movies`. `MyMoviesController.php:22`
- In-app links: **none**. The header "Watchlist" item is only highlighted on `mymovies*`. `header-menu.blade.php:33`

### `watchlist` (WatchlistController::index), Movies tab
Route `routes/web.php:217`. Controller `app/Http/Controllers/WatchlistController.php:15-27`. Service `app/Services/Releases/WatchlistService.php`. Views `resources/views/watchlist/index.blade.php`, `lists.blade.php`, `artwork.blade.php`. Alpine `watchlist-component.js:119-146`.
- Default tab is Movies when the user has `view movies`. `WatchlistController.php:17`
- Breadcrumb and header "Watchlist". `watchlist/index.blade.php:4-5`
- **"View releases from these"** links to `browse/movies?watching=1`. `watchlist/index.blade.php:7`
- **RSS** button copies `/rss/mymovies?api_token=…` to the clipboard. `watchlist/index.blade.php:8-11`
- Tabs "My Movies · N" and "My Shows · N" (each gated by permission; counts update live). `watchlist/index.blade.php:15-19`; `WatchlistService.php:195-201`
- Search "Find a movie to add…" (300 ms debounce, AJAX fragment). It lists up to 6 **unfollowed** movies matching `movieinfo.title`, each with poster, title link, year and an **Add** button. `watchlist/index.blade.php:21-24`; `lists.blade.php:3-15`; `WatchlistService.php:144-158`; `watchlist-component.js:124-144`
- Followed list, alphabetical, 48 per page (Laravel paginator links). Each entry shows: poster (links to the title page), title link, year, "Latest: {newest release name → details} · {relative date}" or "No releases yet.", followed-category chips, and **Edit** / **Remove** buttons. `lists.blade.php:19-35`; `artwork.blade.php:1-3`; `WatchlistService.php:164-192`
- Empty state: "Nothing followed yet." with a "Browse Movies" action. `lists.blade.php:17`
- The list refreshes itself after any picker change (`watchlist-changed` event). `watchlist-component.js:122`

### Watch picker: `watchlist/{root}/{id}` GET/POST/DELETE (JSON)
Routes `routes/web.php:214-216`. Controller `WatchlistController.php:29-77`. UI `resources/views/partials/watchlist-picker.blade.php` (global modal, `layouts/main.blade.php:51`). JS `watchlist-component.js:3-117`.
- Triggered by any `x-watch-button`: row heart, cover heart, XL cover Watch, title page Watch/Edit/Remove, details header Watch, watchlist Add/Edit/Remove. `resources/views/components/watch-button.blade.php:1-10`
- Modal heading: 'Add "{title}" to My Movies' or 'Edit "{title}" on My Movies'. `watchlist-component.js:7`
- Loading state, error line, and a checkbox per Movies sub-category (user-excluded ones hidden): "Get releases in these categories:". `watchlist-picker.blade.php:4-10`; `WatchlistService.php:30-34`
- Default ticks for a new follow: the user's last-used category choice, else UHD + HD. `WatchlistService.php:52-61,73-86`
- Buttons: Add/Save, Cancel, Remove (only when already followed). `watchlist-picker.blade.php:12-16`
- Saving needs at least one category (warning toast). The toast "Added {title} to My Movies · {cats}" has an **Open** link to the watchlist. `watchlist-component.js:54-68`
- Remove shows a toast with **Undo** (5-minute encrypted token). `watchlist-component.js:70-90`; `WatchlistService.php:111-142`
- On change, every matching heart/label on the page updates, as do the header watchlist count, tab counts and the title page "On your My Movies for" summary. `watchlist-component.js:91-115`
- Storage: `user_movies (users_id, imdbid, categories '|'-joined ids)`. `WatchlistService.php:94-108`

---

## 6. Home page and header menu

### Home (`resources/views/content/home.blade.php`, `app/Services/Releases/HomeDashboard.php`)
- "Latest releases": 8 cards (All roots), with a "Browse all" link. Not Movies-specific. `home.blade.php:9-12`; `HomeDashboard.php:19-20`
- **"Watchlist"** section: latest release of up to 5 followed titles, **movies and TV mixed** (one row per title). Each row shows the title (→ title page), release name (→ details) and added date. Links to "View Watchlist". Empty text: "Follow a movie or show to see its latest release here." `home.blade.php:13-21`; `HomeDashboard.php:21-24`
- **"Trending this week"** (**Movies only**, needs `view movies`): header link "Movies" → `trending-movies`; 6 cover tiles (size S) sorted by grabs in the last 7 days, with **#1–#6 rank badges**, heart buttons, and the expanded-cover panel (fetched from `browse/movies?view=covers&sort=grabs`). Empty: "No trending titles yet." or "No trending titles available for your categories." `home.blade.php:22-29`; `HomeDashboard.php:25,30`
- CMS content blocks follow. `home.blade.php:30-36`

### Header menu (`resources/views/partials/header-menu.blade.php`)
- "Browse" mega-menu (highlighted on browse, tv, title and details pages unless trending). `header-menu.blade.php:5-8`
  - Movies section: root link "Movies" → `/browse/movies` (`:13`); one link per Movies sub-category → `/browse/movies/{id}` (`:14-16`); **"Trending Movies"** → `trending-movies` (`:17-19`). The section appears only if Movies is in the user's category list. `app/View/Composers/GlobalDataComposer.php:108,128-139`
  - No "My Movies" link (TV's section has "My Shows" → `watchlist?tab=tv`, `:20-23`).
- Top-level **"Trending"** item (shown only when the Movies root is visible; highlighted on `trending*` or `?trending=1`). `header-menu.blade.php:30-32`
- Top-level **"Watchlist {count}"** (movies + TV follow count). `header-menu.blade.php:33`. The same link is in the user menu. `header-menu.blade.php:55`

---

## Metadata fields read

### `movieinfo` columns (`database/schema/mariadb-schema.sql:805-830`)
| Line | Column | Type |
|---|---|---|
| 806 | id | int unsigned PK |
| 807 | imdbid | varchar(100) NOT NULL, UNIQUE (827) |
| 808 | tmdbid | int unsigned, idx (829) |
| 809 | traktid | int unsigned, idx (830) |
| 810 | title | varchar(255), idx (828) |
| 811 | tagline | varchar(1024) |
| 812 | rating | varchar(4) |
| 813 | rtrating | varchar(10) (RottenTomatoes) |
| 814 | plot | varchar(1024) |
| 815 | year | varchar(4) |
| 816 | genre | varchar(64) |
| 817 | type | varchar(32) |
| 818 | director | varchar(64) |
| 819 | actors | varchar(2000) |
| 820 | language | varchar(64) |
| 821 | cover | tinyint(1) flag |
| 822 | backdrop | tinyint(1) flag |
| 823-824 | created_at / updated_at | timestamp |
| 825 | trailer | varchar(255) |

Model: `app/Models/MovieInfo.php` (`$table='movieinfo'` :70; `releases()` hasMany via `imdbid`↔`imdbid` :82-85).

### Other movie-related tables and columns
- `user_movies` (`mariadb-schema.sql:3434-3445`): id 3435, users_id 3436, **imdbid** 3437, categories 3438 ('|'-joined category ids; NULL/''/'NULL' = all), created_at 3439, updated_at 3440, key (users_id, imdbid) 3442, FK users 3443.
- `releases`: **imdbid** varchar(100) 2802; **movieinfo_id** int 2808 ("FK to movieinfo.id", no constraint); proc_media_movie 2830; imdb_lookup_attempted_at/attempts 2840-2841; movie_record_lookup_attempted_at/attempts 2842-2843; indexes `ix_releases_movieinfo_cat` 2865 and `ix_releases_imdbid_password_cat_postdate` 2867.
- `users`: movieview 3521 and movie_layout 3539 (legacy; not read by any current Movies screen; movieview only in the admin user form); **view_prefs** 3546 (JSON; holds the `movies` view/size/per/thumbs preferences).
- `user_downloads` (timestamp, releases_id): trending source. `CoverBrowseScope.php:54-58`
- `media_infos.movie_name` 742 (media-info data, not movie metadata).
- No movie-genre table: `genres`/`video_genres` are for music/console/games/TV. Movie genre is the free-text `movieinfo.genre`.
- Images are files, not columns: `covers/movies/{imdbid}-cover.{webp|jpg|jpeg}`, resolved by file existence (`helpers.php:537-590`). The `movieinfo.cover` flag is not consulted by the web UI.
- Search: Manticore `movies` index, keyed by imdbid (covers search). `ReleaseBrowserMetadata.php:101`

### How a release links to a movie
- **All web screens use `releases.imdbid = movieinfo.imdbid`**: browse join `ReleaseBrowserMetadata.php:36`; covers `MovieBrowseService.php:121,155,162,178`; entity chip `app/Services/Releases/ReleaseEntityDataLoader.php:21,34`; title page `TitleMetadataLoader.php:18`; details `DetailsController.php:81-82`; watched `app/Services/Releases/ReleaseRowDataLoader.php:83-90`; `user_movies.imdbid`.
- **`releases.movieinfo_id`** is also written, looked up from imdbid (`app/Models/Release.php:370-387`). It is read only by the API/RSS/search-index paths (`Release.php:754,776-779`; `app/Services/Releases/ReleaseBrowseService.php:309,330,580`; the Search and Api classes).

### Fields read per screen
| Screen | movieinfo fields read (via releases.imdbid) | Other sources |
|---|---|---|
| Browse table/cards rows | title, year (entity chip); `imdbid` for artwork path | poster file; `user_movies.imdbid` (heart state); releases.* |
| Browse filters | year, genre, rating (filters); genre (options); title, actors, director, plot (covers search) | Manticore `movies` index |
| Browse covers | **selected**: imdbid, tmdbid, traktid, title, year, rating, plot, genre, director, actors, cover (`MovieBrowseService.php:149-150`). **displayed**: title, year (from first release's entity), rating, genre, imdbid (IMDb chip). tmdbid, traktid, plot, director, actors, cover are fetched but unused | poster file; counts from releases; `user_downloads` (trending) |
| Expanded cover | title | releases.* |
| Title page | `SELECT *` (`TitleMetadataLoader.php:33`). **displayed**: title, year, rating, genre, director, actors, plot, trailer, imdbid, tmdbid, traktid. `runtime` is read but the column does not exist | poster file; `user_movies.categories`; `categories`; release names (quality) |
| Details | MovieInfo model (all columns). **displayed**: title, year, tagline, rating, plot, genre, director, actors, language, trailer | poster file (header); Trakt/IMDb live trailer fetch; predb, comments, reports |
| Watchlist Movies tab | `title.*` select. **displayed**: title, year, imdbid (links, poster) | `user_movies`; latest release per imdbid; poster file |
| Watch picker | title | `user_movies.categories`; `categories` (root 2000) |
| Home watchlist and trending | title, year (entity); trending = covers set above | `user_movies`, `user_downloads` |

**No Movies screen reads:** `rtrating`, `type`, `backdrop` (neither the flag nor any backdrop file), the `cover` flag, or `created_at`/`updated_at`. Only the details page reads `tagline` and `language`. Only the title page shows `tmdbid`/`traktid` (as links).

---

## Links into each page

- **Movies browse `browse/movies[/{id}]`**: header mega-menu root and sub-categories (`header-menu.blade.php:13,15`); details breadcrumb (`details/index.blade.php:12`); title breadcrumb (`title/index.blade.php:5`); "Only titles I follow" (`browse/index.blade.php:16`); watchlist "View releases from these" (`watchlist/index.blade.php:7`); watchlist empty-state "Browse Movies" (`lists.blade.php:17`). Redirects into it: `trending-movies`, `Movies/{id?}`, `mymovies/browse`, `mymovies?id=browse`.
- **Title page `title/movies/{id}`**: entity chip on every movie release row, card or panel (`origin.blade.php:2,6`); XL cover title (`cover-detail.blade.php:5`); expanded-cover "Title page" (`expanded-cover.blade.php:13`); details breadcrumb (`details/index.blade.php:13`); details "View all N other releases" (`related.blade.php:7`); watchlist poster and title, including "Add" search results (`lists.blade.php:7-8,22,24`; `WatchlistService.php:188`); home watchlist row (`home.blade.php:17`). Redirects into it: `movie/{imdbid}`, `mymovies?id=add…` (with `watch=1`).
- **Details `details/{guid}`**: release name on every row, card, panel and expanded table; "Details" row action; Reported/Response chips (`facts.blade.php:4,7`); title page release list; details "Other releases" and "Similar releases"; watchlist "Latest:" (`lists.blade.php:25`); home watchlist and latest releases.
- **`trending-movies`**: `header-menu.blade.php:18,31`; `home.blade.php:23`.
- **`movie/{imdbid}` (`movie.view`)**: no template or JS link; only from `Movies?imdb=` (`MovieController.php:19`).
- **`Movies/{id?}` (`Movies`)**: details movie-info Genre/Director/Cast links (`helpers.php:96` via `DetailsController.php:86-92`). No other link.
- **`mymovies`, `mymovies/browse`**: no in-app links (only the nav highlight pattern `header-menu.blade.php:33` and the clearance check `ClearanceMiddleware.php:76`).
- **`watchlist`** (Movies tab): header nav and user menu (`header-menu.blade.php:33,55`); home "View Watchlist" (`home.blade.php:14`); picker success toast "Open" (`WatchlistService.php:68`, `watchlist-component.js:63`); `mymovies` redirect.
- **Watch picker `watchlist/movies/{id}`**: every `x-watch-button`: `actions.blade.php:6-8`, `covers.blade.php:23-25`, `cover-detail.blade.php:12-14`, `title/index.blade.php:15,25-26`, `details/partials/header.blade.php:18-20`, `lists.blade.php:9,29-30`.

---

## Shared vs Movies-only

### Movies-only
- `app/Services/MovieBrowseService.php` (the covers grouping query). `ReleaseCoverBrowser.php:71`
- Browse: "Only titles I follow" button (`browse/index.blade.php:15-17`); "Trending Movies" and "Movies you follow" titles (`BrowseController.php:67-72`); trending rank badge (`covers.blade.php:27-29`); IMDb chip on XL covers (`cover-detail.blade.php:9-11`); cover heart/watch URL, among cover roots (`ReleaseCoverBrowser.php:126`); cover line and metadata (`ReleaseCoverBrowser.php:95,107`); imdbid as the cover and expansion key (`ReleaseCoverBrowser.php:44,114`); movie field search in covers (`ReleaseBrowserQuery.php:121-122`, `ReleaseBrowserMetadata.php:95-111`); filter fields year/genre/rating and the movieinfo join (`ReleaseBrowserMetadata.php:22,36`).
- Title page: watch state and "My Movies" summary (`TitleController.php:45-62`; TV has its own show page); Movies metadata set, IMDb/TMDB/Trakt links and trailer (`TitleMetadataLoader.php:43-44,68,89-90`).
- Details: the `movie-info.blade.php` partial, trailer resolution and live fetch, field links to `/Movies` (`DetailsController.php:79-101`).
- Home "Trending this week" (`home.blade.php:22-29`, `HomeDashboard.php:25`).
- Header "Trending Movies" and top-level "Trending" (`header-menu.blade.php:17-19,30-32`).
- Legacy redirect controllers `MovieController`, `MyMoviesController`.

### Movies + TV (shared by the two "followable" roots)
- Watch button and picker, the `watching` filter, the `trending` filter (TV variant keyed on `videos_id`), the watchlist page and tabs, and the home watchlist section. `watch-button.blade.php`; `actions.blade.php:6-8`; `ReleaseBrowserQuery.php:60-64,94-119`; `WatchlistService.php`; `WatchlistController.php:68-77`.

### Shared with all or most roots (changing these affects TV, Audio, Console, Games, Books, Adult, All, Other, search, cart, poster identity and home)
- `x-release-browser` and all of `components/release-browser/*`: table, row, card, facts, origin, actions, artwork, pager, covers, cover-art, cover-detail, panel, expanded-cover, toolbar.
- `components/release-facts.blade.php`, `release-completion-chips.blade.php`, `chip.blade.php`, `entity-chip.blade.php`, `origin-chip.blade.php`, `year-picker.blade.php`, `partials/release-modals.blade.php`.
- `ReleaseBrowserState`, `ReleaseBrowserQuery`, `ReleaseSort`, `ReleaseCoverBrowser`, `ReleaseRowDataLoader`, `ReleaseEntityDataLoader`.
- The details page shell and partials (every non-TV root). The title page and `TitleReleaseBrowser`/`TitleMetadataLoader` (Movies, Audio, Console, Games, Books). `RelatedReleaseBrowser`.
- JS: `release-browser-component.js`, `release-cover-browser.js`, `release-details-component.js`, `title-overview-component.js`, `trailer-modal-component.js` (only Movies emits `data-trailer-url` today), `watchlist-component.js`.
- `x-resolution-chip` is **not** used on any Movies screen (TV only).

### Oddities worth knowing before a redesign
1. Covers "Name A–Z" sorts by release name, not movie title. `ReleaseSort.php:37-42`, `CoverBrowseScope.php:40`
2. Movies with releases but no `movieinfo` row (or an empty title) never appear in covers but do appear in table/cards. `MovieBrowseService.php:90`
3. The title-page "Runtime" can never show (no column). `TitleMetadataLoader.php:44`
4. The XL cover `genres` line can never show (`ReleaseCoverItem::genres` is never set). `cover-detail.blade.php:6`
5. The rating filter and min-completion work by URL only; there is no control for either.
6. The details Genre/Director/Cast links go through the legacy `/Movies` redirect, landing on covers view with a field search.
7. The details page can make a live Trakt/IMDb request for a trailer. `MovieService.php:159-171`
8. `MovieBrowseService::getBrowseBy` / `getTextSearchWhere` / `getMovieOrder` are unreachable from web browse (the scope is always passed). `MovieBrowseService.php:65,73,88`
