# Home page: data notes

Facts measured on the query lab's restored production catalogue (MariaDB 11.4, 2,359,525 releases, "now" = the newest
`adddate` in the copy, 2026-09-20 02:01:57) on 2026-10-10, read-only. Every read of the approved page was timed warm,
with the lab client's round trip (88 ms) subtracted. Code facts are from master at the time of writing. Section 3 was
re-measured on the production database after the page's adversarial review (2026-10-10): the lab copy's schema stops
at the migration of 2026-09-19 and lacks the band column and indexes the reads use.

## 1. Why today's home page is slow

`ContentController::show` (`app/Http/Controllers/ContentController.php:84`) renders `content.home` with
`HomeDashboard::forUser` (`app/Services/Releases/HomeDashboard.php:17-29`):

| What it runs | Lab time | Why |
|---|---|---|
| `ReleaseBrowserQuery::paginate` for eight "Latest" cards (`app/Services/Releases/ReleaseBrowserQuery.php:20-37`): `$query->count()` over every visible release before taking eight rows | 293 ms | a full-catalogue count for a pager the page never shows (`:toolbar="false" :pager="false"` in `home.blade.php:11`) |
| the eight rows themselves (newest `adddate`, `isrenamed = 1`, post-processed) | < 5 ms | |
| "Following": `ROW_NUMBER() OVER (PARTITION BY …)` across every release of every followed title, then `title_rank = 1`, limit 5 (`HomeDashboard.php:21-23`) | 701 ms | scans all releases of the followed titles; grows with them |
| `loadReleaseRows` on the result (`app/Services/Releases/ReleaseBrowseService.php:45`) and the Blade render | not timed | |

Both big reads are unbounded: they grow with the catalogue and with the followed set, and a cold cache multiplies them.

## 2. Catalogue facts that shaped the design

| Section | Releases | Added in the last 24 h | Added in the last 7 days |
|---|---|---|---|
| Other | 1,590,022 | 8,333 | 176,990 |
| Movies | 575,109 | 425 | 3,252 |
| TV | 173,152 | 2,621 | 8,675 |
| Adult | 15,878 | 111 | 674 |
| Audio | 3,645 | 30 | 423 |
| PC | 1,362 | 0 | 42 |
| Books | 211 | 0 | 18 |
| Console | 17 | 0 | 1 |

