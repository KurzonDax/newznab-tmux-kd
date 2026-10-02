# Books / Console / PC data contract: measured reads (2026-10-01)

Scratch schema `shelfc` on the query lab, built by `01-build.sql` exactly as the Adult contract's `adc`: a copy of every
release of the restored production catalogue (2,359,525; backup of 2026-09-20) with the #773 resolution / source, the
generated `category_band`, the release names, completion, password status, the columns the details page reads,
`releases.consoleinfo_id` in production's type (`int(11) NULL`), and exactly the list indexes production has on master
(`ix_releases_band_{posted,added,count}`, the #827 `_cat_` / `_res_` / `_src_` indexes) plus production's
`ix_releases_consoleinfo_id`. The pristine `nntmux` schema is only read; the media-info reads run on its untouched
tables (read-only). Nothing ran against production.

Band counts in the lab (all / visible under the default password setting, `passwordstatus <= 0`):

| Band | Releases | Visible | Sub-categories holding releases |
|---|---:|---:|---|
| Books (7000) | 211 | 208 | Magazines 52, Ebook 152, Comics 1, Technical 6 |
| Console (1000) | 17 | 15 | PSP 1, Wii 2, Xbox 360 2, PS3 3, PS Vita 6, Xbox One 1, Other 2 |
| PC (4000) | 1,362 | 1,327 | 0day 1,135, ISO 154, Mac 2, Games 43, Phone-IOS 1, Phone-Android 27 |

(Production on 2026-10-01, from `SHELF-DATA-NOTES.md`: Books 108, Console 18, PC 1,408. Neither Books › Other (7999)
nor PC › "Phone-Other" (4999) holds a release, so the "Exclude Other" item is not offered on those two lists today; it
was measured anyway as the list it would read, every sub-category but Other.)

**The proposed Console storage** is `02-console-storage.sql` (the DDL a future migration would produce): `consoleinfo`
as production has it plus `storyline TEXT NULL`, `critic_score TINYINT UNSIGNED NULL`, `user_score TINYINT UNSIGNED
NULL`, `website VARCHAR(1000) NULL`, `details_refreshed_at TIMESTAMP NULL`; `console_genres` byte for byte as the #917
migration, over a copy of `genres`; `companies (id, name, igdb_id unique, key name)` + `console_companies
(consoleinfo_id, companies_id, role 0 developer / 1 publisher, position; PK (companies_id, consoleinfo_id, role); key
(consoleinfo_id, role, position); FKs cascade)` (the `people` / `movie_people` pattern); `game_modes` +
`console_game_modes` and `player_perspectives` + `console_player_perspectives` (PK (lookup id, consoleinfo_id); key
consoleinfo_id; FKs cascade). The release's game is `releases.consoleinfo_id`.

**Every game value is synthetic.** `consoleinfo` is empty on production and in the lab, so `03-fill.py` invents the
games (seeded, reproducible): 1-3 genres from IGDB's genre list, weighted (4% of games have only the `Unknown` genre
instead, the row `ConsoleGenres::UNKNOWN` links to); 1-3 game modes; 1-2 player perspectives; 1-2 developers and 1
publisher (Zipf-ish over a company list); a release date in 1995-2025 (2% none); a cover on 85%; summary (`review`) on
90%; storyline on 35% (300-2,000 characters, average 1,193: an assumption, it drives the size figure); critic score
45%, user score 70%, official website 60%. Only the releases are real.

- **(a) Real size, `shelfc`**: one game per Console-band release, except 4 releases left with no game (two SACD audio
  rips and two VHS concert videos, the misfiled rows the data notes describe): **13 games**, 21 genre rows, 40
  companies, 30 company credits, 25 mode rows, 15 perspective rows, 8 games with a cover; one game is forced to
  `Unknown` so that path is exercised. Releases that name a real game got its name (Big Beach Sports, Dragon Ball Z:
  Raging Blast 2, Let's Sing, Harry Potter and the Deathly Hallows: Part 1, Ford Street Racing: L.A. Duel).
- **(b) Stress, `shelfs`** (`04-stress.sql`, same build): every release of the Movies band (575,109) recategorised into
  a Console sub-category by a hash of its id (Other 1999 20%, PS4 1180 8%, each of the other twelve seeded Console
  sub-categories 6%), so the Console band holds **575,126 releases (562,757 visible)**; **71,891 games** (one per 8
  releases); **459,729 releases (80%) linked** to a game by a hash of the release id (71,741 games have a release,
  about 6.4 each); 140,558 genre rows (2,814 games `Unknown` only), 60,980 games with a cover (84.8%), 6,000 companies,
  161,637 company credits, 129,268 mode rows, 89,727 perspective rows. Release names, dates, completion and password
  status stay the Movies releases' own.

**Candidate indexes NOT in the proposal** (`05-candidate-indexes.sql`, present in both scratch schemas; every query
names its index with `FORCE` / `IGNORE INDEX`, so a "proposal" query never reads them):
`ix_releases_consoleinfo_cat (consoleinfo_id, categories_id, passwordstatus, postdate, adddate, completion)`, the twin
of production's Movies per-film index `ix_releases_movieinfo_cat`; and `ix_consoleinfo_releasedate_cand (releasedate)`.

Method, as the Adult contract: each query ran three times warm in one session (best time kept, from `SHOW PROFILES`),
then once after `FLUSH STATUS` to read the `Handler_read*` counters ("rows read"). `bench.py` (the Adult one with the
schema as an argument) also runs `EXPLAIN` on every query and records each table's index ("index used") and any
filesort, temporary table or full scan of `releases` ("sort / scan"). `gen-queries.py` writes the `q-*.tsv` files with
literal values (page offsets from the real counts, the 50 ids of a page, the details release), as the application
would send them. Queries are written as `BandReleaseList` builds them (`app/Services/Releases/BandReleaseList.php`):
the index forced as `releaseIndex()` chooses it (a `_cat_` index when the chosen sub-categories hold at most half the
band), the default password condition, no excluded categories, 50 a page, pages past the middle read mirrored from the
other end, so the worst page is the last one before the middle. The name search is today's condition
(`ReleaseBrowserQuery.php:115-126`, `displayName()` `:133-136`): `COALESCE(NULLIF(TRIM(display_name), ''), searchname)
LIKE '%word%' ESCAPE '!'`. Lab milliseconds are relative (Apple Silicon, 4 GiB pool); rows read and plans transfer.

The Console game filters were measured three ways:

- **A, release-led**: the band index in date order, the game condition as an `EXISTS` on `console_genres` /
  `consoleinfo` through `releases.consoleinfo_id` (a row lookup per release: the band indexes do not hold it).
- **B, game-led on the proposal's indexes**: the matching games first (`console_genres` by genre, `consoleinfo` by
  date), `STRAIGHT_JOIN` to their releases on production's `ix_releases_consoleinfo_id`, the band as `categories_id
  BETWEEN 1000 AND 1999` (the Movies list's form, `MovieReleaseList::filmReleases()`), sorted; the same cost on every
  page, so never mirrored.
- **C, game-led on the candidate `ix_releases_consoleinfo_cat`**: B made index-only.

Unknown (a release with no game, or whose game's only genre is `Unknown`) cannot be read game-led from
`ix_releases_consoleinfo_id` (the `NULL`s of every other band are in it), so it is A, or on the candidate index two
parts: `consoleinfo_id IS NULL AND categories_id BETWEEN 1000 AND 1999` (a range of the candidate index) plus the
`Unknown` games' releases, merged.

The game-name search is `(name LIKE '%w%' OR r.consoleinfo_id IN (SELECT id FROM consoleinfo WHERE title LIKE
'%w%'))` on the band index: the matching games are read once (a scan of `consoleinfo`) and the release side stays
today's name search. The `LEFT JOIN consoleinfo … OR c.title LIKE` form was measured as the alternative.

Search words: Books `magazine` (common, 106 of 208), `fiction` (middling, 19), `zzqqxx` (nothing); PC `x64` (537 of
1,327), `photoshop` (73), `zzqqxx`; Console real `PSV`, `PS3`, `zzqqxx` and the game word `Broken` (two game titles);
Console stress `1080p` (34,765 release names), `2016` (1,905), `zzqqxx` and the game word `dragon` (233 release names,
7,749 releases through their game's title).

The scratch schemas `shelfc` and `shelfs` are **kept** so the contract can be re-run (`gen-queries.py`, then
`bench.py <schema> q-….tsv results-….md`); drop them with `drop database shelfc; drop database shelfs;`.

## Summary: what is over 50 ms, scans or sorts more than a page

Books, PC and Console at real size: **nothing**. Every read is 1.6 ms or less (the worst: the PC name search that
matches nothing, 1.5 / 1.6 ms, reading the band's 1,363 rows). No full scan of `releases` anywhere, at either size.

Console at stress size (575,126 releases, 459,729 with a game), with the proposal's indexes:

| Read | Worst ms | Rows read | Note |
|---|---:|---:|---|
| list counts (no filter, Category, Completion, Exclude Other) on `ix_releases_band_count` | 53-64 | 575,127 | today's count shape (Movies' no-filter count is 46.8 ms); cached 10 min |
| list, worst unmirrored page (offset ~281,000) | 40-55 | ~283,000 | today's shape at this band size |
| Category menu counts (`valueCounts`) | 88 | 1,150,922 | cached 1 h |
| name search: count, and page 1 of a word nothing contains | 392-424 | 575,127 | today's `LIKE '%w%'`, reads every band row |
| game-name search (IN-list form): count, and page 1 of a word nothing contains | 430-467 | 647,182-1,106,720 | +10% on the name search; the LEFT JOIN form is 951-963 ms (rejected) |
| game-name search + Category PS4, count | 68 | 154,784 | |
| Genre, A release-led: count / worst page | 350-524 / 185-277 | ~1,025,000 | page 1 is 0.3-5.8 ms |
| Genre, B game-led on `ix_releases_consoleinfo_id`: every page / count | 172-335 / 162-312 | 329,269-697,431 | sorts every matching release (up to 195,983) |
| Year, A release-led: count / worst page | 720-736 / 385 | ~1,025,000 | page 1 0.2-1.1 ms |
| Year, B game-led on `ix_releases_consoleinfo_id`: every page / count | 57-321 / 56-290 | up to 675,023 | scans `consoleinfo` (71,891 rows), sorts every match |
| Genre + Year + Category, B game-led | 100-108 | ~146,000 | A on the cat index: 0.6 ms page 1, 68.8 ms count |
| Genre + game-name search, C game-led | 226 | 310,953 | the name test needs each matching release's row |
| Genre menu, alternative "genres with a band release" | 895 | 1,296,464 | rejected; "genres that have a game" is 0.1 ms |

With the candidate `ix_releases_consoleinfo_cat` (C): Genre Adventure (largest, 131,647 releases) 33 / 39 / 27 ms
(page 1 / worst page / count); Genre Adventure or Shooter 54 / 65 / 49 ms; Genre Pinball 1.4 / 1.2 ms; Unknown (two
parts) 18 / 43 / 16 ms; Year 2010s 68 / 79 / 59 ms; 1990s + 2000s 57 / 50 ms; range 1998-2001 21 / 19 ms (8.4 / 7.7 ms
with the candidate date index, which does not help a decade: 63 / 54 ms); Genre + Year + Category 28 / 21 ms. Still
over 50 ms: two genres together and the decade reads (the widest sets, sorting up to 225,000 releases).

Every game-led read (B and C) sorts all its matching releases (`filesort` of `cg` / `c` with a temporary table: up to
225,000 entries), the Movies list's accepted shape (same cost on every page); the Unknown two-part read sorts at most
offset + 50 per part. Full scans of `consoleinfo` (71,891 rows at stress) happen in the game-led Year reads without the
date index and in the game-name search's IN list; never of `releases`.

Details, row extras and media info are flat at both sizes: the console details page in **two queries** (the release
with its game, 0.1 ms; one `UNION ALL` of genres, developers, publishers, modes and perspectives in order, 0.1 ms) or
**one** (the row with five ordered `GROUP_CONCAT` subqueries, 0.2 ms); the list's row extras for a page's 50 ids in
**one** query, 0.4 ms (game, year, cover flag, genres in order; `ConsoleGenres::titlesSql()`'s shape); today's
`ReleaseMediaInfoAvailabilityLoader` reads (its six-table `UNION`, then the `video_data` and first-`audio_data`
summaries) 0.3-0.9 ms for a page and 0.0-0.2 ms for one release.

## Storage at stress size (`06-sizes.sql`, `results-sizes.txt`)

| Table / index | Rows | MB (data + index) |
|---|---:|---:|
| `consoleinfo` with the five new columns | 71,891 | 105.17 (100.63 + 4.55) |
| `consoleinfo` without them (same rows, same indexes) | 71,891 | 69.13 (64.58 + 4.55) |
| the new columns' share | | **36.0**, of which storyline text 30.2 MB raw (25,271 games, 1,193 characters average, synthetic) and website 1.4 MB (42,920) |
| `console_genres` | 140,558 | 8.03 |
| `companies` | 6,000 | 0.61 |
| `console_companies` | 161,637 | 9.03 |
| `game_modes` / `player_perspectives` | 6 / 7 | 0.03 each |
| `console_game_modes` | 129,268 | 7.03 |
| `console_player_perspectives` | 89,727 | 5.03 |
| candidate `ix_releases_consoleinfo_cat` (on the 2,359,525-row copy) | | 84.72 (production's `ix_releases_consoleinfo_id`, already there: 38.56) |
| candidate `ix_consoleinfo_releasedate_cand` | | 1.52 |

`information_schema.table_rows` for `consoleinfo` reads 63,714 (InnoDB's estimate); the exact count is 71,891. The
FULLTEXT index's auxiliary tables are not in these figures (they are production's, unchanged). The new child tables
total 29.8 MB; with the new columns, about 66 MB at this (hypothetical) size.

## Notes

- `releases.consoleinfo_id` is `int(11)` signed while `consoleinfo.id` is unsigned: every join in these plans still
  uses the index (`eq_ref` on `consoleinfo.PRIMARY`, `ref` on `ix_releases_consoleinfo_id` / the candidate).
- The release-led reads (A) keep page 1 cheap for any common filter (0.2-5.8 ms) because the band index is in date
  order; their counts and deep pages read every band release plus its game. The game-led reads cost the same on every
  page. At stress, A is better for page 1 and C for counts and deep pages; at real size both are 0.1 ms.
- The Year menu needs no data (fixed decades, 1990s to 2020s, and a typed range); the Genre menu reads `genres` of type
  1000 that have a `console_genres` row, 0.1 ms, and whether to offer Unknown is one 0.1 ms probe.
- The genre ids are the same in both schemas (Adventure 1000170, Shooter 1000171, Pinball 1000191, Unknown 1000193).
- The stress band is far harsher than any likely Console band: 80% of its releases carry a game, against 7.7% of the
  Movies band with a film (44,454 of 575,109 in the Movies contract's scratch copy).

## Every measured query

Each table is the bench output; the SQL of every row follows its table. "Worst page (offset 0 …)" on a list of 100 releases or fewer is page 1 itself.

### Books list (real size, `q-books.tsv`)

Schema `shelfc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Books: no filter, posted, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_posted |  |
| Books: no filter, posted, worst page (offset 100, the last before mirroring) | 0.1 | 153 | 50 | r:ix_releases_band_posted |  |
| Books: no filter, posted, last page (mirrored: oldest first, offset 0, 8 rows) | 0.0 | 8 | 8 | r:ix_releases_band_posted |  |
| Books: no filter, posted, count (208) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: no filter, added, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_added |  |
| Books: no filter, added, worst page | 0.1 | 150 | 50 | r:ix_releases_band_added |  |
| Books: Category Magazines (band_cat_posted), page 1 | 0.1 | 50 | 50 | r:ix_releases_band_cat_posted |  |
| Books: Category Magazines (band_cat_posted), worst page (offset 0, the last before mirroring) | 0.1 | 50 | 50 | r:ix_releases_band_cat_posted |  |
| Books: Category Magazines (band_cat_posted), last page (mirrored: oldest first, offset 0, 2 rows) | 0.0 | 2 | 2 | r:ix_releases_band_cat_posted |  |
| Books: Category Magazines (band_cat_posted), count (52) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: Category Ebook (band_posted), page 1 | 0.1 | 86 | 50 | r:ix_releases_band_posted |  |
| Books: Category Ebook (band_posted), worst page (offset 50, the last before mirroring) | 0.1 | 138 | 50 | r:ix_releases_band_posted |  |
| Books: Category Ebook (band_posted), last page (mirrored: oldest first, offset 0, 49 rows) | 0.1 | 73 | 49 | r:ix_releases_band_posted |  |
| Books: Category Ebook (band_posted), count (149) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: Exclude Other (band index: over half the band), page 1 | 0.1 | 50 | 50 | r:ix_releases_band_posted |  |
| Books: Exclude Other (band index: over half the band), worst page (offset 100, the last before mirroring) | 0.1 | 153 | 50 | r:ix_releases_band_posted |  |
| Books: Exclude Other (band index: over half the band), last page (mirrored: oldest first, offset 0, 8 rows) | 0.0 | 8 | 8 | r:ix_releases_band_posted |  |
| Books: Exclude Other (band index: over half the band), count (208) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: Completion 100%, page 1 | 0.1 | 147 | 50 | r:ix_releases_band_posted |  |
| Books: Completion 100%, worst page (offset 0, the last before mirroring) | 0.1 | 147 | 50 | r:ix_releases_band_posted |  |
| Books: Completion 100%, last page (mirrored: oldest first, offset 0, 31 rows) | 0.1 | 64 | 31 | r:ix_releases_band_posted |  |
| Books: Completion 100%, count (81) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: Completion 95%+, page 1 | 0.1 | 146 | 50 | r:ix_releases_band_posted |  |
| Books: Completion 95%+, worst page (offset 0, the last before mirroring) | 0.1 | 146 | 50 | r:ix_releases_band_posted |  |
| Books: Completion 95%+, last page (mirrored: oldest first, offset 0, 34 rows) | 0.1 | 65 | 34 | r:ix_releases_band_posted |  |
| Books: Completion 95%+, count (84) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: Category Magazines + 95%+, page 1 | 0.1 | 53 | 37 | r:ix_releases_band_cat_posted |  |
| Books: Category Magazines + 95%+, count (37) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: Exclude Other + 100%, page 1 | 0.1 | 147 | 50 | r:ix_releases_band_posted |  |
| Books: Exclude Other + 100%, worst page (offset 0, the last before mirroring) | 0.1 | 147 | 50 | r:ix_releases_band_posted |  |
| Books: Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 31 rows) | 0.1 | 64 | 31 | r:ix_releases_band_posted |  |
| Books: Exclude Other + 100%, count (81) | 0.1 | 212 | 1 | r:ix_releases_band_count |  |
| Books: name search, common (magazine), page 1 | 0.2 | 103 | 50 | r:ix_releases_band_posted |  |
| Books: name search, common (magazine), count | 0.3 | 212 | 1 | r:ix_releases_band_posted |  |
| Books: name search, middling (fiction), page 1 | 0.3 | 212 | 19 | r:ix_releases_band_posted |  |
| Books: name search, middling (fiction), count | 0.3 | 212 | 1 | r:ix_releases_band_posted |  |
| Books: name search, nothing (zzqqxx), page 1 | 0.3 | 212 | 0 | r:ix_releases_band_posted |  |
| Books: name search, nothing (zzqqxx), count | 0.3 | 212 | 1 | r:ix_releases_band_posted |  |
| Books: name search (fiction) + Category Magazines, page 1 | 0.1 | 53 | 0 | r:ix_releases_band_cat_posted |  |
| Books: name search (fiction) + Category Magazines, count | 0.1 | 53 | 1 | r:ix_releases_band_cat_posted |  |
| Books: Category menu counts (valueCounts, cached 1 h) | 0.1 | 448 | 12 | releases:ix_releases_band_count | filesort(releases), temporary |

