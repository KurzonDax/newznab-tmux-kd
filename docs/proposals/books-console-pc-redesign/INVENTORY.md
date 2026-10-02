# Books, Console and PC screens: feature inventory (master @ 85a680951, 2026-10-01)

Scope: what a user can see and do on every Books (root 7000), Console (root 1000) and PC (root 4000) screen today, with `path:line` refs.
All paths are relative to the repository root (`newznab-tmux-kd`). Read on master `85a680951` with a clean tree; line numbers may drift. Read-only survey; nothing in the repo was edited.

Notes up front:
- **Root names in code do not match the labels.** `BrowseRoot::Console` is root 1000 ("Console"). `BrowseRoot::Games` is root 4000 and is labelled "PC". `BrowseRoot::Books` is root 7000. `app/Enums/BrowseRoot.php:50-52,90`
- **Current list URLs:** Books `/browse/books`, Console `/browse/console`, PC `/browse/games` (alias `/browse/pc` works too, `BrowseRoot.php:26`). A sub-category is `/browse/{root}/{id or title}`.
- **Current details URL** for any release: `/details/{guid}` (`routes/web.php:219`). These three bands use the generic details page; only TV, Movies and Adult have their own branch (`app/Http/Controllers/DetailsController.php:74-82`).
- **Title pages exist** for these three roots: `/title/books/{bookinfo id}`, `/title/console/{consoleinfo id}`, `/title/games/{gamesinfo id}` (`/title/pc/{id}` also resolves) — `routes/web.php:214`, `app/Http/Controllers/TitleController.php:16-17`.
- All three bands use the shared release browser (`BrowseController` + `x-release-browser`), the same one Adult used before its redesign. Almost everything on the list screen is shared with Audio.

---

## 1. Routes, entry points and navigation

### Routes
- **Browse route:** `browse/{parentCategory}/{id?}` → `BrowseController::show`, `clearance` middleware, GET|POST — `routes/web.php:201`.
  - `BrowseRoot::fromRoute` maps `books`, `console`, `games` and the alias `pc` (any case) — `app/Enums/BrowseRoot.php:21-29`.
  - `{id}` is a numeric id or a sub-category title inside the root; unknown → 404 — `app/Http/Controllers/BrowseController.php:30-34`.
  - A sub-category in the user's exclusions → plain 403 — `BrowseController.php:35`.
  - These roots are not redirected (only TV and Movies are, lines 37-42); they render `browse.index` via `renderBrowser` (lines 44, 47-91).
- **Legacy routes (redirect only),** inside the clearance group — `routes/web.php:233-237`:
  - `Games` (no id) → `GamesController::show` — `routes/web.php:234`, `app/Http/Controllers/GamesController.php:14-17`.
  - `Console/{id?}` → `ConsoleController::show` — `routes/web.php:236`, `app/Http/Controllers/ConsoleController.php:14-17`.
  - `Books/{id?}` → `BooksController::index` — `routes/web.php:237`, `app/Http/Controllers/BooksController.php:14-17`.
  - All three call `LegacyCoverRedirect::redirect` — `app/Services/Releases/LegacyCoverRedirect.php:15-51`:
    - Drops `t`, `ob`, `parentCategory`, `id`, `_token` (line 17).
    - `title=` becomes `q=` (lines 18-21).
    - `ob=` becomes `sort=`: `title_asc`→`title`, `year_desc`→`year`, `rating_desc`→`rating`, `artist_asc`→`artist`, `stats_desc`→`grabs`, else `newest` (lines 22-27).
    - For Console and Games, a numeric `genre=` id becomes the genre title (lines 28-33). Books is not translated.
    - Forces `view=covers` unless given (line 34).
    - `{id}` path is a sub-category title; `WiiVare` is rewritten to `WiiVareVC` (line 45). Or `?t=<id>` (line 46-47).
    - Redirects to `route('browse', ['parentCategory' => root value, 'id' => category id, ...])` (line 50).
- **No lowercase top-level routes:** `routes/web.php` registers no `/books`, `/console`, `/games` or `/pc` page (only the capitalised legacy ones above).
- **Title route:** `title/{root}/{id}` → `TitleController::show` — `routes/web.php:214` (section 4).
- **Details route:** `details/{guid}` — `routes/web.php:219` (section 3).
- **Web search:** `/search?t=1000|4000|7000` (or a sub-id) — `routes/web.php:264`. No root-specific code for these roots in search.
- **Home page:** no Books, Console or PC section. The only blocks are "Latest releases" (All root, cards, 8) and the followed-titles strip (Movies/TV) — `app/Services/Releases/HomeDashboard.php:19-23`.

### Sub-categories (as seeded)
- Console 1000 — `database/seeders/RootCategoriesTableSeeder.php:26-27`; subs `database/seeders/CategoriesTableSeeder.php:40-158`:
  1010 NDS, 1020 PSP, 1030 Wii, 1040 Xbox, 1050 Xbox 360, 1060 WiiWare VC, 1070 Xbox 360 DLC, 1080 PS3, 1110 3DS, 1120 PS Vita, 1130 WiiU, 1140 Xbox One, 1180 PS4, 1999 Other.
- PC 4000 — `RootCategoriesTableSeeder.php:53-54`; subs `CategoriesTableSeeder.php:301-356`:
  4010 0day, 4020 ISO, 4030 Mac, 4050 Games, 4060 Phone-IOS, 4070 Phone-Android, 4999 Phone-Other.
