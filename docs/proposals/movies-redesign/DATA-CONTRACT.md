# NNTmux Movies section: data contract

Written 2026-09-27 after all four Movies screens were approved (`SPEC.md` 5, 5A, 5B, 5C), and corrected the same day on an
adversarial review. It says exactly what is stored, where, which existing code writes it, how existing rows are filled, and
what every screen reads, each read measured at full catalogue size. It follows the maintainer's schema rules: **normalize
wherever possible; nothing about releases may be specific to one category; downtime is not a concern.** It also settles the
TV items the approved TV changes need (the releases list's show filters, Audio and Completion; Similar shows with
visibility).

Every claim about the application was read from the code on `master` at `95a844596` and carries a `path:line` (line numbers
move: re-check before relying on one). Every query shape was run on the restored production catalogue in the maintainer's
query lab **at full size: 2,359,525 releases, 575,109 in the Movies band (519,173 of them Movies > Other), 173,152 TV; 17,320
films, 16,832 with a release**. The lab write-up is `evidence/movies-data-contract.md` with every statement. Times are the best
of three warm runs; rows read are InnoDB handler reads. The lab's `releases` copy holds only the columns these reads use
(a 302 MB primary index against production's 816 MB), so reads that look a row up by primary key (Audio, reads from the
shows) run about 20% slower on the real table: Audio English page 200 took 89 ms on the full-width copy against 74 ms on the
narrow one in the same session.

Repository conventions followed (as in `../tv-redesign/DATA-CONTRACT.md`): anonymous-class migrations with
`declare(strict_types=1)`, the Schema builder, `->comment()` on new columns, explicitly named indexes of at most 64
characters (`tests/Unit/MigrationIdentifierLengthTest.php`), a working `down()`, plural-table key columns, no triggers,
model casts through `casts()`, explicit relationship keys; `database/schema/mariadb-schema.sql` refreshed with `schema:dump`
in every migration PR; tests build tables with `Tests\Support\ProductionTables::fromAuthority()`.

---

## 1. Facts about the application that shape the design

1. **Releases link to films by a number.** `releases.movieinfo_id` (FK to `movieinfo.id`) is written beside
   `releases.imdbid` when a release is matched (`app/Services/MovieService.php:1041-1047`) and when a film is saved
   (`app/Services/MetadataProcessing/MovieReleaseBackfill.php:14-40`, which links every release with the film's `imdbid`
   and no `movieinfo_id`). On the production copy 44,454 movie releases carry it and 44,435 of them agree with `imdbid`;
   46,666 carry an `imdbid` (2,231 name a film with no record). Browse, search and RSS already join on it
   (`app/Services/Releases/ReleaseBrowseService.php:309`, `app/Services/Releases/ReleaseSearchService.php:324`,
   `app/Http/Controllers/Api/RSS.php:73`). **Every Movies read below keys films by `movieinfo_id`.**
2. **A film's details are written in one method, plus one admin edit.** `MovieService::updateMovieInfo()`
   (`app/Services/MovieService.php:354`) gathers TMDB, IMDb, Trakt and OMDb values and saves them through
   `MovieService::update()` (`:301-330`), an upsert on `imdbid` that keeps only non-empty values (`:311`) and cuts `genre` and
   `language` to 64 characters (`:313-314`). Its callers: `AdminMovieController` add and refresh (`:86`, `:145`),
   `FetchMovieByImdb` (`:51`) and `MovieService` itself (`:1060`, `:1109`). The **admin edit form** calls `update()` directly
   with hand-edited genre, director and actors (`app/Http/Controllers/Admin/AdminMovieController.php:163-190`).
   TMDB is read by `fetchTMDBProperties()` (`:592`) with `TmdbClient::getMovie($id, ['credits'])` (`:610`); `getMovie()`
   passes any `append_to_response` list (`app/Services/TmdbClient.php:161-184`), so the US certificate (`release_dates`)
   arrives in the same request, and `vote_count` and `original_language` are in the base response. Results are cached for 7
   days under `tmdb_movie_<md5>` (`:596-600`), which `FetchMovieByImdb` clears (`app/Console/Commands/FetchMovieByImdb.php:45`).
   The director and actors text follows IMDb, then OMDb, then TMDB (`:488-502`).
