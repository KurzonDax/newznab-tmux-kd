# Adult screens: feature inventory (master @ 841fff370, 2026-09-29)

Checked against master at `841fff370` with a clean working tree. All paths are relative to the repository root. This is a snapshot of the screens before the redesign; `SPEC.md` records what the redesign keeps, drops and changes, and #879 / #880 cover the category and access findings.

Findings:
- **Permission gap on `/browse/adult`:** the `adult` alias skips the `view adult` check in `ClearanceMiddleware`. The page still renders, but empty, because the exclusions remove every 6xxx release.
- **No check on the details page:** `details/{guid}` has no permission or exclusion check, so any logged-in user with the GUID can open an adult release.
- **`canpreview` is not enforced:** the `preview` permission is stored but nothing reads it.
- **`audiostatus` is gone:** the column was dropped on 2026-08-13.
- **Adult releases never have an entity**, so the "Other releases of this title" panel always says "None." and there is no title page.

---

## 1. Entry points, navigation and per-user hiding

### Routes
- **Browse route:** `browse/{parentCategory}/{id?}` → `BrowseController::show`, with `clearance` middleware — `routes/web.php:201`.
  - `BrowseRoot::fromRoute` maps both `xxx` and `adult` (any case) to Adult — `app/Enums/BrowseRoot.php:21-29`.
  - `{id}` can be a numeric id or a subcategory title, scoped to root 6000 — `app/Http/Controllers/BrowseController.php:30-36`.
  - A subcategory in the user's exclusions returns 403 — `BrowseController.php:35`.
  - Adult is not redirected (only TV and Movies are, lines 37-42). It goes to `renderBrowser` → `browse.index` (lines 44, 91).
- **Legacy route:** `XXX/{id?}` (GET/POST), named `XXX`, inside the clearance group — `routes/web.php:233,237`.
  - `AdultController::show` resolves `{id}` by title, or `?t=<id>`, inside root 6000.
  - It then redirects to `browse` with `parentCategory=xxx` and drops `t`, `parentCategory` and `id` from the query — `app/Http/Controllers/AdultController.php:13-25`.
- **Subcategory ids and titles**, as used by `/browse/xxx/{id|title}` and `/XXX/{title}`:
  - 6010 DVD, 6020 WMV, 6030 XviD, 6040 x264, 6041 "HD Clips", 6042 "SD Clips", 6045 UHD, 6050 Packs, 6060 Imageset, 6080 SD, 6090 WEBDL, 6999 Other — `database/seeders/CategoriesTableSeeder.php:444-545`.
  - 6046 VR — `database/migrations/2024_09_15_184830_add_xxx_vr_category.php:15`.
  - 6047 OnlyFans — `database/migrations/2025_08_28_000000_add_onlyfans_category_to_categories_table.php:17`.
  - Constants — `app/Models/Category.php:154-180`; `XXX_ROOT` — `:206`.
  - The root category title is `XXX` — `database/seeders/RootCategoriesTableSeeder.php:72`.
- **Web search:** `/search?t=6000` (or a sub-id) — `routes/web.php:263`. `WebSearchState` works out the root with `BrowseRoot::fromCategoryId` — `app/Data/WebSearchState.php:55-63`.
- **Title page:** `/title/xxx/{id}` returns 404. `TitleController` only allows Audio, Console, Games and Books — `app/Http/Controllers/TitleController.php:17`.

### Navigation links
- **Header "Browse" mega-menu:**
  - Adult root link → `url('/browse/xxx')`.
  - One link per subcategory → `/browse/xxx/{id}`.
  - Both at `resources/views/partials/header-menu.blade.php:9-16`.
- **Header search scope:** a select option labelled "Adult" with value 6000 — `header-menu.blade.php:36-38`.
- **Where the menu roots come from:** `GlobalDataComposer::navigationRoots` includes Adult in the fixed order — `app/View/Composers/GlobalDataComposer.php:128-139`.
  - The list comes from `Category::getForMenu($userdata->categoryexclusions)` — `GlobalDataComposer.php:98-100`, `app/Models/Category.php:573-590`.
  - A root with every subcategory excluded disappears, so users without `view adult` get no Adult menu entry and no Adult search scope.