- Books 7000 — `RootCategoriesTableSeeder.php:80-81`; subs `CategoriesTableSeeder.php:562-608`:
  7010 Magazines, 7020 Ebook, 7030 Comics, 7040 Technical, 7060 Foreign, 7999 Other.
- Constants — `app/Models/Category.php:56-82` (GAME_*), `:118-132` (PC_*), `:180-190` (BOOKS_*), roots `:194,200,206`.

### Header navigation (#908 drop-downs) — `resources/views/partials/header-menu.blade.php`
- Root order: Movies, TV, Audio, **Books, Console, PC**, Adult, Other — `app/View/Composers/GlobalDataComposer.php:160`.
  - A root shows only if `Category::getForMenu` returns at least one non-excluded sub-category for it — `GlobalDataComposer.php:101-103,156-166`, `app/Models/Category.php:571-588`.
- Each root is a button with a drop-down — `header-menu.blade.php:8-10`. Button text is `$root->label()`: "Books", "Console", "PC".
- Books / Console / PC have no dedicated list route, so `$list` is null (line 7) and the items are:
  - **"All Books"** → `url('/browse/books')`; **"All Console"** → `/browse/console`; **"All PC"** → `/browse/games` — line 11.
  - Then one item per sub-category → `/browse/{books|console|games}/{category id}` — lines 13-15.
  - No extra items for these roots (the extra divider links are Movies "Films" and TV "TV Shows"/"My Shows" only, lines 16-24).
- **"All" drop-down** (last button): "Browse by group" → `route('browsegroup')` (`/browsegroup`), "All Releases" → `route('browse.all')` (`/browse/all`) — `header-menu.blade.php:28-34`.
- **Current section highlight:** a `browse` route marks its root; a `title` route marks its root; a details page marks the release's root — `GlobalDataComposer.php:131-143`.
- **Header search scope:** options include "Books" (7000), "Console" (1000), "PC" (4000) — `header-menu.blade.php:37`.

### Permissions and per-user hiding
- Permissions: `view books`, `view console`, `view pc` — `BrowseRoot.php:68-70`.
- **ClearanceMiddleware:**
  - `browse/{root}` and `browse/{root}/{sub}` check the root's permission through `BrowseRoot::fromRoute`, so the `pc` alias gets the same check — `app/Http/Middleware/ClearanceMiddleware.php:39-70,147-156`. Blocked → `errors.category-disabled`, 403 (lines 211-216); the PC root is named "PC" there (`BrowseRoot.php:79-82`).
  - A sub-category given by **title** is checked against the user's exclusions (lines 50-55, 163-191). A numeric id (what the header links use) is not matched there; `BrowseController.php:35` returns a plain 403 instead.
  - Legacy `Console*`, `Books*`, `Games*`/`PC*` paths: lines 82-97, 118-124.
- **Account → Categories:** "Show categories" checkboxes `viewconsole`, `viewpc`, `viewbooks`; "Exclude subcategories" per root — `resources/views/account/appearance.blade.php:20-33`.
- **Browse query** removes excluded categories — `app/Services/Releases/ReleaseBrowserQuery.php:67`.
- **Details page** checks the exclusion list and shows the category-disabled page — `DetailsController.php:61-63`, `app/Http/Controllers/BasePageController.php:92-103`.
- **Title page** checks the direct permission and the `t=` sub-category exclusion with plain 403s — `TitleController.php:18-19`, `app/Services/Releases/TitleReleaseBrowser.php:27-32`.

### Admin pages (pointer only)
- Books: `admin/book-list`, `admin/book-edit` — `routes/web.php:309-310`. Console: `admin/console-list`, `admin/console-edit` — `routes/web.php:415-416`. PC: `admin/game-list`, `admin/game-edit` — `routes/web.php:418-419`. Details header has "Edit release" for Admin/Moderator — `resources/views/details/partials/header.blade.php:19`.

---

## 2. Browse screen: `browse/{books|console|games}/{id?}` (`browse.index`)

View: `resources/views/browse/index.blade.php`. Component: `resources/views/components/release-browser.blade.php` + `components/release-browser/*`. Alpine: `resources/js/alpine/components/release-browser-component.js`, `release-cover-browser.js`.

### Page frame
- Breadcrumb "Browse › {Books|Console|PC}" — `browse/index.blade.php:9`.
- Page title "Books" / "Console" / "PC", or "{root} · {sub-category}"; icon `fa-compass` — `browse/index.blade.php:10`, `BrowseController.php:69`.
- "Clear filter" button only when a group or poster filter is set — `browse/index.blade.php:12-14`.
- Modals: file list, NFO, preview, media info, image, report — `browse/index.blade.php:3-5` (`partials/release-modals`).

### Views, sizes and remembered preferences
- **Books and Console:** Table, Cards, Covers. **PC:** Table and Covers only (no Cards) — `BrowseRoot.php:114-115`.
- Cover sizes S, L, XL for all three — `BrowseRoot.php:120-123`.
- Per page 24 / 48 / 100, default 48 — `app/Data/ReleaseBrowserState.php:106`.
- **Saved per user and per root** in `users.view_prefs[books|console|games]`: view, size, per, thumbs. Defaults table / s / 48 / thumbs off — `app/Models/User.php:210-216`, `ReleaseBrowserState.php:86,104-107`.
  - Saved by POST `/profile/update-view` — `routes/web.php:258`, `app/Http/Controllers/ReleaseViewPreferencesController.php:15-31`, validated in `app/Http/Requests/UpdateReleaseViewRequest.php:22-31`.
  - **Sort is not saved** for these roots (only TV, Movies, Adult may save one) — `UpdateReleaseViewRequest.php:28`.
  - Account → Appearance lists the saved view per root — `resources/views/account/appearance.blade.php:9-13`.
  - Legacy `users.consoleview` / `bookview` / `gameview` columns (`database/schema/mariadb-schema.sql:3597-3598`) are only read and written by the admin user form — `app/Http/Controllers/Admin/AdminUserController.php:132-134,233-236`.