3. **A film is refreshed on only one path today.** A release whose IMDb id is read from its text calls `updateMovieInfo()`
   again when the film's record is more than 30 days old (`app/Services/MovieService.php:1051-1072`). A release that already
   carries a valid IMDb id goes to `fetchAndLinkMovieRecord()` (`:1020-1026`, `:1084-1093`), which links it to an existing
   film and never refreshes it. Nothing refreshes films on a schedule.
4. **Genres and people are text today.** `movieinfo.genre` (varchar 64), `director` (varchar 64) and `actors` (varchar 2000)
   hold comma-joined names. On the production copy 16,921 films have genres (6 strings cut at 64 characters), 16,961 a
   director (none cut), 16,748 a cast (85 at the 2,000-character limit; 3 contain control characters; 79 contain a name
   suffix after a comma, such as ", Jr."). No `genres` row has the Movies type (2000); TV's genres are `genres` rows of type
   5000 and TV's people `people` + `video_people` (#775). `genres` has no unique key (the production copy holds "Hip-Hop/Rap"
   six times under type 3000); `people` is unique on `tmdb_id` only (`ux_people_tmdb_id`), with no index on `name`.
5. **The external API reads the film text and lists every genre.** `XML_Response` returns `movieinfo.genre`, `director` and
   `actors` (`app/Http/Controllers/Api/XML_Response.php:721`), and the API capabilities response lists **every enabled `genres` row
   of every type** (`app/Services/Api/ApiCapabilitiesService.php:122-133`). The API is frozen, so the text columns stay, and
   new genre types must not reach that list (5.4; TV's type-5000 rows from #775 already do on `master`).
6. **Per-release derived facts have one write point.** `App\Services\Releases\ReleaseDerivedFacts::refresh()`
   (`app/Services/Releases/ReleaseDerivedFacts.php:28-38`) keeps `resolution`, `source` and `release_tv_episodes` in step and
   is called only from `SearchService::updateRelease()` (`app/Services/Search/SearchService.php:161`), which every release
   change reaches (`../tv-redesign/DATA-CONTRACT.md` fact 2). Media info is stored as probes and tracks (the probe chosen by
   `MediaInfoSnapshotService`) and the legacy `audio_data`; both paths already request a search re-index.
7. **What a user may see is per user**: `passwordstatus <= 0` (or `<= 1` with the `showpasswordedrelease` setting) and not in
   the user's excluded categories. Counts cannot be global. The built TV wall tests visibility with a per-show scalar probe,
   because MariaDB turns `EXISTS` into a semi-join over every release (`app/Services/Releases/TvShowWall.php:180-200`).
8. **Similar releases** is `ReleaseSearchService::searchSimilar()` (`app/Services/Releases/ReleaseSearchService.php:1386-1413`),
   called from `app/Http/Controllers/DetailsController.php:69` with the user's excluded categories. `search()` (`:52-150`)
   goes through `ReleaseSearchQuery` to the Manticore or Elasticsearch driver, with a MySQL fallback. The search index carries
   `movieinfo_id` on every release (`app/Support/ReleaseSearchIndexDocument.php:24,114`).
9. **Indexes the app names.** `TvReleaseList` forces `ix_releases_band_posted`, `ix_releases_band_added` and
   `ix_releases_band_count` by name (`app/Services/Releases/TvReleaseList.php:32,55`): those names stay. No code names
   `ix_releases_movieinfo_cat` (added by
   `database/migrations/2025_12_22_000000_add_additional_performance_indexes_to_releases_table.php:33`).
10. **The reset command truncates by a fixed list** with foreign-key checks off (`app/Console/Commands/NntmuxResetDb.php:56,
    68-99`): truncation restarts ids, so link tables left off the list re-attach to new rows. The TV redesign's
    `release_tv_episodes`, `video_genres` and `video_people` are not on it today.
11. **Follow state** is `user_movies (users_id, imdbid)` (`app/Models/UserMovie.php:47-107`), read by IMDb id.

---

## 2. New storage

Nothing is copied and no aggregate is stored, with one stated exception: `movieinfo.genre`, `director` and `actors` stay
beside the new rows because the frozen API reads them (fact 5). The screens read only the rows.

### 2.1 Indexes on `releases`, for every category (his decision, 2026-09-27: "All six")

Filters that leave out most of a band walk the band's date-order index past everything they reject. In the Movies band,
Movies > Other is 519,173 of 575,109 releases, so "everything but Other", 1080p or WEB read about half a million entries for
a deep page (60–75 ms). An index that starts with the filter's value reads only that value's releases:

```
ix_releases_band_cat_posted (category_band, categories_id, postdate, id, resolution, source, passwordstatus, completion)
ix_releases_band_cat_added  (category_band, categories_id, adddate,  id, resolution, source, passwordstatus, completion)
ix_releases_band_res_posted (category_band, resolution,    postdate, id, source, categories_id, passwordstatus, completion)
ix_releases_band_res_added  (category_band, resolution,    adddate,  id, source, categories_id, passwordstatus, completion)
ix_releases_band_src_posted (category_band, source,        postdate, id, resolution, categories_id, passwordstatus, completion)
ix_releases_band_src_added  (category_band, source,        adddate,  id, resolution, categories_id, passwordstatus, completion)
```

About 86 MB each at full size (measured on a fresh build): about 515 MB together. `completion` is held so the Completion
filter never reads a row (without it, 100% at the middle page costs 160 ms; with it 40 ms). `releases` is 816 MB of data and
2.8 GB of indexes on the production copy. Existing indexes extended (same leading columns, same names, so everything that
uses them today still can):

- `ix_releases_band_posted`, `ix_releases_band_added` gain `videos_id, completion` (95 MB each fresh-built): the TV list's
  show filters test the show from the index (4.5) and Completion reads no row.
- `ix_releases_band_count` gains `completion`: the Completion count becomes index-only.
- `ix_releases_movieinfo_cat` gains `adddate, resolution, source, completion` (production's is 90.7 MB today; about 100 MB
  after): the film-driven reads become index-only in both date orders.

`movieinfo` gains `ix_movieinfo_year (year)` for the wall's "Newest films first" (`ix_movieinfo_title` exists). `people` gains
`ix_people_name (name)` for the name claim of 3.1. `releases` is altered with the documented procedure
(`docs/releases-table-optimization.md`); downtime is acceptable. Index maintenance on insert and update is not measured here.

### 2.2 Three columns on `movieinfo`

| Column | Type | Meaning |
|---|---|---|
| `vote_count` | `unsignedInteger`, nullable | TMDB `vote_count`, 0 included; NULL = not fetched since this column was added |
| `content_rating_us` | `string(8)`, default `''` | TMDB US certification from `release_dates` (shown as **MPAA Rating**); `''` = none |
| `original_language` | `string(8)`, default `''` | TMDB `original_language` (ISO 639-1, as `tv_info.original_language`); `''` = unknown |

Stored **going forward** (his decision, 2026-09-26): no backfill; they arrive as films are fetched (3.1). Score bands
(`SPEC.md` 5.2) on the stored `rating` (numeric text or empty on every row of the production copy): a film is in **Too few
votes** when `rating` is empty or 0, or `vote_count < 10`; otherwise in its band by `rating + 0` (**Under 5** = above 0 and
below 5). A NULL `vote_count` bands by the score alone. `year` is four digits or empty on every row (142 empty); decades and
ranges compare it as text.

### 2.3 `movie_genres` (film ↔ genre)

| Column | Type |
|---|---|
| `movieinfo_id` | `unsignedInteger`, FK `movieinfo.id` `ON DELETE CASCADE` |
| `genres_id` | `unsignedInteger`, FK `genres.id` `ON DELETE CASCADE` |
| `position` | `unsignedTinyInteger`: the genre's order for the film, 0 = first (his decision, 2026-09-27) |

Primary key `(genres_id, movieinfo_id)`; index `ix_movie_genres_movie (movieinfo_id)`. Genres are `genres` rows with
`type = 2000` (the Movies root), as TV's are `type = 5000`. 38,291 links at full size. `position` keeps TMDB's order of the
film's genres (from text, the text's order, the fill included): the Films wall tiles show the first two, and the film page's
genre tags follow it.