- **Search page "+ Add filter" → Category:** includes an Adult optgroup — `resources/views/search/filter-menu.blade.php:18-26`.
- **Home page:** there is no Adult section.
  - The only block is "Latest releases" (All root, cards, 8 rows) plus a "Browse all" link — `resources/views/content/home.blade.php:10-11`, `app/Services/Releases/HomeDashboard.php:19-20`.
  - Adult releases appear there unless they are excluded. Card view only shows renamed, post-processed releases.
- **Details breadcrumb:** links back to `/browse/xxx` — `resources/views/details/index.blade.php:12`.

### Per-user hiding and permissions
- **Permission:** `view adult` (Spatie) — `database/seeders/RolesAndPermissionsSeeder.php:28,39`. It is granted by default to User, Admin, Moderator and Friend (lines 59, 75, 105, 120).
- **ClearanceMiddleware:**
  - Checks `XXX`, `XXX/*`, `browse/xxx` and `browse/xxx/*` against `hasDirectPermission('view adult')` and shows `errors.category-disabled` with 403 — `app/Http/Middleware/ClearanceMiddleware.php:39-70,108-115,146-172,219-229`.
  - Subcategory exclusions are checked on `browse/xxx/{title}` — lines 183-214.
  - **Gap:** the `$categoryPermissions` map (lines 146-155) has no `adult` key. So `/browse/adult` and `/browse/adult/*` are not blocked there.
  - `/browse/adult` renders an empty list, because the exclusions contain every 6xxx id.
  - `/browse/adult/{id}` returns 403 through `BrowseController.php:35`.
- **Category exclusions:**
  - `User::getCategoryExclusionById` excludes every 6xxx subcategory unless the user has `view adult` both directly and through a role. It also merges the user's own subcategory exclusions — `app/Models/User.php:1733-1782` (map at `:1747`).
  - It is cached — `User.php:1716-1728`.
  - The browse query applies it with `whereNotIn('r.categories_id', categoryexclusions)` — `app/Services/Releases/ReleaseBrowserQuery.php:67`.
- **Account → Categories:**
  - "Show categories" includes an "Adult" checkbox (`viewadult`) — `resources/views/account/appearance.blade.php:20-24`.
  - "Exclude subcategories" includes the XXX group — `appearance.blade.php:25-33`.
  - Saved by `AccountController::categories` (`app/Http/Controllers/AccountController.php:101-113`) through `PermissionSyncHelper::syncUserPermissions` (`app/Support/PermissionSyncHelper.php:20,35-46`).
- **Account → Appearance:** "Default view per root" shows the saved Adult view — `appearance.blade.php:9-13`, using data from `AccountController.php:46`.
- **Admin roles:**
  - "View Adult" checkbox — `resources/views/admin/roles/add.blade.php:178-182`, `resources/views/admin/roles/edit.blade.php:205-210`.
  - `canpreview` → `preview` permission — `PermissionSyncHelper.php:26`, `admin/roles/edit.blade.php:136`.
  - **`preview` is never checked anywhere** in `app/` or `resources/views`.
- **Legacy `users.xxxview` column:** still in the model (`app/Models/User.php:64,190,882,899`). `AdminUserController.php:234` passes the existing value through unchanged. Nothing uses it for access control.
- **No other hiding mechanisms exist:** there is no `hidexxx` flag and no admin setting to hide adult content.
- **Details page has no gate:** `details/{guid}` (`routes/web.php:219`) is outside the clearance group, and `DetailsController::show` does not check exclusions or permissions.

---

## 2. Browse screen: `browse.index` for Adult

### Page frame
- Breadcrumb "Browse › Adult" — `resources/views/browse/index.blade.php:9`.
- Page header: title is "Adult" or "Adult · {subcategory}", with icon `fa-compass` — `browse/index.blade.php:10`, `BrowseController.php:69`.
- A "Clear filter" button appears only when a group or poster filter is set — `browse/index.blade.php:12-14`.
- The page embeds the release browser component — `browse/index.blade.php:17`, `resources/views/components/release-browser.blade.php`.
- Modals included: file list, NFO, preview, media info, image, report — `resources/views/partials/release-modals.blade.php:2-7`.