```sql
-- Books: no filter, posted, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Books: no filter, posted, worst page (offset 100, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 100;
-- Books: no filter, posted, last page (mirrored: oldest first, offset 0, 8 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 8;
-- Books: no filter, posted, count (208)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0;
-- Books: no filter, added, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 7000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Books: no filter, added, worst page
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 7000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50 offset 100;
-- Books: Category Magazines (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010) order by r.postdate desc, r.id desc limit 50;
-- Books: Category Magazines (band_cat_posted), worst page (offset 0, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010) order by r.postdate desc, r.id desc limit 50 offset 0;
-- Books: Category Magazines (band_cat_posted), last page (mirrored: oldest first, offset 0, 2 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010) order by r.postdate asc, r.id asc limit 2;
-- Books: Category Magazines (band_cat_posted), count (52)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010);
-- Books: Category Ebook (band_posted), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7020) order by r.postdate desc, r.id desc limit 50;
-- Books: Category Ebook (band_posted), worst page (offset 50, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7020) order by r.postdate desc, r.id desc limit 50 offset 50;
-- Books: Category Ebook (band_posted), last page (mirrored: oldest first, offset 0, 49 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7020) order by r.postdate asc, r.id asc limit 49;
-- Books: Category Ebook (band_posted), count (149)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7020);
-- Books: Exclude Other (band index: over half the band), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060) order by r.postdate desc, r.id desc limit 50;
-- Books: Exclude Other (band index: over half the band), worst page (offset 100, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060) order by r.postdate desc, r.id desc limit 50 offset 100;
-- Books: Exclude Other (band index: over half the band), last page (mirrored: oldest first, offset 0, 8 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060) order by r.postdate asc, r.id asc limit 8;
-- Books: Exclude Other (band index: over half the band), count (208)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060);
-- Books: Completion 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Books: Completion 100%, worst page (offset 0, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 0;
-- Books: Completion 100%, last page (mirrored: oldest first, offset 0, 31 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate asc, r.id asc limit 31;
-- Books: Completion 100%, count (81)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 100;
-- Books: Completion 95%+, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Books: Completion 95%+, worst page (offset 0, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 0;
-- Books: Completion 95%+, last page (mirrored: oldest first, offset 0, 34 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate asc, r.id asc limit 34;
-- Books: Completion 95%+, count (84)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0 and r.completion >= 95;
-- Books: Category Magazines + 95%+, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Books: Category Magazines + 95%+, count (37)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010) and r.completion >= 95;
-- Books: Exclude Other + 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Books: Exclude Other + 100%, worst page (offset 0, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 0;
-- Books: Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 31 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060) and r.completion >= 100 order by r.postdate asc, r.id asc limit 31;
-- Books: Exclude Other + 100%, count (81)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010,7020,7030,7040,7060) and r.completion >= 100;
-- Books: name search, common (magazine), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%magazine%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Books: name search, common (magazine), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%magazine%' escape '!';
-- Books: name search, middling (fiction), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%fiction%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Books: name search, middling (fiction), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%fiction%' escape '!';
-- Books: name search, nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Books: name search, nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
-- Books: name search (fiction) + Category Magazines, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%fiction%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Books: name search (fiction) + Category Magazines, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 7000 and r.passwordstatus <= 0 and r.categories_id in (7010) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%fiction%' escape '!';
-- Books: Category menu counts (valueCounts, cached 1 h)
select categories_id, resolution, source, count(*) from releases force index (ix_releases_band_count) where category_band = 7000 group by categories_id, resolution, source;
```

### PC list (real size, `q-pc.tsv`)