### 2.4 `movie_people` (film ↔ person) — the #556 key

| Column | Type |
|---|---|
| `movieinfo_id` | `unsignedInteger`, FK `movieinfo.id` `ON DELETE CASCADE` |
| `people_id` | `unsignedInteger`, FK `people.id` `ON DELETE CASCADE` |
| `role` | `unsignedTinyInteger`: 0 director, 1 cast |
| `position` | `unsignedTinyInteger`: order within the role |

Primary key `(people_id, movieinfo_id, role)`; index `ix_movie_people_movie (movieinfo_id, role, position)`. People are the
**shared `people` table** TV uses: a person in films and shows is one row. A film has no `videos` row, so it gets its own link
keyed by `movieinfo_id` (fact 1) rather than `video_people`. **Every director, and the first 12 distinct cast** (as TV's
`CAST_LIMIT`, `app/Services/TvProcessing/TvShowDetails.php:26`; the film page shows 12), one row per person per role:
187,711 links at full size. "Directed by" lists role 0, "Starring" role 1 by position.

### 2.5 `languages` + `release_audio_languages` (every category)

| Table | Columns | Keys |
|---|---|---|
| `languages` | `id` smallint, `name` string(64) | unique `ux_languages_name (name)` |
| `release_audio_languages` | `releases_id` (FK `releases.id` `ON DELETE CASCADE`), `languages_id` (FK) | PK `(languages_id, releases_id)`, `ix_release_audio_languages_release (releases_id)` |