5,778 shows (`videos`), 5,624 with a poster (`tv_info.image = 1`); 17,320 films (`movieinfo`), 17,134 with a cover; 41
active groups; 548 audio-tag rows; 149 shows had a visible release added in the last 24 hours and 2,573 films in the
last 7 days (the prototype's dataset carries the newest 24 of each). The Movies arrivals in the 14-day window carry a
backfill (67,848 / 43,465 / 93,724 on three days); the "today" bar of the prototype is the rolling last 24 hours, the
same figure as the count line. `user_series`, `user_movies`, `users_releases` and `release_comments` are empty in the
copy; `user_downloads` has two rows.

The restored copy predates the `releases.resolution` / `releases.source` columns; nothing on the page reads them.

## 3. Every read of the approved page, bounded

Measured on 2026-10-10 on the production database (MariaDB 11.4.13, about 2.4 million releases), read-only, best of
three runs. It runs master's schema; the lab copy does not: `releases.category_band` (the thousand-band of
`categories_id`: 5000 for every TV category, 0 for Other's 10 and 20) and the indexes below came with the migration
`2026_09_24_000000_add_resolution_and_source_to_releases`. "Password rule" is `ReleaseBrowseService::showPasswords`
(`passwordstatus <= 0` under the site's default setting). No read carries an index hint: the index named is the one
MariaDB chose.

| Shelf / element | Read | Index | Time |
|---|---|---|---|
| "N today" / "N this week" per section | `SELECT categories_id, COUNT(*) FROM releases WHERE category_band IN (the eight sections' bands) AND adddate >= now − 1 day AND <password rule> GROUP BY categories_id` (7 days likewise); the user's excluded categories are dropped and the rest summed per section in PHP | `ix_releases_band_added`, index only | 3.6 ms (24 h), 17.3 ms (7 days, 74,396 releases in the window) |
| TV shelf: shows with new episodes | `SELECT videos_id, categories_id, COUNT(*), MAX(postdate) FROM releases WHERE category_band = 5000 AND adddate >= now − 1 day AND videos_id > 0 AND <password rule> GROUP BY videos_id, categories_id`, **no limit**; in PHP the user's excluded categories are dropped, the rest summed per show, S is the number of shows, and the 60 with the newest posting make the rail; then the shows by id | `ix_releases_band_added` | 5 ms (184 rows, 172 shows) |
| Movies shelf: films posted this week | the same on `movieinfo_id` in band 2000 over 7 days; F is the number of films; then `movieinfo` by id | `ix_releases_band_added` | 4.2 ms (675 rows, 659 films) |
| Audio, Books, Console, PC, Adult, Other shelves: the newest 60 of a section | `WHERE category_band = ? AND <password rule> AND categories_id NOT IN (the user's exclusions) ORDER BY postdate DESC, id DESC LIMIT 60`, the read the section's list makes for its first page | `ix_releases_band_posted`, index only | 0.1 to 0.2 ms per section |
| Following shelf: the followed shows | one statement over the user's `user_series` rows; per row, two subqueries on `releases` with `videos_id =`, band 5000, the password rule, the user's exclusions and the row's category list (5): the id of the newest release (`ORDER BY adddate DESC, id DESC LIMIT 1`) and the `COUNT(*)` of those with `adddate > last_visit` | `ix_releases_videos_added` | 2.1 ms for 25 shows |
| Following shelf: the followed films | the same over `user_movies` on `imdbid` in band 2000 | | 4.2 ms for 25 films |
| a show's or film's panel | `WHERE videos_id = ?` (or `imdbid = ?`) with the band, the password rule and the user's exclusions, and in the Following shelf the follow's category list; `ORDER BY adddate DESC, id DESC LIMIT 60` | `ix_releases_videos_added` for a show | 0.1 ms (a show with about 1,400 releases), 0.3 ms (a film with about 150) |
| Audio albums | the newest 60 Audio releases (above) joined to `release_audio_tags` by `releases_id`, grouped in PHP by performer + album (SPEC 3.2) | primary key | 60 rows by key, not timed |
| the "N new" badges | the count read of the Following rows above | | included above |

Every count is bounded by an `adddate` window or by one followed title; there is no window function and nothing is
read per tile or per row. The index-only counts grow with the window's arrivals, not with the catalogue. The section
lists read the band column the same way (`where('category_band', …)` in `BandReleaseList` and the lists built on it);
the same filter written as `categories_id IN (the section's sub-categories)` cannot use the band indexes.

**The Following numbers.** No follow rows exist in the catalogue, so the two Following reads were timed against a
stand-in set: the 25 shows and the 25 films with the most releases, each with the default HD + UHD category list,
the last visit 72 hours back. The plan is the one MariaDB gives for the statement over the real `user_series` table.
The Following rail is not capped: the statement returns every followed title.

**What may be cached.** The counts, the two groupings and the sections' newest-60 lists may be kept for up to 60 s.
A cached value must not differ between two users unless its key does. The counts and groupings are read per
category, so one copy per password setting serves everyone and each user's exclusions are applied in PHP. The
newest-60 lists carry the exclusions in the query and therefore in the key, as the section lists build theirs
(`BandReleaseList::count`, `app/Services/Releases/BandReleaseList.php:70-75`). Only ids, counts and dates are kept:
rows are hydrated on every request, because hydration reads the viewer's cart and Follow state
(`app/Services/Releases/ReleaseRowDataLoader.php:85-95`). The Following reads are per user and are not cached.

**Requests.** The full page (`GET /`) renders the heading row and the ticked shelves' rails, and no panel's rows. Two
further reads are fragments of the same route in the lists' `?_fragment=` convention
(`app/Http/Controllers/GenericReleasesController.php:121`; `fetchList` in `resources/js/alpine/components/tv-list.js`):
the panel of one opened tile, read under the rules of the shelf it belongs to, and the shelves block, drawn again
after the dialog saved a change. A fragment never writes `seen_at` or `last_visit` (SPEC 3.4). The fragments' names
and parameters are the build's choice.

## 4. What the page stores

One new key in the existing per-user JSON column `users.view_prefs` (`app/Models/User.php:143,189`; written through
`ReleaseViewPreferencesController` (`app/Http/Controllers/ReleaseViewPreferencesController.php:17-30`) under a root
key validated by `UpdateReleaseViewRequest` (`app/Http/Requests/UpdateReleaseViewRequest.php:19-36`)): `home` → `{
shelves: [the nine shelf names in the user's order], ticked: [the ticked names], seen_at: <unix>, last_visit: <unix>
}`. No new table, no new column, no migration.

- **Two lists, not one.** The dialog reorders unticked shelves too, and a shelf keeps its place while it is unticked
  (the prototype stores `order` and `on`), so the order of all nine and the ticked set are stored separately.
- **The request** accepts root `home` beside the `BrowseRoot` enum and, with it, only `shelves` and `ticked` (lists of
  distinct names from the nine; `ticked` may be empty). It refuses those two keys for a list root, and the list keys
  (`view`, `size`, `per`, `thumbs` and the sorts) for `home`. **No `home` case is added to `BrowseRoot`**
  (`app/Enums/BrowseRoot.php:11-19`): a case would make `/browse/home` render the Other list
  (`app/Http/Controllers/BrowseController.php:20-21,37`) and add a "Home" row to Account > Appearance
  (`resources/views/account/appearance.blade.php:10-12`). `User::releaseViewPreferences()` (`User.php:210-216`) stays
  as it is for the list roots.
- **Defaults.** The default set and order (SPEC 4) apply while the two lists are absent. The first render stores
  `seen_at` under the same `home` key, so "the user has a `home` key" is not the test. `ticked: []` is valid and means
  no shelves.
- **Hidden sections.** All nine shelves always have a place: a shelf whose section the user may not view keeps its
  stored place and tick, and is left out of the page and of the dialog.
- **The two times.** `seen_at` and `last_visit` are written by the full-page render only, inside the lock-and-re-read
  transaction both existing writers of the column use (`ReleaseViewPreferencesController.php:19-28`,
  `app/Services/Releases/RememberedListFilters.php:155-170`), so a tick saved at the same moment is not overwritten.

## 5. Where the pictures and rows come from

- Show poster: `TvShowTile::$poster` (`app/Data/TvShowTile.php:14`), as the TV shows wall builds it
  (`resources/views/tv/partials/show-tile.blade.php`); film cover: `MovieFilmTile` (`app/Data/MovieFilmTile.php`), the
  same tile with `kind = film`. That partial is a link (`show-tile.blade.php:11`) shared by the TV wall, Similar shows
  and the Films wall: the home tile is a `button` with the same `.tv-tile` look, a partial of its own, and the shared
  partial does not change.
- Adult preview thumbnail: `ReleaseRowFacts` builds `['thumb' => getImageAssetUrl('preview', $guid.'_thumb'), …]`
  (`app/Services/Releases/ReleaseRowFacts.php:100`); the Adult list's row picture is `AdultReleaseRow::picture()`
  (`app/Data/AdultReleaseRow.php:74-84`: the preview thumbnail, else the sample thumbnail, else none, which draws the
  "No picture" tile), and the home tile uses the same rule; served by `GET /covers/{type}/{filename}`
  (`routes/web.php:112-116`, `CoverController::show`, which accepts the `_thumb` basename at
  `app/Http/Controllers/CoverController.php:68-69`).
- Rows: the generic lists' row (`resources/views/generic/releases/row.blade.php`), loaded by
  `GenericReleaseRows::load` (`app/Services/Releases/GenericReleaseRows.php:34`). The panel leaves out the row's
  select cell (`row.blade.php:28`), and its Category cell (`:49`) reads the sub-category alone in a section shelf and
  "Root > Sub" in Following; the lists' own output does not change. The older row of search and the basket
  (`resources/views/components/release-browser/row.blade.php`) is not the panel's row.
- The followed set: `WatchlistService::subscriptions` / `titles` (`app/Services/Releases/WatchlistService.php:24,144`),
  tables `user_series (users_id, videos_id, categories)` and `user_movies (users_id, imdbid, categories)`.
  `categories` holds the follow's category ids joined by `|`; NULL, empty or holding the root's own id means every
  category of the root. This is the `watching` rule (`app/Services/Releases/ReleaseBrowserQuery.php:80-104`) the
  Following page reads a title's latest release with (`WatchlistService.php:171-175`), and the Following shelf
  applies it to everything it shows for the title (SPEC 3.4).
- The password rule: `ReleaseBrowseService::showPasswords` (`ReleaseBrowseService.php:581`), the setting
  `showpasswordedrelease`; the user's category exclusions (`users.categoryexclusions`, as
  `ReleaseBrowserQuery::baseQuery` applies them, `ReleaseBrowserQuery.php:57-59`) apply to every shelf's rows and
  counts. The exclusions already hold every sub-category of a section the user may not view
  (`app/Services/Releases/HiddenCategoryGate.php:28-29`).
- Bands: `releases.category_band` is 5000 for TV, 2000 Movies, 3000 Audio, 7000 Books, 1000 Console, 4000 PC, 6000
  Adult and **0 for Other** (its categories are 10 and 20; `Category::OTHER_ROOT` is 1 and is not a band).
- Section permissions: a shelf is offered only for a root the user may view (`BrowseRoot::permission()`,
  `app/Enums/BrowseRoot.php:61-74`); Following needs `view tv` or `view movies`.

## 6. Existing tests the build touches

`tests/Feature/HomeAndBasketTest.php`: `test_home_shows_eight_eligible_latest_releases_and_survives_empty_content`
(`:57`), `test_home_cards_show_only_finished_releases_with_their_fields_and_actions` (`:74`),
`test_home_and_basket_show_no_title_chip_for_books_or_pc_and_link_console_to_details` (`:105`, the home half) and
`test_home_limits_watched_titles_to_their_newest_releases_and_links_each_film_page` (`:161`) assert today's cards and
rows (`viewData('latest')`, `viewData('homeWatched')`) and are rewritten onto the shelves; the basket half of `:105`
and `test_basket_ignores_listing_filters_and_footer_actions_cover_all_pages_only_for_its_owner` (`:132`) stay. The two
tests named for the cards' finished-only rule (`:57`, `:74`) lose that rule with the cards (SPEC 8) and assert the
shelves' content instead.

`tests/Feature/AdminContentControllerTest.php` reads the front-page content on `/`, which keeps rendering under the
shelves (SPEC 8), but it does not pass untouched: `test_home_page_renders_html_when_no_front_page_content_exists`
(`:108`) asserts "Latest releases" and "No releases yet." (`:116-117`), which the page no longer prints; the class's
`users` fixture (`:604-620`) has no `view_prefs` column, which the render's `seen_at` write needs; and the class
requests the home page at `:112`, `:501` and `:542`.

`tests/Feature/ReleaseBrowserControllerTest.php::test_audio_thumbnails_never_show_the_old_album_cover` (`:524-551`)
asserts today's card markup on `/` in its last lines; that half moves onto the Audio shelf's tile.
