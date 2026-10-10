# Home page: data notes

Facts measured on the query lab's restored production catalogue (MariaDB 11.4, 2,359,525 releases, "now" = the newest
`adddate` in the copy, 2026-09-20 02:01:57) on 2026-10-10, read-only. Every read of the approved page was timed warm,
with the lab client's round trip (88 ms) subtracted. Code facts are from master at the time of writing.

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

5,778 shows (`videos`), 5,624 with a poster (`tv_info.image = 1`); 17,320 films (`movieinfo`), 17,134 with a cover;
41 active groups; 548 audio-tag rows; 24 shows had new episodes in the last 24 hours, 24 films were posted in the last
7 days. The Movies arrivals in the 14-day window carry a backfill (67,848 / 43,465 / 93,724 on three days); the
"today" bar of the prototype is the rolling last 24 hours, the same figure as the count line. `user_series`,
`user_movies`, `users_releases` and `release_comments` are empty in the copy; `user_downloads` has two rows.

The restored copy predates the `releases.resolution` / `releases.source` columns; nothing on the page reads them.

## 3. Every read of the approved page, bounded

| Shelf / element | Query shape | Index | Lab time |
|---|---|---|---|
| "N today" / "N this week" per section | `SELECT categories_id, COUNT(*) FROM releases WHERE adddate >= now − 1 day GROUP BY categories_id` (7 days likewise); summed per root in PHP | `ix_releases_adddate (adddate, categories_id)`, covering | 8 ms (24 h), 20 ms (7 days) |
| TV shelf: shows with new episodes | `SELECT videos_id, COUNT(*), MAX(postdate) FROM releases WHERE adddate >= now − 1 day AND videos_id > 0 AND passwordstatus <= 0 GROUP BY videos_id ORDER BY MAX(postdate) DESC LIMIT 60`, then the shows by id | `ix_releases_adddate` | 29 ms |
| Movies shelf: films posted this week | the same on `movieinfo_id` over 7 days, limit 60, then `movieinfo` by id | `ix_releases_adddate` | 74 ms |
| Audio, Books, Console, PC, Adult, Other shelves: newest 60 of a root | **a `UNION ALL` of per-sub-category `… WHERE passwordstatus <= 0 AND categories_id = ? ORDER BY postdate DESC LIMIT 60`, sorted, limit 60** | `ix_releases_password_categories_postdate (passwordstatus, categories_id, postdate)` | TV 19 ms, Console 2 ms |
| the same written as `categories_id IN (root's sub-categories) ORDER BY postdate DESC` instead | scans the postdate index past other roots | `ix_releases_postdate_admin` | TV 152 ms, Movies 414 ms: **do not write it this way** |
| Following shelf: the newest release per followed show | `SELECT v.videos_id, (SELECT id FROM releases r WHERE r.videos_id = v.videos_id AND r.passwordstatus <= 0 ORDER BY postdate DESC LIMIT 1) FROM user_series v WHERE users_id = ?` | `ix_releases_videos_categories (videos_id, categories_id)` | 51 ms for 12 shows |
| Following shelf: the newest release per followed film | the same on `imdbid` within the Movies categories | `ix_releases_imdbid_password_cat_postdate` | 4 ms for 8 films |
| a panel: a title's newest 60 releases | `WHERE videos_id = ? (or imdbid = ?) AND passwordstatus <= 0 ORDER BY postdate DESC LIMIT 60` | as above | ≤ 51 ms |
| Audio albums | the newest 60 Audio releases (above) joined to `release_audio_tags` by `releases_id`, grouped in PHP by performer + album | primary key | ≤ 20 ms |
| the "N new" badges | the Following rows' `adddate` compared with the stored `last_visit` in PHP | none | 0 |

No `COUNT(*)` over the catalogue, no window function, nothing that grows with the catalogue or with the followed set
beyond one indexed top-1 per followed title. The per-category counts and the two "new in the window" groupings are the
same for every user and can be cached for a minute; the per-root newest-60 lists likewise; the Following reads are
per user and are cheap. Rows that reach the page go through `loadReleaseRows` as every list's rows do.