The name rule is `SPEC.md` 5.2: the base code or name before any region names the language (`en`, `en-US`, `English (US)` →
English; Mandarin → Chinese; TMDB's `cn` → Cantonese), codes that are not a language (`zxx`, `mul`, `und`, `qaa`–`qtz`) are
dropped, a value not in the name table is kept as written. One PHP class holds the table and the rule; the Audio menus list
`languages` names, and the Language menus list `original_language` codes shown through the same table. At full size (the
lab took every probe's audio languages plus `audio_data`; the contract's rule, 3.2, reads the chosen probe else `audio_data`,
which can differ only on the 24,136 releases with probe languages): 369,752 rows, 141 names, 49,480 movie releases and
153,567 TV releases with at least one language.

### 2.6 What is not stored

No release counts, newest dates, best resolution, visibility or Similar picks: each is read (section 4). No "Too few votes"
flag: it is derived from `vote_count` and `rating`.

---

## 3. Write paths

### 3.1 A film's details

- **Fetch.** `fetchTMDBProperties()` asks for `['credits', 'release_dates']` in its one details request and returns, besides
  today's values, `vote_count`, `original_language`, the US certification (the first non-empty `certification` of the `US`
  entry of `release_dates.results`), the genre names, every director and every distinct cast member (the cast is cut to 12
  when the names resolve to people, below). Its cache key gains a version (`tmdb_movie_v3_<md5>`; v2 held the cast cut to
  12 before the names were checked) so arrays cached in an old shape are not served, and `FetchMovieByImdb:45` clears the
  new key.
- **Save.** `update()` writes `vote_count` even when it is 0 (outside the non-empty rule at `:311`), and the two text columns
  as before.
- **Links.** One method (`MovieCredits::sync(int $movieinfoId, array $genres, array $directors, array $cast, array
  $directorsFallback = [], array $castFallback = [])`) replaces the film's `movie_genres` and `movie_people` in one
  transaction when they differ; a people list that resolves to nobody is replaced by its fallback. It is called:
  - by `updateMovieInfo()` after `update()`, with TMDB's lists and the saved director and cast text as the fallbacks; when
    TMDB returns nothing (not configured, no match, or an error) with the saved text split by the rule of 5.2;
  - by the **admin edit form** (fact 2) with its edited text split by the same rule, so the screens follow an admin's edit.