### State (`ReleaseBrowserState::fromRequest`, `app/Data/ReleaseBrowserState.php:85-122`)
- **Views:** table, cards, covers — `BrowseRoot.php:84-91`.
- **Cover sizes:** only `s` and `l`, no `xl` — `BrowseRoot.php:94-97`.
- **Per page:** 24, 48 or 100 (default 48).
- **Saved preferences:** `User::releaseViewPreferences('xxx')` — `User.php:209-215`. Updated via POST `/profile/update-view`, validated against `views()` and `coverSizes()` — `app/Http/Requests/UpdateReleaseViewRequest.php:24-25`.
- **"Following":** not available for Adult (Movies, TV and All only) — `ReleaseBrowserState.php:116`.
- **Letter jump:** not available (Audio and Books covers only) — `ReleaseBrowserState.php:63-66,99-100`.
- **Minimum completion:** only via the `?minc=` URL parameter; there is no toolbar control — `ReleaseBrowserState.php:118`, `app/Support/ReleaseCompletion.php:27`.
- **Count unit:** "releases" in every view — `ReleaseBrowserState.php:42-56`.

### Toolbar (`resources/views/components/release-browser/toolbar.blade.php`)
- **Search box** "Search in Adult" — line 3.
  - Matches `COALESCE(display_name, searchname) LIKE`. Adult covers use the release name too, not `m.title` — `ReleaseBrowserQuery.php:115-126`.
- **Filters:** only a year picker (`x-year-picker`, year, year_from, year_to) — `toolbar.blade.php:4-6`.
  - Source: `ReleaseBrowserMetadata::fields` Adult → `SUBSTR(r.postdate,1,4)` — `app/Services/Releases/ReleaseBrowserMetadata.php:26`.
  - There is no join for Adult (lines 31-55). Options come from `YearRange::years()` — `ReleaseBrowserMetadata.php:96-99`.
- **"Clear" button** when any filter is active — `toolbar.blade.php:19-21`.
- **Total count** — line 24.
- **Sort select** (`toolbar.blade.php:25-29`) with six options from `ReleaseSort::options()` (`app/Enums/ReleaseSort.php:22-29`); column mapping at `:32-46`:
  - Posted · Newest
  - Posted · Oldest
  - Added · Newest (default)
  - Added · Oldest
  - Name · A–Z (display name)
  - Grabs · Most
- **View segment:** Table / Cards / Covers — `toolbar.blade.php:30-38`. The Cards button has the tooltip "Renamed, post-processed releases only" (line 33).
- **Table view:** a thumbnails toggle — line 40.
- **Covers view:** "Cover size" with S and L — `toolbar.blade.php:41-47`.

### Pager (`components/release-browser/pager.blade.php`)
- Shown above and below the list — `release-browser.blade.php:19,48`.
- Text: "Page X of Y · N releases" — `pager.blade.php:7`.
- In Cards view it adds "· renamed and post-processed only (N not shown)" — `pager.blade.php:8-13`.
- Per-page segment 24/48/100 — lines 15-20.
- Prev/next, a ±2 page window with first/last pages and ellipses — lines 22-42.
- "Go to page" input — line 43.
- A page past the end redirects to the last page — `BrowseController.php:66-68`.

### Table view (`table.blade.php`, `row.blade.php`)
- **Columns:** select-all checkbox, Release, Category, Size, Files, Added, Posted, Stats, Actions — `table.blade.php:5-7`.
- **Row contents:**
  - Checkbox — `row.blade.php:5`.
  - Optional thumbnail — `row.blade.php:8-10`.
    - The thumbnail is the preview `_thumb` if `haspreview=1`, else the sample `_thumb` if `jpgstatus=1`, else the `fa-venus-mars` icon — `artwork.blade.php:4-7,17-22`.
    - Adult thumbnails use the `wide` shape — `artwork.blade.php:13`.
  - Title linking to details — `row.blade.php:13`.
  - Facts — line 15.
  - Origin — line 16.
  - Category pill — line 20.
  - File count, which opens the file list modal — line 23.
  - Added and Posted — lines 25-26.
  - Grabs and comments — lines 28-29.
- **Facts** (`facts.blade.php`): `<x-release-facts>` (line 2), "Reported (n)" (lines 3-5), "Response" (lines 6-8).
- **Origin** (`origin.blade.php`): the entity chip is never shown for Adult (entity is null; `entity-chip.blade.php:14` also skips `adult`). It shows a group chip → `browse.all?group=` and a poster chip → `browse.all?poster=` (lines 8-9).
- **Actions** (`actions.blade.php`):
  - Download NZB — line 2.
  - Details — line 3.
  - Basket toggle — line 4.
  - Report — line 5.
  - The watch button is Movies/TV only — lines 6-8.

