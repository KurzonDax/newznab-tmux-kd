# NNTmux TV section: data contract

Rewritten 2026-09-21 around Randall's schema rules: **normalize wherever possible; nothing
about releases may be specific to one category; downtime is not a concern.** This document
says exactly what is stored, where, and which existing code writes it. It replaces `SPEC.md`
sections 5, 6 and 8. (The first version, a TV-only side table, is kept in
the maintainer's notes; it was rejected.)

Every claim about the application was read from the code on `master` at `add3c65a2` and
carries a `path:line` (re-check before relying on one: line numbers move). Every query shape
was run on the restored production catalogue in the maintainer's query lab (outside this repository; its write-ups are copied to `evidence/`)
**at full size: all 2,359,525 releases, of which 173,152 are TV**, 5,648 shows with releases.
Lab write-ups: `evidence/`.

Repository conventions this contract follows (not invented here): anonymous-class migrations
with `declare(strict_types=1)`, Schema builder, `->comment()` on new columns, explicitly named
indexes ≤ 64 characters (`tests/Unit/MigrationIdentifierLengthTest.php`), a working `down()`,
plural-table key columns (`releases_id`, `videos_id`), no database triggers (none exist;
`2025_01_30_115835_drop_triggers.php`), generated columns allowed on MariaDB behind a driver
guard (`2026_09_10_231728_add_name_direct_work_index_to_releases.php:12-21`), model casts
through `casts()`, explicit relationship keys. `database/schema/mariadb-schema.sql` is the
fixture authority and is refreshed with `schema:dump` through `scripts/agent-sail` in every
migration PR (`.ai/rules/testing.md:48-53`); tests build tables with
`Tests\Support\ProductionTables::fromAuthority()` (`:56-65`).

---

## 1. Facts about the application that shape the design

1. **Release inserts and deletes do not fire model events.** `Release::insertRelease()` uses
   `insertGetId` (`app/Models/Release.php:324-355`); every delete is a query-builder or raw
   delete (`app/Services/Releases/ReleaseManagementService.php:81,141,257`;
   `app/Console/Commands/DeleteReleases.php:148`;
   `app/Services/AdditionalProcessing/ReleaseFileManager.php:592`;
   `app/Services/CollectionReconciliation/PostingPublication.php:251`). Child tables rely on
   `ON DELETE CASCADE` foreign keys instead; 29 tables already do.
2. **Every release change already ends in one method: `SearchService::updateRelease()`**
   (`app/Services/Search/SearchService.php:157-160`). The model states the rule: "Query-builder
   and raw SQL writers bypass `ReleaseObserver`. Any such writer that changes a field present in
   `ReleaseSearchIndexDocument` must explicitly call this method" (`app/Models/Release.php:456-460`).
   Verified: 27 call sites in 21 files; `Release::syncSearchIndexAfterCommit()` (`:461-470`),
   `ReleaseSearchIndexSync::forIds() / forVideo() / forCategory()`
   (`app/Support/ReleaseSearchIndexSync.php:22-60`), `ReleaseObserver::updated()`
   (`app/Observers/ReleaseObserver.php:38-70`) and `ReleaseSearchSyncCoordinator`
   (`app/Services/AdditionalProcessing/ReleaseSearchSyncCoordinator.php:44-53`) all funnel into
   it. Creation, renames, recategorisation (single and bulk), TV match and unmatch, password
   and completion changes, admin edits and the reset commands all reach it.
3. **What a user may see is per user.** Today's browse
   (`app/Services/Releases/ReleaseBrowserQuery.php:69-139`) applies `passwordstatus <= 0`, or
   `<= 1` when the site setting `showpasswordedrelease` is 1
   (`app/Services/Releases/ReleaseBrowseService.php:608-617`); the user's excluded categories
   (`:73`; permissions plus the `user_excluded_categories` pivot, `app/Models/User.php:1733-1782`);
   and **no `nzbstatus` test**. Counts therefore cannot be global.
4. **Measured video size has two stores.** Legacy `video_data` (one row per release,
   insert-once: `app/Services/ReleaseExtraService.php:270-299`) and the current
   `media_info_probes` / `media_info_tracks` snapshot (newest two probes kept; a `Complete`
   probe beats a `Partial` one: `app/Services/MediaInfo/MediaInfoSnapshotService.php:34-98`).
   Both are written in one closure (`app/Services/AdditionalProcessing/MediaExtractionService.php:239-262`);
   the audio path has its own pair (`app/Services/AudioProcessing/AudioReleaseProcessor.php:132-137,288`).
   Both paths already request a search re-index (`ReleaseExtraService.php:260-264`), so they
   reach fact 2's method.
5. **Name parsing for quality already exists**: `App\Support\ReleaseQuality::fromName()` and
   `::label()` (`app/Support/ReleaseQuality.php:11-36`). Measured-size cut-offs already exist
   for UHD and HD (`app/Services/Categorization/MediaInfoRefinementService.php:156,199`).
6. **Episode membership is re-derived on every request** by parsing `searchname` into
   temporary tables (`app/Services/Releases/TvBrowseMembershipTable.php:27-108`; parser
   `app/Services/Releases/TvReleaseMembership.php:28-71`). The parsed numbers are stored nowhere.
7. **The single write point for a TV match** is `AbstractTvProvider::setVideoIdFound()`
   (`app/Services/TvProcessing/Providers/AbstractTvProvider.php:159-175`). Shows are created in
   `add()` (`:194-254`); `update()` only fills **empty** fields (`:283-330`). No provider stores
   genres, cast, language, rating or status. `TmdbClient::getTvShow()` supports
   `append_to_response` (`app/Services/TmdbClient.php:281-289`); there is no TV credits or
   content-ratings call today, and no "last refreshed" value for shows. 5,357 of 5,778 shows
   carry a TMDB id although most were created by TVDB.
8. `videos` rows are never deleted or merged by any code path; `tv_info`, `tv_episodes`,
   `videos_aliases`, `user_series` have no foreign key to `videos`.
9. `tv_episodes` can hold duplicate `(videos_id, series, episode)` rows (its unique key includes
   nullable `firstaired`); the code's canonical rule is `MIN(id)`
   (`app/Services/Releases/TvBrowseMembershipTable.php:97-98`).
10. `categories.root_categories_id` equals the thousand-band of the id for every category
    except Misc (10) and Hashed (20), whose root is 1 (checked against the 68 rows in
    production).

---

## 2. New storage

Nothing is copied and no aggregate is stored. One value per release lives on `releases`; one
value per show lives on `tv_info`; genuine one-to-many gets a child table; repeated strings
get lookup tables.

### 2.1 Two columns on `releases`, for every category

| Column | Type | Meaning |
|---|---|---|
| `resolution` | `unsignedTinyInteger`, default 0 | 0 unknown or not applicable, 1 4K, 2 1080p, 3 720p, 4 SD |
| `source` | `unsignedTinyInteger`, default 0 | 0 unknown or not applicable, 1 WEB, 2 Blu-ray, 3 DVD, 4 HDTV, 5 Remux (a Blu-ray remux; the "Blu-ray" filter matches 2 and 5, the UI shows "Remux") |
| `category_band` | generated, `virtualAs('FLOOR(categories_id / 1000) * 1000')`, MariaDB only behind the usual driver guard | 5000 for every TV category, 2000 for every movie category. Not a copy: derived from `categories_id` in the same row. It exists because a list "TV, newest first" needs an *equality* to lead an index, and TV is a *range* of category ids. It equals `categories.root_categories_id` except for Misc and Hashed (fact 10). |

PHP enums `App\Enums\ReleaseResolution` and `App\Enums\ReleaseSource` carry the numbers and
the display labels. The values apply to every video-bearing category; categories where they
mean nothing (audio, books, …) stay 0.

Indexes added to `releases`:

```
ix_releases_band_posted (category_band, postdate, id, resolution, source, categories_id, passwordstatus)
ix_releases_band_added  (category_band, adddate,  id, resolution, source, categories_id, passwordstatus)
ix_releases_band_count  (category_band, resolution, source, categories_id, passwordstatus)
ix_releases_videos_posted (videos_id, postdate)
ix_releases_videos_added  (videos_id, adddate)
```

Measured at full size: the three band indexes are 54–65 MB each, the two show indexes 47 MB
each. `releases` is altered with the documented procedure in
`docs/releases-table-optimization.md` (backup, `tmux:stop`, migrate, `schema:dump`,
`tmux:start`); downtime is acceptable.

**Resolution rule** (his decision: measured, else the name):

1. Measured width `w`, height `h` of the release's video: the video track of the probe that
   `MediaInfoSnapshotService::selectedForRelease()` selects (`:86-146`); else
   `video_data.videowidth / videoheight`; zeros ignored.
2. If measured: `w >= 3800 || h >= 2100` → 4K (existing UHD cut-off, `MediaInfoRefinementService.php:156`);
   else `w >= 1900 || h >= 1000` → 1080p; else `w >= 1280 || h >= 720` → 720p (existing HD
   cut-off, `:199`); else SD.
3. Else from **`searchname`** through `ReleaseQuality::fromName()`: `2160p/i` → 4K, `1080p/i`,
   `720p/i`, `576|480 p/i` → SD, nothing → 0.

**Source rule** (from the name; media info cannot tell a source): extend the one pattern in
`ReleaseQuality::label()` (`app/Support/ReleaseQuality.php:32`) rather than adding another.
`REMUX | BDRemux` → Remux; `WEB-DL | WEBRip | WEB` → WEB; `BluRay | Blu-Ray | BDRip | BRRip` →
Blu-ray; `DVDRip | DVD` → DVD; `HDTV | PDTV | SDTV | DSR | TVRip` → HDTV; else 0.

Production result for TV: 4K 20,379 · 1080p 135,359 · 720p 9,914 · SD 5,632 · unknown 1,868
(1.1%); source unknown 8,583 (5.0%). For movies only 55,367 of 575,109 have a known
resolution today, which matters when Movies is designed (section 4).

### 2.2 `release_tv_episodes`: what a release declares

One release can name several episodes, or contain a whole season, so this is a plain
one-to-many child of `releases`. It is TV by nature, as `tv_episodes` is.

| Column | Type | Meaning |
|---|---|---|
| `id` | auto-increment primary key | |
| `releases_id` | `unsignedInteger`, FK → `releases.id` `cascadeOnDelete` | |
| `season` | `unsignedSmallInteger` | as declared; 0 = the Specials season |
| `episode` | `unsignedSmallInteger`, **nullable** | as declared, 0 included (a season's special); **NULL = the release contains the whole season** |

One index, `ix_release_tv_episodes_release (releases_id)`. No uniqueness rule: the writer
replaces a release's rows wholesale. **No sentinel values**: `episode = 0` is a real episode
(27 production releases declare `SxxE00`) and `season = 0` is the Specials season (10,155
`tv_episodes` rows). The show is not stored here: it is `releases.videos_id`; every read
starts from a show through `ix_releases_videos_posted` and joins on `releases_id`.

Values come from **the existing parser unchanged**, `TvReleaseMembership::describe()`
(`app/Services/Releases/TvReleaseMembership.php:28-71`): `SxxEyy`, multi-episode ranges,
whole-season packs; when the name declares nothing and `releases.tv_episodes_id > 0`, take
`(series, episode)` from that `tv_episodes` row (how date-named daily shows get their place).
A release of a show that declares nothing and has no linked episode has no row and is listed
under "Other releases".

**Deliberate consequence:** the show page and "All N releases of this episode" are driven by
what releases *declare*, not by whether a `tv_episodes` row exists. `tv_episodes` only supplies
title and air date (canonical row = `MIN(id)` per `(videos_id, series, episode)`). On
production 17,319 of 156,679 declared episodes (11%) have no `tv_episodes` row; today those
releases vanish from the show page, with this contract they are listed as "Episode N".

### 2.3 Show details

One value per show → new columns on **`tv_info`** (5,778 rows, keyed by `videos_id`):

| Column | Type | Meaning |
|---|---|---|
| `original_language` | `string(8)`, default `''` | TMDB `original_language` (ISO 639-1); the UI shows the name |
| `status` | `unsignedTinyInteger`, default 0 | 0 unknown, 1 Running (`Returning Series`, `In Production`, `Planned`, `Pilot`), 2 Ended (`Ended`, `Canceled`) |
| `content_rating_us` | `string(8)`, default `''` | TMDB `content_ratings` entry for `US` |
| `premiered` | `date`, nullable | TMDB `first_air_date`; the UI falls back to `videos.started`. Index `ix_tv_info_premiered (premiered, videos_id)` |
| `networks_id` | `unsignedInteger`, nullable, FK → `networks.id` | what the Network filter reads. `publisher` stays as the provider's raw text |
| `details_refreshed_at` | `timestamp`, nullable | when details were last fetched; NULL = never |

Repeated strings → lookup tables plus link tables, keyed by `videos_id` so they serve any row
in `videos` (TV, film, anime), not only TV:

```
networks      (id, name string(80) unique)                         591 raw spellings today collapse here
genres        (id, name string(40) unique)
people        (id, name string(120), tmdb_id unsignedInteger nullable unique)
video_genres  (videos_id, genres_id)            PK (genres_id, videos_id),  ix_video_genres_video (videos_id)
video_people  (videos_id, people_id, position unsignedTinyInteger)
                                                PK (people_id, videos_id),  ix_video_people_video (videos_id, position)
```

`networks.name` is matched case-insensitively after trimming (two capitalisations of one network are one row);
the displayed spelling is the first one stored. Cast = the first 12 of TMDB `credits.cast` in
TMDB's order; `people.tmdb_id` makes two actors with one name distinct and one actor with two
spellings the same. Genres use TMDB names after this mapping: `Sci-Fi & Fantasy` → Sci-Fi +
Fantasy, `Action & Adventure` → Action + Adventure, `War & Politics` → War, `Kids` → Children.

**Not stored:** any per-show "newest release", "first added" or release count. They are read
from `ix_releases_videos_posted` / `ix_releases_videos_added` (section 4).

Coverage to expect (all 5,357 TMDB ids fetched; 5,353 found): language and status 93% of shows
with releases, genres 91%, cast 85%, network 97%, premiere year 99.9%, **US rating 57%**.

---

## 3. Write paths

### 3.1 `resolution`, `source`, `release_tv_episodes`

One service: **`App\Services\Releases\ReleaseDerivedFacts`**, one method:
`refresh(int $releaseId): void`.

1. Read the release (`searchname`, `categories_id`, `videos_id`, `tv_episodes_id`, current
   `resolution`, `source`) and its measured size (section 2.1 rule 1).
2. Compute `resolution` and `source`. Update `releases` **only if a value changed**, with a
   query-builder update of those two columns (no model event, no recursion).
3. If the release is in a TV category and `videos_id > 0`: replace its `release_tv_episodes`
   rows with what `TvReleaseMembership::describe()` returns (one row per number, or one row
   with `episode` NULL for a full season). When the name declares nothing and
   `tv_episodes_id > 0`, take `(series, episode)` from that `tv_episodes` row **only if its
   `videos_id` equals the release's**; otherwise the release has no row (776 releases on the
   production copy are linked to another show's episode through the `addEpisode()` fault,
   #790). Otherwise delete its rows. The shapes `describe()` must read are widened by #792
   (`S01 E06`, `S1940E09`, bare `Sxx` and `COMBINED` packs), with a migration that re-runs the
   fill.

**Called from exactly one place: `SearchService::updateRelease()`**
(`app/Services/Search/SearchService.php:157-160`), before the driver call and whatever search
driver is configured. By fact 2 that covers creation (`Release::insertRelease()` →
`syncSearchIndexAfterCommit()`, `app/Models/Release.php:355`), every rename and
recategorisation, TV match and unmatch (`setVideoIdFound()` → `save()` →
`ReleaseObserver::updated()`), the arrival or improvement of media info (fact 4), admin edits
and the reset commands. A writer that changes `searchname`, `categories_id`, `videos_id` or
`tv_episodes_id` without reaching `updateRelease()` is already a bug under the existing rule
and is fixed, not worked around; check `app/Services/CollectionReconciliation/PostingPublication.php:133`.

Deletes need nothing: the two columns go with the row, the child rows go by cascade, and no
aggregate exists to go stale.

### 3.2 Show details: `App\Services\TvProcessing\TvShowDetails::refreshIfDue(int $videosId)`

1. Return if `tv_info.details_refreshed_at` is within the last 24 hours.
2. Resolve a TMDB id: `videos.tmdb`, else `TmdbClient::findTvByExternalId()`
   (`app/Services/TmdbClient.php:356`) from `tvdb`, then `imdb`. None → set
   `details_refreshed_at = now()` and return, so it is not retried on every release.
3. One request: `TmdbClient::getTvShow($tmdbId, ['content_ratings', 'credits'])`. On failure
   change nothing, including `details_refreshed_at`.
4. In one transaction: overwrite the `tv_info` columns of 2.3 (unlike `AbstractTvProvider::update()`,
   which only fills blanks); `firstOrCreate` the network, genres and people; replace the show's
   `video_genres` and `video_people` rows; set `networks_id` (from TMDB `networks[0]`, else from
   the existing `publisher` text); set `details_refreshed_at = now()`. Then
   `Video::invalidateSeriesListCache()`.

**Called from exactly one place:** after the transaction in `setVideoIdFound()` commits
(`AbstractTvProvider.php:159-175`), never inside it (it holds a row lock; this is a network
call). That one hook is both of his rules: a show's first match is "never refreshed, so
fetch"; every later match is "a new release arrived, refresh unless done in the last 24
hours". **No scheduled or background refresh, no command.** TMDB pacing keeps today's
`sleep(1)` (`TmdbProvider.php:281,458,489`).

---

## 4. Read paths (each measured at full catalogue size)

Visibility `V`, identical to today's browse: `passwordstatus <= {0|1 per setting} AND
categories_id NOT IN (:userExclusions)`. No `nzbstatus` test. Every list filters
`category_band = 5000`.

| Screen | Query | Measured |
|---|---|---|
| TV releases, page N | `SELECT id FROM releases FORCE INDEX (ix_releases_band_posted | _added) WHERE category_band = 5000 AND V [AND categories_id IN (…)] [AND resolution IN (…)] [AND source IN (…)] ORDER BY postdate|adddate, id LIMIT 50 OFFSET n`; past the middle of the list run the mirrored order from the other end (offset `N − n − 50`) and reverse the rows; then load those 50 by primary key with the existing row loader | page 1 **0.4 ms / 101 entries**; page 200 **1.7–2.2 ms / 11–13 thousand**; exact middle of the list **12 ms / 88 thousand** |
| TV releases, rows with `videos_id = 0` | included by the page query above (no `videos_id` predicate; 10,464 visible on the production copy); rendered without poster, show line or watch button and never batched (`SPEC.md` 3.1) | no extra query |
| "Showing X–Y of N" | `SELECT COUNT(*) … WHERE category_band = 5000 AND V [AND …]` on `ix_releases_band_count`, cached under the existing browse cache version (`ReleaseBrowseService::bumpCacheVersion()`, `:776-780`) keyed by filters + exclusions + password setting | selective filter **0.4 ms**; unfiltered **15 ms**, reading all 173 thousand TV index entries. This and the exact-middle page are the two places his "thousands, not hundreds of thousands" rule is not met; it is the price of counts that are right for every user with nothing stored twice. |
| TV shows wall, "Newest releases first" / "Newest to the site first" | shows (`videos.type = 0` ⨝ `tv_info`, filters as `IN`, genre and person as `EXISTS` on the link tables) joined to `SELECT videos_id, MAX(postdate)` (or `MIN(adddate)`) `FROM releases WHERE videos_id > 0 GROUP BY videos_id`, which MariaDB answers from `ix_releases_videos_posted` as an index-only group-by | the group-by alone **5.8 ms / 17 thousand entries**; with all six filters, page 1 **6.6 ms**; no filter, last page **8.4 ms** |
| Wall, "Newest premiere first", "A to Z" | `ix_tv_info_premiered` / the `videos` title index, `WHERE EXISTS (release for this show)` | **0.4 ms** |
| Wall count, search box, filter option lists | counted / `LIKE` on 5,648 shows and 23 thousand people | 0.5–4 ms (`evidence/tv-shows-wall.md`) |
| Show page: header counts, season tabs | `releases` (`videos_id = ?`, via `ix_releases_videos_posted`) ⨝ `release_tv_episodes` on `releases_id`; tabs = distinct `season` | **1.3 ms / 4,336 rows** (biggest show, 38 seasons, 1,416 releases) |
| Show page: episode rows | same join `AND season = ? AND episode IS NOT NULL` and V and filters, `GROUP BY episode` (episode 0 is a row like any other): count, `BIT_OR(1 << resolution)`, `MIN/MAX(size)`; titles and air dates from `tv_episodes` by `MIN(id)` | biggest season **3.0 ms / 5,067 rows**; a 118-episode season **1.2 ms** |
| Open an episode; details page "All N releases of this episode" | same join `AND season = ? AND episode = ?` | 933-release episode **3.1 ms**; typical under 1 ms |
| Whole-season packs | same join `AND season = ? AND episode IS NULL` | < 1 ms |
| "Other releases" (every season tab) | `releases WHERE videos_id = ? AND category_band = 5000 AND V AND NOT EXISTS (SELECT 1 FROM release_tv_episodes WHERE releases_id = releases.id)`; ordered by the table's sort; omitted when empty | < 1 ms |
| TV search (toolbar field on the releases screen and the wall) | `GET /tv/search?q=` → `{shows: [{id, title, year, genres, poster}], people: [{id, name, shows: [titles]}]}`; shows from 1 character, people from 2; at most 6 shows then 5 people; shows ordered by match position then title, people by show count; `LIKE` on `videos.title` (type 0, with releases) and `people.name` ⨝ `video_people` | 0.5–4 ms (`evidence/tv-shows-wall.md`) |

**For when Movies is designed (not part of this build):** the same columns and band indexes
serve it, but a *selective* filter on a big band is slow in date order: "1080p movies, page
200" read 508 thousand entries in 60 ms, because only 6% of 575 thousand movies have a known
resolution today. That needs an index led by `(category_band, resolution, postdate)` or a
better resolution hit-rate for movies. TV does not have the problem (99% known).

Sorts reuse `App\Enums\ReleaseSort` (`app/Enums/ReleaseSort.php:9-46`): `posted`,
`posted_oldest`, `newest`, `oldest`. Remembered through `User::releaseViewPreferences('tv')`
(`app/Models/User.php:209-215`) and the existing `POST profile/update-view`
(`routes/web.php:255`). The Category menu is `categories_id IN (…)`, already in every index,
listing only TV sub-categories that exist and are not in the user's exclusions.

Endpoints reused unchanged: add to cart `cart/add` with a comma-separated guid list
(`routes/web.php:207`, `CartController::store():71`); one NZB or a zip `getnzb` with `zip=1`
(`:217-218`, `GetNzbController.php:215-232`); watch a show `POST|DELETE watchlist/tv/{id}`
(`:213-214`); file list `release/{guid}/files` (`:267`); NFO `nfo/{id}?modal` (`:249`); media
info `release/{release}/mediainfo` (`:269-271`, `MediaInfoPresentationService::forRelease()`);
images `getImageAssetUrl('preview'|'sample', …)` (`app/Extensions/helper/helpers.php:578`);
group and poster chips link to the existing cross-category group and poster pages. The media
info block's friendly names exist nowhere today (checked PHP and JS): they are new and belong
in one PHP presenter used by both the details tab and the dialog.

Retired when the new screens ship: `TvBrowseMembershipTable`, `TvEpisodeBrowser`, the TV
branch of `ReleaseCoverBrowser`, `TvShowDirectory`'s per-request membership.

---

## 5. Filling what already exists

Downtime is not a concern, so the migrations fill what they add. No command.

- `resolution`, `source`: the migration that adds the columns fills them for **every**
  release with one set-based `UPDATE … LEFT JOIN video_data … LEFT JOIN (probes ⨝ tracks)`,
  using the same cut-offs and name patterns as the PHP rule (lab statement:
  `evidence/release-quality-columns.sql`; **14 seconds for all 2.36 million
  releases** as an insert). A test runs both the SQL and the PHP rule over one fixture table
  and requires identical results.
- `release_tv_episodes`: needs the PHP parser, so its migration walks TV releases with a show
  in primary-key chunks of 500 (the loop `TvBrowseMembershipTable::populate()` already runs per
  request, `:65-80`), upserting so it can be re-run.
- `networks` / `tv_info.networks_id`: one statement per distinct trimmed, lower-cased
  `publisher`.
- Genres, cast, language, rating, status: **nothing**. They fill on each show's next release,
  as he decided.

---

## 6. Tests the build must include

1. Resolution and source rules: a table of names and measured sizes → expected values;
   measured beats the name; a `Complete` probe beats a `Partial` one; zero sizes; remux; the
   migration's SQL and the PHP rule agree.
2. `ReleaseDerivedFacts::refresh()` through `SearchService::updateRelease()`: create, rename,
   recategorise into and out of TV, match, unmatch, media info arriving later; each leaves the
   two columns and `release_tv_episodes` correct; an unchanged release causes no write.
3. Deleting a release through each of the four delete sites leaves no `release_tv_episodes`
   row: an ordinary Feature test on the SQLite `testing` connection, which enforces foreign
   keys (`config/database.php:33`, `'foreign_key_constraints' => true`; precedent
   `tests/Feature/ExecutableReleaseDiscardServiceTest.php:316`). No new Integration file and
   no CI inventory entry; a MariaDB method, if wanted, goes into the already-registered
   `tests/Integration/ReleaseCleanupSafetyMariaDbTest.php`.
4. Visibility: a user excluding a TV sub-category sees neither those rows nor their count;
   `showpasswordedrelease` 0 and 1; the Category menu omits excluded sub-categories.
5. Paging: page 1, a deep page, the mirrored half, the last page, an empty filter, one page;
   "Showing X–Y of N" agrees with the rows each time.
6. Declarations: multi-episode, a `SxxE00` special (a row with `episode = 0`), a season pack
   (one row with `episode` NULL), linked-only daily show, nothing declared; an episode with no
   `tv_episodes` row still lists its releases.
7. `TvShowDetails::refreshIfDue()`: first match fetches; a second within 24 hours does not;
   TMDB failure leaves `details_refreshed_at` untouched; no TMDB id resolves through TVDB then
   IMDb; genres mapped; cast capped at 12 and de-duplicated by `tmdb_id`; two network
   spellings become one row; never called inside the match transaction.
8. Query schema evidence (`.ai/rules/testing.md:101-107`): each query cites the dump's columns
   and keys, independent of the fixture; MariaDB `EXPLAIN` shows the named index for the page,
   count and group-by queries.

---

## 7. Decisions recorded

- 2026-09-21: **normalized design** as above, replacing a TV-only side table that copied
  release columns, a global count table and stored per-show aggregates. His rules: normalize
  wherever possible; nothing per category; downtime is not a concern.
- 2026-09-21: of today's browse features the approved screen lacked, he **kept the TV
  sub-category filter** and **dropped** the minimum-completion filter, "only watching", the
  in-list text filter, the page-size choice, the Title and Grabs sorts and "only my cart".
  Group and poster are not TV filters: their chips link to the existing cross-category pages.
- 2026-09-21: resolution = measured, else the name; source = the name; show details from TMDB
  first, saved on first match, refreshed only when a new release arrives and not within 24
  hours; no background refresh.
- 2026-09-24: `release_tv_episodes` has `id`, `releases_id`, `season`, nullable `episode`
  (NULL = whole season; 0 = a real special); no sentinel, no second table.
- 2026-09-24 (approved on the prototype): no "By air date" tab; releases with no matched show
  are listed; the shows-and-people search is a field in the TV toolbar and the site's top bar
  is untouched; genres reuse the existing `genres` table with `type = 5000` (#775); the linked
  episode counts only when it belongs to the release's show; the parser shapes are widened
  (#792).