- **Genre names**: found by `(type 2000, title)` or inserted, under a named lock (Laravel's `Cache::lock`), because `genres`
  has no unique key and movie workers run in parallel. The Genre menu lists only genres that have a film.
- **People**: one resolver (`PeopleRows`) serves film credits and TV cast writes, so a person in films and shows is one
  row. A TMDB person, from a film or a show, is found by `tmdb_id`; else by exact name among people with no `tmdb_id`,
  claimed with a conditional `UPDATE … SET tmdb_id = ? WHERE id = ? AND tmdb_id IS NULL` (a claim that loses the race,
  or a `tmdb_id` another row already holds, falls back to that row); else inserted (insert-or-ignore on
  `ux_people_tmdb_id`, then read). People from text have no `tmdb_id` and are found by exact name (the collation makes
  it case- and accent-insensitive), else inserted. A Movies or TV write that finds no row, from text or TMDB, inserts
  under one `Cache::lock` after repeating the lookups inside it (TV resolves its cast before its transaction opens, so
  the row is committed when the lock is released), so concurrent writes of the same new name (any spelling the collation
  treats as equal) leave one row. No row is inserted with an empty name, from Movies or TV: a TMDB person whose trimmed
  name is empty is linked when a row already holds its `tmdb_id`, else left out, and the next person takes the place
  among the 12 cast. A film's TMDB director or cast list whose every member is left out comes from the saved text, as an
  empty list does. A person left with no film and no show stays in `people` (as TV's).
- **When it runs.** When a film is first saved, and — **his decision, 2026-09-27** — whenever a new release of the film
  arrives and the film's record is more than 30 days old, **on both matching paths**: `fetchAndLinkMovieRecord()`
  (`app/Services/MovieService.php:1084-1093`) gains the same 30-day check as `:1051-1072`. At most one TMDB fetch per film per
  30 days, only when a release arrives: on the production copy 3,039 films received a release in the last 30 days, 34 of them
  with a record older than 30 days. **No new command, no scheduled job, no bulk fetch.**

### 3.2 A release's audio languages: `ReleaseDerivedFacts::refresh()` (fact 6)

A third step beside `refreshQuality()` and `refreshTvEpisodes()`: the languages of the audio tracks of the probe
`MediaInfoSnapshotService` selects, else of the legacy `audio_data` rows, through the name rule; the release's rows are
replaced only when they differ. Every category, as `resolution`.

### 3.3 The reset command

`NntmuxResetDb`'s truncate list (fact 10) gains `movie_genres`, `movie_people`, `release_audio_languages` (and the TV
redesign's `release_tv_episodes`, `video_genres`, `video_people`, which it misses today). An edit to an existing command; no
new command.

### 3.4 Nothing else writes

The list, wall, film page, details page, search and both Similar sections only read.

---

## 4. Read paths (each measured at full catalogue size)

Visibility `V` = `passwordstatus <= {0|1} AND categories_id NOT IN (:userExclusions)` (fact 7). The page is 50 releases (lists,
film page, details table) or 42 films (wall); counts are cached under the existing browse cache version keyed by filters +
exclusions + password setting, as TV's. Deep pages past the middle of a list are read from the other end (mirrored order,
offset `N − n − 50`) and reversed, as TV does.

### 4.1 Movie releases list

**Index choice.** Per value of Category, Resolution and Source, the band's release counts **for all users** (one grouped count
on `ix_releases_band_count`, 79 ms, cached for an hour: it only steers the choice). The list uses the `_cat_`, `_res_` or
`_src_` index of the set filter whose chosen values hold the fewest releases, `_posted` or `_added` by the sort; when no such
filter is set, or the chosen values hold more than half of the band, it uses `ix_releases_band_posted` / `_added`. The other
release filters are conditions on columns the chosen index holds. The same rule serves the TV list (`TvReleaseList`, fact 9).