### Chips (`resources/views/components/release-facts.blade.php`)
- **Completion %** (success, warning or danger) and the **repair label** chip — `x-release-completion-chips`, `resources/views/components/release-completion-chips.blade.php:23-29`; used at `release-facts.blade.php:38`.
- **Password** — `release-facts.blade.php:39-41`.
- **Media Info** (summary text, opens the media info modal) — lines 42-46.
- **NFO** — lines 47-49.
- **Preview or Clip** — lines 50-64.
  - "Clip" with a video icon when a video preview exists (`route('preview.video')`).
  - Otherwise "Preview" with an image icon (preview `_thumb`, full size if on disk) — logic at lines 18-35.
  - "Listen" only appears for audio releases.
- **Sample** (sample `_thumb`, full size) — lines 65-70.

### Cards view (`card.blade.php`)
- Checkbox, wide artwork (same preview/sample fallback), title, facts, origin, then Size / Added / Posted / Grabs, then actions — `card.blade.php:4-17`.
- Filtered to post-processed and `isrenamed=1` releases — `ReleaseBrowserQuery.php:26-30`, `app/Services/Releases/ReleaseRowDataLoader.php:174-180`.

### Covers view for Adult: one tile per release
- **Data:** `ReleaseCoverBrowser::adult()` — `app/Services/Releases/ReleaseCoverBrowser.php:122-139`, dispatched at line 73. It uses the normal release pagination, so all six sorts, the year filter and search apply.
- **Tile fields:**
  - id = guid
  - title = release name
  - artwork = preview `_thumb`, else sample `_thumb`
  - tag = PREVIEW or SAMPLE
  - identifying line = "size · added"
  - footer = category chip + completion %
- **Grid:** `data-shape="wide"` — `covers.blade.php:1`.
  - The release-count badge is hidden for Adult — lines 9-11.
  - Title and identifying line — lines 12-14.
  - Footer only at size L — lines 15-20.
- **Art element** (`cover-art.blade.php:1-12`): image or `fa-venus-mars` fallback, a title overlay, and the PREVIEW/SAMPLE tag. A failed image load falls back to the icon (`resources/js/alpine/components/release-cover-browser.js:21-23,152-156`).
- **XL detail layout** (`cover-detail.blade.php`) is never reached for Adult, because XL is not an allowed size.
- **Clicking a tile:**
  - `openCover` opens an inline expansion panel after the tile's row (on screens ≤640px it's a modal dialog).
  - It fetches `?view=covers&_fragment=cover&cover=<guid>&release_page&release_per` — `release-cover-browser.js:33-81,103-116`.
  - The server side is `BrowseController.php:55-61` → `ReleaseCoverBrowser::expanded`, using column `guid` (lines 40-58, Adult at 45).
  - Clicking the same tile again closes it. Esc closes. Left/Right arrow keys move to the neighbouring tile — `release-cover-browser.js:133-150`.
- **Expanded fragment** (`expanded-cover.blade.php`):
  - Header shows the release name (no entity) and "· 1 releases" — lines 2-8.
  - "Select all on this page" and "Download selected" — lines 10-11.
  - No "Title page" button (entity is null) — lines 12-14.
  - Close button — line 15.
  - The shared table with one row — line 17.
  - Prev/Next and a per-page select (24/48/100) — lines 18-27.

### Selection and bulk actions
- **Floating bulk bar:** "N selected", "Add to basket", "Download N NZBs", "Clear" — `release-browser.blade.php:51-57`.
- **JavaScript** (`resources/js/alpine/components/release-browser-component.js`):
  - `selectAll` — line 15.
  - `downloadSelected` → POST `/getnzb` with `id=<guids>&zip=1` — lines 41-60.
  - `changePreference` → POST `/profile/update-view` — lines 75-79.
  - `toggleBasket` → `/cart/add` or `/cart/delete/{guid}` — lines 140-149.
  - `addSelectedToBasket` — lines 159-166.
- **Routes:** `cart/add` — `routes/web.php:210`; `getnzb` — `web.php:220-221`.

### Empty state
- `x-empty-state` with the `fa-venus-mars` icon and "Clear filters" — `release-browser.blade.php:33-46`.

---

