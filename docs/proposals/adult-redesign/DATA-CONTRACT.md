# NNTmux Adult section: data contract

What the approved Adult screens (`SPEC.md`) read, where each value is already stored, which existing code writes it,
and every read measured at full catalogue size. Written 2026-09-29 against master `34ff936cd`. The measurements are in
`evidence/adult-data-contract.md` (the query lab's write-up; its scratch copy holds all 2,359,525 releases of the
restored production catalogue with exactly production's list indexes).

**The result: the Adult screens need no new table, column or index.** Every value they show or filter on is already
stored for every category by the TV and Movies work (#773 resolution and source, #827 list indexes, #828 audio
languages) or by today's additional processing (pictures and clips). The slowest read is the count for Audio "a language
and Unknown" (19.5 ms, cached like every count); the slowest page is a name search for a word no name contains, which
reads the whole Adult band once (16,008 rows, 16.0 ms; 17.0 ms on the full-width production rows). Two filed issues are prerequisites: **#879** (the VR
category row exists on every install; OnlyFans is dropped and its releases refiled) and **#881** (the release lists
remember each user's last dropdown filters; the Adult list follows the same rule).

---

## 1. Facts about the application that shape the design

1. **The shared list code.** `BandReleaseList` (`app/Services/Releases/BandReleaseList.php`) is the TV and Movies
   lists' query layer: a subclass names its band (`band()`) and cache prefix, `pageIds()` (`:84`) reads 50 ids from the
   index `readIndex()` chooses and mirrors from the other end past the middle (`:94`), `count()` (`:67`) is cached under
   the browse cache version, `releaseIndex()` (`:113`) picks the `_cat_` / `_res_` / `_src_` index of the set filter
   whose values hold the fewest releases unless none is set or they hold over half the band, `whereRelease()` (`:181`)
   applies the password setting, the user's excluded categories, Category, Resolution, Source, Completion and Audio,
   `whereAudio()` (`:211`) is an EXISTS for a language and an anti-join for Unknown alone, `valueCounts()` (`:153`) and
   `audioMenu()` (`:136`) are cached for an hour. 50 a page (`app/Data/ReleaseListFilters.php:21`).
2. **The indexes.** `releases` carries `ix_releases_band_posted` / `_added` / `_count` and the #827 `_cat_` / `_res_` /
   `_src_` posted / added indexes on the generated `category_band` (`database/schema/mariadb-schema.sql`, the
   `releases` table), for every category.
3. **Resolution, source and audio languages** are kept for every category by `ReleaseDerivedFacts::refresh()`
   (`app/Services/Releases/ReleaseDerivedFacts.php:31`), called from `SearchService::updateRelease()`
   (`app/Services/Search/SearchService.php:161`), which every release change reaches.
4. **Pictures and clips** are written by today's additional processing: `releases.haspreview`, `jpgstatus`,
   `videostatus`, and a `release_video_clips` row (unique `releases_id`, with `mime`, `extension`, `duration_seconds`)
   for a clip (`INVENTORY.md` section 4). The shared row facts already carry the preview and sample thumbnails and their
   full-size URLs (`app/Services/Releases/ReleaseRowFacts.php:99-100`); they carry no clip.
5. **Full-size copies.** `getImageAssetUrl()` returns a URL only when the file is on disk and the fallback otherwise
   (`app/Extensions/helper/helpers.php:546-551`; ADR `docs/adr/0012-imagery-full-size-copy-and-ceiling-guarded-downscale.md`),
   so "has a full-size sample" is `getImageAssetUrl('sample', $guid) !== null`.
6. **The clip player** is today's route `preview/video/{guid}` (`routes/web.php:132`, `VideoPreviewController`): it
   serves a clip for any release with `videostatus = 1`, from its `release_video_clips` row when there is one
   (`Release::videoClip()`, `app/Models/Release.php:292`) and otherwise as a legacy OGV. In the lab every adult release
   with `videostatus = 1` has a row (1,803), and 4 of those rows have no `duration_seconds`.
7. **The name search today** is `COALESCE(NULLIF(TRIM(display_name), ''), searchname) LIKE '%…%'` with `!` escaping
   (`app/Services/Releases/ReleaseBrowserQuery.php:115-126`, `:133-136`).