Schema `shelfc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| PC: no filter, posted, page 1 | 0.1 | 51 | 50 | r:ix_releases_band_posted |  |
| PC: no filter, posted, worst page (offset 650, the last before mirroring) | 0.2 | 730 | 50 | r:ix_releases_band_posted |  |
| PC: no filter, posted, last page (mirrored: oldest first, offset 0, 27 rows) | 0.0 | 27 | 27 | r:ix_releases_band_posted |  |
| PC: no filter, posted, count (1327) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: no filter, added, page 1 | 0.1 | 51 | 50 | r:ix_releases_band_added |  |
| PC: no filter, added, worst page | 0.2 | 704 | 50 | r:ix_releases_band_added |  |
| PC: Category ISO (band_cat_posted), page 1 | 0.1 | 50 | 50 | r:ix_releases_band_cat_posted |  |
| PC: Category ISO (band_cat_posted), worst page (offset 50, the last before mirroring) | 0.1 | 100 | 50 | r:ix_releases_band_cat_posted |  |
| PC: Category ISO (band_cat_posted), last page (mirrored: oldest first, offset 0, 3 rows) | 0.0 | 3 | 3 | r:ix_releases_band_cat_posted |  |
| PC: Category ISO (band_cat_posted), count (153) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: Category 0day (band_posted), page 1 | 0.1 | 73 | 50 | r:ix_releases_band_posted |  |
| PC: Category 0day (band_posted), worst page (offset 550, the last before mirroring) | 0.2 | 807 | 50 | r:ix_releases_band_posted |  |
| PC: Category 0day (band_posted), last page (mirrored: oldest first, offset 0, 2 rows) | 0.0 | 2 | 2 | r:ix_releases_band_posted |  |
| PC: Category 0day (band_posted), count (1102) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: Exclude Other (band index: over half the band), page 1 | 0.1 | 51 | 50 | r:ix_releases_band_posted |  |
| PC: Exclude Other (band index: over half the band), worst page (offset 650, the last before mirroring) | 0.2 | 730 | 50 | r:ix_releases_band_posted |  |
| PC: Exclude Other (band index: over half the band), last page (mirrored: oldest first, offset 0, 27 rows) | 0.1 | 27 | 27 | r:ix_releases_band_posted |  |
| PC: Exclude Other (band index: over half the band), count (1327) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: Completion 100%, page 1 | 0.1 | 89 | 50 | r:ix_releases_band_posted |  |
| PC: Completion 100%, worst page (offset 500, the last before mirroring) | 0.2 | 778 | 50 | r:ix_releases_band_posted |  |
| PC: Completion 100%, last page (mirrored: oldest first, offset 0, 10 rows) | 0.0 | 12 | 10 | r:ix_releases_band_posted |  |
| PC: Completion 100%, count (1060) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: Completion 95%+, page 1 | 0.1 | 81 | 50 | r:ix_releases_band_posted |  |
| PC: Completion 95%+, worst page (offset 550, the last before mirroring) | 0.2 | 771 | 50 | r:ix_releases_band_posted |  |
| PC: Completion 95%+, last page (mirrored: oldest first, offset 0, 41 rows) | 0.1 | 45 | 41 | r:ix_releases_band_posted |  |
| PC: Completion 95%+, count (1141) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: Category ISO + 95%+, page 1 | 0.1 | 101 | 50 | r:ix_releases_band_cat_posted |  |
| PC: Category ISO + 95%+, worst page (offset 50, the last before mirroring) | 0.1 | 152 | 50 | r:ix_releases_band_cat_posted |  |
| PC: Category ISO + 95%+, last page (mirrored: oldest first, offset 0, 2 rows) | 0.1 | 2 | 2 | r:ix_releases_band_cat_posted |  |
| PC: Category ISO + 95%+, count (102) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: Exclude Other + 100%, page 1 | 0.1 | 89 | 50 | r:ix_releases_band_posted |  |
| PC: Exclude Other + 100%, worst page (offset 500, the last before mirroring) | 0.2 | 778 | 50 | r:ix_releases_band_posted |  |
| PC: Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 10 rows) | 0.1 | 12 | 10 | r:ix_releases_band_posted |  |
| PC: Exclude Other + 100%, count (1060) | 0.2 | 1363 | 1 | r:ix_releases_band_count |  |
| PC: name search, common (x64), page 1 | 0.4 | 229 | 50 | r:ix_releases_band_posted |  |
| PC: name search, common (x64), count | 1.5 | 1363 | 1 | r:ix_releases_band_posted |  |
| PC: name search, middling (photoshop), page 1 | 1.1 | 891 | 50 | r:ix_releases_band_posted |  |
| PC: name search, middling (photoshop), count | 1.6 | 1363 | 1 | r:ix_releases_band_posted |  |
| PC: name search, nothing (zzqqxx), page 1 | 1.5 | 1363 | 0 | r:ix_releases_band_posted |  |
| PC: name search, nothing (zzqqxx), count | 1.6 | 1363 | 1 | r:ix_releases_band_posted |  |
| PC: name search (photoshop) + Category ISO, page 1 | 0.2 | 155 | 0 | r:ix_releases_band_cat_posted |  |
| PC: name search (photoshop) + Category ISO, count | 0.2 | 155 | 1 | r:ix_releases_band_cat_posted |  |
| PC: Category menu counts (valueCounts, cached 1 h) | 0.3 | 2760 | 17 | releases:ix_releases_band_count | filesort(releases), temporary |

```sql
-- PC: no filter, posted, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- PC: no filter, posted, worst page (offset 650, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 650;
-- PC: no filter, posted, last page (mirrored: oldest first, offset 0, 27 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 27;
-- PC: no filter, posted, count (1327)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0;
-- PC: no filter, added, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 4000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- PC: no filter, added, worst page
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 4000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50 offset 650;
-- PC: Category ISO (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) order by r.postdate desc, r.id desc limit 50;
-- PC: Category ISO (band_cat_posted), worst page (offset 50, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) order by r.postdate desc, r.id desc limit 50 offset 50;
-- PC: Category ISO (band_cat_posted), last page (mirrored: oldest first, offset 0, 3 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) order by r.postdate asc, r.id asc limit 3;
-- PC: Category ISO (band_cat_posted), count (153)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020);
-- PC: Category 0day (band_posted), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010) order by r.postdate desc, r.id desc limit 50;
-- PC: Category 0day (band_posted), worst page (offset 550, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010) order by r.postdate desc, r.id desc limit 50 offset 550;
-- PC: Category 0day (band_posted), last page (mirrored: oldest first, offset 0, 2 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010) order by r.postdate asc, r.id asc limit 2;
-- PC: Category 0day (band_posted), count (1102)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010);
-- PC: Exclude Other (band index: over half the band), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070) order by r.postdate desc, r.id desc limit 50;
-- PC: Exclude Other (band index: over half the band), worst page (offset 650, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070) order by r.postdate desc, r.id desc limit 50 offset 650;
-- PC: Exclude Other (band index: over half the band), last page (mirrored: oldest first, offset 0, 27 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070) order by r.postdate asc, r.id asc limit 27;
-- PC: Exclude Other (band index: over half the band), count (1327)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070);
-- PC: Completion 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- PC: Completion 100%, worst page (offset 500, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 500;
-- PC: Completion 100%, last page (mirrored: oldest first, offset 0, 10 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate asc, r.id asc limit 10;
-- PC: Completion 100%, count (1060)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 100;
-- PC: Completion 95%+, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- PC: Completion 95%+, worst page (offset 550, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 550;
-- PC: Completion 95%+, last page (mirrored: oldest first, offset 0, 41 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate asc, r.id asc limit 41;
-- PC: Completion 95%+, count (1141)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0 and r.completion >= 95;
-- PC: Category ISO + 95%+, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- PC: Category ISO + 95%+, worst page (offset 50, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 50;
-- PC: Category ISO + 95%+, last page (mirrored: oldest first, offset 0, 2 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) and r.completion >= 95 order by r.postdate asc, r.id asc limit 2;
-- PC: Category ISO + 95%+, count (102)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) and r.completion >= 95;
-- PC: Exclude Other + 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- PC: Exclude Other + 100%, worst page (offset 500, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 500;
-- PC: Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 10 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070) and r.completion >= 100 order by r.postdate asc, r.id asc limit 10;
-- PC: Exclude Other + 100%, count (1060)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4010,4020,4030,4050,4060,4070) and r.completion >= 100;
-- PC: name search, common (x64), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%x64%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- PC: name search, common (x64), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%x64%' escape '!';
-- PC: name search, middling (photoshop), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%photoshop%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- PC: name search, middling (photoshop), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%photoshop%' escape '!';
-- PC: name search, nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- PC: name search, nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
-- PC: name search (photoshop) + Category ISO, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%photoshop%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- PC: name search (photoshop) + Category ISO, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 4000 and r.passwordstatus <= 0 and r.categories_id in (4020) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%photoshop%' escape '!';
-- PC: Category menu counts (valueCounts, cached 1 h)
select categories_id, resolution, source, count(*) from releases force index (ix_releases_band_count) where category_band = 4000 group by categories_id, resolution, source;
```

### Console list (real size, `q-console-real.tsv`)

Schema `shelfc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Console (real): no filter, posted, page 1 | 0.1 | 18 | 15 | r:ix_releases_band_posted |  |
| Console (real): no filter, posted, count (15) | 0.0 | 18 | 1 | r:ix_releases_band_count |  |
| Console (real): no filter, added, page 1 | 0.0 | 18 | 15 | r:ix_releases_band_added |  |
| Console (real): no filter, added, worst page | 0.0 | 18 | 15 | r:ix_releases_band_added |  |
| Console (real): Category PS Vita (band_cat_posted), page 1 | 0.0 | 7 | 4 | r:ix_releases_band_cat_posted |  |
| Console (real): Category PS Vita (band_cat_posted), count (4) | 0.0 | 18 | 1 | r:ix_releases_band_count |  |
| Console (real): Exclude Other (band index: over half the band), page 1 | 0.1 | 18 | 13 | r:ix_releases_band_posted |  |
| Console (real): Exclude Other (band index: over half the band), count (13) | 0.1 | 18 | 1 | r:ix_releases_band_count |  |
| Console (real): Completion 100%, page 1 | 0.0 | 18 | 4 | r:ix_releases_band_posted |  |
| Console (real): Completion 100%, count (4) | 0.0 | 18 | 1 | r:ix_releases_band_count |  |
| Console (real): Completion 95%+, page 1 | 0.0 | 18 | 6 | r:ix_releases_band_posted |  |
| Console (real): Completion 95%+, count (6) | 0.0 | 18 | 1 | r:ix_releases_band_count |  |
| Console (real): Category PS Vita + 95%+, page 1 | 0.0 | 7 | 3 | r:ix_releases_band_cat_posted |  |
| Console (real): Category PS Vita + 95%+, count (3) | 0.0 | 18 | 1 | r:ix_releases_band_count |  |
| Console (real): Exclude Other + 100%, page 1 | 0.1 | 18 | 4 | r:ix_releases_band_posted |  |
| Console (real): Exclude Other + 100%, count (4) | 0.1 | 18 | 1 | r:ix_releases_band_count |  |
| Console (real): name search, common (PSV), page 1 | 0.1 | 18 | 4 | r:ix_releases_band_posted |  |
| Console (real): name search, common (PSV), count | 0.1 | 18 | 1 | r:ix_releases_band_posted |  |
| Console (real): name search, middling (PS3), page 1 | 0.1 | 18 | 3 | r:ix_releases_band_posted |  |
| Console (real): name search, middling (PS3), count | 0.1 | 18 | 1 | r:ix_releases_band_posted |  |
| Console (real): name search, nothing (zzqqxx), page 1 | 0.1 | 18 | 0 | r:ix_releases_band_posted |  |
| Console (real): name search, nothing (zzqqxx), count | 0.1 | 18 | 1 | r:ix_releases_band_posted |  |
| Console (real): name search (PS3) + Category PS Vita, page 1 | 0.1 | 7 | 0 | r:ix_releases_band_cat_posted |  |
| Console (real): name search (PS3) + Category PS Vita, count | 0.1 | 7 | 1 | r:ix_releases_band_cat_posted |  |
| Console (real): Category menu counts (valueCounts, cached 1 h) | 0.1 | 58 | 11 | releases:ix_releases_band_count | filesort(releases), temporary |
| Console (real): row extras for page 1 (50 ids): game, year, cover flag, genres in order (one query) | 0.1 | 71 | 15 | r:PRIMARY c:PRIMARY cg:ix_console_genres_console g:PRIMARY |  |
| Console (real): game-name search, common (PSV), page 1 | 0.1 | 25 | 4 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search, common (PSV), count | 0.1 | 32 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search, middling (PS3), page 1 | 0.1 | 28 | 3 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search, middling (PS3), count | 0.1 | 42 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search, nothing (zzqqxx), page 1 | 0.1 | 29 | 0 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search, nothing (zzqqxx), count | 0.1 | 44 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search, a game word (Broken), page 1 | 0.1 | 29 | 2 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search, a game word (Broken), count | 0.1 | 44 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search (Broken) as a LEFT JOIN (alternative), page 1 | 0.1 | 29 | 2 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search (Broken) as a LEFT JOIN (alternative), count | 0.1 | 29 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search (zzqqxx) as a LEFT JOIN (alternative), page 1 | 0.1 | 29 | 0 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): game-name search (Broken) + Category PS Vita, page 1 | 0.1 | 11 | 0 | r:ix_releases_band_cat_posted c:PRIMARY |  |
| Console (real): game-name search (Broken) + Category PS Vita, count | 0.1 | 19 | 1 | r:ix_releases_band_cat_posted c:PRIMARY |  |
| Console (real): Genre Adventure (largest), A release-led, page 1 | 0.1 | 36 | 5 | r:ix_releases_band_posted cg:PRIMARY | filesort(r), temporary |
| Console (real): Genre Adventure (largest), A release-led, count (5) | 0.1 | 25 | 1 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (real): Genre Adventure (largest), B game-led, proposal index, page 1 | 0.1 | 37 | 5 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (real): Genre Adventure (largest), B game-led, proposal index, count | 0.1 | 26 | 1 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (real): Genre Adventure (largest), C game-led, candidate index, page 1 | 0.1 | 37 | 5 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (real): Genre Adventure (largest), C game-led, candidate index, count | 0.1 | 26 | 1 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (real): Genre Pinball (small), A release-led, page 1 | 0.1 | 2 | 0 | cg:PRIMARY r:ix_releases_band_posted | filesort(cg), temporary |
| Console (real): Genre Pinball (small), A release-led, count (0) | 0.1 | 1 | 1 | cg:PRIMARY r:ix_releases_band_posted |  |
| Console (real): Genre Pinball (small), B game-led, proposal index, page 1 | 0.1 | 3 | 0 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (real): Genre Pinball (small), B game-led, proposal index, count | 0.1 | 2 | 1 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (real): Genre Pinball (small), C game-led, candidate index, page 1 | 0.1 | 3 | 0 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (real): Genre Pinball (small), C game-led, candidate index, count | 0.1 | 2 | 1 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (real): Genre Adventure or Shooter, A release-led, page 1 | 0.1 | 40 | 6 | r:ix_releases_band_posted cg:PRIMARY | filesort(r), temporary |
| Console (real): Genre Adventure or Shooter, A release-led, count (6) | 0.1 | 27 | 1 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (real): Genre Adventure or Shooter, B game-led, proposal index, page 1 | 0.1 | 57 | 6 | r:ix_releases_consoleinfo_id cg:ix_console_genres_console |  |
| Console (real): Genre Adventure or Shooter, B game-led, proposal index, count | 0.1 | 44 | 1 | r:ix_releases_consoleinfo_id cg:ix_console_genres_console |  |
| Console (real): Genre Adventure or Shooter, C game-led, candidate index, page 1 | 0.1 | 57 | 6 | r:ix_releases_consoleinfo_cat cg:ix_console_genres_console |  |
| Console (real): Genre Adventure or Shooter, C game-led, candidate index, count | 0.1 | 44 | 1 | r:ix_releases_consoleinfo_cat cg:ix_console_genres_console |  |
| Console (real): Genre Unknown, A release-led, page 1 | 0.1 | 29 | 5 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (real): Genre Unknown, A release-led, count (5) | 0.1 | 44 | 1 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (real): Genre Unknown, C two parts on the candidate index (no game + Unknown games), page 1 | 0.1 | 27 | 5 | r:ix_releases_consoleinfo_cat cg:PRIMARY r:ix_releases_consoleinfo_cat | filesort(cg), filesort(r), temporary |
| Console (real): Genre Unknown, C two parts, count | 0.1 | 9 | 1 | NULL:NULL cg:PRIMARY r:ix_releases_consoleinfo_cat r:ix_releases_consoleinfo_cat |  |
| Console (real): Genre Adventure + Unknown, A release-led, page 1 | 0.1 | 37 | 10 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (real): Genre Adventure + Unknown, A release-led, count | 0.1 | 53 | 1 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (real): Year 2010s, A release-led, page 1 | 0.1 | 29 | 3 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): Year 2010s, A release-led, count (3) | 0.1 | 29 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): Year 2010s, B game-led, proposal index, page 1 | 0.1 | 27 | 3 | c:ALL r:ix_releases_consoleinfo_id | filesort(c), temporary |
| Console (real): Year 2010s, B game-led, proposal index, count | 0.1 | 20 | 1 | c:ALL r:ix_releases_consoleinfo_id |  |
| Console (real): Year 2010s, C game-led, candidate index, page 1 | 0.1 | 27 | 3 | c:ALL r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (real): Year 2010s, C game-led, candidate index, count | 0.1 | 20 | 1 | c:ALL r:ix_releases_consoleinfo_cat |  |
| Console (real): Year 2010s, C game-led + candidate date index, page 1 | 0.1 | 17 | 3 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (real): Year 2010s, C game-led + candidate date index, count | 0.1 | 10 | 1 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat |  |
| Console (real): Year 1990s + 2000s, A release-led, page 1 | 0.1 | 29 | 4 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): Year 1990s + 2000s, A release-led, count (4) | 0.1 | 29 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): Year 1990s + 2000s, B game-led, proposal index, page 1 | 0.1 | 33 | 4 | c:ALL r:ix_releases_consoleinfo_id | filesort(c), temporary |
| Console (real): Year 1990s + 2000s, B game-led, proposal index, count | 0.1 | 24 | 1 | c:ALL r:ix_releases_consoleinfo_id |  |
| Console (real): Year 1990s + 2000s, C game-led, candidate index, page 1 | 0.1 | 33 | 4 | c:ALL r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (real): Year 1990s + 2000s, C game-led, candidate index, count | 0.1 | 24 | 1 | c:ALL r:ix_releases_consoleinfo_cat |  |
| Console (real): Year range 1998-2001, A release-led, page 1 | 0.1 | 29 | 0 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): Year range 1998-2001, A release-led, count (0) | 0.1 | 29 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (real): Year range 1998-2001, B game-led, proposal index, page 1 | 0.1 | 15 | 0 | c:ALL r:ix_releases_consoleinfo_id | filesort(c), temporary |
| Console (real): Year range 1998-2001, B game-led, proposal index, count | 0.1 | 14 | 1 | c:ALL r:ix_releases_consoleinfo_id |  |
| Console (real): Year range 1998-2001, C game-led, candidate index, page 1 | 0.1 | 15 | 0 | c:ALL r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (real): Year range 1998-2001, C game-led, candidate index, count | 0.1 | 14 | 1 | c:ALL r:ix_releases_consoleinfo_cat |  |
| Console (real): Year range 1998-2001, C game-led + candidate date index, page 1 | 0.1 | 2 | 0 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (real): Year range 1998-2001, C game-led + candidate date index, count | 0.1 | 1 | 1 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat |  |
| Console (real): Genre Adventure + Year 2010s + Category PS Vita, A release-led (cat index), page 1 | 0.1 | 13 | 0 | r:ix_releases_band_cat_posted cg:ix_console_genres_console c:PRIMARY |  |
| Console (real): Genre Adventure + Year 2010s + Category PS Vita, A release-led (cat index), count | 0.1 | 13 | 1 | r:ix_releases_band_cat_posted cg:ix_console_genres_console c:PRIMARY |  |
| Console (real): Genre Adventure + Year 2010s + Category PS Vita, B game-led, proposal index, page 1 | 0.1 | 20 | 0 | r:ix_releases_consoleinfo_id cg:PRIMARY c:PRIMARY | temporary |
| Console (real): Genre Adventure + Year 2010s + Category PS Vita, B game-led, proposal index, count | 0.1 | 19 | 1 | r:ix_releases_consoleinfo_id cg:PRIMARY c:PRIMARY | temporary |
| Console (real): Genre Adventure + Year 2010s + Category PS Vita, C game-led, candidate index, page 1 | 0.1 | 19 | 0 | r:ix_releases_consoleinfo_cat cg:PRIMARY c:PRIMARY | temporary |
| Console (real): Genre Adventure + Year 2010s + Category PS Vita, C game-led, candidate index, count | 0.1 | 18 | 1 | r:ix_releases_consoleinfo_cat cg:PRIMARY c:PRIMARY | temporary |
| Console (real): Genre Adventure, C game-led, Added order, page 1 | 0.1 | 37 | 5 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (real): Genre Adventure + game-name search (Broken), C game-led, page 1 | 0.1 | 50 | 0 | r:ix_releases_consoleinfo_cat c:ALL cg:PRIMARY |  |
| Console (real): Genre menu: Console genres that have a game, A to Z (Unknown listed last by the code) | 0.1 | 71 | 11 | g:ix_genres_type_disabled cg:PRIMARY | filesort(g) |
| Console (real): Genre menu, alternative: genres with a game that has a band release | 0.1 | 105 | 11 | cg:ix_console_genres_console g:PRIMARY r:ix_releases_consoleinfo_id | filesort(cg), temporary |
| Console (real): Genre menu: is Unknown needed (a band release with no game, or an Unknown game) | 0.1 | 1 | 1 | NULL:NULL cg:PRIMARY r:ix_releases_band_posted |  |