## 3. Details page for an Adult release

### Controller
- `DetailsController::show` branches only for TV (→ `showTv`) and Movies (→ `showMovies`) — `app/Http/Controllers/DetailsController.php:68-74`.
- Adult uses the generic path (lines 75-189) and renders `details.index` (line 189).
- `category_band` is not used in `DetailsController`.

### What the generic path loads
- Similar releases via `searchSimilar`, restricted to the same root and respecting exclusions — `DetailsController.php:75`, `app/Services/Releases/ReleaseSearchService.php:1390-1418`.
- Failed-download count — line 76.
- Reports — lines 77-79.
- Movie info only if the release has a valid `imdbid` — lines 87-103.
- PreDB via `predb_id` — line 146.
- Comments; posting a comment happens at lines 60-65.
- `RelatedReleaseBrowser::forRelease` returns an empty list for Adult because the entity is null — `app/Services/Releases/RelatedReleaseBrowser.php:25-27`.

### Page layout (`resources/views/details/index.blade.php`)
- **Breadcrumb:** Adult › {subcategory} — line 12.
- **Header** (`details/partials/header.blade.php`):
  - Wide artwork using the same preview → sample → icon fallback (line 2).
  - Name (line 4).
  - Facts chips and group chip (line 5).
  - Category, size, files, completion text, added, posted, grabs, comments (lines 7-10).
  - Actions: Download NZB, basket, NFO, Media info, Files, Report, and "Edit release" for Admin/Moderator (lines 13-19).
  - "N users reported download failure" (line 21).
- **Tabs:** Overview, Files (n), Media info, NFO, Comments (n) — `details/index.blade.php:20`.
- **Overview** (lines 24-42):
  - `preview-images`: covered in its own subsection below.
  - `audio-preview`: audio releases only.
  - The other metadata partials (movie, TV, music, game, console, book, anime) render only if their data exists; for Adult that is movie-info, and only when `imdbid` is set.
  - `predb-info`: Title, Source, Pre Date, Category, and so on (`details/partials/predb-info.blade.php`).
  - `password-info`.
  - Group, Poster and Password status (lines 36-40).
  - Reports (line 41).
- **Files tab:** loaded lazily from `release/{guid}/files` — `details/index.blade.php:43-48`, `routes/web.php:271`.
- **Media info tab:** loaded lazily from `release/{id}/mediainfo` — `details/index.blade.php:49-53`, `routes/web.php:273-275`.
- **NFO tab:** `details/index.blade.php:54-58` (`/nfo/{guid}`, `routes/web.php:253`).
- **Comments tab:** `details/index.blade.php:59-61`.
- **Aside** (`details/partials/related.blade.php`):
  - "Other releases of this title" always shows "None." for Adult — lines 2-9.
  - "Similar releases" (by name, within root 6000) — lines 10-14.

### Preview images and playback (`details/partials/preview-images.blade.php`)
- Preview `_thumb` if `haspreview=1`, and sample `_thumb` if `jpgstatus=1`, each with a full-size link if the file exists — lines 6-17.
- Video preview if `videostatus=1`. The MIME type comes from the `release_video_clips` row, otherwise legacy `ogv` — lines 18-21.
- Heading variants — lines 28-36.
- "Preview" video button that opens the preview modal with `route('preview.video')` — lines 37-50.
- Preview and sample image buttons open the image modal — lines 53-77.

### Adult-specific behaviour
There is none beyond the preview/sample artwork fallback and the wide shape (`artwork.blade.php:4-7,13`). There are no adult metadata partials.

---

## 4. Where adult artwork comes from today

### Release columns
- `haspreview`:
  - `1` means a preview thumb exists.
  - `-1` means pending.
  - `-2` means skipped by per-root policy — `app/Services/Releases/PreviewGenerationPolicy.php:33`.
- `jpgstatus` and `videostatus`.
- `audiostatus` was dropped — `database/migrations/2026_08_13_001652_normalize_and_optimize_releases_table.php:13-22`. Its only remaining reference is `app/Console/Commands/ReleasesOptimizePreflight.php:165`.

### Pipeline (AdditionalProcessing)
- **Candidates:** releases with `haspreview = -1` and `nzbstatus = 1` — `app/Services/AdditionalProcessing/ReleaseClaimant.php:158`.
- **NZB scan** — `app/Services/AdditionalProcessing/NzbContentParser.php:210-223`:
  - Explicit video sample files → sample message ids (needs `processThumbnails`).
  - `.jpg/.png/.webp` files, excluding music groups → JPG message ids (needs `processJPGSample`).