| Query | Measured |
|---|---|
| no filter: page 1 / page 200 / exact middle (worst) | 0.1 / 1.5 / 37.6 ms (289,826 entries) |
| no filter, count | 46.8 ms (all 575,110 entries; cached) |
| Category "everything but Other": page 1 / page 200 / last page / count | 7.3 / 16.1 / 11.1 / 6.2 ms (≤ 65,915 entries) |
| Category HD only, page 200 | 1.7 ms |
| Category HD + Other (more than half the band → band index), middle page | 37.9 ms |
| Resolution 1080p page 200 / 4K or 1080p page 200 / count | 1.5 / 14.2 / 4.3 ms |
| Category Other + Resolution 1080p (fewest: 1080p → `_res_`), page 1 | 5.0 ms (no match) |
| Resolution SD + Source DVD, page 1 | 0.1 ms |
| Source WEB, page 200 | 1.9 ms |
| Added order: everything but Other / 1080p, page 200 | 16.2 / 1.5 ms |
| Completion 100% / 95%+, page 200 | 6.2 / 7.4 ms |
| Completion 100%: exact middle page, completion held in the index | 39.5 ms (159.9 without) |
| everything but Other + 100%, middle page | 10.5 ms |
| Completion 100%, count | 52.8 ms (cached) |

**Film filters** (Genre, Year, Score, MPAA Rating, Language): driven **from the films**: the films that match (`movieinfo`
columns; Genre as `EXISTS` on `movie_genres`) joined in that order (`STRAIGHT_JOIN`) to their releases on the per-film index,
with the band as `categories_id BETWEEN 2000 AND 2999`, `V` and any release filters, sorted. Left to its own plan MariaDB
reads all 2.36 million releases (350–406 ms): the order is required.

| Query | Measured |
|---|---|
| worst case, a film filter matching every film: page 200 Posted / Added / count | 16.7 / 16.6 / 13.0 ms (132,758 entries) |
| Genre Drama or Comedy (the largest): page 200 / count | 15.6 / 12.8 ms |
| Year 1990s + 2000s, page 1 | 4.3 ms |
| Score 7–8.9, page 200 · Too few votes, page 1 | 5.5 · 4.5 ms |
| MPAA R or PG-13, page 1 · Language English, page 200 | 4.1 · 5.3 ms |
| all five film filters + 1080p, page 1 | 3.8 ms |

**Audio**: driven from `release_audio_languages` for the chosen languages, joined to `releases` by primary key, then the band,
`V` and the other filters. The cost is the language's rows **in every category**: Korean 10.6 ms, Hindi 41 ms, **English
88.5 ms on every page and 84.3 ms for its count** (138,755 English rows, 19,610 of them movies). **Unknown** (no language
known) is `NOT EXISTS` on the band index: **count 134.9 ms, exact middle page 99.6 ms**, page 1 under 1 ms. Driving English
from the list index instead costs 0.1 ms on page 1 but 222 ms on page 200, so it is not used. These two options are the
reads in this contract above 60 ms; nothing measured normalized does better, and both cost what they do because Movies >
Other (519,173 releases, none with a known language) is in the band.

**Menus.** Category: the Movies sub-categories not excluded (as today). Resolution, Source: fixed lists. Audio, "most releases
first": languages by release count in the section **for all users**, cached for an hour (220 ms uncached, Movies or TV).
Language: `original_language` by film count (2.4 ms). MPAA Rating: the ratings present, `SELECT DISTINCT content_rating_us`
(17,320 rows). Genre: `genres` of type 2000 that have a film.

**Row state.** Cart as today; Follow by `user_movies (users_id, imdbid)` for the page's films (fact 11), one indexed lookup.

### 4.2 Films wall

| Query | Measured |
|---|---|
| "Newest releases first" / "Newest to the site first": `MAX(postdate)` / `MIN(adddate)` per film from the per-film index (index-only group-by, 44,501 entries) | 7.6 ms |
| visibility: a per-film scalar probe (`(SELECT 1 … LIMIT 1) = 1`, fact 7) over all 16,832 films (the count, and the probes before a deep page) | 27.9 ms (count cached) |
| page 1: films taken in sort order in batches of 100, the visible ones kept, until 42 (the next batch when fewer pass) | 7.0 ms |
| the same written as one query (probe every film, then sort): any page | 40–47 ms |
| last page, newest releases / newest to the site | 47.3 / 47.1 ms |
| A to Z (`ix_movieinfo_title`), page 1 / last page | 0.1 / 27.7 ms |
| Newest films first (`ix_movieinfo_year`), page 1 | 0.2 ms |
| Drama + 2010s + score 7–8.9, page 1 | 29.1 ms |
| films with one person (`movie_people`), page 1 | 15.1 ms |
| the 42 tiles' release counts | 11.0 ms |