```sql
-- Console (real): no filter, posted, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): no filter, posted, count (15)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0;
-- Console (real): no filter, added, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Console (real): no filter, added, worst page
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50 offset 0;
-- Console (real): Category PS Vita (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Category PS Vita (band_cat_posted), count (4)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120);
-- Console (real): Exclude Other (band index: over half the band), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Exclude Other (band index: over half the band), count (13)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180);
-- Console (real): Completion 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Completion 100%, count (4)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 100;
-- Console (real): Completion 95%+, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Completion 95%+, count (6)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 95;
-- Console (real): Category PS Vita + 95%+, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Category PS Vita + 95%+, count (3)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and r.completion >= 95;
-- Console (real): Exclude Other + 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Exclude Other + 100%, count (4)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) and r.completion >= 100;
-- Console (real): name search, common (PSV), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PSV%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (real): name search, common (PSV), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PSV%' escape '!';
-- Console (real): name search, middling (PS3), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PS3%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (real): name search, middling (PS3), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PS3%' escape '!';
-- Console (real): name search, nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (real): name search, nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
-- Console (real): name search (PS3) + Category PS Vita, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PS3%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (real): name search (PS3) + Category PS Vita, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PS3%' escape '!';
-- Console (real): Category menu counts (valueCounts, cached 1 h)
select categories_id, resolution, source, count(*) from releases force index (ix_releases_band_count) where category_band = 1000 group by categories_id, resolution, source;
-- Console (real): row extras for page 1 (50 ids): game, year, cover flag, genres in order (one query)
select r.id, c.title, year(c.releasedate) as year, c.cover, (select group_concat(g.title order by cg.position separator ', ') from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = c.id) as genres from releases r left join consoleinfo c on c.id = r.consoleinfo_id where r.id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337);
-- Console (real): game-name search, common (PSV), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PSV%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%PSV%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): game-name search, common (PSV), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PSV%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%PSV%' escape '!'));
-- Console (real): game-name search, middling (PS3), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PS3%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%PS3%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): game-name search, middling (PS3), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%PS3%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%PS3%' escape '!'));
-- Console (real): game-name search, nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%zzqqxx%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): game-name search, nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%zzqqxx%' escape '!'));
-- Console (real): game-name search, a game word (Broken), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Broken%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%Broken%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): game-name search, a game word (Broken), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Broken%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%Broken%' escape '!'));
-- Console (real): game-name search (Broken) as a LEFT JOIN (alternative), page 1
select r.id from releases r force index (ix_releases_band_posted) left join consoleinfo c on c.id = r.consoleinfo_id where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Broken%' escape '!' or c.title like '%Broken%' escape '!') order by r.postdate desc, r.id desc limit 50;
-- Console (real): game-name search (Broken) as a LEFT JOIN (alternative), count
select count(*) from releases r force index (ix_releases_band_posted) left join consoleinfo c on c.id = r.consoleinfo_id where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Broken%' escape '!' or c.title like '%Broken%' escape '!');
-- Console (real): game-name search (zzqqxx) as a LEFT JOIN (alternative), page 1
select r.id from releases r force index (ix_releases_band_posted) left join consoleinfo c on c.id = r.consoleinfo_id where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or c.title like '%zzqqxx%' escape '!') order by r.postdate desc, r.id desc limit 50;
-- Console (real): game-name search (Broken) + Category PS Vita, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Broken%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%Broken%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): game-name search (Broken) + Category PS Vita, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Broken%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%Broken%' escape '!'));
-- Console (real): Genre Adventure (largest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170)) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure (largest), A release-led, count (5)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170));
-- Console (real): Genre Adventure (largest), B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure (largest), B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Genre Adventure (largest), C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure (largest), C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Genre Pinball (small), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000191)) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Pinball (small), A release-led, count (0)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000191));
-- Console (real): Genre Pinball (small), B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Pinball (small), B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Genre Pinball (small), C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Pinball (small), C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Genre Adventure or Shooter, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000171)) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure or Shooter, A release-led, count (6)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000171));
-- Console (real): Genre Adventure or Shooter, B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure or Shooter, B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Genre Adventure or Shooter, C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure or Shooter, C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Genre Unknown, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000193))) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Unknown, A release-led, count (5)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000193)));
-- Console (real): Genre Unknown, C two parts on the candidate index (no game + Unknown games), page 1
select id from ((select r.id, r.postdate from releases r force index (ix_releases_consoleinfo_cat) where r.consoleinfo_id is null and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from console_genres cg straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = cg.consoleinfo_id where cg.genres_id = 1000193 and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Console (real): Genre Unknown, C two parts, count
select (select count(*) from releases r force index (ix_releases_consoleinfo_cat) where r.consoleinfo_id is null and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0) + (select count(*) from console_genres cg straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = cg.consoleinfo_id where cg.genres_id = 1000193 and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0);
-- Console (real): Genre Adventure + Unknown, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000193))) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure + Unknown, A release-led, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000193)));
-- Console (real): Year 2010s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year 2010s, A release-led, count (3)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01'));
-- Console (real): Year 2010s, B game-led, proposal index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year 2010s, B game-led, proposal index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Year 2010s, C game-led, candidate index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year 2010s, C game-led, candidate index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Year 2010s, C game-led + candidate date index, page 1
select r.id from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year 2010s, C game-led + candidate date index, count
select count(*) from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Year 1990s + 2000s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year 1990s + 2000s, A release-led, count (4)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01'));
-- Console (real): Year 1990s + 2000s, B game-led, proposal index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year 1990s + 2000s, B game-led, proposal index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Year 1990s + 2000s, C game-led, candidate index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year 1990s + 2000s, C game-led, candidate index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Year range 1998-2001, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year range 1998-2001, A release-led, count (0)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01'));
-- Console (real): Year range 1998-2001, B game-led, proposal index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year range 1998-2001, B game-led, proposal index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Year range 1998-2001, C game-led, candidate index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year range 1998-2001, C game-led, candidate index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Year range 1998-2001, C game-led + candidate date index, page 1
select r.id from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (real): Year range 1998-2001, C game-led + candidate date index, count
select count(*) from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (real): Genre Adventure + Year 2010s + Category PS Vita, A release-led (cat index), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170)) and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure + Year 2010s + Category PS Vita, A release-led (cat index), count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1120) and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170)) and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01'));
-- Console (real): Genre Adventure + Year 2010s + Category PS Vita, B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1120) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure + Year 2010s + Category PS Vita, B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1120);
-- Console (real): Genre Adventure + Year 2010s + Category PS Vita, C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1120) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre Adventure + Year 2010s + Category PS Vita, C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1120);
-- Console (real): Genre Adventure, C game-led, Added order, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Console (real): Genre Adventure + game-name search (Broken), C game-led, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Broken%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%Broken%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (real): Genre menu: Console genres that have a game, A to Z (Unknown listed last by the code)
select g.id, g.title from genres g where g.type = 1000 and g.title <> 'Unknown' and exists (select 1 from console_genres cg where cg.genres_id = g.id) order by g.title;
-- Console (real): Genre menu, alternative: genres with a game that has a band release
select g.id, g.title from genres g where g.type = 1000 and g.title <> 'Unknown' and exists (select 1 from console_genres cg join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = cg.consoleinfo_id where cg.genres_id = g.id and r.categories_id between 1000 and 1999) order by g.title;
-- Console (real): Genre menu: is Unknown needed (a band release with no game, or an Unknown game)
select exists (select 1 from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.consoleinfo_id is null) or exists (select 1 from console_genres cg where cg.genres_id = 1000193);
```

### Console list (stress size, `q-console-stress.tsv`)

