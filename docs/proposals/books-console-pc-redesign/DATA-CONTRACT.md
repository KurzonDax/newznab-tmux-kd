# NNTmux Books, Console and PC sections: data contract

Written 2026-10-01 after the three sections' screens were approved (`SPEC.md` 5, 5A, 5B). It says what is stored, where,
which existing code writes it, how existing rows fill in, and what every screen reads, each read measured at full
catalogue size. It follows the maintainer's schema rules: **normalize wherever possible; nothing about releases may be
specific to one category; downtime is not a concern.**

Every claim about the application was read from the code on `master` at `85a680951` and carries a `path:line` (line
numbers move: re-check before relying on one). Every query shape was run on the restored production catalogue in the
maintainer's query lab at full size (**2,359,525 releases**; backup of 2026-09-20), and Console's new reads also on a
**stress copy** in which the Movies band was moved into Console sub-categories (575,126 Console releases, 71,891 games,
459,729 releases linked to a game). The lab write-up is `evidence/shelf-data-contract.md`. Times are the best of three
warm runs; rows read are InnoDB handler reads. **Every game value in the lab is synthetic**: `consoleinfo` holds 0 rows
on production and in the lab (`DATA-NOTES.md`).

Repository conventions followed (as in `../movies-redesign/DATA-CONTRACT.md`): anonymous-class migrations with
`declare(strict_types=1)`, the Schema builder, `->comment()` on new columns, explicitly named indexes of at most 64
characters (`tests/Unit/MigrationIdentifierLengthTest.php`), a working `down()`, plural-table key columns, no triggers,
model casts through `casts()`, explicit relationship keys; `database/schema/mariadb-schema.sql` refreshed with
`schema:dump` in every migration PR; tests build tables with `Tests\Support\ProductionTables::fromAuthority()`.

---

## 1. Facts about the application that shape the design

1. **A release links to its game by `releases.consoleinfo_id`**, written when the console lookup matches it
   (`app/Services/ConsoleService.php:556`). Shared lists already resolve it for the title chip
   (`app/Services/Releases/ReleaseEntityDataLoader.php:24`) and RSS joins on it (`app/Http/Controllers/Api/RSS.php:73`,
   `:188`). Production has `ix_releases_consoleinfo_id`.