Timing script and raw results: `lab/timeq.py`, `lab/timings.json` (kept with the prototype sources, not published).

## 4. What the page stores

One new key in the existing per-user JSON column `users.view_prefs` (`app/Models/User.php:143,189`; written through
`ReleaseViewPreferencesController` (`app/Http/Controllers/ReleaseViewPreferencesController.php:17-30`) under a root
key validated by `UpdateReleaseViewRequest` (`app/Http/Requests/UpdateReleaseViewRequest.php:19-36`)): `home` →
`{ shelves: [ "Following", "TV", … ], seen_at: <unix>, last_visit: <unix> }`. The request gains root `home` (today's
`BrowseRoot` enum has no `home` case: `app/Enums/BrowseRoot.php:9-20`; the build decides whether `home` becomes a
case with no category, views or permission, or the request accepts it beside the enum) and a `shelves` key (an ordered
list of shelf names from the nine). No new table, no new column, no migration.

## 5. Where the pictures and rows come from

- Show poster: `TvShowTile::$poster` (`app/Data/TvShowTile.php:14`), as the TV shows wall builds it
  (`resources/views/tv/partials/show-tile.blade.php`); film cover: `MovieFilmTile` (`app/Data/MovieFilmTile.php`), the
  same tile with `kind = film`.
- Adult preview thumbnail: `ReleaseRowFacts` builds `['thumb' => getImageAssetUrl('preview', $guid.'_thumb'), …]`
  (`app/Services/Releases/ReleaseRowFacts.php:100`); the Adult list's row picture is `AdultReleaseRow::picture()`
  (`app/Data/AdultReleaseRow.php:72-77`), served by `GET /covers/{type}/{filename}` (`routes/web.php:112-116`,
  `CoverController::show`, which accepts the `_thumb` basename at `app/Http/Controllers/CoverController.php:68-69`).
- Rows: the generic list's row component (`resources/views/components/release-browser/row.blade.php` and the
  partials beside it), loaded through `ReleaseBrowseService::loadReleaseRows` (`ReleaseBrowseService.php:45`).
- The followed set: `WatchlistService::subscriptions` / `titles` (`app/Services/Releases/WatchlistService.php:24,144`),
  tables `user_series (users_id, videos_id, categories)` and `user_movies (users_id, imdbid, categories)`.
- The password rule: `ReleaseBrowseService::showPasswords` (`ReleaseBrowseService.php:581`), the setting
  `showpasswordedrelease`; the user's category exclusions (`users.categoryexclusions`, as `ReleaseBrowserQuery::baseQuery`
  applies them, `ReleaseBrowserQuery.php:51-54`) apply to every shelf's rows and counts.
- Section permissions: a shelf is offered only for a root the user may view (`BrowseRoot::permission()`,
  `app/Enums/BrowseRoot.php:61-74`); Following needs `view tv` or `view movies`.

## 6. Existing tests the build touches

`tests/Feature/HomeAndBasketTest.php`: `test_home_shows_eight_eligible_latest_releases_and_survives_empty_content`
(`:57`), `test_home_cards_show_only_finished_releases_with_their_fields_and_actions` (`:74`),
`test_home_and_basket_show_no_title_chip_for_books_or_pc_and_link_console_to_details` (`:105`, the home half) and
`test_home_limits_watched_titles_to_their_newest_releases_and_links_each_film_page` (`:161`) assert today's cards and
rows (`viewData('latest')`, `viewData('homeWatched')`) and are rewritten onto the shelves; the basket half of `:105`
and `test_basket_ignores_listing_filters_and_footer_actions_cover_all_pages_only_for_its_owner` (`:132`) stay.
`tests/Feature/AdminContentControllerTest.php` reads the front-page content on `/` and stays while the content keeps
rendering under the shelves (SPEC 8).