Schema `shelfs`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Console (stress): no filter, posted, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_posted |  |
| Console (stress): no filter, posted, worst page (offset 281350, the last before mirroring) | 40.4 | 283653 | 50 | r:ix_releases_band_posted |  |
| Console (stress): no filter, posted, last page (mirrored: oldest first, offset 0, 7 rows) | 0.1 | 8 | 7 | r:ix_releases_band_posted |  |
| Console (stress): no filter, posted, count (562757) | 56.8 | 575127 | 1 | r:ix_releases_band_count |  |
| Console (stress): no filter, added, page 1 | 0.1 | 65 | 50 | r:ix_releases_band_added |  |
| Console (stress): no filter, added, worst page | 45.5 | 283635 | 50 | r:ix_releases_band_added |  |
| Console (stress): Category PS4 (band_cat_posted), page 1 | 0.1 | 52 | 50 | r:ix_releases_band_cat_posted |  |
| Console (stress): Category PS4 (band_cat_posted), worst page (offset 22450, the last before mirroring) | 3.8 | 22685 | 50 | r:ix_releases_band_cat_posted |  |
| Console (stress): Category PS4 (band_cat_posted), last page (mirrored: oldest first, offset 0, 24 rows) | 0.1 | 25 | 24 | r:ix_releases_band_cat_posted |  |
| Console (stress): Category PS4 (band_cat_posted), count (44974) | 56.0 | 575127 | 1 | r:ix_releases_band_count |  |
| Console (stress): Exclude Other (band index: over half the band), page 1 | 0.1 | 63 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Exclude Other (band index: over half the band), worst page (offset 225100, the last before mirroring) | 55.2 | 283828 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Exclude Other (band index: over half the band), last page (mirrored: oldest first, offset 0, 1 rows) | 0.1 | 1 | 1 | r:ix_releases_band_posted |  |
| Console (stress): Exclude Other (band index: over half the band), count (450201) | 64.3 | 575127 | 1 | r:ix_releases_band_count |  |
| Console (stress): Completion 100%, page 1 | 0.1 | 55 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Completion 100%, worst page (offset 270500, the last before mirroring) | 48.2 | 277679 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Completion 100%, last page (mirrored: oldest first, offset 0, 2 rows) | 0.1 | 6 | 2 | r:ix_releases_band_posted |  |
| Console (stress): Completion 100%, count (541002) | 62.3 | 575127 | 1 | r:ix_releases_band_count |  |
| Console (stress): Completion 95%+, page 1 | 0.1 | 51 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Completion 95%+, worst page (offset 275600, the last before mirroring) | 45.9 | 282115 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Completion 95%+, last page (mirrored: oldest first, offset 0, 41 rows) | 0.1 | 60 | 41 | r:ix_releases_band_posted |  |
| Console (stress): Completion 95%+, count (551291) | 61.6 | 575127 | 1 | r:ix_releases_band_count |  |
| Console (stress): Category PS4 + 95%+, page 1 | 0.1 | 54 | 50 | r:ix_releases_band_cat_posted |  |
| Console (stress): Category PS4 + 95%+, worst page (offset 22000, the last before mirroring) | 3.5 | 22556 | 50 | r:ix_releases_band_cat_posted |  |
| Console (stress): Category PS4 + 95%+, last page (mirrored: oldest first, offset 0, 47 rows) | 0.1 | 157 | 47 | r:ix_releases_band_cat_posted |  |
| Console (stress): Category PS4 + 95%+, count (44097) | 52.9 | 575127 | 1 | r:ix_releases_band_count |  |
| Console (stress): Exclude Other + 100%, page 1 | 0.1 | 71 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Exclude Other + 100%, worst page (offset 216350, the last before mirroring) | 48.0 | 277808 | 50 | r:ix_releases_band_posted |  |
| Console (stress): Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 37 rows) | 0.1 | 120 | 37 | r:ix_releases_band_posted |  |
| Console (stress): Exclude Other + 100%, count (432737) | 62.8 | 575127 | 1 | r:ix_releases_band_count |  |
| Console (stress): name search, common (1080p), page 1 | 0.1 | 76 | 50 | r:ix_releases_band_posted |  |
| Console (stress): name search, common (1080p), count | 401.2 | 575127 | 1 | r:ix_releases_band_posted |  |
| Console (stress): name search, middling (2016), page 1 | 2.0 | 1748 | 50 | r:ix_releases_band_posted |  |
| Console (stress): name search, middling (2016), count | 403.7 | 575127 | 1 | r:ix_releases_band_posted |  |
| Console (stress): name search, nothing (zzqqxx), page 1 | 424.4 | 575127 | 0 | r:ix_releases_band_posted |  |
| Console (stress): name search, nothing (zzqqxx), count | 391.9 | 575127 | 1 | r:ix_releases_band_posted |  |
| Console (stress): name search (2016) + Category PS4, page 1 | 39.6 | 39836 | 50 | r:ix_releases_band_cat_posted |  |
| Console (stress): name search (2016) + Category PS4, count | 45.0 | 45964 | 1 | r:ix_releases_band_cat_posted |  |
| Console (stress): Category menu counts (valueCounts, cached 1 h) | 88.0 | 1150922 | 334 | releases:ix_releases_band_count | filesort(releases), temporary |
| Console (stress): row extras for page 1 (50 ids): game, year, cover flag, genres in order (one query) | 0.4 | 290 | 50 | r:PRIMARY c:PRIMARY cg:ix_console_genres_console g:PRIMARY |  |
| Console (stress): game-name search, common (1080p), page 1 | 13.2 | 71968 | 50 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search, common (1080p), count | 437.1 | 647182 | 1 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search, middling (2016), page 1 | 16.8 | 73640 | 50 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search, middling (2016), count | 439.8 | 647182 | 1 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search, nothing (zzqqxx), page 1 | 461.0 | 647019 | 0 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search, nothing (zzqqxx), count | 429.5 | 647182 | 1 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search, a game word (dragon), page 1 | 19.0 | 76128 | 50 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search, a game word (dragon), count | 467.4 | 1106720 | 1 | r:ix_releases_band_posted c:ALL |  |
| Console (stress): game-name search (dragon) as a LEFT JOIN (alternative), page 1 | 4.7 | 4252 | 50 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): game-name search (dragon) as a LEFT JOIN (alternative), count | 962.5 | 1024876 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): game-name search (zzqqxx) as a LEFT JOIN (alternative), page 1 | 951.4 | 1024876 | 0 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): game-name search (dragon) + Category PS4, page 1 | 19.0 | 78178 | 50 | r:ix_releases_band_cat_posted c:ALL |  |
| Console (stress): game-name search (dragon) + Category PS4, count | 67.6 | 154784 | 1 | r:ix_releases_band_cat_posted c:ALL |  |
| Console (stress): Genre Adventure (largest), A release-led, page 1 | 0.3 | 386 | 50 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure (largest), A release-led, worst page (offset 65800) | 277.1 | 508593 | 50 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure (largest), A release-led, count (131647) | 524.2 | 1024874 | 1 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure (largest), B game-led, proposal index, page 1 | 171.9 | 329269 | 50 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (stress): Genre Adventure (largest), B game-led, proposal index, worst page (offset 65800; same cost on every page) | 177.5 | 395069 | 50 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (stress): Genre Adventure (largest), B game-led, proposal index, count | 161.6 | 197571 | 1 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (stress): Genre Adventure (largest), C game-led, candidate index, page 1 | 32.6 | 329269 | 50 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (stress): Genre Adventure (largest), C game-led, candidate index, worst page (offset 65800; same cost on every page) | 38.5 | 395069 | 50 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (stress): Genre Adventure (largest), C game-led, candidate index, count | 27.3 | 197571 | 1 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (stress): Genre Pinball (small), A release-led, page 1 | 5.8 | 9534 | 50 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (stress): Genre Pinball (small), A release-led, count (4818) | 513.4 | 1024874 | 1 | r:ix_releases_band_posted cg:ix_console_genres_console |  |
| Console (stress): Genre Pinball (small), B game-led, proposal index, page 1 | 5.3 | 12078 | 50 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (stress): Genre Pinball (small), B game-led, proposal index, count | 5.5 | 7209 | 1 | r:ix_releases_consoleinfo_id cg:PRIMARY |  |
| Console (stress): Genre Pinball (small), C game-led, candidate index, page 1 | 1.4 | 12078 | 50 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (stress): Genre Pinball (small), C game-led, candidate index, count | 1.2 | 7209 | 1 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (stress): Genre Adventure or Shooter, A release-led, page 1 | 5.1 | 34630 | 50 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Genre Adventure or Shooter, A release-led, worst page (offset 97950) | 189.8 | 542830 | 50 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Genre Adventure or Shooter, A release-led, count (195983) | 359.8 | 1059238 | 1 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Genre Adventure or Shooter, B game-led, proposal index, page 1 | 335.1 | 599481 | 50 | r:ix_releases_consoleinfo_id cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure or Shooter, B game-led, proposal index, worst page (offset 97950; same cost on every page) | 330.3 | 697431 | 50 | r:ix_releases_consoleinfo_id cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure or Shooter, B game-led, proposal index, count | 312.1 | 403447 | 1 | r:ix_releases_consoleinfo_id cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure or Shooter, C game-led, candidate index, page 1 | 53.6 | 599481 | 50 | r:ix_releases_consoleinfo_cat cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure or Shooter, C game-led, candidate index, worst page (offset 97950; same cost on every page) | 64.9 | 697431 | 50 | r:ix_releases_consoleinfo_cat cg:ix_console_genres_console |  |
| Console (stress): Genre Adventure or Shooter, C game-led, candidate index, count | 48.6 | 403447 | 1 | r:ix_releases_consoleinfo_cat cg:ix_console_genres_console |  |
| Console (stress): Genre Unknown, A release-led, page 1 | 0.6 | 3357 | 50 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Genre Unknown, A release-led, worst page (offset 65350) | 185.2 | 513520 | 50 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Genre Unknown, A release-led, count (130735) | 350.0 | 1037871 | 1 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Genre Unknown, C two parts on the candidate index (no game + Unknown games), page 1 | 17.9 | 156652 | 50 | r:ix_releases_consoleinfo_cat cg:PRIMARY r:ix_releases_consoleinfo_cat | filesort(cg), filesort(r), temporary |
| Console (stress): Genre Unknown, C two parts, worst page (offset 65350) | 42.5 | 322670 | 50 | r:ix_releases_consoleinfo_cat cg:PRIMARY r:ix_releases_consoleinfo_cat | filesort(cg), filesort(r), temporary |
| Console (stress): Genre Unknown, C two parts, count | 16.2 | 138666 | 1 | NULL:NULL cg:PRIMARY r:ix_releases_consoleinfo_cat r:ix_releases_consoleinfo_cat |  |
| Console (stress): Genre Adventure + Unknown, A release-led, page 1 | 3.7 | 24047 | 50 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Genre Adventure + Unknown, A release-led, count | 361.0 | 1058895 | 1 | r:ix_releases_band_posted cg:PRIMARY |  |
| Console (stress): Year 2010s, A release-led, page 1 | 0.2 | 228 | 50 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): Year 2010s, A release-led, worst page (offset 112400) | 385.2 | 507676 | 50 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): Year 2010s, A release-led, count (224814) | 720.9 | 1024874 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): Year 2010s, B game-led, proposal index, page 1 | 302.4 | 562623 | 50 | c:ALL r:ix_releases_consoleinfo_id | filesort(c), temporary |
| Console (stress): Year 2010s, B game-led, proposal index, worst page (offset 112400; same cost on every page) | 320.8 | 675023 | 50 | c:ALL r:ix_releases_consoleinfo_id | filesort(c), temporary |
| Console (stress): Year 2010s, B game-led, proposal index, count | 290.4 | 337758 | 1 | c:ALL r:ix_releases_consoleinfo_id |  |
| Console (stress): Year 2010s, C game-led, candidate index, page 1 | 68.0 | 562623 | 50 | c:ALL r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (stress): Year 2010s, C game-led, candidate index, worst page (offset 112400; same cost on every page) | 79.2 | 675023 | 50 | c:ALL r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (stress): Year 2010s, C game-led, candidate index, count | 58.9 | 337758 | 1 | c:ALL r:ix_releases_consoleinfo_cat |  |
| Console (stress): Year 2010s, C game-led + candidate date index, page 1 | 63.2 | 526717 | 50 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (stress): Year 2010s, C game-led + candidate date index, count | 53.8 | 301852 | 1 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat |  |
| Console (stress): Year 1990s + 2000s, A release-led, page 1 | 0.3 | 293 | 50 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): Year 1990s + 2000s, A release-led, count (176815) | 736.1 | 1024874 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): Year 1990s + 2000s, B game-led, proposal index, page 1 | 249.0 | 457746 | 50 | c:ALL r:ix_releases_consoleinfo_id | filesort(c), temporary |
| Console (stress): Year 1990s + 2000s, B game-led, proposal index, count | 233.5 | 280880 | 1 | c:ALL r:ix_releases_consoleinfo_id |  |
| Console (stress): Year 1990s + 2000s, C game-led, candidate index, page 1 | 56.9 | 457746 | 50 | c:ALL r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (stress): Year 1990s + 2000s, C game-led, candidate index, count | 50.1 | 280880 | 1 | c:ALL r:ix_releases_consoleinfo_cat |  |
| Console (stress): Year range 1998-2001, A release-led, page 1 | 1.1 | 1491 | 50 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): Year range 1998-2001, A release-led, count (30935) | 720.3 | 1024874 | 1 | r:ix_releases_band_posted c:PRIMARY |  |
| Console (stress): Year range 1998-2001, B game-led, proposal index, page 1 | 57.1 | 139447 | 50 | c:ALL r:ix_releases_consoleinfo_id | filesort(c), temporary |
| Console (stress): Year range 1998-2001, B game-led, proposal index, count | 56.0 | 108461 | 1 | c:ALL r:ix_releases_consoleinfo_id |  |
| Console (stress): Year range 1998-2001, C game-led, candidate index, page 1 | 20.8 | 139447 | 50 | c:ALL r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (stress): Year range 1998-2001, C game-led, candidate index, count | 18.8 | 108461 | 1 | c:ALL r:ix_releases_consoleinfo_cat |  |
| Console (stress): Year range 1998-2001, C game-led + candidate date index, page 1 | 8.4 | 72530 | 50 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat | filesort(c), temporary |
| Console (stress): Year range 1998-2001, C game-led + candidate date index, count | 7.7 | 41544 | 1 | c:ix_consoleinfo_releasedate_cand r:ix_releases_consoleinfo_cat |  |
| Console (stress): Genre Adventure + Year 2010s + Category PS4, A release-led (cat index), page 1 | 0.6 | 771 | 50 | r:ix_releases_band_cat_posted cg:ix_console_genres_console c:PRIMARY |  |
| Console (stress): Genre Adventure + Year 2010s + Category PS4, A release-led (cat index), count | 68.8 | 92422 | 1 | r:ix_releases_band_cat_posted cg:ix_console_genres_console c:PRIMARY |  |
| Console (stress): Genre Adventure + Year 2010s + Category PS4, B game-led, proposal index, page 1 | 107.7 | 146325 | 50 | r:ix_releases_consoleinfo_id cg:PRIMARY c:PRIMARY | temporary |
| Console (stress): Genre Adventure + Year 2010s + Category PS4, B game-led, proposal index, count | 99.8 | 140969 | 1 | r:ix_releases_consoleinfo_id cg:PRIMARY c:PRIMARY | temporary |
| Console (stress): Genre Adventure + Year 2010s + Category PS4, C game-led, candidate index, page 1 | 27.7 | 84425 | 50 | r:ix_releases_consoleinfo_cat cg:PRIMARY c:PRIMARY | temporary |
| Console (stress): Genre Adventure + Year 2010s + Category PS4, C game-led, candidate index, count | 21.4 | 79069 | 1 | r:ix_releases_consoleinfo_cat cg:PRIMARY c:PRIMARY | temporary |
| Console (stress): Genre Adventure, C game-led, Added order, page 1 | 32.5 | 329269 | 50 | r:ix_releases_consoleinfo_cat cg:PRIMARY |  |
| Console (stress): Genre Adventure + game-name search (dragon), C game-led, page 1 | 226.2 | 310953 | 50 | r:ix_releases_consoleinfo_cat c:ALL cg:PRIMARY |  |
| Console (stress): Genre menu: Console genres that have a game, A to Z (Unknown listed last by the code) | 0.1 | 71 | 23 | g:ix_genres_type_disabled cg:PRIMARY | filesort(g) |
| Console (stress): Genre menu, alternative: genres with a game that has a band release | 894.7 | 1296464 | 23 | cg:ix_console_genres_console g:PRIMARY r:ix_releases_consoleinfo_id | filesort(cg), temporary |
| Console (stress): Genre menu: is Unknown needed (a band release with no game, or an Unknown game) | 0.1 | 7 | 1 | NULL:NULL cg:PRIMARY r:ix_releases_band_posted |  |