- **"Following" (`watching=1`):** not available (All, Movies, TV only) — `ReleaseBrowserState.php:115`.
- **Letter jump (# A–Z):** Books **covers** view only (also Audio) — `ReleaseBrowserState.php:62-65,98-99`, bar at `release-browser.blade.php:11-18`.
  - Clicking a letter redirects to the page holding the first title with that initial and forces `sort=title` — `BrowseController.php:50-54`, `app/Services/Releases/ReleaseCoverBrowser.php:21-37`.
- **Count unit:** "releases" in Table and Cards; in Covers "books" (Books) or "games" (Console, PC) — `ReleaseBrowserState.php:42-54`.
- **Hidden URL-only inputs:** `minc` (minimum completion) — `ReleaseBrowserState.php:117`; `group=` and `poster=` force table-only — `ReleaseBrowserState.php:94`.

### Toolbar (`resources/views/components/release-browser/toolbar.blade.php`)
- **Search box** "Search in Books|Console|PC" — line 3, debounced.
  - Table/Cards: matches the release name `COALESCE(display_name, searchname) LIKE` — `ReleaseBrowserQuery.php:124-126`.
  - Covers: matches the metadata title `m.title LIKE` only (Audio also matches artist; Books does **not** match author) — `ReleaseBrowserQuery.php:117-123`.
- **Filters** come from `ReleaseBrowserMetadata::fields` — `app/Services/Releases/ReleaseBrowserMetadata.php:23-25`:

| Root | Filters (URL key) | Source column |
|---|---|---|
| Books | Year (`year`, `year_from`, `year_to`), Genre (`genre`), Author (`author`) | `SUBSTR(bookinfo.publishdate,1,4)`, `bookinfo.genre`, `bookinfo.author` (line 25) |
| Console | Year, Genre, Platform (`platform`), Publisher (`publisher`) | `SUBSTR(consoleinfo.releasedate,1,4)`, `console_genres`→`genres.title`, `consoleinfo.platform`, `consoleinfo.publisher` (line 23) |
| PC | Year, Genre, Platform, Publisher | `SUBSTR(gamesinfo.releasedate,1,4)`, `genres.title` via `gamesinfo.genres_id`, the constant `'PC'`, `gamesinfo.publisher` (line 24) |

  - Join: `LEFT JOIN {bookinfo|consoleinfo|gamesinfo} m ON m.id = r.{…}info_id`; Console and PC also left-join `genres` on `m.genres_id` — `ReleaseBrowserMetadata.php:30-54` (genres join line 49-50).
  - Year uses `x-year-picker` (single year or range); options are `YearRange::years()` (next year down to `MIN_YEAR`) — `toolbar.blade.php:5-6`, `ReleaseBrowserMetadata.php:61-70,100-102`, `app/Support/YearRange.php:79-82`.
  - Other filters are a `<select>` labelled with the key name ("Genre", "Platform", "Publisher", "Author") — `toolbar.blade.php:7-16`. A select is hidden when it has no options (line 7).
  - Option lists are the distinct values in the current list (base query without filters) — `ReleaseBrowserMetadata.php:93-118`. Books genres are split on `,` and `|` (lines 112-114). Console genres come one per `console_genres` row (lines 106-110).
  - Matching: Console genre = any of the game's `console_genres` titles (lines 76-80); Books/PC genre = token match inside the delimited text (lines 81-85); Platform, Publisher, Author = exact equality (lines 86-87).
- **"Clear"** button when any filter is active — `toolbar.blade.php:19-21`; clears the keys listed in `release-browser-component.js:134-138`.
- **Total count** — `toolbar.blade.php:24`.
- **Sort select** — `toolbar.blade.php:25-29`; six options, the same for every root — `app/Enums/ReleaseSort.php:22-29`, `ReleaseBrowserQuery.php:50-53`:
  - Posted · Newest, Posted · Oldest, Added · Newest (default), Added · Oldest, Name · A–Z, Grabs · Most. Columns at `ReleaseSort.php:32-46`.
  - In Covers the same sort is applied per title as `MIN`/`MAX` of the release column — `app/Services/Releases/CoverBrowseScope.php:32-39`, `ReleaseSort.php:41-43`.
- **View segment** Table / Cards (not PC) / Covers — `toolbar.blade.php:30-38`. Cards tooltip "Renamed, post-processed releases only" (line 33).
- **Table:** thumbnails toggle — `toolbar.blade.php:39-40`. **Covers:** "Cover size" S / L / XL — lines 41-47.

### Pager (`components/release-browser/pager.blade.php`)
- Above and below the list — `release-browser.blade.php:19,48`.
- "Page X of Y · N {unit}"; in Cards adds "· renamed and post-processed only (N not shown)" — `pager.blade.php:7-13`.
- Per-page 24/48/100 segment (lines 15-20), prev/next with a ±2 window and first/last with ellipses (lines 22-42), "Go to page" input (line 43).
- A page past the end redirects to the last page — `BrowseController.php:66-68`.

### Table view (`table.blade.php`, `row.blade.php`)
- Columns: select-all, Release, Category, Size, Files, Added, Posted, Stats, Actions — `table.blade.php:5-7`.
- Row: checkbox (`row.blade.php:5`), optional thumbnail (8-10), release name → details (13), facts (15), origin chips (16), category pill (20), file count → file-list modal (22-24), Added / Posted (25-26), grabs and comments (27-30), actions (31-33).
- **Thumbnail** (`artwork.blade.php`): the metadata cover file (`covers/book/{id}`, `covers/console/{id}`, `covers/games/{id}`), else `getReleaseCover`, else the root icon (`fa-book-open` or `fa-gamepad`); shape `tall` — `artwork.blade.php:3,11-22`, `app/Services/Releases/ReleaseEntityDataLoader.php:24-26,49`, `app/Extensions/helper/helpers.php:560-620`, icons `BrowseRoot.php:102-103`.
  - Artwork is found by file on disk, not by the `cover` column — `helpers.php:505-551`.
- **Facts** (`facts.blade.php`): `<x-release-facts>` (completion % + repair chip, Password, Media Info, NFO, Preview/Clip, Sample — `resources/views/components/release-facts.blade.php:38-70`), "Reported (n)" (lines 3-5), "Response" (lines 6-8).
- **Origin** (`origin.blade.php`):
  - **Entity chip** when the release has a metadata row: title · year, icon gamepad or book, links to the title page — `origin.blade.php:5-7`, `resources/views/components/entity-chip.blade.php:4-15`, URL from `app/Data/ReleaseEntityData.php:25`.
  - Group chip → `/browse/all?group=` and poster chip → `/browse/all?poster=` (lines 8-9).
  - Entity source per root: `bookinfo` (year from `publishdate`), `consoleinfo` and `gamesinfo` (year from `releasedate`) — `ReleaseEntityDataLoader.php:24-26,55-80`.
- **Actions** (`actions.blade.php`): Download NZB (2), Details (3), Basket toggle (4), Report (5). No watch/follow button (Movies/TV only, 6-8).

### Cards view (Books, Console) — `card.blade.php`
- Checkbox, tall artwork, title, facts, origin, then Size / Added / Posted / Grabs, then actions — `card.blade.php:4-17`.
- Only post-processed, `isrenamed=1` releases — `ReleaseBrowserQuery.php:26-30`, `app/Services/Releases/ReleaseRowDataLoader.php:174-180`.

### Covers view: one tile per metadata title
- **Data:** `ReleaseCoverBrowser::paginate` → `BookService::getBookRange`, `ConsoleService::getConsoleRange`, `GamesService::getGamesRange` — `ReleaseCoverBrowser.php:61-82`.
  - Each tile is one `bookinfo` / `consoleinfo` / `gamesinfo` row joined **INNER** to its releases; releases without a metadata row never appear in Covers — `app/Services/BookService.php:213-234`, `app/Services/ConsoleService.php:212-235`, `app/Services/GamesService.php:265-287`.
  - Rows with an empty title are left out — `BookService.php:195`, `ConsoleService.php:194`.
  - The current filters, search, exclusions and password policy apply through `CoverBrowseScope` (`r.id IN (…)`) — `CoverBrowseScope.php:22-29`.
  - Up to 2 newest releases are attached per tile (`ROW_NUMBER() … rn <= 2`) — `BookService.php:247-261`, `ConsoleService.php:248-262`, `GamesService.php:301-315`.
  - Results are cached unless watching/basket — `CoverBrowseScope.php:28`, `BookService.php:202-210`.
- **Tile fields** (`ReleaseCoverBrowser::item`, lines 84-119; `app/Data/ReleaseCoverItem.php:13-26`):

| Field | Books | Console | PC |
|---|---|---|---|
| Title | `bookinfo.title` | `consoleinfo.title` | `gamesinfo.title` |
| Identifying line | author · year of `publishdate` | platform · year of `releasedate` | "PC" · year of `releasedate` |
| Footer badge (size L) | `bookinfo.genre` | `consoleinfo.esrb` | genre title |
| Metadata chips (XL) | author, publisher | platform, publisher, ESRB | "PC", publisher, genre |
| Release count | releases linked to the title | same | same |
| Title URL | `/title/books/{id}` | `/title/console/{id}` | `/title/games/{id}` |

- **Grid** (`covers.blade.php`): `data-shape="tall"` (line 1); count badge (line 9); title and identifying line (lines 11-12); footer chip + "N releases" at size L only (lines 13-18).
- **Art** (`cover-art.blade.php:1-9`): cover image or root icon, title overlay; failed image falls back to the icon (`release-cover-browser.js:152-156`).
- **XL layout** (`cover-detail.blade.php`): art; title linked to the title page plus year (line 5); a genres line that only shows when `genres` is set (line 6); metadata chips (7-9); "N releases"; the attached releases as panels (`panel.blade.php`); "View all N releases" button (line 18).
- **Clicking a tile** (S/L) or "View all" (XL) opens an inline panel (modal on small screens):
  - Fetches `?view=covers&_fragment=cover&cover=<metadata id>&release_page&release_per` — `release-cover-browser.js:33-81`.
  - Server: `BrowseController.php:55-61` → `ReleaseCoverBrowser::expanded`, filtering `r.{book|console|games}info_id = id`, newest added first, 24/48/100 per page — `ReleaseCoverBrowser.php:40-58`.
  - Esc closes, Left/Right moves to the neighbouring tile — `release-cover-browser.js:133-150`.
- **Expanded panel** (`expanded-cover.blade.php`): title + "· N releases" (lines 7-8), "Select all on this page", "Download selected" (10-11), **"Title page"** button (12-14), close (15), the shared table (17), Previous/Next and per-page select (18-27).

### Selection and bulk actions (all views)
- Floating bar: "N selected", "Add to basket", "Download N NZBs", "Clear" — `release-browser.blade.php:51-57`.
- JS: `downloadSelected` → POST `/getnzb` with `zip=1` (`release-browser-component.js:41-60`), `changePreference` (75-84), `toggleBasket` (140-157), `addSelectedToBasket` (159-171).

### Empty state
- `x-empty-state` with the root icon; "Clear filters" when filters are active — `release-browser.blade.php:33-46`.

---

## 3. Release details page: `details/{guid}` for a book, console or PC release

### Controller (generic path) — `app/Http/Controllers/DetailsController.php:46-198`
- Exclusion check (61-63), comment post-back (65-70), similar releases restricted to the root and exclusions (83; `app/Services/Releases/ReleaseSearchService.php:1390`), failed-download count (84), reports (85-87), PreDB (154).
- Metadata loaded by foreign key, independent of category:
  - `gamesinfo_id > 0` → `GamesService::getGamesInfoById` (`gamesinfo.*` + `genres.title AS genres`) — `DetailsController.php:113-116`, `GamesService.php:105-112`.
  - `bookinfo_id > 0` → `BookService::getBookInfo` (`bookinfo.*`) — `DetailsController.php:123-126`, `BookService.php:79-86`.
  - `consoleinfo_id > 0` → `ConsoleService::getConsoleInfo` (`consoleinfo.*` + first genre title AS `genres`) — `DetailsController.php:128-131`, `ConsoleService.php:81-88`.
  - Movie info is also loaded when the release has a valid `imdbid` (lines 93-111).
- "Other releases of this title": same metadata id, newest first, 10 per page (`other_page`) — `app/Services/Releases/RelatedReleaseBrowser.php:20-41`.

### Layout — `resources/views/details/index.blade.php`
- **Breadcrumb:** `{root label}` → `/browse/{books|console|games}` › metadata title → title page (when there is one) › sub-category — lines 11-15.
- **Header** (`details/partials/header.blade.php`):
  - Tall artwork (metadata cover → release cover → icon) — line 2.
  - Release name (4); facts chips + group chip (5).
  - Category pill, size, files, "Completion not measured" when unmeasured, added, posted, grabs, comments link (6-11).
  - Buttons: Download NZB, Add/Remove basket, NFO, Media info, Files (n), Report, "Edit release" (Admin/Moderator) (12-20).
  - "N users reported download failure" (21).
- **Tabs:** Overview, Files (n), Media info, NFO, Comments (n) — `details/index.blade.php:19-23`. Files, Media info and NFO load lazily (43-58).
- **Overview** (24-42): preview images (`preview-images.blade.php`), audio preview, then every metadata partial whose data exists, then PreDB, password info, Group / Poster / Password status (36-40), reports (41).
- **Aside** (`details/partials/related.blade.php`):
  - "Other releases of this title": each row is a quality/format label (else category), size, completion chips (lines 2-6). "View all N other releases" → title page (7).
  - "Similar releases" by name (10-13).

### Metadata partials (what each shows today)
- **`console-info.blade.php`** — heading "Console Game Information" (line 11). Shows **Title, Publisher, Release Date** only (14-31). Not shown: platform, genre, ESRB, review, IGDB link.
- **`game-info.blade.php`** (PC) — heading "Game Information" (line 12). Shows **Title, Publisher, Release Date, Genres** (the one genre title) (15-38). Not shown: review, ESRB, trailer, backdrop, Steam/IGDB link.
- **`book-info.blade.php`** — heading "Book Information" (line 13). Shows **Title, Author, Publisher, Published, Overview** (16-45). Not shown: pages, ISBN, genre, link.
- None of the three partials has an external link or button. External links exist only on the title page (section 4).

### Preview images
- Preview `_thumb` if `haspreview=1`, sample `_thumb` if `jpgstatus=1`, video preview if `videostatus=1`, each opening the image or preview modal — `details/partials/preview-images.blade.php:2-80`.

---

## 4. Title page: `title/{books|console|games}/{id}` (shared with Audio)

- Controller: `TitleController::show` — allows Audio, Console, Games (PC), Books only; checks `view {console|pc|books}` direct permission; id must be a positive integer — `app/Http/Controllers/TitleController.php:14-31`.
- Metadata: `TitleMetadataLoader::load` — `app/Services/Releases/TitleMetadataLoader.php:31-72`:
  - Source tables: `consoleinfo` / `gamesinfo` / `bookinfo`; artwork types `console` / `games` / `book` (lines 22-24).
  - Genre: Console = all `console_genres` titles in order, joined by `,` (line 39); PC = the one `genres_id` title (line 40); Books = `bookinfo.genre` text (line 41).
  - Metadata list (empty, `0` and `0000-` values dropped, lines 57-58):
    - Console: Platform, Publisher, Genre, Released, ESRB (lines 51-52).
    - PC: Platform ("PC"), Publisher, Genre, Released, ESRB (lines 51-52).
    - Books: Author, Publisher, Published, Pages, ISBN, Genre (lines 53-54).
  - Overview text: Books `bookinfo.overview` only; Console and PC get none (lines 59-62).
  - Subtitle: the year (line 68).
  - External links (lines 87-120): Books "ISBNdb" from a 10/13-digit ISBN (102-107); any `url` labelled by host — Goodreads, ISBNdb, iTunes, IGDB, Steam, else "Website" (108-117).
- View: `resources/views/title/index.blade.php`:
  - Breadcrumb `{root}` → `/browse/{root value}` › title (line 5).
  - Artwork or placeholder (7-10), title + year (12), link buttons through the dereferrer, new tab (13-17), metadata list (18-20), overview (21), stats Releases / Latest (23-27).
- Releases list: `title/partials/releases.blade.php` + `TitleReleaseBrowser::load`:
  - All releases with this metadata id, newest added first, 100 per page, no toolbar, own pager — `TitleReleaseBrowser.php:24-60`, `releases.blade.php:9-15`.
  - "Quality" chips when more than one quality: video resolution tokens, plus EPUB/PDF/MOBI/AZW(3) for Books — `releases.blade.php:2-7`, `app/Support/ReleaseQuality.php:24-40`.

---

## 5. Metadata tables, writers and lookup settings

### `bookinfo` — `database/schema/mariadb-schema.sql:115-137`
- Columns: `id`, `title`, `author`, `asin` (unique), `isbn`, `ean`, `url`, `salesrank`, `publisher`, `publishdate` (datetime), `pages` (varchar), `overview` (varchar 3000), `genre` (varchar, free text), `cover` (0/1), `created_at`, `updated_at`. FULLTEXT on (author, title).
- Release link: `releases.bookinfo_id`.
- Writer: `BookService::updateBookInfo` → `persistBookCandidate` — `app/Services/BookService.php:759-899,1180-1255`.
  - Providers in order: ISBNdb (776-811), Google Books (816-849), Open Library (853-881), iTunes (885-896).
  - Insert or fill-only-non-empty update; cover downloaded to `storage_path('covers/book/')` — `BookService.php:69,1217-1252`.
- Candidates: categories 7000-7999 **and** Audio › Audiobook 3030, without `bookinfo_id` — `app/Services/MetadataProcessing/BookProcessingCandidateQuery.php:28-30`, `BookService.php:399-420`.
- Enabled by setting `lookupbooks` (+ `maxbooksprocessed`) — `app/Services/BooksProcessor.php:21`, `app/Support/Settings/Sections/MetadataLookupsSection.php:206-230`.

### `consoleinfo` — `mariadb-schema.sql:283-300`
- Columns: `id`, `title`, `asin` (unique), `url`, `salesrank`, `platform`, `publisher`, `genres_id` (first genre), `esrb`, `releasedate` (datetime), `review` (varchar 3000), `cover` (0/1), `created_at`, `updated_at`. FULLTEXT on (title, platform).
- **`console_genres`** (#917) — `mariadb-schema.sql:270-279`: `consoleinfo_id`, `genres_id`, `position` (0-based source order). PK (`genres_id`, `consoleinfo_id`); cascades on delete.
  - Created by `database/migrations/2026_10_01_000000_add_console_genres.php:18-26`; filled from the old combined titles by `2026_10_01_000100_fill_console_genres.php:20-44`.
  - Kept in step with `consoleinfo.genres_id` by `ConsoleGenres::replace` — `app/Services/MetadataProcessing/ConsoleGenres.php:62-76`. Genres are `genres` rows with `type = 1000` (line 132).
- Release link: `releases.consoleinfo_id` (`-2` = looked up, not found; `ConsoleService.php:537,556`).
- Writer: `ConsoleService::processConsoleReleases` → `updateConsoleInfo` → `IGDBService::searchConsole` + `buildConsoleData` → `updateConsoleTable` — `ConsoleService.php:426-452,461-489,500-566,674-740`.
- Candidates: categories 1000-1999 without `consoleinfo_id` (mode 2 = renamed only) — `app/Services/MetadataProcessing/ConsoleProcessingCandidateQuery.php:26-45`.
- Enabled by `lookupgames` (shared with PC) + `maxgamesprocessed`, paced by `amazonsleep` — `app/Services/ConsolesProcessor.php:21`, `ConsoleService.php:68-69`, `MetadataLookupsSection.php:231-271`.
- IGDB needs `igdb.credentials.client_id` and `client_secret` — `app/Services/IGDBService.php:83-87`.

#### `consoleinfo` column ← IGDB source (`IGDBService::buildConsoleData`, `IGDBService.php:404-424`; written at `ConsoleService.php:674-740`)

| Column | Comes from |
|---|---|
| `title` | `game.name` (line 411) |
| `asin` | `game.id` as a string (412) |
| `url` | `game.url` (417) |
| `salesrank` | always `''` (422); `null` on update (`ConsoleService.php:728`) |
| `platform` | name of the game's platform matching the release's platform hint, else its first platform (`IGDBService.php:408,419,577-600`) |
| `publisher` | names of `involved_companies` flagged `publisher`, each looked up with `Company::find`, joined with `,`; `Unknown` when none (407,418,543-570) |
| `genres_id` + `console_genres` rows | `genres.name` in order; if there are no genres, `themes.name`; `Unknown` when neither (406,420-421,513-538; `ConsoleService.php:680,697,723`) |
| `esrb` | `age_ratings`: the ESRB rating, else "PEGI x", else null (416,721-757) |
| `releasedate` | earliest `release_dates.date` for the matched platform, else `first_release_date` (415,796-834) |
| `review` | `game.summary` only, cut to 3000 (413; `ConsoleService.php:693`) |
| `cover` | 1 when `cover.image_id` gives a URL and the download to `covers/console/{id}` succeeds (414; `ConsoleService.php:432-436,699-709`) |

#### IGDB fields requested but not stored for Console
- The query asks for top-level `fields *` plus the relation fields from `getGameRelations` — `app/Services/IGDB/QueryBuilder.php:19,79-90,146-150`, `IGDBService.php:325-342`, used at 258 and 295.
- Not stored on any Console row today:
  - **developer** — `involved_companies.developer` is requested (332). Only the PC path reads it (452-457, 503, 849-851).
  - **aggregated_rating**, **rating** — arrive through `fields *`; nothing reads them. Only `aggregated_rating_count` is used, for ordering and the match boost (259, 296, 358-360).
  - **game_modes** (335) — read only by the PC review builder (852-860).
  - **player_perspectives** (336) — not read anywhere.
  - **storyline** — arrives through `fields *`; read only by the PC review builder as a fallback (844-846). Console uses `summary` only (413).
  - **websites** (338) — not read anywhere.
  - **screenshots** (329), **artworks** (330) — read only for the PC backdrop (674-693).
  - **videos** (331) — read only for the PC trailer (698-714).
  - **themes** (334) — used only as the genre fallback when a game has no genres (526-535).
  - Also not stored: the cover URL itself (only the file and the `cover` flag), the other platforms, and the other release dates.

### `gamesinfo` (PC) — `mariadb-schema.sql:518-538`
- Columns: `id`, `title`, `asin` (unique), `url`, `publisher`, `genres_id` (one genre, `genres.type = 4000`), `esrb`, `releasedate`, `review` (varchar 3000), `cover`, `backdrop` (0/1), `trailer` (varchar), `classused` ('steam' default), `created_at`, `updated_at`. FULLTEXT on title.
- Model casts `releasedate` to `date` — `app/Models/GamesInfo.php:73-78`.
- Writer: `GamesService::updateGamesInfo` — `app/Services/GamesService.php:456-527`:
  - Steam first (`buildGameFromSteam`, 535-569): title, Steam id as `asin`, store URL, publisher or `Unknown`, rating or `Not Rated`, release date, description as review, one matched genre.
  - IGDB fallback (`IGDBService::search` + `buildGameData`, `IGDBService.php:431-506`): adds developer and modes into the review text, backdrop from artworks/screenshots, YouTube trailer URL, `asin = igdb-{id}`.
  - Saved by `saveGameToDatabase` (577-720): insert/update at 621-658; cover and `{id}-backdrop` images saved to `covers/games/` (`GamesService.php:92`, 689-705).
  - Genre: one name via `IGDBService::matchGenre` (928-945) stored in `genres` type 4000 (`app/Services/GenreService.php:18`).
- Candidates: **only 4050 PC › Games** — `app/Services/MetadataProcessing/GameProcessingCandidateQuery.php:28`.
- Enabled by `lookupgames` — `app/Services/GamesProcessor.php:24`.

### Other readers of these tables
- Search index projection joins all three — `app/Services/Search/Support/ReleaseIndexProjection.php:52-54,67`.
- Admin list/edit pages (section 1 pointer).

---

## 6. API and RSS surfaces that read these tables (frozen; stay untouched)

- **v1 API** `/api/v1/api` — `routes/api.php:20`:
  - `t=book` → `ApiController::api` function mapping `app/Http/Controllers/Api/ApiController.php:123-124`, handler `:526-580` → `ReleaseSearchService::apiBookSearch` (Books secondary index, then `bookinfo_id`) — `app/Services/Releases/ReleaseSearchService.php:508-526`.
- **v2 API** `GET /api/v2/books` — `routes/api.php:31`, `app/Http/Controllers/Api/ApiV2Controller.php:434-505` → same `apiBookSearch`.
- **RSS** (`routes/rss.php:24-29`: `full-feed`, `cart`, `category`; `RssController.php:74-83,120-131,157-179`) → `app/Http/Controllers/Api/RSS.php`:
  - `getRss` SQL joins `consoleinfo` (all console genres via `ConsoleGenres::titlesSql`) and `bookinfo` — `RSS.php:37,53-79` (joins at 73, 76; columns 63-66).
  - Index-backed variant — `RSS.php:133-191` (joins at 188, 191; columns 180).
  - Output: cover image for console (`co_cover`) or book (`bo_cover`) — `app/Http/Controllers/Api/XML_Response.php:650-678`; "Console Info" block (genre, publisher, year, review, link labelled "Amazon:") — `XML_Response.php:708-709,777-790`.
  - `gamesinfo` is not read by RSS.
- `app/Http/Resources/BookResource.php` / `BookCollection.php` exist; nothing in `app/`, `routes/` or `resources/` references them.

---

## 7. Findings

Stated as read in the code; no runtime checks.

1. **PC's list URL is `/browse/games`.** The header item "All PC" links there (`header-menu.blade.php:11`, root value `games` at `BrowseRoot.php:13`). `/browse/pc` is only an alias (`BrowseRoot.php:26`). Title pages and breadcrumbs also use `games` (`ReleaseEntityData.php:25`, `title/index.blade.php:5`, `details/index.blade.php:12`).
2. **Covers view hides every release without a metadata row** (INNER JOIN — `BookService.php:215,230`, `ConsoleService.php:214,230`, `GamesService.php:267,283`). Table and Covers therefore count different things ("releases" vs "books"/"games", `ReleaseBrowserState.php:42-54`), and the legacy `/Books`, `/Console`, `/Games` links land on Covers (`LegacyCoverRedirect.php:34`).
3. **PC metadata is only looked up for 4050 PC › Games** (`GameProcessingCandidateQuery.php:28`). 0day, ISO, Mac and phone releases never get a `gamesinfo` row, so they never appear in PC Covers.
4. **Covers "Name · A–Z" sorts by release name, not by title.** The scope order is `MIN(COALESCE(display_name, searchname))` (`CoverBrowseScope.php:32-39`, `ReleaseSort.php:37,41-43`). Only the Books letter jump sorts by `m.title` (`CoverBrowseScope.php:34-35`).
5. **PC "Platform" filter is a constant.** Its column is the literal `'PC'` (`ReleaseBrowserMetadata.php:24`), so the select offers only "PC" and filtering by it matches every row (`ReleaseBrowserMetadata.php:86-87`).
6. **Console "Publisher" options are combined strings.** IGDB publishers are stored joined with `,` (`IGDBService.php:418`); the option list is the distinct stored values, not split (`ReleaseBrowserMetadata.php:111-114`); matching is exact (`:86-87`). `Unknown` is stored when there is no publisher (`IGDBService.php:418`, `GamesService.php:550`).
7. **Books covers search ignores the author.** Covers search is `m.title` only; Audio adds artist, Books does not (`ReleaseBrowserQuery.php:117-123`).
8. **Stored fields no web screen shows:** `consoleinfo.review` and `gamesinfo.review` (title page gives Console/PC no overview, `TitleMetadataLoader.php:59-62`; detail partials skip it); `gamesinfo.backdrop` and `gamesinfo.trailer`; `bookinfo.pages`/`isbn`/`genre` on the details page (`book-info.blade.php:16-45`). No non-admin Blade view reads `->review`, `->backdrop`, `->trailer`, `->esrb`, `->pages`, `->isbn` or `->platform` (or the `['…']` array form); ESRB, platform, pages and ISBN reach the screen only through PHP-built lists (cover tiles, title page).
9. **Console details partial is thin:** Title, Publisher, Release Date only (`console-info.blade.php:14-31`). It also reads `con->genres`, which holds only the first genre (`ConsoleService.php:85-86`), but never prints it.
10. **XL cover genres line never shows for these roots.** `ReleaseCoverItem::$genres` defaults to `''` (`ReleaseCoverItem.php:25`) and `ReleaseCoverBrowser::item` never sets it (`ReleaseCoverBrowser.php:109-118`), so `cover-detail.blade.php:6` is always skipped. The Console tile badge is ESRB, not genre (`ReleaseCoverBrowser.php:96-99`).
11. **Legacy sort mapping produces sorts that do not exist.** `ob=year_desc|rating_desc|artist_asc` become `year`/`rating`/`artist` (`LegacyCoverRedirect.php:22-26`); `ReleaseSort::resolve` turns unknown values into Added · Newest (`ReleaseSort.php:16-19`).
12. **Legacy `/Console/WiiVare` looks up the title `WiiVareVC`** (`LegacyCoverRedirect.php:45`); the seeded title is `WiiWare VC` (`CategoriesTableSeeder.php:86`), so that lookup finds no row (`firstOrFail`).
13. **PC "Other" naming:** constant `PC_PHONE_OTHER = 4040` has no seeded row; the seeded 4999 row is titled "Phone-Other" while its constant is `PC_OTHER` (`Category.php:124,128`; `CategoriesTableSeeder.php:355-356`).
14. **Settings text does not match the code.** The Books card says "ISBNdb, with an iTunes fallback" (`MetadataLookupsSection.php:209`), but `updateBookInfo` also calls Google Books and Open Library (`BookService.php:816-881`). The Games card says "IGDB, GiantBomb and Steam" (`MetadataLookupsSection.php:234`); no GiantBomb code exists in `app/` beyond that string. One setting, `lookupgames`, drives both Console and PC (`ConsolesProcessor.php:21`, `GamesProcessor.php:24`).
15. **Book covers are saved under `storage_path('covers/book/')`** (`BookService.php:69`); Console and PC save under `config('nntmux_settings.covers_path')` (`ConsoleService.php:70`, `GamesService.php:92`). The image lookup searches both roots (`helpers.php:512-517`).
16. **RSS labels the console link "Amazon:"** (`XML_Response.php:786`); `consoleinfo.url` now holds the IGDB URL (`IGDBService.php:417`). Frozen surface; listed only.
17. **IGDB `alternative_names` is read in matching** (`IGDBService.php:373-379`) but is not in `getGameRelations` (`IGDBService.php:325-342`); it reaches the code only through the top-level `fields *`.
18. **Title page access errors are plain 403s** (`TitleController.php:18-19`, `TitleReleaseBrowser.php:31`), while browse shows the category-disabled page (`ClearanceMiddleware.php:211-216`).

---

## 8. Tests that cover these screens (file names only)

- Browse/covers/filters: `tests/Feature/ReleaseBrowserControllerTest.php`.
- Title page: `tests/Feature/TitleControllerTest.php`.
- Details page: `tests/Feature/DetailsControllerTest.php`.
- Console genres: `tests/Feature/ConsoleGenresTest.php`.
- Entity/row loaders: `tests/Feature/Releases/ReleaseEntityDataLoaderTest.php`, `tests/Feature/Releases/ReleaseRowDataLoaderTest.php`.
- Lookup admission: `tests/Feature/MetadataProcessingCandidateQueryTest.php`, `tests/Feature/PostProcessRunnerBooksGateTest.php`, `tests/Feature/MetadataLookupThrottleTest.php`.
- Books matching: `tests/Feature/BookServiceMatchingTest.php`, `tests/Feature/BookServiceObfuscatedNormalizationTest.php`.
- Header shell: `tests/Feature/PublicShellTest.php`.
- Admin edit/list: `tests/Feature/AdminBookEditTest.php`, `AdminConsoleEditTest.php`, `AdminConsoleListPageTest.php`, `AdminGameEditTest.php`, `AdminGameListPageTest.php`.