2. **A game is saved in one place.** `ConsoleService::processConsoleReleases()` (`app/Services/ConsoleService.php:500`)
   parses a release name, reuses a stored game of that title and platform when there is one (`:530`, `:544-550`), else
   calls `updateConsoleInfo()` (`:426`) → `fetchIGDBProperties()` (`:461`) → `IGDBService::searchConsole()` and
   `IGDBService::buildConsoleData()` (`app/Services/IGDBService.php:404-420`), then `updateConsoleTable()`
   (`app/Services/ConsoleService.php:674-740`), which inserts or updates `consoleinfo`, replaces its genre rows through
   `ConsoleGenres::replace()` (`app/Services/MetadataProcessing/ConsoleGenres.php`, #917) and saves the cover.
   The admin edit form writes title, url, publisher, esrb, release date, genre and cover
   (`app/Http/Controllers/Admin/AdminConsoleController.php:91-96`).
3. **A stored game is never refreshed today**: a release whose game is already stored only gets the link (`:544-550`).
   TV refreshes a show's details when a new release of it arrives, at most every 24 hours
   (`app/Services/TvProcessing/TvShowDetails.php:25`, `:89-102`, column `tv_info.details_refreshed_at`): the
   maintainer's rule for title metadata.
4. **IGDB already returns everything the design keeps.** The query asks for every top-level field (`fields *`,
   `app/Services/IGDB/QueryBuilder.php:19`) plus the relations of `IGDBService::getGameRelations()`
   (`app/Services/IGDBService.php:325-342`): `involved_companies` (company, developer, publisher), `game_modes.name`,
   `player_perspectives.name`, `websites` (url, type). `buildConsoleData()` keeps only title, IGDB id (`asin`), summary
   (`review`), cover URL, release date, age rating (`esrb`), IGDB URL (`url`), publishers (joined with `,`), platform and
   genres (`:404-420`). Checked live on 2026-10-01 (`DATA-NOTES.md`): critic score is `aggregated_rating`, user score
   `rating` (both 0-100 floats), `storyline` text, website type **1 = "Official Website"**, one per game checked.
5. **The frozen RSS reads `consoleinfo`** (`title`, `url`, `publisher`, `releasedate`, `review`, `cover` and the genre
   titles; `app/Http/Controllers/Api/RSS.php:63-65`, `:178-180`). Those columns keep their meaning; nothing new is added to
   an API or RSS response.
6. **The lists are `BandReleaseList`s.** Adult's list and its `releaseIndex()` choice
   (`app/Services/Releases/BandReleaseList.php:114`) serve every band; the Movies list's film filters read the films
   first and join their releases (`app/Services/Releases/MovieReleaseList.php:138`), on production's
   `ix_releases_movieinfo_cat`.
7. **Media info presence** is today's `ReleaseMediaInfoAvailabilityLoader::load()`
   (`app/Services/Releases/ReleaseMediaInfoAvailabilityLoader.php:15`), one batch for a page.
8. **What links to the pages this design retires**: the header's "All …" items go to `/browse/<root>` for every root
   without a new list (`resources/views/partials/header-menu.blade.php:7`, `:11`); the shared lists' title chip for
   console, PC and book releases goes to `/title/<root>/<id>` (`app/Data/ReleaseEntityData.php:25`); the cover browser
   builds the same links (`app/Services/Releases/ReleaseCoverBrowser.php:117`); routes `browse/{parentCategory}/{id?}`
   (`routes/web.php:201`) and `title/{root}/{id}` (`routes/web.php:214`).

---

## 2. New storage

Books and PC need **none**: their screens read only `releases` and the tables today's lists and details page read.

Console keeps the values the lookup already receives (`SPEC.md` 6.5).

### 2.1 Five columns on `consoleinfo`

| Column | Type | From IGDB | Note |
|---|---|---|---|
| `storyline` | `TEXT NULL` | `storyline` | absent for many games |
| `critic_score` | `TINYINT UNSIGNED NULL` | `round(aggregated_rating)` | 0-100 |
| `user_score` | `TINYINT UNSIGNED NULL` | `round(rating)` | 0-100 |
| `website` | `VARCHAR(1000) NULL` | the first `websites` entry with `type` 1 | official site only |
| `details_refreshed_at` | `TIMESTAMP NULL` | | when IGDB was last asked about this game (3.2) |

The summary stays `review` (already stored, `VARCHAR(3000)`, cut to 3,000 characters at `ConsoleService.php:693`); the
IGDB page stays `url`; the age rating stays `esrb` (#914). At stress size the five columns add 36.0 MB to `consoleinfo`
(105.2 against 69.1 MB), 30.2 MB of it storyline text at an assumed 1,193 characters on 35% of games.

### 2.2 `companies` + `console_companies` (game ↔ developer / publisher)

The `people` / `movie_people` pattern:

- `companies`: `id` (int unsigned, auto), `name` (varchar 255), `igdb_id` (int unsigned null, unique
  `ux_companies_igdb_id`), key `ix_companies_name`.
- `console_companies`: `consoleinfo_id`, `companies_id`, `role` (tinyint unsigned, comment "0 developer, 1 publisher"),
  `position` (tinyint unsigned, "0-based order within the role"); primary key (`companies_id`, `consoleinfo_id`,
  `role`); key `ix_console_companies_console` (`consoleinfo_id`, `role`, `position`); FKs to both, cascade on delete.
- **`consoleinfo.publisher` stays** and is written as today (the joined publisher names): RSS reads it (fact 5) and the
  admin form edits it. The screens read publishers from `console_companies`. Both are written by the same save (3.1),
  as `consoleinfo.genres_id` stays beside `console_genres` (#917).

### 2.3 `game_modes` + `console_game_modes`, `player_perspectives` + `console_player_perspectives`

- `game_modes` / `player_perspectives`: `id`, `name` (varchar 120, unique), `igdb_id` (int unsigned null, unique).
- `console_game_modes` / `console_player_perspectives`: `consoleinfo_id`, the lookup id, `position`; primary key
  (lookup id, `consoleinfo_id`); key on `consoleinfo_id`; FKs cascade.

At stress size: `console_companies` 9.0 MB, `companies` 0.6 MB, `console_game_modes` 7.0 MB,
`console_player_perspectives` 5.0 MB, each lookup table about 0.03 MB.

### 2.4 Index `ix_releases_consoleinfo_cat` (his decision, 2026-10-01: "Add it")

`releases (consoleinfo_id, categories_id, passwordstatus, postdate, adddate, completion)`, the twin of the Movies
`ix_releases_movieinfo_cat`; 84.7 MB on the full catalogue. It makes the game-led Genre, Year and Unknown reads
index-only (4.2). At today's 18 console releases nothing changes either way.

### 2.5 What is not stored

Screenshots, artworks, trailer videos, themes, other website types and `alternative_names`: no screen shows them.

---

## 3. Write paths

### 3.1 A game's details: `ConsoleService::updateConsoleTable()`

`IGDBService::buildConsoleData()` also returns `storyline`, `critic_score`, `user_score`, `website`, the developers and
publishers (in IGDB's order, each once, with IGDB company ids), the game modes and the player perspectives (names with
IGDB ids). `updateConsoleTable()` writes them with the columns it writes today, **in one transaction**: the five columns
(2.1, `details_refreshed_at` = now), then `ChildRows::replace()` (`app/Support/ChildRows.php:26`) for
`console_genres`, `console_companies`, `console_game_modes` and `console_player_perspectives`, with lookup rows found or
created before the transaction opens (the `ConsoleGenres::ids()` rule, so no transaction holds a new lookup row another
worker cannot see). A missing value writes `NULL` or no child rows. `publisher` is written as today. The insert path
puts the new row in the console secondary index, as `ConsoleInfoObserver` does for a model save.

### 3.2 Refresh when a new release arrives (the TV rule)

When `processConsoleReleases()` finds a stored game for a release (`ConsoleService.php:544-550`) and that game's
`details_refreshed_at` is `NULL` or older than 24 hours, it asks IGDB for the game by its stored IGDB id (`asin`) and
saves it through 3.1; when IGDB has nothing, it only stamps `details_refreshed_at` (retried after 24 hours, as
`TvShowDetails.php:100-102`). The save rewrites every column and child row a lookup writes, admin edits included, which
last until the next refresh; `cover` keeps its stored value when IGDB has no cover or its download fails. This is how games stored before this change get the new values: on their next release. No
scheduled refresh, no backfill command (the maintainer's rules). Needs `lookupgames` on and IGDB configured, as today.

### 3.3 The admin edit form

Unchanged (`AdminConsoleController.php:91-96`): it does not touch the new columns or child tables, and what it writes
lasts until the game's next refresh (3.2).

### 3.4 Nothing else writes

The new tables have no other writer. The fill migration of 2.1-2.4 creates the tables, columns and index only; existing
rows fill by 3.2.

---

## 4. Read paths (each measured at full catalogue size)

### 4.1 Book and PC releases lists

`BandReleaseList` reads as Adult's (`SPEC.md` 5): band index in date order, Category through the `_cat_` index when
the chosen sub-categories hold at most half the band, Completion, the name search
(`COALESCE(NULLIF(TRIM(display_name), ''), searchname) LIKE '%word%'`). Lab (Books 211 releases, 208 visible; PC 1,362,
1,327 visible): **every read 1.6 ms or less**, the worst the PC name search for a word nothing contains (1.5 / 1.6 ms,
1,363 rows). Exclude Other is measured although neither band holds an Other release today (the item is not offered).

### 4.2 Console releases list

At real size (17 releases, 15 visible, 13 synthetic games) every read is 0.1-0.4 ms. At stress size (575,126 releases):

- **No filter, Category, Completion, Exclude Other**: today's shapes; counts 53-64 ms, the worst unmirrored page 40-55
  ms, Category menu counts 88 ms (cached as today).
- **Row extras** for the 50 ids of a page (game name, year, cover flag, genres in order): **one query, 0.4 ms**; never a
  query per row.
- **Name search "Search releases or games"**: `(name LIKE '%w%' OR r.consoleinfo_id IN (SELECT id FROM consoleinfo
  WHERE title LIKE '%w%'))` on the band index: count and the no-match page 1 430-467 ms, 10% over today's name search at
  that size (392-424 ms). The `LEFT JOIN consoleinfo … OR title LIKE` form took 951-963 ms: **not used**.
- **Genre, Year, Unknown**: read **game-led** on `ix_releases_consoleinfo_cat` (2.4): the matching games first
  (`console_genres` by genre; `consoleinfo` by year), joined to their releases, the band as `categories_id BETWEEN 1000
  AND 1999`, sorted; the Movies list's shape (`MovieReleaseList.php:138`). Genre, the largest (131,647 releases), 33 /
  39 / 27 ms (page 1 / worst page / count); a small genre 1.4 / 1.2 ms; two genres 54 / 65 / 49 ms; Unknown (two parts:
  releases in the band with no game (`consoleinfo_id` IS NULL **or -2**, the value the lookup writes when IGDB finds
  nothing, `ConsoleService::CONS_NTFND`), plus releases of games with no genre (`consoleinfo.genres_id` IS NULL or the
  `Unknown` genre), merged) 18 / 43 / 16 ms; a decade 68 / 79 / 59 ms; a typed range 21 / 19 ms; Genre + Year +
  Category 28 / 21 ms. Without the index the same reads take 160-335 ms (Genre) and up to 321 ms (Year).
- **Genre column**: the game's genre titles in `console_genres.position` order joined with ", "; a game whose only
  genre is `Unknown` reads "Unknown" (his design record, 2026-10-01: it matches the menu option).
- **Genre menu**: the Console genres that have a game, A to Z, then Unknown: 0.1 ms. "Genres with a band release" took
  895 ms: **not used**. **Year menu**: decades are fixed (2020s to 1990s), no read.

### 4.3 Console release details

- The release with its game: 0.1 ms; the game's genres, developers, publishers, modes and perspectives in order in one
  `UNION ALL`: 0.1 ms (or everything in one query with ordered `GROUP_CONCAT`s: 0.2 ms).
- **"All N releases of this game"**: `releases` where `consoleinfo_id` = the game, visible under the password setting,
  ordered by the chosen sort, 50 a page: 0.1 ms page 1 and count on the stress game with the most releases
  (`ix_releases_consoleinfo_cat`).
- **Similar releases**: today's search-index query (`ReleaseSearchService::searchSimilar()`, #859), with the releases of
  the same game removed from its result.

### 4.4 Media info tab

Today's `ReleaseMediaInfoAvailabilityLoader::load()`: 0.3-0.9 ms for a page, 0.2 ms or less for one release. The tab
shows only when it reports media info (`SPEC.md` 5A).

### 4.5 Remembered filters

Beside the sort in `users.view_prefs` under each root's key (the #881 rule); on Console the Genre and Year values too.

### 4.6 The title chip on shared lists

`ReleaseEntityData::titleUrl()` (`app/Data/ReleaseEntityData.php:25`): a console release's chip links to its own details
page; book and PC releases get no chip; Audio is unchanged. No new read.

---

## 5. Filling what already exists

Nothing to backfill on production: `consoleinfo` is empty there. On an install with stored games, each game fills its new
columns and rows on its next release (3.2). The migration only creates the tables, columns and index.

---

## 6. Tests the build must include

- The save (3.1) writes every new column and child row from a faked IGDB game, in IGDB order, each once; a game with no
  storyline, scores, website or companies writes `NULL`s and no rows; `publisher` is still the joined publisher names.
- A second release of a stored game refreshes it from IGDB when `details_refreshed_at` is null or over 24 hours old, and
  does not when it is newer; a game IGDB no longer returns only gets its stamp.
- No test calls IGDB: HTTP is faked.
- The lists: Category, Exclude Other, Completion, the name search (and on Console the game-name search), Genre including
  Unknown, Year decades and range, each alone and combined; the row extras in one query for a page; the Media info tab
  only with media info; "All N releases of this game" with this release marked; Similar releases without the same game.
- The retired addresses (`/browse/books|console|games|pc`, `/title/books|console|games/<id>`, the legacy `/Books`,
  `/Console`, `/Games` redirects) return 404, and nothing in the app links to them.
- RSS output for a console release is byte-identical before and after.

---

## 7. Decisions recorded

- New storage for developer, critic and user score, game modes, player perspective, storyline and official website
  (his list, 2026-10-01), normalized (2.1-2.3); `consoleinfo.publisher` kept for RSS and the admin form.
- `ix_releases_consoleinfo_cat` added (his "Add it", 2026-10-01).
- Existing games fill on their next release, at most once a day, as TV shows do; no backfill and no new command.
- Addresses `/books`, `/console`, `/pc` (his pick); the old browse and title pages retired and every link to them
  repointed (his rule, 2026-10-01).