### 4.3 "Search films or actors" (Movie releases list and Films wall)

| Query | Measured |
|---|---|
| films: `movieinfo.title LIKE %text%`, visible, ordered by match position then title, 6 | 11.6 ms ("the") |
| people: `people.name LIKE %text%` joined to `movie_people`; the 50 with most films, then the visibility probe per film and the count, top 5 | 14.0 ms ("smi"), 41.3 ms ("an") |
| a person's first three films | primary keys |

The shared `people` table grows from about 23 thousand (TV) to about 111 thousand rows; the `LIKE %text%` scan of all of it
costs 12.4 ms ("an"). TV's search (`app/Services/Releases/TvShowSearch.php:64-86`) scans the same table and grows by the same
amount.

### 4.4 Film page and details

| Query | Measured |
|---|---|
| a page of the film's releases, any sort, largest film (152) | 0.1 ms |
| header: count, latest, best resolution | 0.1 ms |
| details "All N releases": the film's table; the page holding this release = its rank in the table's order | 0.1 ms |
| PreDB block: `predb` by `releases.predb_id`; as today | primary key |

### 4.5 Similar

| Query | Measured |
|---|---|
| **Similar films** (`SPEC.md` 6.6: candidates share a genre or a person; 2 × shared genres + 3 × shared people − \|year gap\| / 10, no year term when either year is empty; top 6) with **the viewer's visibility** as a per-candidate probe: Heat, Inception, The Conjuring, Sully, Mass Jathara, Fist of the North Star | 33.7, 17.2, 14.5, 19.9, 25.5, 15.3 ms |
| **TV Similar shows**, the same rule on `video_genres` / `video_people` with the premiere year, visibility per candidate (most genres, Breaking Bad, Doctor Who, The West Wing) | 36.8, 29.1, 32.4, 20.7 ms |
| **Similar releases** without the same film (`SPEC.md` 5C.5): today's search (fact 8) with the film left out in every path: a `movieinfo_id` filter in the Manticore and Elasticsearch queries, and `(r.movieinfo_id IS NULL OR r.movieinfo_id <> ?)` in the MySQL fallback; a release without a film filters nothing | 2 ms on the production Manticore index (read-only, 2026-09-27) |

### 4.6 TV releases list (the approved changes)

Show filters (Genre, Premiered, Language, Network, Rating, Status) read one of two ways, chosen by the count the page needs
anyway (cached): **under 50,000 matching releases**, from the matching shows to their releases on `ix_releases_videos_posted`,
sorted (the same cost on every page); **otherwise** from the band index with `videos_id IN (matching shows)`, the show id held
by the extended index (2.1). Near the threshold both cost about the same (Rating TV-14: page 200 from the shows 43.2 ms,
exact middle from the index 33.4 ms, count 33.3 ms), so the worst case is about 43 ms.

| Query | Measured |
|---|---|
| all six show filters: count / page 1 (from the shows) | 15.4 / 17.2 ms |
| Language Korean (22 thousand releases): any page from the shows · count | 11.2 · 9.9 ms |
| Language English (103 thousand): exact middle, band index with `videos_id` | 34.9 ms (73.6 without it) |
| Language English, count | 61.8 ms (cached) |
| Audio English (TV), page 200 from the band index + `EXISTS` / count | 7.4 / 74.6 ms (cached) |
| six show filters + Audio English + 95%+, page 1 | 25.8 ms |
| Completion 95%+, count | 15.8 ms |

---

## 5. Filling what already exists

Downtime is not a concern, so the migrations fill what they add. **No command.**