- **Gating** in `ReleaseProcessor::initializeContext` — `app/Services/AdditionalProcessing/ReleaseProcessor.php:321-346`.
  - The per-root `PreviewGenerationPolicy` (`root_categories.generate_previews`, `PreviewGenerationPolicy.php:50,141`) is AND-ed with the site switches.
  - The free-disk guard can also suppress imagery.
- **Preview thumb:** `MediaExtractionService::getSample` extracts a representative frame into `preview/` — `app/Services/AdditionalProcessing/MediaExtractionService.php:69-101`.
- **Clip:** `getVideo` → `shouldAttemptClip` → `storeClip` — `MediaExtractionService.php:104-186`.
  - `ClipGenerationPolicy` allows only Movies, TV and XXX, reads `root_categories.generate_clips`, and defaults to off — `app/Services/Releases/ClipGenerationPolicy.php:24-28,40-47,57`.
  - Writes a `release_video_clips` row and sets `videostatus=1` — `MediaExtractionService.php:175-186`.
- **JPG sample:** `getJPGSample` saves into `sample/` and sets `jpgstatus=1` — `MediaExtractionService.php:295-310`. It is called from `ReleaseProcessor.php:733-749,1518,1623-1653`.
- **Segment budget:** `DynamicPreviewBudgetPolicy` allows only Movies, TV and XXX (`root_categories.dynamic_preview_budget`, default off) — `app/Services/Releases/DynamicPreviewBudgetPolicy.php:26-30,42,59`. It is injected at `ReleaseProcessor.php:63`.
- **Settlement:** `ReleaseFileManager` sets `haspreview` / `videostatus` / `jpgstatus` from the files on disk — `app/Services/AdditionalProcessing/ReleaseFileManager.php:318-354`.
- **Admin settings** — `app/Support/Settings/Sections/PostProcessingSection.php`:
  - `processthumbnails` — line 276.
  - `processvideos` — line 282.
  - `processjpg` ("Mostly an XXX convention") — lines 287-292.
  - `generate_previews` — lines 302-308.
  - `dynamic_preview_budget` — lines 309-315.
  - `generate_clips` — lines 344-350.
  - `clip_minimum_seconds` — line 352.

### Storage and URLs
- **Directories:** `{covers_path}/preview/`, `/sample/`, `/video/` — `app/Services/ReleaseImageService.php:53-58`.
- **File names:** `{guid}_thumb.{ext}` and full-size `{guid}.{ext}` — `ReleaseImageService.php:241,308-310`.
- **URL helper:** `getImageAssetUrl($type, $basename)` → `/covers/{type}/{file}` — `app/Extensions/helper/helpers.php:546-551`.
- **Image route:** `/covers/{type}/{filename}` — `routes/web.php:113-117`. The type whitelist includes `preview`, `sample` and `video`, but not `xxx`.
- **Video route:** `/preview/video/{guid}` — `routes/web.php:132-135`, `app/Http/Controllers/VideoPreviewController.php:25-55`. It needs `videostatus=1` and uses the clip row's container, otherwise ogv. It requires login but does not check the category permission.
- **Leftovers:**
  - `getCoverURL` still whitelists type `xxx` — `helpers.php:934,947`.
  - `getRawHtml` still has an unused `$adultSites` / `$isAdultSite` block — `helpers.php:26-41`.

---

## 5. Categorisation of the 6000 band

- **Pipeline order:** Misc → GroupObfuscatedRouting → GroupName → **Xxx** → TV → Movie → … — `app/Services/Categorization/CategorizationPipeline.php:305-315`.
- `XxxPipe` has priority 10 and wraps `XxxCategorizer` — `app/Services/Categorization/Pipes/XxxPipe.php:14-33`.
- **Gate:** `looksLikeXxx` (`app/Services/Categorization/Categorizers/XxxCategorizer.php:121-169`) passes on any of:
  - Adult markers.
  - A VR site.
  - A VR device together with a resolution.
  - An adult keyword together with a video token.
  - A `site.YY.MM.DD` name with a keyword or firstname.lastname.