8. **Similar releases** is today's `ReleaseSearchService::searchSimilar()` (`app/Services/Releases/ReleaseSearchService.php:1390`),
   fixed for every root by #859.
9. **The password setting** is `ReleaseBrowseService::showPasswords()` (`app/Services/Releases/ReleaseBrowseService.php:608`).
10. **The file count** a row shows is `releases.totalpart` (`app/Services/Releases/ReleaseRowDataLoader.php:105`); "—"
    is shown where it is 0 (`SPEC.md` 5A.2).
11. **The Category menu** of a band list is built as `MovieReleaseList::categoryMenu()` builds it
    (`app/Services/Releases/MovieReleaseList.php:71-88`): the root's sub-categories the user may see
    (`Category::getForMenu()`), in an explicit `CATEGORY_ORDER`, kept only while they hold releases (`valueCounts()`).
12. **The view preferences** accept a `sort` only for TV and Movies today (`app/Http/Requests/UpdateReleaseViewRequest.php:28`).
13. **The header menu** links a root with a built list to it and every other root, Adult included, to `/browse/<root>`
    (`resources/views/partials/header-menu.blade.php:12-15`).

---

## 2. New storage

None. What each approved element reads:

| Element (SPEC) | Stored in | Written by |
|---|---|---|
| Category filter, "Exclude Other" (5.2) | `releases.categories_id`; the `categories` rows of root 6000 | categorisation; VR's row per #879 |
| Resolution filter and chip (5.2, 5.4) | `releases.resolution` | `ReleaseDerivedFacts` (fact 3) |
| Audio filter (5.2) | `release_audio_languages` + `languages` | `ReleaseDerivedFacts` (fact 3) |
| Completion filter and chip (5.2, 5.5) | `releases.completion` | today |
| Name search (5.9) | `releases.display_name`, `searchname` | today |
| Picture: preview, else sample (5.6) | the preview / sample thumbnail file on disk (`ReleaseRowFacts` returns its URL only then) | additional processing (fact 4) |
| Clip chip, tag and dialog (5.10, 5A.3) | `releases.videostatus`; `release_video_clips.duration_seconds` when present | additional processing (fact 4) |
| Sample at full size (5A.3) | the full-size file on disk | additional processing; fact 5 |
| Files "—" (5A.2) | `releases.totalpart` = 0 | today (fact 10) |
| PreDB (5A.3) | `releases.predb_id` → `predb` | today |
| Similar releases (5A.4) | the search index | today (fact 8) |
| Remembered filters and sort (5.1, 5.2) | `users.view_prefs` under the list's root (`xxx`, `BrowseRoot::Adult`), beside the sort; `UpdateReleaseViewRequest` accepts Adult's sort (fact 12) | #881's rule |
| "Exclude Other" | a mode of the Category filter, in the URL and the remembered filters, never a list of ids (4.1) | this contract |