```sql
-- Console (stress): no filter, posted, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): no filter, posted, worst page (offset 281350, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 281350;
-- Console (stress): no filter, posted, last page (mirrored: oldest first, offset 0, 7 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 7;
-- Console (stress): no filter, posted, count (562757)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0;
-- Console (stress): no filter, added, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Console (stress): no filter, added, worst page
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 1000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50 offset 281350;
-- Console (stress): Category PS4 (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Category PS4 (band_cat_posted), worst page (offset 22450, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) order by r.postdate desc, r.id desc limit 50 offset 22450;
-- Console (stress): Category PS4 (band_cat_posted), last page (mirrored: oldest first, offset 0, 24 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) order by r.postdate asc, r.id asc limit 24;
-- Console (stress): Category PS4 (band_cat_posted), count (44974)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180);
-- Console (stress): Exclude Other (band index: over half the band), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Exclude Other (band index: over half the band), worst page (offset 225100, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) order by r.postdate desc, r.id desc limit 50 offset 225100;
-- Console (stress): Exclude Other (band index: over half the band), last page (mirrored: oldest first, offset 0, 1 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) order by r.postdate asc, r.id asc limit 1;
-- Console (stress): Exclude Other (band index: over half the band), count (450201)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180);
-- Console (stress): Completion 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Completion 100%, worst page (offset 270500, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 270500;
-- Console (stress): Completion 100%, last page (mirrored: oldest first, offset 0, 2 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate asc, r.id asc limit 2;
-- Console (stress): Completion 100%, count (541002)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 100;
-- Console (stress): Completion 95%+, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Completion 95%+, worst page (offset 275600, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 275600;
-- Console (stress): Completion 95%+, last page (mirrored: oldest first, offset 0, 41 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate asc, r.id asc limit 41;
-- Console (stress): Completion 95%+, count (551291)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.completion >= 95;
-- Console (stress): Category PS4 + 95%+, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Category PS4 + 95%+, worst page (offset 22000, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 22000;
-- Console (stress): Category PS4 + 95%+, last page (mirrored: oldest first, offset 0, 47 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and r.completion >= 95 order by r.postdate asc, r.id asc limit 47;
-- Console (stress): Category PS4 + 95%+, count (44097)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and r.completion >= 95;
-- Console (stress): Exclude Other + 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Exclude Other + 100%, worst page (offset 216350, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 216350;
-- Console (stress): Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 37 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) and r.completion >= 100 order by r.postdate asc, r.id asc limit 37;
-- Console (stress): Exclude Other + 100%, count (432737)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1010,1020,1030,1040,1050,1060,1070,1080,1110,1120,1130,1140,1180) and r.completion >= 100;
-- Console (stress): name search, common (1080p), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (stress): name search, common (1080p), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!';
-- Console (stress): name search, middling (2016), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (stress): name search, middling (2016), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!';
-- Console (stress): name search, nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (stress): name search, nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
-- Console (stress): name search (2016) + Category PS4, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Console (stress): name search (2016) + Category PS4, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!';
-- Console (stress): Category menu counts (valueCounts, cached 1 h)
select categories_id, resolution, source, count(*) from releases force index (ix_releases_band_count) where category_band = 1000 group by categories_id, resolution, source;
-- Console (stress): row extras for page 1 (50 ids): game, year, cover flag, genres in order (one query)
select r.id, c.title, year(c.releasedate) as year, c.cover, (select group_concat(g.title order by cg.position separator ', ') from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = c.id) as genres from releases r left join consoleinfo c on c.id = r.consoleinfo_id where r.id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941);
-- Console (stress): game-name search, common (1080p), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%1080p%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): game-name search, common (1080p), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%1080p%' escape '!'));
-- Console (stress): game-name search, middling (2016), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%2016%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): game-name search, middling (2016), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%2016%' escape '!'));
-- Console (stress): game-name search, nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%zzqqxx%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): game-name search, nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%zzqqxx%' escape '!'));
-- Console (stress): game-name search, a game word (dragon), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%dragon%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%dragon%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): game-name search, a game word (dragon), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%dragon%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%dragon%' escape '!'));
-- Console (stress): game-name search (dragon) as a LEFT JOIN (alternative), page 1
select r.id from releases r force index (ix_releases_band_posted) left join consoleinfo c on c.id = r.consoleinfo_id where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%dragon%' escape '!' or c.title like '%dragon%' escape '!') order by r.postdate desc, r.id desc limit 50;
-- Console (stress): game-name search (dragon) as a LEFT JOIN (alternative), count
select count(*) from releases r force index (ix_releases_band_posted) left join consoleinfo c on c.id = r.consoleinfo_id where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%dragon%' escape '!' or c.title like '%dragon%' escape '!');
-- Console (stress): game-name search (zzqqxx) as a LEFT JOIN (alternative), page 1
select r.id from releases r force index (ix_releases_band_posted) left join consoleinfo c on c.id = r.consoleinfo_id where r.category_band = 1000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or c.title like '%zzqqxx%' escape '!') order by r.postdate desc, r.id desc limit 50;
-- Console (stress): game-name search (dragon) + Category PS4, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%dragon%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%dragon%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): game-name search (dragon) + Category PS4, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%dragon%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%dragon%' escape '!'));
-- Console (stress): Genre Adventure (largest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170)) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure (largest), A release-led, worst page (offset 65800)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170)) order by r.postdate desc, r.id desc limit 50 offset 65800;
-- Console (stress): Genre Adventure (largest), A release-led, count (131647)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170));
-- Console (stress): Genre Adventure (largest), B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure (largest), B game-led, proposal index, worst page (offset 65800; same cost on every page)
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 65800;
-- Console (stress): Genre Adventure (largest), B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Genre Adventure (largest), C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure (largest), C game-led, candidate index, worst page (offset 65800; same cost on every page)
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 65800;
-- Console (stress): Genre Adventure (largest), C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Genre Pinball (small), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000191)) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Pinball (small), A release-led, count (4818)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000191));
-- Console (stress): Genre Pinball (small), B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Pinball (small), B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Genre Pinball (small), C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Pinball (small), C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000191)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Genre Adventure or Shooter, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000171)) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure or Shooter, A release-led, worst page (offset 97950)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000171)) order by r.postdate desc, r.id desc limit 50 offset 97950;
-- Console (stress): Genre Adventure or Shooter, A release-led, count (195983)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000171));
-- Console (stress): Genre Adventure or Shooter, B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure or Shooter, B game-led, proposal index, worst page (offset 97950; same cost on every page)
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 97950;
-- Console (stress): Genre Adventure or Shooter, B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Genre Adventure or Shooter, C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure or Shooter, C game-led, candidate index, worst page (offset 97950; same cost on every page)
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 97950;
-- Console (stress): Genre Adventure or Shooter, C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170,1000171)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Genre Unknown, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000193))) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Unknown, A release-led, worst page (offset 65350)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000193))) order by r.postdate desc, r.id desc limit 50 offset 65350;
-- Console (stress): Genre Unknown, A release-led, count (130735)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000193)));
-- Console (stress): Genre Unknown, C two parts on the candidate index (no game + Unknown games), page 1
select id from ((select r.id, r.postdate from releases r force index (ix_releases_consoleinfo_cat) where r.consoleinfo_id is null and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from console_genres cg straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = cg.consoleinfo_id where cg.genres_id = 1000193 and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Console (stress): Genre Unknown, C two parts, worst page (offset 65350)
select id from ((select r.id, r.postdate from releases r force index (ix_releases_consoleinfo_cat) where r.consoleinfo_id is null and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 65400) union all (select r.id, r.postdate from console_genres cg straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = cg.consoleinfo_id where cg.genres_id = 1000193 and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 65400)) u order by postdate desc, id desc limit 50 offset 65350;
-- Console (stress): Genre Unknown, C two parts, count
select (select count(*) from releases r force index (ix_releases_consoleinfo_cat) where r.consoleinfo_id is null and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0) + (select count(*) from console_genres cg straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = cg.consoleinfo_id where cg.genres_id = 1000193 and r.categories_id between 1000 and 1999 and r.passwordstatus <= 0);
-- Console (stress): Genre Adventure + Unknown, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000193))) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure + Unknown, A release-led, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and (r.consoleinfo_id is null or exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170,1000193)));
-- Console (stress): Year 2010s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year 2010s, A release-led, worst page (offset 112400)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) order by r.postdate desc, r.id desc limit 50 offset 112400;
-- Console (stress): Year 2010s, A release-led, count (224814)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01'));
-- Console (stress): Year 2010s, B game-led, proposal index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year 2010s, B game-led, proposal index, worst page (offset 112400; same cost on every page)
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 112400;
-- Console (stress): Year 2010s, B game-led, proposal index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Year 2010s, C game-led, candidate index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year 2010s, C game-led, candidate index, worst page (offset 112400; same cost on every page)
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 112400;
-- Console (stress): Year 2010s, C game-led, candidate index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Year 2010s, C game-led + candidate date index, page 1
select r.id from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year 2010s, C game-led + candidate date index, count
select count(*) from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Year 1990s + 2000s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year 1990s + 2000s, A release-led, count (176815)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01'));
-- Console (stress): Year 1990s + 2000s, B game-led, proposal index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year 1990s + 2000s, B game-led, proposal index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Year 1990s + 2000s, C game-led, candidate index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year 1990s + 2000s, C game-led, candidate index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1990-01-01' and c.releasedate < '2000-01-01' or c.releasedate >= '2000-01-01' and c.releasedate < '2010-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Year range 1998-2001, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year range 1998-2001, A release-led, count (30935)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01'));
-- Console (stress): Year range 1998-2001, B game-led, proposal index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year range 1998-2001, B game-led, proposal index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Year range 1998-2001, C game-led, candidate index, page 1
select r.id from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year range 1998-2001, C game-led, candidate index, count
select count(*) from (select c.id from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Year range 1998-2001, C game-led + candidate date index, page 1
select r.id from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Year range 1998-2001, C game-led + candidate date index, count
select count(*) from (select c.id from consoleinfo c force index (ix_consoleinfo_releasedate_cand) where (c.releasedate >= '1998-01-01' and c.releasedate < '2002-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0;
-- Console (stress): Genre Adventure + Year 2010s + Category PS4, A release-led (cat index), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170)) and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure + Year 2010s + Category PS4, A release-led (cat index), count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 1000 and r.passwordstatus <= 0 and r.categories_id in (1180) and exists (select 1 from console_genres cg where cg.consoleinfo_id = r.consoleinfo_id and cg.genres_id in (1000170)) and exists (select 1 from consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) where c.id = r.consoleinfo_id and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01'));
-- Console (stress): Genre Adventure + Year 2010s + Category PS4, B game-led, proposal index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1180) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure + Year 2010s + Category PS4, B game-led, proposal index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1180);
-- Console (stress): Genre Adventure + Year 2010s + Category PS4, C game-led, candidate index, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1180) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure + Year 2010s + Category PS4, C game-led, candidate index, count
select count(*) from (select distinct cg.consoleinfo_id as id from console_genres cg join consoleinfo c ignore index (ix_consoleinfo_releasedate_cand) on c.id = cg.consoleinfo_id where cg.genres_id in (1000170) and (c.releasedate >= '2010-01-01' and c.releasedate < '2020-01-01')) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and r.categories_id in (1180);
-- Console (stress): Genre Adventure, C game-led, Added order, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Console (stress): Genre Adventure + game-name search (dragon), C game-led, page 1
select r.id from (select distinct cg.consoleinfo_id as id from console_genres cg where cg.genres_id in (1000170)) g straight_join releases r force index (ix_releases_consoleinfo_cat) on r.consoleinfo_id = g.id where r.categories_id between 1000 and 1999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%dragon%' escape '!' or r.consoleinfo_id in (select c.id from consoleinfo c where c.title like '%dragon%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Console (stress): Genre menu: Console genres that have a game, A to Z (Unknown listed last by the code)
select g.id, g.title from genres g where g.type = 1000 and g.title <> 'Unknown' and exists (select 1 from console_genres cg where cg.genres_id = g.id) order by g.title;
-- Console (stress): Genre menu, alternative: genres with a game that has a band release
select g.id, g.title from genres g where g.type = 1000 and g.title <> 'Unknown' and exists (select 1 from console_genres cg join releases r force index (ix_releases_consoleinfo_id) on r.consoleinfo_id = cg.consoleinfo_id where cg.genres_id = g.id and r.categories_id between 1000 and 1999) order by g.title;
-- Console (stress): Genre menu: is Unknown needed (a band release with no game, or an Unknown game)
select exists (select 1 from releases r force index (ix_releases_band_posted) where r.category_band = 1000 and r.consoleinfo_id is null) or exists (select 1 from console_genres cg where cg.genres_id = 1000193);
```

### Console details, one release with a game (real size, `q-details-real.tsv`)