- **S/E guard:** a season/episode token without adult markers means no match — lines 46-49.
- **Word lists:** `app/Services/Categorization/ReleaseContext.php:17,20,23`.

The checks run in this order (`categorize()`, lines 42-116):

| Subcategory | Line | Signal |
|---|---|---|
| OnlyFans 6047 | 171-184 | `OnlyFans` or a leading `OF.`; photo packs without a video hint are skipped |
| VR 6046 | 186-238 | known VR site, generic `*VR.com`, or a VR device / VR180 / VR360 plus an adult marker |
| UHD 6045 | 240-261 | 2160p / 4K / UHD plus XXX, a studio or a keyword; known UHD groups get 0.95 |
| ClipHD 6041 | 263-329 | studio + performer + HD, studio + date + HD, `site.YYYY.MM.DD` + HD or keyword, 2-digit date + HD, a six-digit `YYMMDD` date + HD, or `XXX` next to a resolution. Studio + date or a six-digit date **without** HD → **x264 6040** |
| Pack 6050 | 331-338 | ` PACK ` token |
| ClipSD 6042 | 340-363 | specific poster emails, three named release-group tokens, or a sub-HD resolution with a site date |
| SD 6080 | 371-378 | `SDX264XXX` or `XXX.HR.` |
| WEB-DL 6090 | 380-395 | `web-dl` / `webrip` plus an adult keyword, studio or XXX; only when `catWebDL` is set (line 83) |
| x264 6040 | 397-422 | x264 / h264 / AVC (not x265) plus an adult regex |
| XviD 6030 | 424-431 | `dvdrip` / `bdrip` / `divx` / `xvid` and similar |
| ImageSet 6060 | 433-440 | `IMAGESET` / `PICTURESET` / `ABPEA` |
| WMV 6020 | 442-454 | WMV token and no modern codecs |
| DVD 6010 | 456-463 | `dvdr` / `dvd5` / `dvd9` |
| Other 6999 | 465-470, 115 | explicit terms (a list of named terms in the code); any other adult-positive name falls back here at 0.75 |

**Other routes into 6000:**
- `GroupNameCategorizer`: groups whose names match an adult pattern → 6999 — `app/Services/Categorization/Categorizers/GroupNameCategorizer.php:35-36`.
- Group forced root — `app/Services/Releases/ForcedRootPolicy.php:20-29`.
- `MediaInfoRefinementService` moves 6999 releases using probe data — `app/Services/Categorization/MediaInfoRefinementService.php:157-205`:
  - UHD → 6045
  - MPEG-PS → 6010
  - HEVC → 6040
  - VC-1 / WMV → 6020
  - MPEG-4 Visual / XviD → 6030
  - HD → 6040, otherwise SD → 6080

---

## 6. Metadata stored per adult release

**There is no title, performer or studio data anywhere.**
- `xxxinfo` and `releases.xxxinfo_id` were dropped — `database/migrations/2026_02_13_000000_drop_xxxinfo_and_releases_xxxinfo_id.php:14-24`.
- Commit `ce10c0b7b` removed `AdultProcessing/*`, the provider pipes (seven metadata-site pipes), `XxxInfo` and `ProcessAdultMovies`.
- Studio names exist only as regex constants used for categorisation (`ReleaseContext.php:17-23`); they are not stored.
- `ReleaseEntityDataLoader` has no XXX source, so the entity is always null — `app/Services/Releases/ReleaseEntityDataLoader.php:20-27`.

What *is* stored:
- **`releases` row:**
  - `name`, `searchname`, `display_name`
  - `fromname` (the poster, used for poster identity)
  - `groups_id` plus cross-posted groups in `releases_groups`
  - `size`, `postdate`, `adddate`, `completion`, `grabs`, `comments`
  - `passwordstatus`, `nfostatus`, `haspreview`, `jpgstatus`, `videostatus`
  - `predb_id`
  - repair/rescan outcome columns
  - `proc_xxx` (name-fixing from one named file-name token — `app/Services/NameFixing/NameFixingService.php:1348-1360`, `app/Services/NameFixing/NameFixingQueryService.php:81,213`)
  - `category_band` (virtual)
  - Full column list: `database/schema/mariadb-schema.sql:2825-2950`