1. **The indexes** (2.1): built by the migration.
2. **`movie_genres`, `movie_people`, `genres` (type 2000), `people`** (his decision, 2026-09-27: "Yes, move today's text
   now"): the migration splits today's `movieinfo.genre`, `director` and `actors` of every film (17,320) by the rule below and
   writes the rows through the same method as 3.1. Each film's next refresh replaces them from TMDB.
   **Split rule**: control characters become spaces; split on commas; trim; drop empty parts; a part that is only a name
   suffix (`Jr.`, `Sr.`, `II`, `III`, `IV`, with or without the dot) joins the name before it; every director; the first 12
   distinct cast. **Genres** (his decision, 2026-09-28: film genres are TMDB's standard movie genres only): a name is a
   genre only when it is one of TMDB's 19 movie genres (Action, Adventure, Animation, Comedy, Crime, Documentary, Drama,
   Family, Fantasy, History, Horror, Music, Mystery, Romance, Science Fiction, TV Movie, Thriller, War, Western), matched
   ignoring case and stored in that spelling; any other name is dropped, so the six genre strings cut at 64 bytes give no
   stub such as "Science F", and a cut after a space gives no "Science" or "TV". **Directors**: a director text that has
   reached 64 characters, where the column cuts it, loses its last part before the names become people (an empty last
   part, from a cut just after a comma, costs no name); a shorter text keeps every part. Cast names are not filtered.
   The rule is the same for the fill, the admin edit and the TMDB fallback (3.1); the saved text itself is not changed.
3. **`release_audio_languages`, `languages`**: the migration runs the 3.2 rule over every release with media info, in
   primary-key chunks, re-runnable. Its run time on the full-width table is not measured here (the lab filled 369,752 rows
   from a script).
4. **`vote_count`, `content_rating_us`, `original_language`**: nothing; going forward only (2.2, 3.1).
5. **API genre list**: `ApiCapabilitiesService::genres()` lists the genres it listed before the redesign, leaving out types
   2000 and 5000 (fact 5). This restores the frozen output; TV's type-5000 rows reach it on `master` today.

---

## 6. Tests the build must include

1. Each film filter, the Audio filter (a language, and Unknown) and each release filter return exactly the releases a direct
   predicate returns, on a fixture with Movies > Other releases, excluded categories and a passworded release; both sorts; a
   page past the middle (the mirrored read).
2. The list names the index section 4.1's rule chooses for each filter combination (a test on the chosen index name), and the
   TV list chooses the show side or the band index by 4.6's count rule.
3. `updateMovieInfo()` with a faked TMDB response writes the three columns (`vote_count` 0 included), the genre rows and the
   people rows (every director, 12 cast); a second fetch with different credits replaces them; a person found by `tmdb_id`,
   one claimed by name, one whose claim meets a taken `tmdb_id`; TMDB returning nothing splits the saved text; the admin edit
   form's text reaches the rows.
4. `fetchAndLinkMovieRecord()` refreshes a film whose record is over 30 days old and not one refreshed within 30 days.
5. `ReleaseDerivedFacts::refresh()` writes audio languages from the selected probe, falls back to `audio_data`, applies the
   name rule (region dropped, `zxx`/`und` dropped, unknown kept), and does nothing when unchanged.
6. The fill migrations are idempotent on a fixture and give the same rows as the write paths for the same data; the split rule
   keeps "Robert Downey, Jr." as one person.
7. The wall lists a film only when the viewer may see one of its releases; Similar films and Similar shows skip a candidate
   the viewer may not see; Similar releases never lists the film's own releases, in the Manticore, Elasticsearch and MySQL
   paths.
8. The API capabilities response lists the same genres as before the redesign (no type 2000 or 5000 rows).
9. `NntmuxResetDb`'s list names every table in 3.3.

---

## 7. Decisions recorded

- 2026-09-26: US certificate and TMDB vote count stored going forward, no backfill; Score = the stored `movieinfo.rating`.
- 2026-09-27: the six filter-led indexes on `releases` ("All six, ~400 MB"; held `completion` makes them about 515 MB),
  over posted-only indexes (Added order 29–43 ms) and over none (deep filtered pages 60–75 ms).
- 2026-09-27: films' people and genres are rows on the shared `people` / `genres` tables keyed by `movieinfo_id` (#556), and
  the migration moves today's text into them ("Yes, move today's text now").
- 2026-09-27: every new release of a film refreshes it when its record is over 30 days old, on both matching paths ("Yes, on
  every new release").
- 2026-09-27: Similar releases leave out the same film (`SPEC.md` 5C.5), in the search itself.
- Carried from the TV contract: counts per user, cached; visibility probes as scalar subqueries; no background refresh; the
  frozen API's output restored where the redesign's rows would change it.