**Not stored**: nothing about a release is copied for Adult, no per-section table, no per-row aggregate (the
repository's normalisation rule).

---

## 3. Write paths

No new write path. The screens only read. The values above are written by the paths named in section 2; #879 adds the
VR category row and #881 adds the remembered filters.

---

## 4. Read paths (each measured at full catalogue size)

The Adult list is a `BandReleaseList` for band 6000, built like `MovieReleaseList` without its film parts: the band
query, `whereRelease()`, and the name search as one more condition on the chosen index. All figures: best of three warm
runs on the lab's full copy; "read" is the `Handler_read*` count (`evidence/adult-data-contract.md`). With 13,063
visible releases, a page past offset 6,531 is read mirrored from the other end (`BandReleaseList.php:94`).

### 4.1 The list

| Read | Index (as `releaseIndex()` chooses) | ms | rows read |
|---|---|---:|---:|
| page 1, posted newest | band_posted | 0.1 | 156 |
| the worst page (offset 6,500, the last read before mirroring) | band_posted | 1.4 | 9,357 |
| last page (mirrored) | band_posted | 0.1 | 51 |
| Added newest, page 1 / middle | band_added | 0.1 / 1.7 | 112 / 10,439 |
| count, no filter | band_count | 1.6 | 16,008 |
| Exclude Other, page 1 / middle / last | band_cat_posted | 1.1 / 3.5 / 1.1 | 7,204 / 10,654 / 7,204 |
| Exclude Other, count | band_count | 1.6 | 16,008 |
| Category x264, page 1 / count | band_cat_posted / band_count | 0.1 / 1.7 | 50 / 16,008 |
| Resolution 1080p, page 1 / count | band_res_posted / band_count | 0.1 / 0.5 | 50 / 3,786 |
| Exclude Other + 1080p + 95%+, page 1 / count | band_res_posted / band_count | 0.1 / 0.5 | 62 / 3,786 |
| Completion 100% only, page 1 / count | band_posted / band_count | 0.1 / 1.8 | 223 / 16,008 |
| Audio English, page 1 / count | band_posted / band_count | 0.2 / 5.7 | 552 / 29,072 |
| Audio Unknown alone, page 1 / count | band_posted / band_count | 0.1 / 5.6 | 252 / 29,071 |
| Audio English + Unknown (NOT EXISTS), page 1 / count | band_posted / band_count | 0.3 / 19.5 | 333 / 40,402 |

- **Exclude Other is a mode, not a list of ids.** It is kept as its own value of the Category filter in the URL and in
  the remembered filters, and becomes, at query time, every sub-category the user's Category menu lists except Other.
  Kept as ids, a remembered "Exclude Other" would stop meaning "everything but Other" the day a sub-category gains its
  first release (the menu lists only sub-categories that hold releases; WEBDL holds none in the lab), which is not what
  he asked for ("essentially shows all categories except Other"). Ticking or unticking a sub-category while it is set
  turns it into that explicit list; with no Other in the menu there is no Exclude Other item.
- **Its index.** `releaseIndex()` weighs the chosen values with `valueCounts()`, which counts every release of the band
  whatever its password status: Exclude Other is 7,170 of 16,007 (45%), under half, so the `_cat_` index is read and its
  ranges sorted: every page reads the chosen categories' entries (7,204) once, 1.1 ms. The cost grows with the band's
  non-Other count, and remembered filters make Exclude Other a likely standing choice.
- **On the TV and Movies lists** (`SPEC.md` 5.2: every release list), measured on the same full copy: Movies Other holds
  519,173 of 575,109 releases, so Exclude Other (55,936) reads the `_cat_` index: 8.0 ms page 1, 10.0 ms page 20, count
  56.6 ms (cached); TV Other holds 1,433 of 173,152, so Exclude Other is over half and reads the band index: 0.1 ms page
  1, count 17.9 ms. The TV and Movies lists are built, so adding it there is their own build issue.
- The Category menu is fact 11's for root 6000 with `CATEGORY_ORDER` DVD, WMV, XviD, x264, HD Clips, SD Clips, UHD, VR,
  Packs, Imageset, SD, WEBDL, Other (6010, 6020, 6030, 6040, 6041, 6042, 6045, 6046, 6050, 6060, 6080, 6090, 6999); a
  sub-category with no release is not listed. `valueCounts()` is 2.6 ms uncached (32,192 rows read) and the Audio menu
  `audioMenu()` 7.1 ms (34,700 rows read), both cached an hour. Adult's cache prefix is its own (`adult_releases`), so
  its counts and menus never share TV's or Movies' keys.

### 4.2 The name search

The condition of fact 7, exactly, applied to the index `releaseIndex()` chooses; the row is read by primary key for each
index entry until 50 match. The scratch copy has production's list indexes but narrower rows (the columns the lists
read); the worst case was also run on the untouched full-width copy (77 columns, the admin index on
`categories_id, postdate`): 17.0 ms page, 16.6 ms count.

| Read | ms | rows read |
|---|---:|---:|
| a common token, page 1 / count | 0.6 / 16.7 | 581 / 16,008 |
| a middling token, page 1 / count | 3.5 / 15.7 | 3,617 / 16,008 |
| a token no name contains, page 1 / count (the worst) | 16.0 / 15.4 | 16,008 / 16,008 |
| a middling token + Exclude Other, page 1 / count | 5.9 / 5.6 | 7,204 / 7,154 |
| a common token, last page (mirrored) | 4.4 | 4,562 |