- **`predb`:** title, source, predate, category — shown by `predb-info.blade.php`.
- **`release_files`:** the file list; `par2_file_descriptors` (migration `2026_09_08_205107`).
- **`release_nfos`:** NFO text.
- **`media_infos`:** `movie_name`, `file_name`, `unique_id` (migration `2024_04_27_124202`).
  - `movie_name` is the only embedded, title-like field. It appears as the embedded title in the Media info modal (`app/Services/MediaInfo/MediaInfoPresentationService.php:119-120`) and is indexed for search (`app/Services/Search/Support/ReleaseIndexProjection.php:91`).
- **`media_info_probes` / `media_info_tracks`** (migration `2026_09_06_000000`), plus legacy `video_data` / `audio_data` / `release_subtitles` (read at `MediaInfoPresentationService.php:110-111`), plus `release_audio_languages`.
- **`release_video_clips`** (migration `2026_08_27_150100`), **`release_imagery_disk_skips`** (migration `2026_08_28_120000`).
- **Other per-release tables:** `release_comments`, `release_reports`, `users_releases` (basket), `release_regexes`, DNZB failures.

---

## 7. API and RSS surfaces that expose 6000-band releases (frozen)

- **v1 API** — `/api/v1/api` (`routes/api.php:20`), `app/Http/Controllers/Api/ApiController.php`:
  - search — line 210
  - get — line 633
  - details — line 649
  - nfo — line 663
  - caps — line 759
  - Exclusions come from `User::getCategoryExclusionForApi` (`User.php:1812`), so `view adult` applies here too.
- **v2 API** — `routes/api.php:24-36`, `app/Http/Controllers/Api/ApiV2Controller.php`:
  - capabilities — line 270
  - search — line 578
  - getnzb — line 738
  - details — line 792
  - nzbadd — `routes/api.php:25`
- **Other API routes:** `inform/release` (`routes/api.php:40`) and `release/{id}/mediainfo` (`routes/api.php:44`).
- **RSS** (`/rss/*`, prefix at `bootstrap/app.php:44`) — `routes/rss.php:24-29`, `app/Http/Controllers/RssController.php`:
  - full-feed — line 74
  - cart — line 120
  - category — line 157
  - Feed builder category select — `resources/views/account/api.blade.php:14`.
- **Web downloads:** `getnzb`, single or zip — `routes/web.php:220-221`.

---

## 8. Tests that cover Adult browse

- **`tests/Feature/ReleaseBrowserControllerTest.php`:**
  - `test_every_year_capable_category_uses_its_own_year_in_every_supported_view` (line 87; adult dataset line 179)
  - `test_canonical_roots_and_numeric_subcategories_never_fall_back_to_all_releases` (line 260; xxx at 262)
  - `test_other_root_filters_apply_to_metadata_and_adult_posted_year` (line 376; dataset line 406)
  - `test_legacy_adult_navigation_opens_the_canonical_subcategory_table` (line 442)
  - `test_cards_only_include_renamed_releases_that_finished_processing` (line 584; adult at 607, 621)
  - `test_adult_covers_use_preview_then_sample_then_placeholder_and_expand_one_release` (line 848)
- **`tests/Feature/ReleaseViewPreferencesTest.php:68`:** `xl` is rejected for `xxx`.
- **`tests/Feature/TitleControllerTest.php:112-118`:** `/title/xxx/*` returns 404.
- **Exclusions and poster:** `tests/Feature/UserExcludedCategoryTest.php`, `tests/Feature/PosterIdentityControllerTest.php:596`.
- **Clip chip on a 6030 release:** `tests/Feature/ReleasePreviewImageViewTest.php:148`.
- **Browser script:** `tests/browser/year_picker.py:67`.
- **Categorisation and policies (not browse):**
  - `tests/Unit/XxxCategorizationTest.php`
  - `tests/Unit/CategorizationFalsePositiveRegressionTest.php`
  - `tests/Unit/HashedReleaseCategorizationTest.php`
  - `tests/Unit/Models/CategoryRootCategoryForTest.php`
  - `tests/Feature/GroupForcedRootCategorizationTest.php`
  - `tests/Feature/Services/Categorization/MediaInfoRefinementPersistenceTest.php`
  - `tests/Feature/ClipGenerationPolicyTest.php`
  - `tests/Feature/DynamicPreviewBudgetPolicyTest.php`
- **No tests** cover `ClearanceMiddleware` for XXX or the `adult` alias, and none cover the details page for an Adult release.