Schema `shelfc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Console (real): details 1 of 2: the release and its game (one row) | 0.1 | 2 | 1 | r:PRIMARY c:PRIMARY |  |
| Console (real): details 2 of 2: genres, developers, publishers, modes, perspectives in order (one UNION ALL) | 0.1 | 41 | 9 | cg:ix_console_genres_console g:PRIMARY cc:ix_console_companies_console co:PRIMARY cm:ix_console_game_modes_console m:PRIMARY cp:ix_console_player_perspectives_console p:PRIMARY |  |
| Console (real): details in ONE query (the row + each list as an ordered GROUP_CONCAT subquery) | 0.2 | 25 | 1 | r:PRIMARY c:PRIMARY cp:ix_console_player_perspectives_console p:PRIMARY cm:ix_console_game_modes_console m:PRIMARY cc:ix_console_companies_console co:PRIMARY cc:ix_console_companies_console co:PRIMARY cg:ix_console_genres_console g:PRIMARY |  |
| Console (real): separately, genres in order | 0.0 | 7 | 3 | cg:ix_console_genres_console g:PRIMARY | filesort(cg) |
| Console (real): separately, companies by role in order | 0.0 | 5 | 2 | cc:ix_console_companies_console co:PRIMARY |  |
| Console (real): separately, game modes in order | 0.0 | 7 | 3 | cm:ix_console_game_modes_console m:PRIMARY | filesort(cm) |
| Console (real): separately, player perspectives in order | 0.0 | 3 | 1 | cp:ix_console_player_perspectives_console p:PRIMARY | filesort(cp) |

```sql
-- Console (real): details 1 of 2: the release and its game (one row)
select r.*, c.id as game_id, c.title as game_title, c.url, c.platform, c.publisher, c.genres_id, c.esrb, c.releasedate, c.review, c.cover, c.storyline, c.critic_score, c.user_score, c.website, c.details_refreshed_at from releases r left join consoleinfo c on c.id = r.consoleinfo_id where r.id = 1908174;
-- Console (real): details 2 of 2: genres, developers, publishers, modes, perspectives in order (one UNION ALL)
select 'genre' as kind, cg.position, g.id, g.title as name from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = 11 union all select if(cc.role = 0, 'developer', 'publisher'), cc.position, co.id, co.name from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = 11 union all select 'mode', cm.position, m.id, m.name from console_game_modes cm join game_modes m on m.id = cm.game_modes_id where cm.consoleinfo_id = 11 union all select 'perspective', cp.position, p.id, p.name from console_player_perspectives cp join player_perspectives p on p.id = cp.player_perspectives_id where cp.consoleinfo_id = 11 order by kind, position;
-- Console (real): details in ONE query (the row + each list as an ordered GROUP_CONCAT subquery)
select r.*, c.id as game_id, c.title as game_title, c.url, c.platform, c.publisher, c.genres_id, c.esrb, c.releasedate, c.review, c.cover, c.storyline, c.critic_score, c.user_score, c.website, c.details_refreshed_at, (select group_concat(g.title order by cg.position separator '|') from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = c.id) as genres, (select group_concat(co.name order by cc.position separator '|') from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = c.id and cc.role = 0) as developers, (select group_concat(co.name order by cc.position separator '|') from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = c.id and cc.role = 1) as publishers, (select group_concat(m.name order by cm.position separator '|') from console_game_modes cm join game_modes m on m.id = cm.game_modes_id where cm.consoleinfo_id = c.id) as modes, (select group_concat(p.name order by cp.position separator '|') from console_player_perspectives cp join player_perspectives p on p.id = cp.player_perspectives_id where cp.consoleinfo_id = c.id) as perspectives from releases r left join consoleinfo c on c.id = r.consoleinfo_id where r.id = 1908174;
-- Console (real): separately, genres in order
select g.id, g.title from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = 11 order by cg.position;
-- Console (real): separately, companies by role in order
select cc.role, co.name from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = 11 order by cc.role, cc.position;
-- Console (real): separately, game modes in order
select m.name from console_game_modes cm join game_modes m on m.id = cm.game_modes_id where cm.consoleinfo_id = 11 order by cm.position;
-- Console (real): separately, player perspectives in order
select p.name from console_player_perspectives cp join player_perspectives p on p.id = cp.player_perspectives_id where cp.consoleinfo_id = 11 order by cp.position;
```

### Console details, one release with a game (stress size, `q-details-stress.tsv`)

Schema `shelfs`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Console (stress): details 1 of 2: the release and its game (one row) | 0.1 | 2 | 1 | r:PRIMARY c:PRIMARY |  |
| Console (stress): details 2 of 2: genres, developers, publishers, modes, perspectives in order (one UNION ALL) | 0.1 | 37 | 8 | cg:ix_console_genres_console g:PRIMARY cc:ix_console_companies_console co:PRIMARY cm:ix_console_game_modes_console m:PRIMARY cp:ix_console_player_perspectives_console p:PRIMARY |  |
| Console (stress): details in ONE query (the row + each list as an ordered GROUP_CONCAT subquery) | 0.2 | 23 | 1 | r:PRIMARY c:PRIMARY cp:ix_console_player_perspectives_console p:PRIMARY cm:ix_console_game_modes_console m:PRIMARY cc:ix_console_companies_console co:PRIMARY cc:ix_console_companies_console co:PRIMARY cg:ix_console_genres_console g:PRIMARY |  |
| Console (stress): separately, genres in order | 0.1 | 5 | 2 | cg:ix_console_genres_console g:PRIMARY | filesort(cg) |
| Console (stress): separately, companies by role in order | 0.0 | 5 | 2 | cc:ix_console_companies_console co:PRIMARY |  |
| Console (stress): separately, game modes in order | 0.0 | 7 | 3 | cm:ix_console_game_modes_console m:PRIMARY | filesort(cm) |
| Console (stress): separately, player perspectives in order | 0.0 | 3 | 1 | cp:ix_console_player_perspectives_console p:PRIMARY | filesort(cp) |

```sql
-- Console (stress): details 1 of 2: the release and its game (one row)
select r.*, c.id as game_id, c.title as game_title, c.url, c.platform, c.publisher, c.genres_id, c.esrb, c.releasedate, c.review, c.cover, c.storyline, c.critic_score, c.user_score, c.website, c.details_refreshed_at from releases r left join consoleinfo c on c.id = r.consoleinfo_id where r.id = 28;
-- Console (stress): details 2 of 2: genres, developers, publishers, modes, perspectives in order (one UNION ALL)
select 'genre' as kind, cg.position, g.id, g.title as name from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = 56295 union all select if(cc.role = 0, 'developer', 'publisher'), cc.position, co.id, co.name from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = 56295 union all select 'mode', cm.position, m.id, m.name from console_game_modes cm join game_modes m on m.id = cm.game_modes_id where cm.consoleinfo_id = 56295 union all select 'perspective', cp.position, p.id, p.name from console_player_perspectives cp join player_perspectives p on p.id = cp.player_perspectives_id where cp.consoleinfo_id = 56295 order by kind, position;
-- Console (stress): details in ONE query (the row + each list as an ordered GROUP_CONCAT subquery)
select r.*, c.id as game_id, c.title as game_title, c.url, c.platform, c.publisher, c.genres_id, c.esrb, c.releasedate, c.review, c.cover, c.storyline, c.critic_score, c.user_score, c.website, c.details_refreshed_at, (select group_concat(g.title order by cg.position separator '|') from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = c.id) as genres, (select group_concat(co.name order by cc.position separator '|') from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = c.id and cc.role = 0) as developers, (select group_concat(co.name order by cc.position separator '|') from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = c.id and cc.role = 1) as publishers, (select group_concat(m.name order by cm.position separator '|') from console_game_modes cm join game_modes m on m.id = cm.game_modes_id where cm.consoleinfo_id = c.id) as modes, (select group_concat(p.name order by cp.position separator '|') from console_player_perspectives cp join player_perspectives p on p.id = cp.player_perspectives_id where cp.consoleinfo_id = c.id) as perspectives from releases r left join consoleinfo c on c.id = r.consoleinfo_id where r.id = 28;
-- Console (stress): separately, genres in order
select g.id, g.title from console_genres cg join genres g on g.id = cg.genres_id where cg.consoleinfo_id = 56295 order by cg.position;
-- Console (stress): separately, companies by role in order
select cc.role, co.name from console_companies cc join companies co on co.id = cc.companies_id where cc.consoleinfo_id = 56295 order by cc.role, cc.position;
-- Console (stress): separately, game modes in order
select m.name from console_game_modes cm join game_modes m on m.id = cm.game_modes_id where cm.consoleinfo_id = 56295 order by cm.position;
-- Console (stress): separately, player perspectives in order
select p.name from console_player_perspectives cp join player_perspectives p on p.id = cp.player_perspectives_id where cp.consoleinfo_id = 56295 order by cp.position;
```

### Media info presence, today's `ReleaseMediaInfoAvailabilityLoader` (`q-media.tsv`, on the untouched `nntmux` tables)

Schema `nntmux`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Books page 1 (50 ids): media info presence, the loader's UNION over six tables | 0.6 | 320 | 3 | media_info_probes:media_info_probes_releases_id_captured_at_index media_info_tracks:media_info_tracks_media_info_probe_id_type_track_index_unique media_infos:ix_media_infos_releases_id video_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid release_subtitles:ix_releasesubs_releases_id_subsid release_audio_tags:release_audio_tags_releases_id_unique |  |
| Books page 1 (50 ids): media info summary, video_data | 0.1 | 50 | 3 | video_data:PRIMARY |  |
| Books page 1 (50 ids): media info summary, first audio_data row | 0.2 | 62 | 3 | audio_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid |  |
| PC page 1 (50 ids): media info presence, the loader's UNION over six tables | 0.6 | 340 | 9 | media_info_probes:media_info_probes_releases_id_captured_at_index media_info_tracks:media_info_tracks_media_info_probe_id_type_track_index_unique media_infos:ix_media_infos_releases_id video_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid release_subtitles:ix_releasesubs_releases_id_subsid release_audio_tags:release_audio_tags_releases_id_unique |  |
| PC page 1 (50 ids): media info summary, video_data | 0.1 | 50 | 9 | video_data:PRIMARY |  |
| PC page 1 (50 ids): media info summary, first audio_data row | 0.2 | 79 | 9 | audio_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid |  |
| Console (real) page 1 (all 15 visible ids): media info presence, the loader's UNION over six tables | 0.3 | 112 | 5 | media_info_probes:media_info_probes_releases_id_captured_at_index media_info_tracks:media_info_tracks_media_info_probe_id_type_track_index_unique media_infos:ix_media_infos_releases_id video_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid release_subtitles:ix_releasesubs_releases_id_subsid release_audio_tags:release_audio_tags_releases_id_unique |  |
| Console (real) page 1 (all 15 visible ids): media info summary, video_data | 0.1 | 15 | 5 | video_data:PRIMARY |  |
| Console (real) page 1 (all 15 visible ids): media info summary, first audio_data row | 0.1 | 33 | 5 | audio_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid |  |
| Console (stress) page 1 (50 ids: Movies releases): media info presence, the loader's UNION over six tables | 0.9 | 748 | 44 | media_info_probes:media_info_probes_releases_id_captured_at_index media_info_tracks:media_info_tracks_media_info_probe_id_type_track_index_unique media_infos:ix_media_infos_releases_id video_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid release_subtitles:ix_releasesubs_releases_id_subsid release_audio_tags:release_audio_tags_releases_id_unique |  |
| Console (stress) page 1 (50 ids: Movies releases): media info summary, video_data | 0.1 | 50 | 44 | video_data:PRIMARY |  |
| Console (stress) page 1 (50 ids: Movies releases): media info summary, first audio_data row | 0.2 | 214 | 44 | audio_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid |  |
| One release with media info (2315799): media info presence, the loader's UNION over six tables | 0.2 | 12 | 1 | media_info_probes:media_info_probes_releases_id_captured_at_index media_info_tracks:media_info_tracks_media_info_probe_id_type_track_index_unique media_infos:ix_media_infos_releases_id video_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid release_subtitles:ix_releasesubs_releases_id_subsid NULL:NULL |  |
| One release with media info (2315799): media info summary, video_data | 0.0 | 1 | 1 | video_data:PRIMARY |  |
| One release with media info (2315799): media info summary, first audio_data row | 0.1 | 5 | 1 | audio_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid |  |
| One release without media info (1908174): media info presence, the loader's UNION over six tables | 0.2 | 7 | 0 | media_info_probes:media_info_probes_releases_id_captured_at_index media_info_tracks:media_info_tracks_media_info_probe_id_type_track_index_unique media_infos:ix_media_infos_releases_id NULL:NULL audio_data:ix_releaseaudio_releaseid_audioid release_subtitles:ix_releasesubs_releases_id_subsid NULL:NULL |  |
| One release without media info (1908174): media info summary, video_data | 0.0 | 1 | 0 | NULL:NULL |  |
| One release without media info (1908174): media info summary, first audio_data row | 0.1 | 2 | 0 | audio_data:PRIMARY audio_data:ix_releaseaudio_releaseid_audioid |  |

```sql
-- Books page 1 (50 ids): media info presence, the loader's UNION over six tables
select releases_id from media_info_probes where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490) and (((embedded_title is not null and embedded_title != '') or (source_filename is not null and source_filename != '') or (container_format is not null and container_format != '') or (music_tags is not null and music_tags != '') or duration_ms is not null or overall_bitrate_bps is not null or exists (select 1 from media_info_tracks where media_info_tracks.media_info_probe_id = media_info_probes.id))) union select releases_id from media_infos where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490) and ((movie_name is not null and movie_name != '') or (file_name is not null and file_name != '')) union select releases_id from video_data where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490) and ((containerformat is not null and containerformat != '') or (overallbitrate is not null and overallbitrate != '') or (videoduration is not null and videoduration != '') or (videoformat is not null and videoformat != '') or (videocodec is not null and videocodec != '') or (videoaspect is not null and videoaspect != '') or (videolibrary is not null and videolibrary != '') or videowidth is not null or videoheight is not null or videoframerate is not null) union select releases_id from audio_data where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490) and ((audioformat is not null and audioformat != '') or (audiobitrate is not null and audiobitrate != '') or (audiochannels is not null and audiochannels != '') or (audiosamplerate is not null and audiosamplerate != '') or (audiolanguage is not null and audiolanguage != '') or (audiotitle is not null and audiotitle != '') or audioid is not null) union select releases_id from release_subtitles where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490) and ((subslanguage is not null and subslanguage != '') or subsid is not null) union select releases_id from release_audio_tags where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490) and ((album is not null and album != '') or (performer is not null and performer != '') or (album_performer is not null and album_performer != '') or (genre is not null and genre != '') or (recorded_date is not null and recorded_date != '') or (track_name is not null and track_name != '') or (musicbrainz_album_id is not null and musicbrainz_album_id != '') or (musicbrainz_track_id is not null and musicbrainz_track_id != '') or (audio_format is not null and audio_format != '') or track_position is not null or track_position_total is not null);
-- Books page 1 (50 ids): media info summary, video_data
select releases_id, videoheight, videocodec, videoformat from video_data where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490);
-- Books page 1 (50 ids): media info summary, first audio_data row
select releases_id, audioformat, audiochannels from audio_data where id in (select min(id) from audio_data where releases_id in (3018168,3009974,3009897,3006936,3003984,3003980,3003981,3003979,3003983,3003982,2987389,2987388,2983526,2983390,2985174,2917560,2878238,2853580,2779738,2779727,2779725,2632770,2764205,2625142,2607137,2607136,2607135,2607133,2607134,2607130,2607128,2607126,2607123,2607124,2580645,2575972,2577295,2577294,2575860,2575364,2575363,2621692,2540680,2490973,2559495,2315797,2559493,2559492,2559491,2559490) group by releases_id);
-- PC page 1 (50 ids): media info presence, the loader's UNION over six tables
select releases_id from media_info_probes where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154) and (((embedded_title is not null and embedded_title != '') or (source_filename is not null and source_filename != '') or (container_format is not null and container_format != '') or (music_tags is not null and music_tags != '') or duration_ms is not null or overall_bitrate_bps is not null or exists (select 1 from media_info_tracks where media_info_tracks.media_info_probe_id = media_info_probes.id))) union select releases_id from media_infos where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154) and ((movie_name is not null and movie_name != '') or (file_name is not null and file_name != '')) union select releases_id from video_data where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154) and ((containerformat is not null and containerformat != '') or (overallbitrate is not null and overallbitrate != '') or (videoduration is not null and videoduration != '') or (videoformat is not null and videoformat != '') or (videocodec is not null and videocodec != '') or (videoaspect is not null and videoaspect != '') or (videolibrary is not null and videolibrary != '') or videowidth is not null or videoheight is not null or videoframerate is not null) union select releases_id from audio_data where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154) and ((audioformat is not null and audioformat != '') or (audiobitrate is not null and audiobitrate != '') or (audiochannels is not null and audiochannels != '') or (audiosamplerate is not null and audiosamplerate != '') or (audiolanguage is not null and audiolanguage != '') or (audiotitle is not null and audiotitle != '') or audioid is not null) union select releases_id from release_subtitles where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154) and ((subslanguage is not null and subslanguage != '') or subsid is not null) union select releases_id from release_audio_tags where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154) and ((album is not null and album != '') or (performer is not null and performer != '') or (album_performer is not null and album_performer != '') or (genre is not null and genre != '') or (recorded_date is not null and recorded_date != '') or (track_name is not null and track_name != '') or (musicbrainz_album_id is not null and musicbrainz_album_id != '') or (musicbrainz_track_id is not null and musicbrainz_track_id != '') or (audio_format is not null and audio_format != '') or track_position is not null or track_position_total is not null);
-- PC page 1 (50 ids): media info summary, video_data
select releases_id, videoheight, videocodec, videoformat from video_data where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154);
-- PC page 1 (50 ids): media info summary, first audio_data row
select releases_id, audioformat, audiochannels from audio_data where id in (select min(id) from audio_data where releases_id in (3011221,3017645,3001213,3000149,2998961,2998739,3002865,2998323,2997319,2996722,3002241,2994533,3000506,2994099,2994235,2994234,2994231,2987589,2979899,2986303,2986291,2986276,2977863,2969546,2960323,2960183,2959602,2959585,2957879,2957871,2917482,2923237,2913619,2913597,2913584,2913580,2904155,2876355,2876235,2875794,2826388,2842488,2823845,2803000,2688263,2576719,2577764,2575971,2575963,2572154) group by releases_id);
-- Console (real) page 1 (all 15 visible ids): media info presence, the loader's UNION over six tables
select releases_id from media_info_probes where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337) and (((embedded_title is not null and embedded_title != '') or (source_filename is not null and source_filename != '') or (container_format is not null and container_format != '') or (music_tags is not null and music_tags != '') or duration_ms is not null or overall_bitrate_bps is not null or exists (select 1 from media_info_tracks where media_info_tracks.media_info_probe_id = media_info_probes.id))) union select releases_id from media_infos where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337) and ((movie_name is not null and movie_name != '') or (file_name is not null and file_name != '')) union select releases_id from video_data where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337) and ((containerformat is not null and containerformat != '') or (overallbitrate is not null and overallbitrate != '') or (videoduration is not null and videoduration != '') or (videoformat is not null and videoformat != '') or (videocodec is not null and videocodec != '') or (videoaspect is not null and videoaspect != '') or (videolibrary is not null and videolibrary != '') or videowidth is not null or videoheight is not null or videoframerate is not null) union select releases_id from audio_data where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337) and ((audioformat is not null and audioformat != '') or (audiobitrate is not null and audiobitrate != '') or (audiochannels is not null and audiochannels != '') or (audiosamplerate is not null and audiosamplerate != '') or (audiolanguage is not null and audiolanguage != '') or (audiotitle is not null and audiotitle != '') or audioid is not null) union select releases_id from release_subtitles where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337) and ((subslanguage is not null and subslanguage != '') or subsid is not null) union select releases_id from release_audio_tags where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337) and ((album is not null and album != '') or (performer is not null and performer != '') or (album_performer is not null and album_performer != '') or (genre is not null and genre != '') or (recorded_date is not null and recorded_date != '') or (track_name is not null and track_name != '') or (musicbrainz_album_id is not null and musicbrainz_album_id != '') or (musicbrainz_track_id is not null and musicbrainz_track_id != '') or (audio_format is not null and audio_format != '') or track_position is not null or track_position_total is not null);
-- Console (real) page 1 (all 15 visible ids): media info summary, video_data
select releases_id, videoheight, videocodec, videoformat from video_data where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337);
-- Console (real) page 1 (all 15 visible ids): media info summary, first audio_data row
select releases_id, audioformat, audiochannels from audio_data where id in (select min(id) from audio_data where releases_id in (2969628,2205791,1290822,1288443,1280750,189846,568422,581064,665556,143344,187861,248812,251974,339384,307337) group by releases_id);
-- Console (stress) page 1 (50 ids: Movies releases): media info presence, the loader's UNION over six tables
select releases_id from media_info_probes where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) and (((embedded_title is not null and embedded_title != '') or (source_filename is not null and source_filename != '') or (container_format is not null and container_format != '') or (music_tags is not null and music_tags != '') or duration_ms is not null or overall_bitrate_bps is not null or exists (select 1 from media_info_tracks where media_info_tracks.media_info_probe_id = media_info_probes.id))) union select releases_id from media_infos where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) and ((movie_name is not null and movie_name != '') or (file_name is not null and file_name != '')) union select releases_id from video_data where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) and ((containerformat is not null and containerformat != '') or (overallbitrate is not null and overallbitrate != '') or (videoduration is not null and videoduration != '') or (videoformat is not null and videoformat != '') or (videocodec is not null and videocodec != '') or (videoaspect is not null and videoaspect != '') or (videolibrary is not null and videolibrary != '') or videowidth is not null or videoheight is not null or videoframerate is not null) union select releases_id from audio_data where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) and ((audioformat is not null and audioformat != '') or (audiobitrate is not null and audiobitrate != '') or (audiochannels is not null and audiochannels != '') or (audiosamplerate is not null and audiosamplerate != '') or (audiolanguage is not null and audiolanguage != '') or (audiotitle is not null and audiotitle != '') or audioid is not null) union select releases_id from release_subtitles where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) and ((subslanguage is not null and subslanguage != '') or subsid is not null) union select releases_id from release_audio_tags where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) and ((album is not null and album != '') or (performer is not null and performer != '') or (album_performer is not null and album_performer != '') or (genre is not null and genre != '') or (recorded_date is not null and recorded_date != '') or (track_name is not null and track_name != '') or (musicbrainz_album_id is not null and musicbrainz_album_id != '') or (musicbrainz_track_id is not null and musicbrainz_track_id != '') or (audio_format is not null and audio_format != '') or track_position is not null or track_position_total is not null);
-- Console (stress) page 1 (50 ids: Movies releases): media info summary, video_data
select releases_id, videoheight, videocodec, videoformat from video_data where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941);
-- Console (stress) page 1 (50 ids: Movies releases): media info summary, first audio_data row
select releases_id, audioformat, audiochannels from audio_data where id in (select min(id) from audio_data where releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) group by releases_id);
-- One release with media info (2315799): media info presence, the loader's UNION over six tables
select releases_id from media_info_probes where releases_id in (2315799) and (((embedded_title is not null and embedded_title != '') or (source_filename is not null and source_filename != '') or (container_format is not null and container_format != '') or (music_tags is not null and music_tags != '') or duration_ms is not null or overall_bitrate_bps is not null or exists (select 1 from media_info_tracks where media_info_tracks.media_info_probe_id = media_info_probes.id))) union select releases_id from media_infos where releases_id in (2315799) and ((movie_name is not null and movie_name != '') or (file_name is not null and file_name != '')) union select releases_id from video_data where releases_id in (2315799) and ((containerformat is not null and containerformat != '') or (overallbitrate is not null and overallbitrate != '') or (videoduration is not null and videoduration != '') or (videoformat is not null and videoformat != '') or (videocodec is not null and videocodec != '') or (videoaspect is not null and videoaspect != '') or (videolibrary is not null and videolibrary != '') or videowidth is not null or videoheight is not null or videoframerate is not null) union select releases_id from audio_data where releases_id in (2315799) and ((audioformat is not null and audioformat != '') or (audiobitrate is not null and audiobitrate != '') or (audiochannels is not null and audiochannels != '') or (audiosamplerate is not null and audiosamplerate != '') or (audiolanguage is not null and audiolanguage != '') or (audiotitle is not null and audiotitle != '') or audioid is not null) union select releases_id from release_subtitles where releases_id in (2315799) and ((subslanguage is not null and subslanguage != '') or subsid is not null) union select releases_id from release_audio_tags where releases_id in (2315799) and ((album is not null and album != '') or (performer is not null and performer != '') or (album_performer is not null and album_performer != '') or (genre is not null and genre != '') or (recorded_date is not null and recorded_date != '') or (track_name is not null and track_name != '') or (musicbrainz_album_id is not null and musicbrainz_album_id != '') or (musicbrainz_track_id is not null and musicbrainz_track_id != '') or (audio_format is not null and audio_format != '') or track_position is not null or track_position_total is not null);
-- One release with media info (2315799): media info summary, video_data
select releases_id, videoheight, videocodec, videoformat from video_data where releases_id in (2315799);
-- One release with media info (2315799): media info summary, first audio_data row
select releases_id, audioformat, audiochannels from audio_data where id in (select min(id) from audio_data where releases_id in (2315799) group by releases_id);
-- One release without media info (1908174): media info presence, the loader's UNION over six tables
select releases_id from media_info_probes where releases_id in (1908174) and (((embedded_title is not null and embedded_title != '') or (source_filename is not null and source_filename != '') or (container_format is not null and container_format != '') or (music_tags is not null and music_tags != '') or duration_ms is not null or overall_bitrate_bps is not null or exists (select 1 from media_info_tracks where media_info_tracks.media_info_probe_id = media_info_probes.id))) union select releases_id from media_infos where releases_id in (1908174) and ((movie_name is not null and movie_name != '') or (file_name is not null and file_name != '')) union select releases_id from video_data where releases_id in (1908174) and ((containerformat is not null and containerformat != '') or (overallbitrate is not null and overallbitrate != '') or (videoduration is not null and videoduration != '') or (videoformat is not null and videoformat != '') or (videocodec is not null and videocodec != '') or (videoaspect is not null and videoaspect != '') or (videolibrary is not null and videolibrary != '') or videowidth is not null or videoheight is not null or videoframerate is not null) union select releases_id from audio_data where releases_id in (1908174) and ((audioformat is not null and audioformat != '') or (audiobitrate is not null and audiobitrate != '') or (audiochannels is not null and audiochannels != '') or (audiosamplerate is not null and audiosamplerate != '') or (audiolanguage is not null and audiolanguage != '') or (audiotitle is not null and audiotitle != '') or audioid is not null) union select releases_id from release_subtitles where releases_id in (1908174) and ((subslanguage is not null and subslanguage != '') or subsid is not null) union select releases_id from release_audio_tags where releases_id in (1908174) and ((album is not null and album != '') or (performer is not null and performer != '') or (album_performer is not null and album_performer != '') or (genre is not null and genre != '') or (recorded_date is not null and recorded_date != '') or (track_name is not null and track_name != '') or (musicbrainz_album_id is not null and musicbrainz_album_id != '') or (musicbrainz_track_id is not null and musicbrainz_track_id != '') or (audio_format is not null and audio_format != '') or track_position is not null or track_position_total is not null);
-- One release without media info (1908174): media info summary, video_data
select releases_id, videoheight, videocodec, videoformat from video_data where releases_id in (1908174);
-- One release without media info (1908174): media info summary, first audio_data row
select releases_id, audioformat, audiochannels from audio_data where id in (select min(id) from audio_data where releases_id in (1908174) group by releases_id);
```

## Added 2026-10-01 (after the run): "All N releases of this game"

The Console release page's list of every release of its game (`SPEC.md` 5B), on `shelfs` with
`ix_releases_consoleinfo_cat`, for the stress game with the most releases (`consoleinfo_id` 66656, 20 releases, 18
visible). `q-game-releases.tsv`, `results-game-releases.md`.

| Query | ms | rows read | rows returned | plan |
|---|---:|---:|---:|---|
| page 1, posted newest | 0.1 | 39 | 18 | `ix_releases_consoleinfo_cat`, filesort of the game's rows |
| count | 0.1 | 21 | 1 | `ix_releases_consoleinfo_cat` |
| page 1, sorted by size | 0.1 | 39 | 18 | `ix_releases_consoleinfo_id`, filesort of the game's rows |