- The count with a name search cannot be answered from `ix_releases_band_count` (it holds no name): it is read from the
  same index as the page (`countIndex()` follows `readIndex()` while a search is set). The count's cache key includes the
  search text.
- The worst case reads every row of the band once; the cost grows with the Adult band's size (16,007 releases on the
  lab copy, 17,195 on production).

### 4.3 The row facts for a page

| Read | ms | rows read |
|---|---:|---:|
| clip, preview and sample flags for the page's 50 ids (primary key) | 0.1 | 151 |
| clip seconds for the page's ids with a clip (`release_video_clips`, unique `releases_id`) | 0.3 | 324 |

- The Adult rows add a clip to the shared row facts: present when `videostatus = 1` (today's player plays it, fact 6),
  with its seconds from `release_video_clips.duration_seconds` when there is a row and a value; otherwise the chip and
  the details tag read "Clip" and the preview's accessible name "Preview, play the video clip". One query per page for
  the list and one for the Similar releases table (both show the Clip chip); how it is shared is the implementer's
  choice.

### 4.4 The details page

- The release by guid, its PreDB row, its media info, files and NFO: today's reads, unchanged.
- The clip marker and the Video clip dialog: `videostatus = 1` and today's player (fact 6).
- The sample at full size: only a full-size copy (`getImageAssetUrl('sample', $guid)` not null, fact 5) can be larger
  than the dialog; the dialog opens in its Full size state when the loaded image's natural size is larger than the
  dialog's, a browser-side check (thumbnails are 650 px wide and never are).
- Similar releases: today's search-index query (fact 8). Its search-index part was timed on production's index,
  read-only: 40 queries from random Adult names, median 1 ms, slowest 2 ms (`SHOW META`; the database fetch of the
  50 rows that follows is a primary-key read).

---

## 5. Filling what already exists

Nothing to fill: every value is already written for every category. The Adult rows of the lab copy that have no
resolution or no audio language read "Unknown", as TV and Movies do.

---

## 6. Tests the build must include

1. The Adult list reads band 6000 only, 50 a page, newest posted first; a page past the middle returns the same ids as
   the unmirrored read.
2. Category, Resolution, Audio (a language, Unknown alone, both) and Completion narrow the list as TV and Movies do,
   OR within a filter and AND between filters.
3. "Exclude Other" is a mode: set, it lists no Other release and the cell reads "Exclude Other"; remembered, it still reads
   "Exclude Other" and includes a sub-category that gained its first release after it was chosen; ticking Other as well
   turns it into the explicit list; with no Other sub-category in the menu there is no "Exclude Other" item.
4. The name search keeps only releases whose `display_name`, or `searchname` when that is empty, contains the text,
   with `%`, `_` and `!` taken literally; it combines with the filters; its count matches the rows.
5. A release with `videostatus = 1` and a `release_video_clips` row with seconds shows "Clip · N s" (chip and details
   tag); with no row or no seconds it reads "Clip"; with `videostatus = 0` there is no chip, tag or play button.
6. A row's picture is the preview thumbnail when its URL is not null, else the sample thumbnail, else the "No picture"
   tile.
7. The details page: a release with a clip has the preview's clip marker and its preview opens the clip dialog; a
   release with a full-size sample opens it at full size; one without shows its thumbnail.
8. Excluded categories and the password setting hide releases from the list, its count and Similar releases.
9. The count's cache key includes the name search text and the Exclude Other mode; Adult's cache keys never collide with
   TV's or Movies'.
10. The Adult sort is accepted and remembered by `UpdateReleaseViewRequest` for root `xxx`; the header menu's Adult root
    and sub-category links open the Adult list.

---

## 7. Decisions recorded

- No new storage for Adult (this contract). The maintainer's decisions that set what is read are in `SPEC.md`: VR kept
  and OnlyFans dropped (#879), hidden categories enforced on single-release pages (#880), the lists remember their
  dropdown filters (#881), the Audio filter kept, no Source filter or column.
