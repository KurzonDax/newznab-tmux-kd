# Audio data contract: measured reads (2026-10-04)

What the approved Audio screens read (the `/audio` list with its filters and name search, the row extras of a page,
the Genre menu, the release page's tag row, track list, MusicBrainz release group and "All N releases of this album"),
measured on the query lab against the restored production catalogue of 2026-09-20, with the proposed
`release_audio_genres` table and two candidate indexes. Queries mirror master `f8365df5f`:
`app/Services/Releases/BandReleaseList.php`, `ShelfReleaseList.php` and `ConsoleReleaseList.php`. Nothing ran against
production; the `nntmux` schema was only read.

## Method

As the Books / Console / PC contract (`experiments/shelf-data-contract/`): each query ran three times warm in one
session (best time kept, from `SHOW PROFILES`), then once after `FLUSH STATUS` to read the `Handler_read*` counters
("rows read"). `bench.py` (the shelf one) also runs `EXPLAIN` and records each table's index ("index used") and any
filesort, temporary table, full table scan ("FULL SCAN of t") or full index scan; here a scan of ANY table is flagged,
not only of `releases`. `gen-queries.py` writes the `q-*.tsv` files with literal values (counts, page offsets, genre
ids, the 50 ids of a page, the details release and its album), as the application would send them. Lab milliseconds are
relative (Apple Silicon, 4 GiB buffer pool, MariaDB 11.4.13); rows read and plans transfer.

Release-led reads are written as `BandReleaseList` builds them: the index forced as `releaseIndex()` chooses it (a
`_cat_` index when the chosen sub-categories hold at most half the band), the default password condition
`passwordstatus <= 0`, no excluded categories, 50 a page, pages past the middle read mirrored from the other end (so the
worst page is the last one before the middle). Tag-led reads are written as `ConsoleReleaseList` builds its game-led
read: a derived table `g` of the matching release ids `STRAIGHT_JOIN releases` (here on `PRIMARY`, since the tag
tables are keyed by `releases_id`), the band as `categories_id BETWEEN 3000 AND 3999`, sorted by the date; the same
cost on every page, so never mirrored. The name search is today's `COALESCE(NULLIF(TRIM(display_name), ''),
searchname) LIKE '%word%' ESCAPE '!'`, OR the tag's `album`, `album_performer` or `performer` `LIKE '%word%'`.

Shapes, per filter:

- **A, release-led**: the band (or `_cat_`) index in date order; the genre / year test as `EXISTS` per release (an
  index probe each).
- **B, tag-led on existing indexes**: the matching release ids from `release_audio_genres` (its PK is `(genres_id,
  releases_id)`, so one genre is one range) or from `release_audio_tags` (no year index: a scan of every tag row).
- **C, tag-led on the candidate year index** `ix_release_audio_tags_year_cand (recorded_year, releases_id)`.

## Schemas

- **`audc`, real size** (`01-build.sql`, then `02-audio-storage.sql` + `03-fill-genres.sql`): every release of the
  catalogue (2,359,525) built exactly as the shelf contract's `shelfc` (the #773 resolution / source, the generated
  `category_band`, production's list indexes `ix_releases_band_{posted,added,count}` and the #827 `_cat_` / `_res_` /
  `_src_` indexes), plus `genres`, `release_audio_tags`, `release_audio_evidence`, `release_audio_evidence_tracks` and
  `release_music_identifications` copied as the catalogue holds them (structure from `CREATE TABLE ... LIKE`).
  Audio band: **3,645 releases, 3,438 visible** (MP3 634, Video 1,669, Lossless 366, Podcast 4, Foreign 377, Other
  595; no Audiobook). **548 tag rows** (539 on Audio releases, all visible; 6 on Misc, 3 on PC), 2,983 evidence
  revisions with 17,833 tracks, 2,924 identifications (18 `accepted_release_group`, 4 `accepted_edition`, all one
  algorithm version), 169 genres (all type 3000).
- **`auds`, stress** (`04-stress.sql`, `05-stress-tags.py`, `06-stress-evidence.sql`, then `02` + `03`): the same
  releases with every Movies-band release (575,109) moved into an Audio sub-category by a hash of its id (MP3 25%,
  Lossless 25%, Video 20%, Foreign 10%, Other 15%, Audiobook 3%, Podcast 2%): **578,754 Audio releases, 566,180
  visible** (MP3 144,124, Video 116,834, Audiobook 17,290, Lossless 144,458, Podcast 11,652, Foreign 57,830, Other
  86,566). Release names, dates, completion and password status stay the Movies releases' own.

**Every tag, evidence and identification value in `auds` is synthetic** (seeded, reproducible; only the releases are
real; the real tag rows are not carried over): **231,757 tag rows** (40% of the band, by a hash of the id) cut into
148,853 albums (1.56 releases per album; 70.9% single, the biggest 30-31 releases), artists from a pool of 25,000
(weight 1/rank^0.5; 3% of albums "Various Artists"), 3% of albums under a common name shared across artists
("Unplugged" ends up on 725 tag rows), 5% of sibling rows lower-cased; `recorded_year` with the real decade mix (2020s
69,949, none 32,590, 1940s 408); genre strings drawn from the real distribution (10% `A; B`, 12% NULL, 1% `Unknown`);
`has_preview` on 60%. **324,091 evidence revisions** (all tagged releases revision 1, 40% also revision 2), **4,533,066
tracks** (8-20 per revision), **17,700 identifications** (5% of tagged releases, half of those accepted with a release
group id shared by the album; plus an older-evidence row where there are two revisions and a second algorithm version
for 10%).

## The proposed storage (`02-audio-storage.sql`)

```sql
CREATE TABLE `release_audio_genres` (
  `releases_id` int(10) unsigned NOT NULL,
  `genres_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL COMMENT '0-based order of the genre in the tag value',
  PRIMARY KEY (`genres_id`,`releases_id`),
  KEY `ix_release_audio_genres_release` (`releases_id`,`position`),
  CONSTRAINT `fk_release_audio_genres_genres_id` FOREIGN KEY (`genres_id`) REFERENCES `genres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_release_audio_genres_releases_id` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
```

Styled as `release_audio_languages` and `console_genres`. `genres_id` is `int(10) unsigned`, not `int`: `genres.id` is
unsigned and a foreign key needs the same type (`console_genres` has the same). The genres are the existing `genres`
rows of type 3000 (`GenreService::MUSIC_TYPE = Category::MUSIC_ROOT`).

**"Unknown" stores nothing** (no row). Then Unknown on the list is one test, "the release has no
`release_audio_genres` row", which covers a release with no tag row, a tag row with no genre and a genre of only
"Unknown" alike; no special genre id has to be looked up, left out of the menu or kept out of other genre reads, and a
value such as `Rock; Unknown` is simply Rock. Linking it instead would make Unknown "no row OR the Unknown row" (two
tests in every Unknown read) and put a non-genre into every genre read.

**The fill** (`03-fill-genres.sql`) is two `INSERT ... SELECT`: the names the tags use that `genres` lacks (split on `;`,
trimmed, empty and `Unknown` parts dropped, one row per name case-insensitively), then the links (a name matched to the
lowest type-3000 id of that title, a name repeated in one value kept once, positions renumbered 0, 1, 2 over the kept
parts, `ROW_NUMBER()`). The join order must be forced (`STRAIGHT_JOIN`: tags, then the split on MariaDB's
`seq_1_to_101`, then the genre by name); left to the optimiser it built the cross product genres x seq x tags in a join
buffer, 2 s for the lab's 548 tag rows.

| Fill | Tag rows | New genres | Links | Seconds |
|---|---:|---:|---:|---:|
| real (`audc`) | 548 | 48 (0.00 s) | 422 (416 on Audio releases) | 0.01 |
| stress (`auds`), two runs | 231,757 | 48 (0.23 s) | 225,247 (201,988 releases; positions 0-1) | **5.58 / 5.67** |

No real tag genre holds a `;`; 12 of the 548 tag rows hold a comma and 6 a slash (`Synth-pop, Disco`, `Pop/Rock`,
`Rock / Folk Rock / Psychedelic Rock / Classic Rock`), so under the `;` rule each of those is one genre. The 48 new
genre rows the real fill made include `Hip Hop` beside the existing `Hip-Hop`, `alt metal` beside `Alt. Metal`,
`www.mp3-ogg.ru` and one base64 string (`results-fill-real.txt`; the full list is `select title from audc.genres where
id > 1000169`).

## Candidate indexes (`07-candidate-indexes.sql`, on both schemas; every query names its index)

- `ix_release_audio_tags_year_cand (recorded_year, releases_id)`: the Year filter read tag-first.
- `ix_release_audio_tags_album_artist_cand (album, album_performer, performer)`: "All releases of this album", against
  production's `release_audio_tags_album_index (album)`.

## Summary

**Real size: nothing is over 6.7 ms.** Every list, filter, menu and details read on `audc` is 0.0-6.7 ms; the slowest
are the name searches (3.8-6.7 ms: today's release-name search reads the band's 3,646 rows, the tag side adds one scan
of the 548 tag rows). No sort or scan of more than the band.

**Stress size**, the worst cases of the shapes recommended below (ms best of 3 warm / rows read):

| Read | Recommended shape | Page 1 | Worst page | Count |
|---|---|---:|---:|---:|
| list, no filter / Category / Completion / Exclude Other | today's `BandReleaseList` | 0.1 | 41-48 / ~285,000 | 53-62 / 578,755 |
| several sub-categories on a `_cat_` index (MP3 + Lossless) | today's | 40.6 / 288,634 | 59.3 | 58.2 |
| name + tag search (no other filter) | release-led, tag match as an `IN` list | 70-91 (522 when nothing matches) / ~232,000-810,000 | | 488-532 / 810,713-1,389,467 |
| Genre, one (Rock, the largest: 41,836) | B tag-led | 37.1 / 127,294 | 37.1 | 34.3 / 85,407 |
| Genre, two (Rock or Metal: 66,597) | B tag-led, `DISTINCT` | 65.7 / 427,919 | 66.5 | 61.6 |
| Genre Unknown alone (368,523, 65% of the band) | A release-led, `LEFT JOIN ... IS NULL` | 0.1 / 148 | 130.8 / 568,407 (last page 0.1) | 241.3 / 1,144,935 (`NOT EXISTS`: 148.3) |
| Genre Rock + Unknown (410,359) | A release-led | 5.2-36.9 | 141.9-151.8 | 200.8-280.4 |
| Year, a decade (2020s: 68,478) | C tag-led, year index | 67.1 / 208,428 | 69.4 | 69.8 / 139,899 |
| Year without the year index | B tag-led (scans 231,757 tag rows) | 99.2 / 370,236 | 102.4 | 97.4 |
| Genre + Year (Rock + 2020s: 12,469) | B genre first | 59.8 / 110,632 | same | 59.5 |
| Unknown + Year (2020s: 9,064) | C year first, `LEFT JOIN` anti-join | 37.8 / 158,292 | same | 39.2 |
| Everything: Lossless + 95%+ + Rock + 2020s + tag search (27) | B genre first, tag row joined, search on its columns | 60.8 / 98,167 | same | 59.6 |
| Genre Rock + tag search | B genre first, tag row joined | 97.8 / 128,620 | same | 96.6 |
| Year 2020s + tag search | C year first, tag row joined | 158.3 / 140,664 | same | 156.2 |
| row extras for a page of 50 (tag fields + genres in order) | one query | 0.2-0.3 / ≤224 | | |
| Genre menu (103 genres) | loose index scan of the PK | 0.2 / 518 | | |
| Genre menu, "is Unknown needed" | `LEFT JOIN ... IS NULL LIMIT 1` | 0.1 / 4 | | |
| details: tag row + genres, newest evidence, track list, release group | four single-row / one-range reads | 0.0-0.1 each | | |
| All releases of this album (31 releases; a name on 725 rows) | existing album index, band 3000 only | 0.1-0.5 / ≤772 | | 0.1-0.6 |

Counts are cached (10 minutes) and the Category menu counts (89.8 ms, 1,157,860 rows) for an hour, as today. No read
scans `releases`; the only full table scan left in the recommended shapes is the tag-search `IN` list's one pass over
`release_audio_tags` (231,757 rows, ~70 ms of `LIKE` work), which every tag search needs while the match is `%word%`.

## Conclusions

1. **The list without tag filters is today's `ShelfReleaseList`** for band 3000, unchanged: page 1 0.1 ms, the
   middle page 41-48 ms and counts 53-62 ms at 578,754 releases (Console's stress figures). Ticking two sub-categories
   that together hold at most half the band (MP3 + Lossless, Audiobook + Podcast) reads the `_cat_` index as two ranges
   and sorts them: 4.5-40.6 ms on page 1, 13.6-59.3 ms on the worst page. That is today's `releaseIndex()` behaviour
   for every section, not something Audio adds.
2. **Name search: release name OR the tag's album / album_performer / performer as an `IN (SELECT releases_id FROM
   release_audio_tags WHERE ... LIKE ...)` list**, as Console's game-name search. `EXISTS` gets the identical plan
   (MariaDB reads the tag matches once, a scan of every tag row); the `LEFT JOIN ... OR` form is rejected (878-915 ms
   for a count or a word nothing contains, against 488-532). The tag side adds about 85-110 ms to today's 395-412 ms
   release-name count at stress (1080p 410 -> 493, 2016 412 -> 520, zzqqxx 395 -> 488). A covering index `(album, album_performer, performer, releases_id)` for that scan was
   tried and dropped: 68 against 71 ms, the time is the `LIKE` work, not the reading.
3. **Genre: tag-led (B), as Console**: the genre's PK range `STRAIGHT_JOIN releases` on `PRIMARY`, the same cost on
   every page, bounded by the genre's size (37 ms for the largest, 0.4 ms for a small one, 66 ms for two). Release-led
   (A) is fast on page 1 of a big genre (0.5 ms) but 28.7 ms for a small one, 135 ms on the middle page and 249 ms
   for a count. Two genres: `DISTINCT`, `GROUP BY` and `UNION` all measure 58-71 ms; keep `DISTINCT`.
4. **Unknown: release-led, as the Audio-language Unknown (`BandReleaseList::whereAudio()`): `LEFT JOIN
   release_audio_genres ... WHERE releases_id IS NULL`** on the band index. Unknown is most of the band (65% at stress,
   88% of the real visible band: 3,022 of 3,438), so the band index finds a page at once (0.1 ms page 1 and last page,
   131 ms middle page). `NOT EXISTS` is materialised by MariaDB (a copy of the whole table first: 28 ms page 1) but
   counts faster (148 against 241 ms); either is fine for the cached count. The two-part `UNION ALL` the Console list
   uses ("no tag row" + "tag rows with no genre") is not needed with nothing stored for Unknown and is slower (137 ms
   page 1, 274 ms middle page, 279 ms count): rejected. **Genre + Unknown** (e.g. Rock + Unknown, 71% of the band):
   release-led too, `(EXISTS genre OR no genre row)`, with the no-genre test as a `LEFT JOIN` on the release's
   `position = 0` row (every release with a genre has exactly one, so the join never repeats a release): 5.2 ms page 1,
   152 ms middle page, 280 ms count (the `EXISTS / NOT EXISTS` form: 37 / 142 / 201 ms; two parts merged: 37 / 229 ms).
   With a Year, Unknown is tag-led (below).
5. **Year: tag-led on the year index (C)**: 67-70 ms for the largest decade, 36-39 ms for two decades, 10-12 ms for a
   four-year range, 0.4 ms for the 1940s. Without the index (B) every Year read first scans all tag rows: 97-102,
   60-67, 33-35 and 19-20 ms. Release-led (A) counts read the whole band with a tag probe each: 410-421 ms. **The year
   index is worth adding** (4.5 MB at stress; it removes the ~20 ms floor and up to a third of the larger reads) but
   not required: without it the worst Year read is 102 ms. Most of what remains is the per-release lookup into
   `releases` and the sort of every match (68,478 for the 2020s), the accepted cost of a title-led read.
6. **Genre + Year: genre first** (the genre's range, the tag row by `releases_id`, the year tested on it): 60 ms, against
   99-103 ms year first. **Unknown + Year: year first** on the year index with the `LEFT JOIN` anti-join: 38-39 ms (60-62
   ms without the index; `NOT EXISTS` 99-131 ms).
7. **The search combined with a tag-led filter reads the joined tag row's columns** instead of the second scan the `IN`
   list makes: everything ticked (Lossless + 95%+ + Rock + 2020s + a tag word) 60.8 ms genre first with the tag row
   joined, against 166-181 ms year first and 211-217 ms release-led; Rock + a tag word 98 against 126 ms.
8. **Row extras: one query per page**: the 50 ids' tag rows (artist = `COALESCE(album_performer, performer)`, album,
   recorded_year, has_preview and the preview fields) with the genres in order as a correlated `GROUP_CONCAT` over
   `ix_release_audio_genres_release`: 0.2-0.3 ms at stress (the two-query form is 0.1 + 0.1 ms).
9. **Genre menu: `SELECT DISTINCT genres_id FROM release_audio_genres` (a loose index scan of the PK, one probe per
   genre) joined to `genres` of type 3000, A to Z, then Unknown**: 0.2 ms. The `EXISTS` form is materialised by MariaDB
   (24 ms, a pass over all 225,247 links), because the `type` index is not selective. Restricting the menu to genres
   with an Audio-band release costs 0.6 ms as one `LIMIT 1` probe per genre (130 ms as `EXISTS`); on the real data it
   drops one of the 103 genres (only on a non-Audio release), at stress none. "Is Unknown needed" as a `LEFT JOIN ... IS NULL LIMIT 1`: 0.1 ms
   (`NOT EXISTS`: 28 ms).
10. **Details page: flat.** The tag row with its genres in order 0.1 ms; the newest evidence revision (`ORDER BY
    revision DESC LIMIT 1` on the `(releases_id, revision)` unique key) 0.0 ms; its tracks by
    `release_audio_evidence_id` in `source_kind, source_ordinal` order on `audio_evidence_track_source`, no sort,
    0.1 ms (also 0.1 ms as one query with the newest evidence as a subquery); the release group (`releases_id`,
    the newest evidence's `evidence_hash`, accepted state, group id not null, newest row by id) on the
    `release_music_identity_version` unique key 0.1 ms, sorting at most the two algorithm versions (0.1 ms as one query
    joined to the newest evidence).
11. **All releases of this album: the existing `release_audio_tags_album_index` is enough; the album + artist candidate
    is not needed.** `album = ? AND COALESCE(album_performer, performer) = ?` (the column collation makes both
    case-insensitive: the lower-cased stress siblings match) `STRAIGHT_JOIN releases`, newest posted first: 0.1 ms
    for the biggest stress album (31 rows), 0.5-0.7 ms when the album name is on 725 rows of other artists (741-772 rows
    read), 0.1 ms on the candidate. **Band 3000 only and any band cost the same; band 3000 only matches Console.** On the
    real data it hides releases: the biggest real album (Album A / Artist A, 4 tag rows) has 2 of its 4
    releases in Misc (category 10), so band 3000 only lists 2.

**Proposed DDL**: `release_audio_genres` as above, and (recommended, optional)

```sql
ALTER TABLE `release_audio_tags` ADD KEY `ix_release_audio_tags_recorded_year` (`recorded_year`, `releases_id`);
```

No album index.

## Storage at stress size (`08-sizes.sql`, `results-sizes.txt`; after `OPTIMIZE`)

| Table / index | Rows | MB |
|---|---:|---:|
| `release_audio_genres` (PK 7.52 + `ix_release_audio_genres_release` 4.52) | 225,247 | **12.03** |
| `release_audio_tags` (data 46.56 + every index 35.64) | 231,757 | 82.20 |
| candidate `ix_release_audio_tags_year_cand` | | **4.52** |
| candidate `ix_release_audio_tags_album_artist_cand` | | 15.58 (the existing `release_audio_tags_album_index`: 8.52) |
| `release_audio_evidence` (synthetic, near-empty manifests) | 324,091 | 84.28 |
| `release_audio_evidence_tracks` (synthetic) | 4,533,066 | 1,458.89 |
| `release_music_identifications` (synthetic) | 17,700 | 9.25 |

`information_schema.table_rows` is InnoDB's estimate; the row counts are exact. The evidence and track sizes are of
synthetic rows (short JSON, no raw tags) and only show that those tables are big next to the new one; nothing proposed
changes them.

## Files

`01-build.sql` (`audc`), `02-audio-storage.sql` (the proposed DDL), `03-fill-genres.sql` (the timed fill),
`04-stress.sql` (`auds`), `05-stress-tags.py` and `06-stress-evidence.sql` (synthetic stress values),
`07-candidate-indexes.sql`, `08-sizes.sql`, `bench.py`, `gen-queries.py`; query files `q-list-*`, `q-filters-*`,
`q-variants-*` (the second pass: the plan fixes for the menu, Unknown and two genres, and the genre-first reads with the
tag row joined), `q-details-*`; results `results-*.md` (each table followed by its SQL), `results-fill-*.txt`,
`results-stress-evidence.txt`, `results-sizes.txt`.

The scratch schemas `audc` and `auds` are **kept** so the contract can be re-run (`gen-queries.py`, then `bench.py
<schema> q-….tsv results-….md`); drop them with `drop database audc; drop database auds;`.

## Every measured query

Each table is the bench output; the SQL of every row follows its table.

### Audio list and name search (real size, `q-list-real.tsv`)

Schema `audc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (real): no filter, posted newest, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_posted |  |
| Audio (real): no filter, posted newest, worst page (offset 1700, the last before mirroring) | 0.4 | 1911 | 50 | r:ix_releases_band_posted |  |
| Audio (real): no filter, posted newest, last page (mirrored: oldest first, offset 0, 38 rows) | 0.1 | 38 | 38 | r:ix_releases_band_posted |  |
| Audio (real): no filter, posted newest, count (3438) | 0.4 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): no filter, posted oldest, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_posted |  |
| Audio (real): no filter, added newest, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_added |  |
| Audio (real): no filter, added newest, worst page | 0.3 | 1851 | 50 | r:ix_releases_band_added |  |
| Audio (real): no filter, added oldest, page 1 | 0.1 | 51 | 50 | r:ix_releases_band_added |  |
| Audio (real): Category Lossless (band_cat_posted), page 1 | 0.1 | 50 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Lossless (band_cat_posted), worst page (offset 150, the last before mirroring) | 0.1 | 203 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 10 rows) | 0.1 | 10 | 10 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Lossless (band_cat_posted), count (360) | 0.4 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Category Video (band_cat_posted), page 1 | 0.1 | 50 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Video (band_cat_posted), worst page (offset 800, the last before mirroring) | 0.2 | 850 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Video (band_cat_posted), last page (mirrored: oldest first, offset 0, 19 rows) | 0.1 | 19 | 19 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Video (band_cat_posted), count (1669) | 0.4 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Category MP3 + Lossless (band_cat_posted), page 1 | 0.2 | 1052 | 50 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (real): Category MP3 + Lossless (band_cat_posted), worst page (offset 400, the last before mirroring) | 0.5 | 1452 | 50 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (real): Category MP3 + Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 4 rows) | 0.2 | 1006 | 4 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (real): Category MP3 + Lossless (band_cat_posted), count (804) | 0.5 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Category Audiobook + Podcast (band_cat_posted), page 1 | 0.1 | 10 | 4 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (real): Category Audiobook + Podcast (band_cat_posted), count (4) | 0.4 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Exclude Other (all but 3999; band index: over half the band), page 1 | 0.1 | 79 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Exclude Other (all but 3999; band index: over half the band), worst page (offset 1400, the last before mirroring) | 0.4 | 1888 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Exclude Other (all but 3999; band index: over half the band), last page (mirrored: oldest first, offset 0, 4 rows) | 0.1 | 4 | 4 | r:ix_releases_band_posted |  |
| Audio (real): Exclude Other (all but 3999; band index: over half the band), count (2854) | 0.5 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Completion 100%, page 1 | 0.1 | 434 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Completion 100%, worst page (offset 1300, the last before mirroring) | 0.4 | 2010 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Completion 100%, last page (mirrored: oldest first, offset 0, 39 rows) | 0.1 | 44 | 39 | r:ix_releases_band_posted |  |
| Audio (real): Completion 100%, count (2639) | 0.4 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Completion 95%+, page 1 | 0.1 | 428 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Completion 95%+, worst page (offset 1350, the last before mirroring) | 0.4 | 2005 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Completion 95%+, last page (mirrored: oldest first, offset 0, 12 rows) | 0.1 | 13 | 12 | r:ix_releases_band_posted |  |
| Audio (real): Completion 95%+, count (2762) | 0.4 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Category Lossless + 95%+, page 1 | 0.1 | 53 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Lossless + 95%+, worst page (offset 150, the last before mirroring) | 0.1 | 207 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Lossless + 95%+, last page (mirrored: oldest first, offset 0, 33 rows) | 0.1 | 48 | 33 | r:ix_releases_band_cat_posted |  |
| Audio (real): Category Lossless + 95%+, count (333) | 0.4 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Exclude Other + 100%, page 1 | 0.1 | 436 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Exclude Other + 100%, worst page (offset 1200, the last before mirroring) | 0.4 | 1985 | 50 | r:ix_releases_band_posted |  |
| Audio (real): Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 11 rows) | 0.1 | 14 | 11 | r:ix_releases_band_posted |  |
| Audio (real): Exclude Other + 100%, count (2461) | 0.5 | 3646 | 1 | r:ix_releases_band_count |  |
| Audio (real): Category menu counts (valueCounts, cached 1 h) | 0.7 | 7364 | 36 | releases:ix_releases_band_count | filesort(releases), temporary |
| Audio (real): release-name search only (today), common (FLAC), count | 3.8 | 3646 | 1 | r:ix_releases_band_posted |  |
| Audio (real): release-name search only (today), middling (2024), count | 4.3 | 3646 | 1 | r:ix_releases_band_posted |  |
| Audio (real): release-name search only (today), nothing (zzqqxx), count | 4.1 | 3646 | 1 | r:ix_releases_band_posted |  |
| Audio (real): name + tag search (IN list), common (FLAC), page 1 | 0.8 | 1414 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list), common (FLAC), count | 4.6 | 7465 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), common (FLAC), page 1 | 0.9 | 1414 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), common (FLAC), count | 4.5 | 7465 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list), middling (2024), page 1 | 4.8 | 7604 | 29 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list), middling (2024), count | 4.6 | 8010 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), middling (2024), page 1 | 4.7 | 7604 | 29 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), middling (2024), count | 4.7 | 8010 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list), nothing (zzqqxx), page 1 | 4.8 | 4195 | 0 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list), nothing (zzqqxx), count | 4.2 | 4395 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), nothing (zzqqxx), page 1 | 4.1 | 4195 | 0 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), nothing (zzqqxx), count | 4.4 | 4395 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list), a tag word (Alice), page 1 | 5.2 | 7630 | 3 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list), a tag word (Alice), count | 4.4 | 8037 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), a tag word (Alice), page 1 | 4.7 | 7630 | 3 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (EXISTS), a tag word (Alice), count | 4.7 | 8037 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), page 1 | 6.2 | 7084 | 0 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), count | 5.1 | 7084 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): name + tag search (LEFT JOIN, alternative), a tag word (Alice), page 1 | 5.4 | 7084 | 3 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): name + tag search (LEFT JOIN, alternative), a tag word (Alice), count | 5.3 | 7084 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): name + tag search (IN list) (Alice) + Category Lossless, page 1 | 0.7 | 1273 | 3 | r:ix_releases_band_cat_posted t:ALL | FULL SCAN of t |
| Audio (real): name + tag search (IN list) (Alice) + Category Lossless, count | 0.8 | 1479 | 1 | r:ix_releases_band_cat_posted t:ALL | FULL SCAN of t |

```sql
-- Audio (real): no filter, posted newest, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): no filter, posted newest, worst page (offset 1700, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 1700;
-- Audio (real): no filter, posted newest, last page (mirrored: oldest first, offset 0, 38 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 38;
-- Audio (real): no filter, posted newest, count (3438)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0;
-- Audio (real): no filter, posted oldest, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 50;
-- Audio (real): no filter, added newest, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Audio (real): no filter, added newest, worst page
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50 offset 1700;
-- Audio (real): no filter, added oldest, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.adddate asc, r.id asc limit 50;
-- Audio (real): Category Lossless (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Category Lossless (band_cat_posted), worst page (offset 150, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) order by r.postdate desc, r.id desc limit 50 offset 150;
-- Audio (real): Category Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 10 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) order by r.postdate asc, r.id asc limit 10;
-- Audio (real): Category Lossless (band_cat_posted), count (360)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040);
-- Audio (real): Category Video (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Category Video (band_cat_posted), worst page (offset 800, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020) order by r.postdate desc, r.id desc limit 50 offset 800;
-- Audio (real): Category Video (band_cat_posted), last page (mirrored: oldest first, offset 0, 19 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020) order by r.postdate asc, r.id asc limit 19;
-- Audio (real): Category Video (band_cat_posted), count (1669)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020);
-- Audio (real): Category MP3 + Lossless (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Category MP3 + Lossless (band_cat_posted), worst page (offset 400, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040) order by r.postdate desc, r.id desc limit 50 offset 400;
-- Audio (real): Category MP3 + Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 4 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040) order by r.postdate asc, r.id asc limit 4;
-- Audio (real): Category MP3 + Lossless (band_cat_posted), count (804)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040);
-- Audio (real): Category Audiobook + Podcast (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3030,3050) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Category Audiobook + Podcast (band_cat_posted), count (4)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3030,3050);
-- Audio (real): Exclude Other (all but 3999; band index: over half the band), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Exclude Other (all but 3999; band index: over half the band), worst page (offset 1400, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) order by r.postdate desc, r.id desc limit 50 offset 1400;
-- Audio (real): Exclude Other (all but 3999; band index: over half the band), last page (mirrored: oldest first, offset 0, 4 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) order by r.postdate asc, r.id asc limit 4;
-- Audio (real): Exclude Other (all but 3999; band index: over half the band), count (2854)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060);
-- Audio (real): Completion 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Completion 100%, worst page (offset 1300, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 1300;
-- Audio (real): Completion 100%, last page (mirrored: oldest first, offset 0, 39 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate asc, r.id asc limit 39;
-- Audio (real): Completion 100%, count (2639)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100;
-- Audio (real): Completion 95%+, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Completion 95%+, worst page (offset 1350, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 1350;
-- Audio (real): Completion 95%+, last page (mirrored: oldest first, offset 0, 12 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate asc, r.id asc limit 12;
-- Audio (real): Completion 95%+, count (2762)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95;
-- Audio (real): Category Lossless + 95%+, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Category Lossless + 95%+, worst page (offset 150, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 150;
-- Audio (real): Category Lossless + 95%+, last page (mirrored: oldest first, offset 0, 33 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 order by r.postdate asc, r.id asc limit 33;
-- Audio (real): Category Lossless + 95%+, count (333)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95;
-- Audio (real): Exclude Other + 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Exclude Other + 100%, worst page (offset 1200, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 1200;
-- Audio (real): Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 11 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100 order by r.postdate asc, r.id asc limit 11;
-- Audio (real): Exclude Other + 100%, count (2461)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100;
-- Audio (real): Category menu counts (valueCounts, cached 1 h)
select categories_id, resolution, source, count(*) from releases force index (ix_releases_band_count) where category_band = 3000 group by categories_id, resolution, source;
-- Audio (real): release-name search only (today), common (FLAC), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%FLAC%' escape '!';
-- Audio (real): release-name search only (today), middling (2024), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2024%' escape '!';
-- Audio (real): release-name search only (today), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
-- Audio (real): name + tag search (IN list), common (FLAC), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%FLAC%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%FLAC%' escape '!' or t.album_performer like '%FLAC%' escape '!' or t.performer like '%FLAC%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (IN list), common (FLAC), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%FLAC%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%FLAC%' escape '!' or t.album_performer like '%FLAC%' escape '!' or t.performer like '%FLAC%' escape '!')));
-- Audio (real): name + tag search (EXISTS), common (FLAC), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%FLAC%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%FLAC%' escape '!' or t.album_performer like '%FLAC%' escape '!' or t.performer like '%FLAC%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (EXISTS), common (FLAC), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%FLAC%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%FLAC%' escape '!' or t.album_performer like '%FLAC%' escape '!' or t.performer like '%FLAC%' escape '!')));
-- Audio (real): name + tag search (IN list), middling (2024), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2024%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%2024%' escape '!' or t.album_performer like '%2024%' escape '!' or t.performer like '%2024%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (IN list), middling (2024), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2024%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%2024%' escape '!' or t.album_performer like '%2024%' escape '!' or t.performer like '%2024%' escape '!')));
-- Audio (real): name + tag search (EXISTS), middling (2024), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2024%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%2024%' escape '!' or t.album_performer like '%2024%' escape '!' or t.performer like '%2024%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (EXISTS), middling (2024), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2024%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%2024%' escape '!' or t.album_performer like '%2024%' escape '!' or t.performer like '%2024%' escape '!')));
-- Audio (real): name + tag search (IN list), nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (IN list), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!')));
-- Audio (real): name + tag search (EXISTS), nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (EXISTS), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!')));
-- Audio (real): name + tag search (IN list), a tag word (Alice), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (IN list), a tag word (Alice), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')));
-- Audio (real): name + tag search (EXISTS), a tag word (Alice), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (EXISTS), a tag word (Alice), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')));
-- Audio (real): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!'));
-- Audio (real): name + tag search (LEFT JOIN, alternative), a tag word (Alice), page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (LEFT JOIN, alternative), a tag word (Alice), count
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'));
-- Audio (real): name + tag search (IN list) (Alice) + Category Lossless, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): name + tag search (IN list) (Alice) + Category Lossless, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')));
```

### Audio list and name search (stress size, `q-list-stress.tsv`)

Schema `auds`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (stress): no filter, posted newest, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): no filter, posted newest, worst page (offset 283050, the last before mirroring) | 41.2 | 285357 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): no filter, posted newest, last page (mirrored: oldest first, offset 0, 30 rows) | 0.1 | 30 | 30 | r:ix_releases_band_posted |  |
| Audio (stress): no filter, posted newest, count (566180) | 53.3 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): no filter, posted oldest, page 1 | 0.1 | 50 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): no filter, added newest, page 1 | 0.1 | 65 | 50 | r:ix_releases_band_added |  |
| Audio (stress): no filter, added newest, worst page | 40.8 | 285339 | 50 | r:ix_releases_band_added |  |
| Audio (stress): no filter, added oldest, page 1 | 0.1 | 483 | 50 | r:ix_releases_band_added |  |
| Audio (stress): Category Lossless (band_cat_posted), page 1 | 0.1 | 53 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Lossless (band_cat_posted), worst page (offset 70650, the last before mirroring) | 10.3 | 71249 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 30 rows) | 0.1 | 31 | 30 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Lossless (band_cat_posted), count (141380) | 53.7 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Category Video (band_cat_posted), page 1 | 0.1 | 51 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Video (band_cat_posted), worst page (offset 57150, the last before mirroring) | 8.4 | 57659 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Video (band_cat_posted), last page (mirrored: oldest first, offset 0, 3 rows) | 0.1 | 3 | 3 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Video (band_cat_posted), count (114353) | 53.8 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Category MP3 + Lossless (band_cat_posted), page 1 | 40.6 | 288634 | 50 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (stress): Category MP3 + Lossless (band_cat_posted), worst page (offset 141100, the last before mirroring) | 59.3 | 288584 | 50 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (stress): Category MP3 + Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 4 rows) | 37.0 | 288588 | 4 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (stress): Category MP3 + Lossless (band_cat_posted), count (282254) | 58.2 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Category Audiobook + Podcast (band_cat_posted), page 1 | 4.5 | 28994 | 50 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (stress): Category Audiobook + Podcast (band_cat_posted), worst page (offset 14100, the last before mirroring) | 13.6 | 43094 | 50 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (stress): Category Audiobook + Podcast (band_cat_posted), last page (mirrored: oldest first, offset 0, 39 rows) | 3.9 | 28983 | 39 | r:ix_releases_band_cat_posted | filesort(r) |
| Audio (stress): Category Audiobook + Podcast (band_cat_posted), count (28289) | 56.7 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Exclude Other (all but 3999; band index: over half the band), page 1 | 0.1 | 56 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Exclude Other (all but 3999; band index: over half the band), worst page (offset 240750, the last before mirroring) | 46.3 | 285289 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Exclude Other (all but 3999; band index: over half the band), last page (mirrored: oldest first, offset 0, 11 rows) | 0.1 | 12 | 11 | r:ix_releases_band_posted |  |
| Audio (stress): Exclude Other (all but 3999; band index: over half the band), count (481511) | 59.3 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Completion 100%, page 1 | 0.1 | 56 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Completion 100%, worst page (offset 271800, the last before mirroring) | 42.4 | 279564 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Completion 100%, last page (mirrored: oldest first, offset 0, 37 rows) | 0.1 | 42 | 37 | r:ix_releases_band_posted |  |
| Audio (stress): Completion 100%, count (543637) | 58.8 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Completion 95%+, page 1 | 0.1 | 51 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Completion 95%+, worst page (offset 277000, the last before mirroring) | 42.9 | 284144 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Completion 95%+, last page (mirrored: oldest first, offset 0, 47 rows) | 0.1 | 50 | 47 | r:ix_releases_band_posted |  |
| Audio (stress): Completion 95%+, count (554047) | 58.2 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Category Lossless + 95%+, page 1 | 0.1 | 53 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Lossless + 95%+, worst page (offset 69200, the last before mirroring) | 11.4 | 70840 | 50 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Lossless + 95%+, last page (mirrored: oldest first, offset 0, 29 rows) | 0.1 | 43 | 29 | r:ix_releases_band_cat_posted |  |
| Audio (stress): Category Lossless + 95%+, count (138479) | 55.4 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Exclude Other + 100%, page 1 | 0.1 | 65 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Exclude Other + 100%, worst page (offset 231300, the last before mirroring) | 48.1 | 279519 | 50 | r:ix_releases_band_posted |  |
| Audio (stress): Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 16 rows) | 0.1 | 19 | 16 | r:ix_releases_band_posted |  |
| Audio (stress): Exclude Other + 100%, count (462666) | 61.9 | 578755 | 1 | r:ix_releases_band_count |  |
| Audio (stress): Category menu counts (valueCounts, cached 1 h) | 89.8 | 1157860 | 175 | releases:ix_releases_band_count | filesort(releases), temporary |
| Audio (stress): release-name search only (today), common (1080p), count | 410.1 | 578755 | 1 | r:ix_releases_band_posted |  |
| Audio (stress): release-name search only (today), middling (2016), count | 411.8 | 578755 | 1 | r:ix_releases_band_posted |  |
| Audio (stress): release-name search only (today), nothing (zzqqxx), count | 394.7 | 578755 | 1 | r:ix_releases_band_posted |  |
| Audio (stress): name + tag search (IN list), common (1080p), page 1 | 70.0 | 231836 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list), common (1080p), count | 493.0 | 810713 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), common (1080p), page 1 | 70.1 | 231836 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), common (1080p), count | 489.2 | 810713 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list), middling (2016), page 1 | 75.6 | 233712 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list), middling (2016), count | 520.0 | 810713 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), middling (2016), page 1 | 76.4 | 233712 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), middling (2016), count | 523.1 | 810713 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list), nothing (zzqqxx), page 1 | 521.7 | 810513 | 0 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list), nothing (zzqqxx), count | 487.8 | 810713 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), nothing (zzqqxx), page 1 | 513.1 | 810513 | 0 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), nothing (zzqqxx), count | 489.8 | 810713 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list), a tag word (Velmir), page 1 | 91.0 | 260027 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list), a tag word (Velmir), count | 532.1 | 1389467 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), a tag word (Velmir), page 1 | 91.1 | 260027 | 50 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (EXISTS), a tag word (Velmir), count | 531.2 | 1389467 | 1 | r:ix_releases_band_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), page 1 | 915.1 | 1144935 | 0 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), count | 877.7 | 1144935 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): name + tag search (LEFT JOIN, alternative), a tag word (Velmir), page 1 | 25.0 | 28269 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): name + tag search (LEFT JOIN, alternative), a tag word (Velmir), count | 889.9 | 1144935 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): name + tag search (IN list) (Velmir) + Category Lossless, page 1 | 89.8 | 258758 | 50 | r:ix_releases_band_cat_posted t:ALL | FULL SCAN of t |
| Audio (stress): name + tag search (IN list) (Velmir) + Category Lossless, count | 208.2 | 520875 | 1 | r:ix_releases_band_cat_posted t:ALL | FULL SCAN of t |

```sql
-- Audio (stress): no filter, posted newest, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): no filter, posted newest, worst page (offset 283050, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 283050;
-- Audio (stress): no filter, posted newest, last page (mirrored: oldest first, offset 0, 30 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 30;
-- Audio (stress): no filter, posted newest, count (566180)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0;
-- Audio (stress): no filter, posted oldest, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 50;
-- Audio (stress): no filter, added newest, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Audio (stress): no filter, added newest, worst page
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50 offset 283050;
-- Audio (stress): no filter, added oldest, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 3000 and r.passwordstatus <= 0 order by r.adddate asc, r.id asc limit 50;
-- Audio (stress): Category Lossless (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Category Lossless (band_cat_posted), worst page (offset 70650, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) order by r.postdate desc, r.id desc limit 50 offset 70650;
-- Audio (stress): Category Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 30 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) order by r.postdate asc, r.id asc limit 30;
-- Audio (stress): Category Lossless (band_cat_posted), count (141380)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040);
-- Audio (stress): Category Video (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Category Video (band_cat_posted), worst page (offset 57150, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020) order by r.postdate desc, r.id desc limit 50 offset 57150;
-- Audio (stress): Category Video (band_cat_posted), last page (mirrored: oldest first, offset 0, 3 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020) order by r.postdate asc, r.id asc limit 3;
-- Audio (stress): Category Video (band_cat_posted), count (114353)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3020);
-- Audio (stress): Category MP3 + Lossless (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Category MP3 + Lossless (band_cat_posted), worst page (offset 141100, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040) order by r.postdate desc, r.id desc limit 50 offset 141100;
-- Audio (stress): Category MP3 + Lossless (band_cat_posted), last page (mirrored: oldest first, offset 0, 4 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040) order by r.postdate asc, r.id asc limit 4;
-- Audio (stress): Category MP3 + Lossless (band_cat_posted), count (282254)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3040);
-- Audio (stress): Category Audiobook + Podcast (band_cat_posted), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3030,3050) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Category Audiobook + Podcast (band_cat_posted), worst page (offset 14100, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3030,3050) order by r.postdate desc, r.id desc limit 50 offset 14100;
-- Audio (stress): Category Audiobook + Podcast (band_cat_posted), last page (mirrored: oldest first, offset 0, 39 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3030,3050) order by r.postdate asc, r.id asc limit 39;
-- Audio (stress): Category Audiobook + Podcast (band_cat_posted), count (28289)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3030,3050);
-- Audio (stress): Exclude Other (all but 3999; band index: over half the band), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Exclude Other (all but 3999; band index: over half the band), worst page (offset 240750, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) order by r.postdate desc, r.id desc limit 50 offset 240750;
-- Audio (stress): Exclude Other (all but 3999; band index: over half the band), last page (mirrored: oldest first, offset 0, 11 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) order by r.postdate asc, r.id asc limit 11;
-- Audio (stress): Exclude Other (all but 3999; band index: over half the band), count (481511)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060);
-- Audio (stress): Completion 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Completion 100%, worst page (offset 271800, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 271800;
-- Audio (stress): Completion 100%, last page (mirrored: oldest first, offset 0, 37 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate asc, r.id asc limit 37;
-- Audio (stress): Completion 100%, count (543637)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 100;
-- Audio (stress): Completion 95%+, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Completion 95%+, worst page (offset 277000, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 277000;
-- Audio (stress): Completion 95%+, last page (mirrored: oldest first, offset 0, 47 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95 order by r.postdate asc, r.id asc limit 47;
-- Audio (stress): Completion 95%+, count (554047)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.completion >= 95;
-- Audio (stress): Category Lossless + 95%+, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Category Lossless + 95%+, worst page (offset 69200, the last before mirroring)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50 offset 69200;
-- Audio (stress): Category Lossless + 95%+, last page (mirrored: oldest first, offset 0, 29 rows)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 order by r.postdate asc, r.id asc limit 29;
-- Audio (stress): Category Lossless + 95%+, count (138479)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95;
-- Audio (stress): Exclude Other + 100%, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Exclude Other + 100%, worst page (offset 231300, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100 order by r.postdate desc, r.id desc limit 50 offset 231300;
-- Audio (stress): Exclude Other + 100%, last page (mirrored: oldest first, offset 0, 16 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100 order by r.postdate asc, r.id asc limit 16;
-- Audio (stress): Exclude Other + 100%, count (462666)
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3010,3020,3030,3040,3050,3060) and r.completion >= 100;
-- Audio (stress): Category menu counts (valueCounts, cached 1 h)
select categories_id, resolution, source, count(*) from releases force index (ix_releases_band_count) where category_band = 3000 group by categories_id, resolution, source;
-- Audio (stress): release-name search only (today), common (1080p), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!';
-- Audio (stress): release-name search only (today), middling (2016), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!';
-- Audio (stress): release-name search only (today), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
-- Audio (stress): name + tag search (IN list), common (1080p), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%1080p%' escape '!' or t.album_performer like '%1080p%' escape '!' or t.performer like '%1080p%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (IN list), common (1080p), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%1080p%' escape '!' or t.album_performer like '%1080p%' escape '!' or t.performer like '%1080p%' escape '!')));
-- Audio (stress): name + tag search (EXISTS), common (1080p), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%1080p%' escape '!' or t.album_performer like '%1080p%' escape '!' or t.performer like '%1080p%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (EXISTS), common (1080p), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%1080p%' escape '!' or t.album_performer like '%1080p%' escape '!' or t.performer like '%1080p%' escape '!')));
-- Audio (stress): name + tag search (IN list), middling (2016), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%2016%' escape '!' or t.album_performer like '%2016%' escape '!' or t.performer like '%2016%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (IN list), middling (2016), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%2016%' escape '!' or t.album_performer like '%2016%' escape '!' or t.performer like '%2016%' escape '!')));
-- Audio (stress): name + tag search (EXISTS), middling (2016), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%2016%' escape '!' or t.album_performer like '%2016%' escape '!' or t.performer like '%2016%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (EXISTS), middling (2016), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%2016%' escape '!' or t.album_performer like '%2016%' escape '!' or t.performer like '%2016%' escape '!')));
-- Audio (stress): name + tag search (IN list), nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (IN list), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!')));
-- Audio (stress): name + tag search (EXISTS), nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (EXISTS), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!')));
-- Audio (stress): name + tag search (IN list), a tag word (Velmir), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (IN list), a tag word (Velmir), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')));
-- Audio (stress): name + tag search (EXISTS), a tag word (Velmir), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (EXISTS), a tag word (Velmir), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')));
-- Audio (stress): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (LEFT JOIN, alternative), nothing (zzqqxx), count
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' or (t.album like '%zzqqxx%' escape '!' or t.album_performer like '%zzqqxx%' escape '!' or t.performer like '%zzqqxx%' escape '!'));
-- Audio (stress): name + tag search (LEFT JOIN, alternative), a tag word (Velmir), page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (LEFT JOIN, alternative), a tag word (Velmir), count
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_tags t on t.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'));
-- Audio (stress): name + tag search (IN list) (Velmir) + Category Lossless, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): name + tag search (IN list) (Velmir) + Category Lossless, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')));
```

### Genre, Year, combined filters, row extras, Genre menu (real size, `q-filters-real.tsv`)

Schema `audc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (real): Genre Rock (largest), A release-led, page 1 | 1.1 | 6455 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (real): Genre Rock (largest), A release-led, count (81) | 1.1 | 7084 | 1 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (real): Genre Rock (largest), B tag-led (release_audio_genres PK), page 1 | 0.1 | 295 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Rock (largest), B tag-led (release_audio_genres PK), count | 0.1 | 163 | 1 | rag:PRIMARY r:PRIMARY |  |
| Audio (real): Genre Country (small), A release-led, page 1 | 1.3 | 7084 | 5 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (real): Genre Country (small), A release-led, count (5) | 2.2 | 18236 | 1 | rag:PRIMARY r:ix_releases_band_posted |  |
| Audio (real): Genre Country (small), B tag-led, page 1 | 0.1 | 22 | 5 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Country (small), B tag-led, count | 0.1 | 11 | 1 | rag:PRIMARY r:PRIMARY |  |
| Audio (real): Genre Rock or Metal, A release-led, page 1 | 0.2 | 1324 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (real): Genre Rock or Metal, A release-led, worst page (offset 50) | 0.8 | 6598 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (real): Genre Rock or Metal, A release-led, count (131) | 0.7 | 7217 | 1 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (real): Genre Rock or Metal, B tag-led, DISTINCT, page 1 | 0.2 | 710 | 50 | r:PRIMARY rag:PRIMARY | temporary |
| Audio (real): Genre Rock or Metal, B tag-led, DISTINCT, worst page (offset 50; same cost on every page) | 0.2 | 760 | 50 | r:PRIMARY rag:PRIMARY | temporary |
| Audio (real): Genre Rock or Metal, B tag-led, DISTINCT, count | 0.2 | 528 | 1 | r:PRIMARY rag:PRIMARY | temporary |
| Audio (real): Genre Rock, B tag-led, added newest, page 1 | 0.1 | 295 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Rock, B tag-led, posted oldest, page 1 | 0.1 | 295 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Unknown, A release-led NOT EXISTS, page 1 | 0.1 | 588 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (real): Genre Unknown, A release-led NOT EXISTS, worst page (offset 1500, the last before mirroring) | 0.6 | 4334 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (real): Genre Unknown, A release-led NOT EXISTS, last page (mirrored: oldest first, offset 0, 22 rows) | 0.1 | 567 | 22 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (real): Genre Unknown, A release-led NOT EXISTS, count (3022) | 0.9 | 7707 | 1 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join (whereAudio form), page 1 | 0.1 | 110 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join, worst page (offset 1500) | 0.7 | 3711 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join, count | 1.1 | 7084 | 1 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (real): Genre Unknown, two parts UNION ALL (no tag row + tag rows with no genre), page 1 | 0.6 | 2885 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique t:release_audio_tags_releases_id_unique r:PRIMARY rag:ix_release_audio_genres_release | filesort(t), full index scan of rag, full index scan of t, temporary |
| Audio (real): Genre Unknown, two parts UNION ALL, worst page (offset 1500) | 1.2 | 9907 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique t:release_audio_tags_releases_id_unique r:PRIMARY rag:ix_release_audio_genres_release | filesort(t), full index scan of rag, full index scan of t, temporary |
| Audio (real): Genre Unknown, two parts, count | 1.3 | 9679 | 1 | NULL:NULL t:release_audio_tags_releases_id_unique r:PRIMARY rag:ix_release_audio_genres_release r:ix_releases_band_posted t:release_audio_tags_releases_id_unique | full index scan of rag, full index scan of t |
| Audio (real): Genre Unknown, count by subtraction (band count - releases with a genre, read from position 0 rows) | 0.7 | 4491 | 1 | NULL:NULL rag:ix_release_audio_genres_release r:PRIMARY r:ix_releases_band_count | full index scan of rag |
| Audio (real): Genre Rock + Unknown, A release-led, page 1 | 0.2 | 780 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release rag:PRIMARY | full index scan of rag |
| Audio (real): Genre Rock + Unknown, A release-led, worst page (offset 1550) | 0.9 | 6401 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release rag:PRIMARY | full index scan of rag |
| Audio (real): Genre Rock + Unknown, A release-led, count (3103) | 1.3 | 11346 | 1 | r:ix_releases_band_posted rag:ix_release_audio_genres_release rag:PRIMARY | full index scan of rag |
| Audio (real): Genre Rock + Unknown, two parts (B Rock + A Unknown), page 1 | 0.3 | 1034 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), full index scan of rag, temporary |
| Audio (real): Genre Rock + Unknown, two parts, worst page (offset 1550) | 1.0 | 8042 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), full index scan of rag, temporary |
| Audio (real): Year 2020s (largest), A release-led, page 1 | 0.3 | 1095 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): Year 2020s (largest), A release-led, worst page (offset 50) | 0.3 | 1301 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): Year 2020s (largest), A release-led, count (161) | 1.4 | 7084 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), page 1 | 0.3 | 925 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (real): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), worst page (offset 50; same cost on every page) | 0.3 | 975 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (real): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), count | 0.3 | 713 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (real): Year 2020s (largest), C tag-led, candidate year index, page 1 | 0.2 | 541 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): Year 2020s (largest), C tag-led, candidate year index, worst page (offset 50; same cost on every page) | 0.2 | 591 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): Year 2020s (largest), C tag-led, candidate year index, count | 0.2 | 329 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (real): Year 1960s + 1970s, A release-led, page 1 | 1.3 | 6885 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): Year 1960s + 1970s, A release-led, count (84) | 1.2 | 7084 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), page 1 | 0.2 | 768 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (real): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), count | 0.2 | 633 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (real): Year 1960s + 1970s, C tag-led, candidate year index, page 1 | 0.1 | 305 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): Year 1960s + 1970s, C tag-led, candidate year index, count | 0.1 | 170 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (real): Year range 1998-2001, A release-led, page 1 | 1.4 | 7084 | 33 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): Year range 1998-2001, A release-led, count (33) | 1.3 | 7084 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (real): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), page 1 | 0.2 | 649 | 33 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (real): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), count | 0.1 | 582 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (real): Year range 1998-2001, C tag-led, candidate year index, page 1 | 0.1 | 134 | 33 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): Year range 1998-2001, C tag-led, candidate year index, count | 0.1 | 67 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (real): Year 1940s (smallest), A release-led, page 1 | 0.1 | 2 | 0 | t:ix_release_audio_tags_year_cand r:ix_releases_band_posted | filesort(t), temporary |
| Audio (real): Year 1940s (smallest), A release-led, count (0) | 0.1 | 1 | 1 | t:ix_release_audio_tags_year_cand r:ix_releases_band_posted |  |
| Audio (real): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), page 1 | 0.1 | 550 | 0 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (real): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), count | 0.1 | 549 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (real): Year 1940s (smallest), C tag-led, candidate year index, page 1 | 0.1 | 2 | 0 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): Year 1940s (smallest), C tag-led, candidate year index, count | 0.1 | 1 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (real): Genre Rock + Year 2020s, A release-led, page 1 | 1.4 | 7165 | 15 | r:ix_releases_band_posted rag:PRIMARY t:release_audio_tags_releases_id_unique |  |
| Audio (real): Genre Rock + Year 2020s, A release-led, count (15) | 1.2 | 7165 | 1 | r:ix_releases_band_posted rag:PRIMARY t:release_audio_tags_releases_id_unique |  |
| Audio (real): Genre Rock + Year 2020s, B tag-led, genre first, page 1 | 0.2 | 209 | 15 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Rock + Year 2020s, B tag-led, genre first, count | 0.1 | 178 | 1 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY |  |
| Audio (real): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, page 1 | 0.3 | 539 | 15 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY | filesort(t), temporary |
| Audio (real): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, count | 0.4 | 508 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY |  |
| Audio (real): Genre Unknown + Year 2020s, A release-led, page 1 | 1.6 | 10729 | 11 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (real): Genre Unknown + Year 2020s, A release-led, count (11) | 1.5 | 10729 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (real): Genre Unknown + Year 2020s, B tag-led, existing indexes, page 1 | 0.4 | 913 | 11 | t:ALL r:PRIMARY rag:ix_release_audio_genres_release | FULL SCAN of t, filesort(t), temporary |
| Audio (real): Genre Unknown + Year 2020s, B tag-led, existing indexes, count | 0.4 | 890 | 1 | t:ALL r:PRIMARY rag:ix_release_audio_genres_release | FULL SCAN of t |
| Audio (real): Genre Unknown + Year 2020s, C tag-led, candidate year index, page 1 | 0.4 | 529 | 11 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:ix_release_audio_genres_release | filesort(t), temporary |
| Audio (real): Genre Unknown + Year 2020s, C tag-led, candidate year index, count | 0.4 | 506 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:ix_release_audio_genres_release |  |
| Audio (real): ALL: Category Lossless + 95%+ + Genre Rock + Year 2020s + search (Alice), A release-led (cat index), page 1 | 0.8 | 1251 | 0 | r:ix_releases_band_cat_posted rag:PRIMARY t:release_audio_tags_releases_id_unique t:ALL | FULL SCAN of t |
| Audio (real): ALL, A release-led, count | 0.8 | 1484 | 1 | r:ix_releases_band_cat_posted rag:PRIMARY t:release_audio_tags_releases_id_unique t:ALL | FULL SCAN of t |
| Audio (real): ALL, C tag-led, search as IN list, page 1 | 0.6 | 1074 | 0 | t:ix_release_audio_tags_year_cand r:PRIMARY t:ALL rag:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (real): ALL, C tag-led, search as IN list, count | 0.6 | 1073 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY t:ALL rag:PRIMARY | FULL SCAN of t |
| Audio (real): ALL, C tag-led, search on the derived tag columns, page 1 | 0.4 | 509 | 0 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY | filesort(t), temporary |
| Audio (real): ALL, C tag-led, search on the derived tag columns, count | 0.5 | 508 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY |  |
| Audio (real): Genre Rock + search (Alice), B tag-led, search as IN list, page 1 | 0.3 | 326 | 2 | rag:PRIMARY r:PRIMARY t:release_audio_tags_releases_id_unique | filesort(rag), temporary |
| Audio (real): Genre Rock + search (Alice), B tag-led, count | 0.3 | 321 | 1 | rag:PRIMARY r:PRIMARY t:release_audio_tags_releases_id_unique |  |
| Audio (real): Genre Rock + search (Alice), A release-led, page 1 | 6.7 | 7633 | 2 | r:ix_releases_band_posted rag:PRIMARY t:ALL | FULL SCAN of t |
| Audio (real): Genre Rock + search (Alice), A release-led, count | 4.6 | 8040 | 1 | r:ix_releases_band_posted rag:PRIMARY t:ALL | FULL SCAN of t |
| Audio (real): row extras, no-filter page 1: tag fields + genres in order, ONE query | 0.1 | 65 | 5 | t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (real): row extras, no-filter page 1: tag fields only (two-query form, 1 of 2) | 0.1 | 50 | 5 | t:release_audio_tags_releases_id_unique |  |
| Audio (real): row extras, no-filter page 1: genres in order (two-query form, 2 of 2) | 0.1 | 60 | 5 | rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (real): row extras, Genre Rock page 1 (every row tagged): tag fields + genres in order, ONE query | 0.3 | 200 | 50 | t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (real): row extras, Genre Rock page 1 (every row tagged): tag fields only (two-query form, 1 of 2) | 0.1 | 50 | 50 | t:release_audio_tags_releases_id_unique |  |
| Audio (real): row extras, Genre Rock page 1 (every row tagged): genres in order (two-query form, 2 of 2) | 0.1 | 101 | 50 | rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (real): Genre menu: type-3000 genres with a release, A to Z | 0.2 | 1075 | 103 | g:ALL rag:ix_release_audio_genres_release | FULL SCAN of g, filesort(g), full index scan of rag |
| Audio (real): Genre menu, alternative: genres with an Audio-band release | 0.5 | 1497 | 102 | g:ALL rag:ix_release_audio_genres_release r:PRIMARY | FULL SCAN of g, filesort(g), full index scan of rag |
| Audio (real): Genre menu: is Unknown needed (an Audio release with no genre row) | 0.1 | 426 | 1 | NULL:NULL r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |

```sql
-- Audio (real): Genre Rock (largest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock (largest), A release-led, count (81)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018));
-- Audio (real): Genre Rock (largest), B tag-led (release_audio_genres PK), page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock (largest), B tag-led (release_audio_genres PK), count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Country (small), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000003)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Country (small), A release-led, count (5)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000003));
-- Audio (real): Genre Country (small), B tag-led, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000003)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Country (small), B tag-led, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000003)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Rock or Metal, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018,1000010)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock or Metal, A release-led, worst page (offset 50)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018,1000010)) order by r.postdate desc, r.id desc limit 50 offset 50;
-- Audio (real): Genre Rock or Metal, A release-led, count (131)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018,1000010));
-- Audio (real): Genre Rock or Metal, B tag-led, DISTINCT, page 1
select r.id from (select distinct rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018,1000010)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock or Metal, B tag-led, DISTINCT, worst page (offset 50; same cost on every page)
select r.id from (select distinct rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018,1000010)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 50;
-- Audio (real): Genre Rock or Metal, B tag-led, DISTINCT, count
select count(*) from (select distinct rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018,1000010)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Rock, B tag-led, added newest, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Audio (real): Genre Rock, B tag-led, posted oldest, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 50;
-- Audio (real): Genre Unknown, A release-led NOT EXISTS, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Unknown, A release-led NOT EXISTS, worst page (offset 1500, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 50 offset 1500;
-- Audio (real): Genre Unknown, A release-led NOT EXISTS, last page (mirrored: oldest first, offset 0, 22 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate asc, r.id asc limit 22;
-- Audio (real): Genre Unknown, A release-led NOT EXISTS, count (3022)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id);
-- Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join (whereAudio form), page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join, worst page (offset 1500)
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 50 offset 1500;
-- Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join, count
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null;
-- Audio (real): Genre Unknown, two parts UNION ALL (no tag row + tag rows with no genre), page 1
select id from ((select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_tags t where t.releases_id = r.id) order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from release_audio_tags t straight_join releases r on r.id = t.releases_id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id) order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Audio (real): Genre Unknown, two parts UNION ALL, worst page (offset 1500)
select id from ((select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_tags t where t.releases_id = r.id) order by r.postdate desc, r.id desc limit 1550) union all (select r.id, r.postdate from release_audio_tags t straight_join releases r on r.id = t.releases_id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id) order by r.postdate desc, r.id desc limit 1550)) u order by postdate desc, id desc limit 50 offset 1500;
-- Audio (real): Genre Unknown, two parts, count
select (select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_tags t where t.releases_id = r.id)) + (select count(*) from release_audio_tags t straight_join releases r on r.id = t.releases_id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id));
-- Audio (real): Genre Unknown, count by subtraction (band count - releases with a genre, read from position 0 rows)
select (select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0) - (select count(*) from release_audio_genres rag force index (ix_release_audio_genres_release) straight_join releases r on r.id = rag.releases_id where rag.position = 0 and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0);
-- Audio (real): Genre Rock + Unknown, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) or not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + Unknown, A release-led, worst page (offset 1550)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) or not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id)) order by r.postdate desc, r.id desc limit 50 offset 1550;
-- Audio (real): Genre Rock + Unknown, A release-led, count (3103)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) or not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id));
-- Audio (real): Genre Rock + Unknown, two parts (B Rock + A Unknown), page 1
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Audio (real): Genre Rock + Unknown, two parts, worst page (offset 1550)
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 1600) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 1600)) u order by postdate desc, id desc limit 50 offset 1550;
-- Audio (real): Year 2020s (largest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 2020s (largest), A release-led, worst page (offset 50)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50 offset 50;
-- Audio (real): Year 2020s (largest), A release-led, count (161)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029));
-- Audio (real): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), worst page (offset 50; same cost on every page)
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 50;
-- Audio (real): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Year 2020s (largest), C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 2020s (largest), C tag-led, candidate year index, worst page (offset 50; same cost on every page)
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 50;
-- Audio (real): Year 2020s (largest), C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Year 1960s + 1970s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 1960s + 1970s, A release-led, count (84)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979));
-- Audio (real): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Year 1960s + 1970s, C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 1960s + 1970s, C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Year range 1998-2001, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1998 and 2001)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year range 1998-2001, A release-led, count (33)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1998 and 2001));
-- Audio (real): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Year range 1998-2001, C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year range 1998-2001, C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Year 1940s (smallest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1940 and 1949)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 1940s (smallest), A release-led, count (0)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1940 and 1949));
-- Audio (real): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Year 1940s (smallest), C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 1940s (smallest), C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Rock + Year 2020s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + Year 2020s, A release-led, count (15)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029));
-- Audio (real): Genre Rock + Year 2020s, B tag-led, genre first, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + Year 2020s, B tag-led, genre first, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Unknown + Year 2020s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Unknown + Year 2020s, A release-led, count (11)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029));
-- Audio (real): Genre Unknown + Year 2020s, B tag-led, existing indexes, page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Unknown + Year 2020s, B tag-led, existing indexes, count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Unknown + Year 2020s, C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Unknown + Year 2020s, C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): ALL: Category Lossless + 95%+ + Genre Rock + Year 2020s + search (Alice), A release-led (cat index), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): ALL, A release-led, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')));
-- Audio (real): ALL, C tag-led, search as IN list, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): ALL, C tag-led, search as IN list, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')));
-- Audio (real): ALL, C tag-led, search on the derived tag columns, page 1
select r.id from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): ALL, C tag-led, search on the derived tag columns, count
select count(*) from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!'));
-- Audio (real): Genre Rock + search (Alice), B tag-led, search as IN list, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + search (Alice), B tag-led, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')));
-- Audio (real): Genre Rock + search (Alice), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + search (Alice), A release-led, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Alice%' escape '!' or t.album_performer like '%Alice%' escape '!' or t.performer like '%Alice%' escape '!')));
-- Audio (real): row extras, no-filter page 1: tag fields + genres in order, ONE query
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes, (select group_concat(g.title order by rag.position separator '|') from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id = t.releases_id) as genres from release_audio_tags t where t.releases_id in (3031224,3024729,3022938,3023165,3021843,3021811,3021233,3020394,3020311,3024637,3024631,3023323,3021900,3021784,3020450,3020426,3020219,3020218,3020217,3020216,3020215,3020214,3020213,3020211,3020212,3020209,3020210,3020206,3020208,3020207,3019111,3019096,3019074,3019073,3019067,3019065,3019064,3019063,3019060,3019059,3019058,3019057,3019033,3019032,3019031,3019030,3018726,3018714,3018713,3018605);
-- Audio (real): row extras, no-filter page 1: tag fields only (two-query form, 1 of 2)
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes from release_audio_tags t where t.releases_id in (3031224,3024729,3022938,3023165,3021843,3021811,3021233,3020394,3020311,3024637,3024631,3023323,3021900,3021784,3020450,3020426,3020219,3020218,3020217,3020216,3020215,3020214,3020213,3020211,3020212,3020209,3020210,3020206,3020208,3020207,3019111,3019096,3019074,3019073,3019067,3019065,3019064,3019063,3019060,3019059,3019058,3019057,3019033,3019032,3019031,3019030,3018726,3018714,3018713,3018605);
-- Audio (real): row extras, no-filter page 1: genres in order (two-query form, 2 of 2)
select rag.releases_id, g.id, g.title from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id in (3031224,3024729,3022938,3023165,3021843,3021811,3021233,3020394,3020311,3024637,3024631,3023323,3021900,3021784,3020450,3020426,3020219,3020218,3020217,3020216,3020215,3020214,3020213,3020211,3020212,3020209,3020210,3020206,3020208,3020207,3019111,3019096,3019074,3019073,3019067,3019065,3019064,3019063,3019060,3019059,3019058,3019057,3019033,3019032,3019031,3019030,3018726,3018714,3018713,3018605) order by rag.releases_id, rag.position;
-- Audio (real): row extras, Genre Rock page 1 (every row tagged): tag fields + genres in order, ONE query
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes, (select group_concat(g.title order by rag.position separator '|') from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id = t.releases_id) as genres from release_audio_tags t where t.releases_id in (3011060,2980997,2981067,2917505,2857054,2730625,2314653,2312795,2064981,1988719,1988718,1988258,1983172,1511620,1512383,1365691,1307338,1149955,726045,420785,440975,188066,3611,26776,26479,4905,3603,13125,48044,48043,48042,48040,48039,48038,48036,48065,220327,220347,297718,297726,305959,297748,297761,307338,1648981,418576,418505,300507,333261,1649563);
-- Audio (real): row extras, Genre Rock page 1 (every row tagged): tag fields only (two-query form, 1 of 2)
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes from release_audio_tags t where t.releases_id in (3011060,2980997,2981067,2917505,2857054,2730625,2314653,2312795,2064981,1988719,1988718,1988258,1983172,1511620,1512383,1365691,1307338,1149955,726045,420785,440975,188066,3611,26776,26479,4905,3603,13125,48044,48043,48042,48040,48039,48038,48036,48065,220327,220347,297718,297726,305959,297748,297761,307338,1648981,418576,418505,300507,333261,1649563);
-- Audio (real): row extras, Genre Rock page 1 (every row tagged): genres in order (two-query form, 2 of 2)
select rag.releases_id, g.id, g.title from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id in (3011060,2980997,2981067,2917505,2857054,2730625,2314653,2312795,2064981,1988719,1988718,1988258,1983172,1511620,1512383,1365691,1307338,1149955,726045,420785,440975,188066,3611,26776,26479,4905,3603,13125,48044,48043,48042,48040,48039,48038,48036,48065,220327,220347,297718,297726,305959,297748,297761,307338,1648981,418576,418505,300507,333261,1649563) order by rag.releases_id, rag.position;
-- Audio (real): Genre menu: type-3000 genres with a release, A to Z
select g.id, g.title from genres g where g.type = 3000 and exists (select 1 from release_audio_genres rag where rag.genres_id = g.id) order by g.title, g.id;
-- Audio (real): Genre menu, alternative: genres with an Audio-band release
select g.id, g.title from genres g where g.type = 3000 and exists (select 1 from release_audio_genres rag join releases r on r.id = rag.releases_id where rag.genres_id = g.id and r.categories_id between 3000 and 3999) order by g.title, g.id;
-- Audio (real): Genre menu: is Unknown needed (an Audio release with no genre row)
select exists (select 1 from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id));
```

### Genre, Year, combined filters, row extras, Genre menu (stress size, `q-filters-stress.tsv`)

Schema `auds`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (stress): Genre Rock (largest), A release-led, page 1 | 0.5 | 1115 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Rock (largest), A release-led, worst page (offset 20900) | 134.8 | 570233 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Rock (largest), A release-led, count (41836) | 248.8 | 1144935 | 1 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Rock (largest), B tag-led (release_audio_genres PK), page 1 | 37.1 | 127294 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Rock (largest), B tag-led (release_audio_genres PK), worst page (offset 20900; same cost on every page) | 37.1 | 148194 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Rock (largest), B tag-led (release_audio_genres PK), count | 34.3 | 85407 | 1 | rag:PRIMARY r:PRIMARY |  |
| Audio (stress): Genre Schlager (small), A release-led, page 1 | 28.7 | 118660 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Schlager (small), A release-led, count (445) | 244.4 | 1144935 | 1 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Schlager (small), B tag-led, page 1 | 0.4 | 1405 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Schlager (small), B tag-led, count | 0.4 | 909 | 1 | rag:PRIMARY r:PRIMARY |  |
| Audio (stress): Genre Rock or Metal, A release-led, page 1 | 9.8 | 70063 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Rock or Metal, A release-led, worst page (offset 33250) | 69.8 | 634481 | 50 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Rock or Metal, A release-led, count (66597) | 112.3 | 1214221 | 1 | r:ix_releases_band_posted rag:PRIMARY |  |
| Audio (stress): Genre Rock or Metal, B tag-led, DISTINCT, page 1 | 65.7 | 427919 | 50 | r:PRIMARY rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Rock or Metal, B tag-led, DISTINCT, worst page (offset 33250; same cost on every page) | 66.5 | 461169 | 50 | r:PRIMARY rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Rock or Metal, B tag-led, DISTINCT, count | 61.6 | 361271 | 1 | r:PRIMARY rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Rock, B tag-led, added newest, page 1 | 37.6 | 127294 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Rock, B tag-led, posted oldest, page 1 | 35.7 | 127294 | 50 | rag:PRIMARY r:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Unknown, A release-led NOT EXISTS, page 1 | 28.4 | 225470 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Unknown, A release-led NOT EXISTS, worst page (offset 184250, the last before mirroring) | 97.4 | 793855 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Unknown, A release-led NOT EXISTS, last page (mirrored: oldest first, offset 0, 23 rows) | 28.4 | 225356 | 23 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Unknown, A release-led NOT EXISTS, count (368523) | 148.3 | 1370383 | 1 | r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join (whereAudio form), page 1 | 0.1 | 148 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join, worst page (offset 184250) | 130.8 | 568407 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join, count | 241.3 | 1144935 | 1 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (stress): Genre Unknown, two parts UNION ALL (no tag row + tag rows with no genre), page 1 | 137.1 | 980031 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique t:release_audio_tags_releases_id_unique r:PRIMARY rag:ix_release_audio_genres_release | filesort(t), full index scan of rag, full index scan of t, temporary |
| Audio (stress): Genre Unknown, two parts UNION ALL, worst page (offset 184250) | 273.7 | 2023807 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique t:release_audio_tags_releases_id_unique r:PRIMARY rag:ix_release_audio_genres_release | filesort(t), full index scan of rag, full index scan of t, temporary |
| Audio (stress): Genre Unknown, two parts, count | 278.6 | 2095625 | 1 | NULL:NULL t:release_audio_tags_releases_id_unique r:PRIMARY rag:ix_release_audio_genres_release r:ix_releases_band_posted t:release_audio_tags_releases_id_unique | full index scan of rag, full index scan of t |
| Audio (stress): Genre Unknown, count by subtraction (band count - releases with a genre, read from position 0 rows) | 183.0 | 1005991 | 1 | NULL:NULL rag:ix_release_audio_genres_release r:PRIMARY r:ix_releases_band_count | full index scan of rag |
| Audio (stress): Genre Rock + Unknown, A release-led, page 1 | 36.9 | 268289 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release rag:PRIMARY | full index scan of rag |
| Audio (stress): Genre Rock + Unknown, A release-led, worst page (offset 205150) | 141.9 | 1099029 | 50 | r:ix_releases_band_posted rag:ix_release_audio_genres_release rag:PRIMARY | full index scan of rag |
| Audio (stress): Genre Rock + Unknown, A release-led, count (410359) | 200.8 | 1937631 | 1 | r:ix_releases_band_posted rag:ix_release_audio_genres_release rag:PRIMARY | full index scan of rag |
| Audio (stress): Genre Rock + Unknown, two parts (B Rock + A Unknown), page 1 | 66.0 | 352915 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), full index scan of rag, temporary |
| Audio (stress): Genre Rock + Unknown, two parts, worst page (offset 205150) | 193.0 | 1479418 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), full index scan of rag, temporary |
| Audio (stress): Year 2020s (largest), A release-led, page 1 | 0.3 | 805 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 2020s (largest), A release-led, worst page (offset 34200) | 217.3 | 567991 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 2020s (largest), A release-led, count (68478) | 410.6 | 1144935 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), page 1 | 99.2 | 370236 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), worst page (offset 34200; same cost on every page) | 102.4 | 404436 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), count | 97.4 | 301707 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (stress): Year 2020s (largest), C tag-led, candidate year index, page 1 | 67.1 | 208428 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): Year 2020s (largest), C tag-led, candidate year index, worst page (offset 34200; same cost on every page) | 69.4 | 242628 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): Year 2020s (largest), C tag-led, candidate year index, count | 69.8 | 139899 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (stress): Year 1960s + 1970s, A release-led, page 1 | 0.6 | 1413 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 1960s + 1970s, A release-led, worst page (offset 17200) | 216.9 | 569846 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 1960s + 1970s, A release-led, count (34493) | 420.9 | 1144935 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), page 1 | 63.3 | 301570 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), worst page (offset 17200; same cost on every page) | 67.1 | 318770 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), count | 59.7 | 267026 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (stress): Year 1960s + 1970s, C tag-led, candidate year index, page 1 | 38.9 | 105082 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): Year 1960s + 1970s, C tag-led, candidate year index, worst page (offset 17200; same cost on every page) | 39.1 | 122282 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): Year 1960s + 1970s, C tag-led, candidate year index, count | 35.6 | 70538 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (stress): Year range 1998-2001, A release-led, page 1 | 1.8 | 5084 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year range 1998-2001, A release-led, count (11960) | 415.7 | 1144935 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), page 1 | 35.0 | 255976 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), count | 32.8 | 243965 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (stress): Year range 1998-2001, C tag-led, candidate year index, page 1 | 12.1 | 36426 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): Year range 1998-2001, C tag-led, candidate year index, count | 10.4 | 24415 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (stress): Year 1940s (smallest), A release-led, page 1 | 54.7 | 149112 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 1940s (smallest), A release-led, count (399) | 419.4 | 1144935 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), page 1 | 20.3 | 232616 | 50 | t:ALL r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), count | 18.8 | 232166 | 1 | t:ALL r:PRIMARY | FULL SCAN of t |
| Audio (stress): Year 1940s (smallest), C tag-led, candidate year index, page 1 | 0.4 | 1267 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): Year 1940s (smallest), C tag-led, candidate year index, count | 0.4 | 817 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |
| Audio (stress): Genre Rock + Year 2020s, A release-led, page 1 | 1.6 | 4761 | 50 | r:ix_releases_band_posted rag:PRIMARY t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Genre Rock + Year 2020s, A release-led, count (12469) | 303.2 | 1186771 | 1 | r:ix_releases_band_posted rag:PRIMARY t:release_audio_tags_releases_id_unique |  |
| Audio (stress): Genre Rock + Year 2020s, B tag-led, genre first, page 1 | 59.8 | 110632 | 50 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Rock + Year 2020s, B tag-led, genre first, count | 59.5 | 98112 | 1 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY |  |
| Audio (stress): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, page 1 | 103.0 | 165324 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY | filesort(t), temporary |
| Audio (stress): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, count | 98.6 | 152804 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY |  |
| Audio (stress): Genre Unknown + Year 2020s, A release-led, page 1 | 30.3 | 235393 | 50 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Unknown + Year 2020s, A release-led, count (9064) | 306.4 | 1738906 | 1 | r:ix_releases_band_posted t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release | full index scan of rag |
| Audio (stress): Genre Unknown + Year 2020s, B tag-led, existing indexes, page 1 | 131.1 | 320300 | 50 | t:ALL r:PRIMARY rag:ix_release_audio_genres_release | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Genre Unknown + Year 2020s, B tag-led, existing indexes, count | 124.4 | 311185 | 1 | t:ALL r:PRIMARY rag:ix_release_audio_genres_release | FULL SCAN of t |
| Audio (stress): Genre Unknown + Year 2020s, C tag-led, candidate year index, page 1 | 99.7 | 158492 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:ix_release_audio_genres_release | filesort(t), temporary |
| Audio (stress): Genre Unknown + Year 2020s, C tag-led, candidate year index, count | 98.6 | 149377 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:ix_release_audio_genres_release |  |
| Audio (stress): ALL: Category Lossless + 95%+ + Genre Rock + Year 2020s + search (Velmir), A release-led (cat index), page 1 | 217.2 | 515383 | 27 | r:ix_releases_band_cat_posted rag:PRIMARY t:release_audio_tags_releases_id_unique t:ALL | FULL SCAN of t |
| Audio (stress): ALL, A release-led, count | 211.0 | 521562 | 1 | r:ix_releases_band_cat_posted rag:PRIMARY t:release_audio_tags_releases_id_unique t:ALL | FULL SCAN of t |
| Audio (stress): ALL, C tag-led, search as IN list, page 1 | 181.4 | 387852 | 27 | t:ix_release_audio_tags_year_cand r:PRIMARY t:ALL rag:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): ALL, C tag-led, search as IN list, count | 181.3 | 387797 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY t:ALL rag:PRIMARY | FULL SCAN of t |
| Audio (stress): ALL, C tag-led, search on the derived tag columns, page 1 | 165.7 | 152859 | 27 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY | filesort(t), temporary |
| Audio (stress): ALL, C tag-led, search on the derived tag columns, count | 165.5 | 152804 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY rag:PRIMARY |  |
| Audio (stress): Genre Rock + search (Velmir), B tag-led, search as IN list, page 1 | 125.7 | 359711 | 50 | rag:PRIMARY r:PRIMARY t:ALL | FULL SCAN of t, filesort(rag), temporary |
| Audio (stress): Genre Rock + search (Velmir), B tag-led, count | 122.8 | 359201 | 1 | rag:PRIMARY r:PRIMARY t:ALL | FULL SCAN of t |
| Audio (stress): Genre Rock + search (Velmir), A release-led, page 1 | 125.9 | 347317 | 50 | r:ix_releases_band_posted rag:PRIMARY t:ALL | FULL SCAN of t |
| Audio (stress): Genre Rock + search (Velmir), A release-led, count | 533.9 | 1391749 | 1 | r:ix_releases_band_posted rag:PRIMARY t:ALL | FULL SCAN of t |
| Audio (stress): row extras, no-filter page 1: tag fields + genres in order, ONE query | 0.2 | 101 | 17 | t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (stress): row extras, no-filter page 1: tag fields only (two-query form, 1 of 2) | 0.1 | 50 | 17 | t:release_audio_tags_releases_id_unique |  |
| Audio (stress): row extras, no-filter page 1: genres in order (two-query form, 2 of 2) | 0.1 | 84 | 17 | rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (stress): row extras, Genre Rock page 1 (every row tagged): tag fields + genres in order, ONE query | 0.3 | 224 | 50 | t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (stress): row extras, Genre Rock page 1 (every row tagged): tag fields only (two-query form, 1 of 2) | 0.1 | 50 | 50 | t:release_audio_tags_releases_id_unique |  |
| Audio (stress): row extras, Genre Rock page 1 (every row tagged): genres in order (two-query form, 2 of 2) | 0.1 | 137 | 62 | rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (stress): Genre menu: type-3000 genres with a release, A to Z | 24.0 | 225900 | 103 | g:ALL rag:ix_release_audio_genres_release | FULL SCAN of g, filesort(g), full index scan of rag |
| Audio (stress): Genre menu, alternative: genres with an Audio-band release | 130.2 | 427888 | 103 | g:ALL rag:ix_release_audio_genres_release r:PRIMARY | FULL SCAN of g, filesort(g), full index scan of rag |
| Audio (stress): Genre menu: is Unknown needed (an Audio release with no genre row) | 28.2 | 225254 | 1 | NULL:NULL r:ix_releases_band_posted rag:ix_release_audio_genres_release | full index scan of rag |

```sql
-- Audio (stress): Genre Rock (largest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock (largest), A release-led, worst page (offset 20900)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) order by r.postdate desc, r.id desc limit 50 offset 20900;
-- Audio (stress): Genre Rock (largest), A release-led, count (41836)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018));
-- Audio (stress): Genre Rock (largest), B tag-led (release_audio_genres PK), page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock (largest), B tag-led (release_audio_genres PK), worst page (offset 20900; same cost on every page)
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 20900;
-- Audio (stress): Genre Rock (largest), B tag-led (release_audio_genres PK), count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Schlager (small), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000208)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Schlager (small), A release-led, count (445)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000208));
-- Audio (stress): Genre Schlager (small), B tag-led, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000208)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Schlager (small), B tag-led, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000208)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Rock or Metal, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018,1000010)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock or Metal, A release-led, worst page (offset 33250)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018,1000010)) order by r.postdate desc, r.id desc limit 50 offset 33250;
-- Audio (stress): Genre Rock or Metal, A release-led, count (66597)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018,1000010));
-- Audio (stress): Genre Rock or Metal, B tag-led, DISTINCT, page 1
select r.id from (select distinct rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018,1000010)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock or Metal, B tag-led, DISTINCT, worst page (offset 33250; same cost on every page)
select r.id from (select distinct rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018,1000010)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 33250;
-- Audio (stress): Genre Rock or Metal, B tag-led, DISTINCT, count
select count(*) from (select distinct rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018,1000010)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Rock, B tag-led, added newest, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock, B tag-led, posted oldest, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 50;
-- Audio (stress): Genre Unknown, A release-led NOT EXISTS, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Unknown, A release-led NOT EXISTS, worst page (offset 184250, the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 50 offset 184250;
-- Audio (stress): Genre Unknown, A release-led NOT EXISTS, last page (mirrored: oldest first, offset 0, 23 rows)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate asc, r.id asc limit 23;
-- Audio (stress): Genre Unknown, A release-led NOT EXISTS, count (368523)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id);
-- Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join (whereAudio form), page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join, worst page (offset 184250)
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 50 offset 184250;
-- Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join, count
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null;
-- Audio (stress): Genre Unknown, two parts UNION ALL (no tag row + tag rows with no genre), page 1
select id from ((select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_tags t where t.releases_id = r.id) order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from release_audio_tags t straight_join releases r on r.id = t.releases_id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id) order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Audio (stress): Genre Unknown, two parts UNION ALL, worst page (offset 184250)
select id from ((select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_tags t where t.releases_id = r.id) order by r.postdate desc, r.id desc limit 184300) union all (select r.id, r.postdate from release_audio_tags t straight_join releases r on r.id = t.releases_id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id) order by r.postdate desc, r.id desc limit 184300)) u order by postdate desc, id desc limit 50 offset 184250;
-- Audio (stress): Genre Unknown, two parts, count
select (select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_tags t where t.releases_id = r.id)) + (select count(*) from release_audio_tags t straight_join releases r on r.id = t.releases_id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id));
-- Audio (stress): Genre Unknown, count by subtraction (band count - releases with a genre, read from position 0 rows)
select (select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 3000 and r.passwordstatus <= 0) - (select count(*) from release_audio_genres rag force index (ix_release_audio_genres_release) straight_join releases r on r.id = rag.releases_id where rag.position = 0 and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0);
-- Audio (stress): Genre Rock + Unknown, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) or not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + Unknown, A release-led, worst page (offset 205150)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) or not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id)) order by r.postdate desc, r.id desc limit 50 offset 205150;
-- Audio (stress): Genre Rock + Unknown, A release-led, count (410359)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) or not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id));
-- Audio (stress): Genre Rock + Unknown, two parts (B Rock + A Unknown), page 1
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Audio (stress): Genre Rock + Unknown, two parts, worst page (offset 205150)
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 205200) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) order by r.postdate desc, r.id desc limit 205200)) u order by postdate desc, id desc limit 50 offset 205150;
-- Audio (stress): Year 2020s (largest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 2020s (largest), A release-led, worst page (offset 34200)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50 offset 34200;
-- Audio (stress): Year 2020s (largest), A release-led, count (68478)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029));
-- Audio (stress): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), worst page (offset 34200; same cost on every page)
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 34200;
-- Audio (stress): Year 2020s (largest), B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Year 2020s (largest), C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 2020s (largest), C tag-led, candidate year index, worst page (offset 34200; same cost on every page)
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 34200;
-- Audio (stress): Year 2020s (largest), C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Year 1960s + 1970s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 1960s + 1970s, A release-led, worst page (offset 17200)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) order by r.postdate desc, r.id desc limit 50 offset 17200;
-- Audio (stress): Year 1960s + 1970s, A release-led, count (34493)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979));
-- Audio (stress): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), worst page (offset 17200; same cost on every page)
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 17200;
-- Audio (stress): Year 1960s + 1970s, B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Year 1960s + 1970s, C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 1960s + 1970s, C tag-led, candidate year index, worst page (offset 17200; same cost on every page)
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 17200;
-- Audio (stress): Year 1960s + 1970s, C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1960 and 1969 or t.recorded_year between 1970 and 1979)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Year range 1998-2001, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1998 and 2001)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year range 1998-2001, A release-led, count (11960)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1998 and 2001));
-- Audio (stress): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year range 1998-2001, B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Year range 1998-2001, C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year range 1998-2001, C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1998 and 2001)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Year 1940s (smallest), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1940 and 1949)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 1940s (smallest), A release-led, count (399)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 1940 and 1949));
-- Audio (stress): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 1940s (smallest), B tag-led, existing indexes (scan of release_audio_tags), count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Year 1940s (smallest), C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 1940s (smallest), C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 1940 and 1949)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Rock + Year 2020s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + Year 2020s, A release-led, count (12469)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029));
-- Audio (stress): Genre Rock + Year 2020s, B tag-led, genre first, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + Year 2020s, B tag-led, genre first, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + Year 2020s, C tag-led, year first on the candidate index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Unknown + Year 2020s, A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Unknown + Year 2020s, A release-led, count (9064)
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029));
-- Audio (stress): Genre Unknown + Year 2020s, B tag-led, existing indexes, page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Unknown + Year 2020s, B tag-led, existing indexes, count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Unknown + Year 2020s, C tag-led, candidate year index, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Unknown + Year 2020s, C tag-led, candidate year index, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and not exists (select 1 from release_audio_genres rag where rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): ALL: Category Lossless + 95%+ + Genre Rock + Year 2020s + search (Velmir), A release-led (cat index), page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): ALL, A release-led, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and exists (select 1 from release_audio_tags t where t.releases_id = r.id and (t.recorded_year between 2020 and 2029)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')));
-- Audio (stress): ALL, C tag-led, search as IN list, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): ALL, C tag-led, search as IN list, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')));
-- Audio (stress): ALL, C tag-led, search on the derived tag columns, page 1
select r.id from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): ALL, C tag-led, search on the derived tag columns, count
select count(*) from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029) and exists (select 1 from release_audio_genres rag where rag.genres_id in (1000018) and rag.releases_id = t.releases_id)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!'));
-- Audio (stress): Genre Rock + search (Velmir), B tag-led, search as IN list, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + search (Velmir), B tag-led, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')));
-- Audio (stress): Genre Rock + search (Velmir), A release-led, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!'))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + search (Velmir), A release-led, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)) and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or r.id in (select t.releases_id from release_audio_tags t where (t.album like '%Velmir%' escape '!' or t.album_performer like '%Velmir%' escape '!' or t.performer like '%Velmir%' escape '!')));
-- Audio (stress): row extras, no-filter page 1: tag fields + genres in order, ONE query
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes, (select group_concat(g.title order by rag.position separator '|') from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id = t.releases_id) as genres from release_audio_tags t where t.releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941);
-- Audio (stress): row extras, no-filter page 1: tag fields only (two-query form, 1 of 2)
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes from release_audio_tags t where t.releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941);
-- Audio (stress): row extras, no-filter page 1: genres in order (two-query form, 2 of 2)
select rag.releases_id, g.id, g.title from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id in (3030694,3029546,3029192,3029683,3029599,3028667,3028581,3027847,3027756,3027125,3025797,3025744,3025696,3025680,3025653,3025628,3028367,3025593,3025507,3025506,3025456,3025408,3025399,3025409,3025383,3025374,3025372,3025329,3025306,3025297,3025269,3025262,3025195,3025173,3025162,3025102,3025059,3025035,3031235,3031487,3025030,3025014,3025009,3025005,3031181,3024987,3024970,3024953,3024929,3024941) order by rag.releases_id, rag.position;
-- Audio (stress): row extras, Genre Rock page 1 (every row tagged): tag fields + genres in order, ONE query
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes, (select group_concat(g.title order by rag.position separator '|') from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id = t.releases_id) as genres from release_audio_tags t where t.releases_id in (3029192,3025653,3025014,3024861,3025657,3025796,3025455,3025205,3024589,3030992,3022987,3022719,3022696,3022522,3022377,3022345,3028420,3021513,3021020,3020906,3020659,3020473,3020472,3024064,3020277,3020131,3020112,3019899,3024907,3019583,3019459,3019434,3019321,3019269,3018879,3018880,3018778,3018620,3023507,3018249,3018203,3018041,3017681,3023145,3017147,3016887,3021784,3015441,3015361,3014985);
-- Audio (stress): row extras, Genre Rock page 1 (every row tagged): tag fields only (two-query form, 1 of 2)
select t.releases_id, coalesce(t.album_performer, t.performer) as artist, t.album, t.recorded_year, t.has_preview, t.preview_extension, t.preview_mime, t.preview_seconds, t.preview_bytes from release_audio_tags t where t.releases_id in (3029192,3025653,3025014,3024861,3025657,3025796,3025455,3025205,3024589,3030992,3022987,3022719,3022696,3022522,3022377,3022345,3028420,3021513,3021020,3020906,3020659,3020473,3020472,3024064,3020277,3020131,3020112,3019899,3024907,3019583,3019459,3019434,3019321,3019269,3018879,3018880,3018778,3018620,3023507,3018249,3018203,3018041,3017681,3023145,3017147,3016887,3021784,3015441,3015361,3014985);
-- Audio (stress): row extras, Genre Rock page 1 (every row tagged): genres in order (two-query form, 2 of 2)
select rag.releases_id, g.id, g.title from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id in (3029192,3025653,3025014,3024861,3025657,3025796,3025455,3025205,3024589,3030992,3022987,3022719,3022696,3022522,3022377,3022345,3028420,3021513,3021020,3020906,3020659,3020473,3020472,3024064,3020277,3020131,3020112,3019899,3024907,3019583,3019459,3019434,3019321,3019269,3018879,3018880,3018778,3018620,3023507,3018249,3018203,3018041,3017681,3023145,3017147,3016887,3021784,3015441,3015361,3014985) order by rag.releases_id, rag.position;
-- Audio (stress): Genre menu: type-3000 genres with a release, A to Z
select g.id, g.title from genres g where g.type = 3000 and exists (select 1 from release_audio_genres rag where rag.genres_id = g.id) order by g.title, g.id;
-- Audio (stress): Genre menu, alternative: genres with an Audio-band release
select g.id, g.title from genres g where g.type = 3000 and exists (select 1 from release_audio_genres rag join releases r on r.id = rag.releases_id where rag.genres_id = g.id and r.categories_id between 3000 and 3999) order by g.title, g.id;
-- Audio (stress): Genre menu: is Unknown needed (an Audio release with no genre row)
select exists (select 1 from releases r force index (ix_releases_band_posted) where r.category_band = 3000 and not exists (select 1 from release_audio_genres rag where rag.releases_id = r.id));
```

### Second pass: plan fixes and genre-first reads (real size, `q-variants-real.tsv`)

Schema `audc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (real): Genre menu, loose index scan (DISTINCT genres_id of the PK, then genres) | 0.2 | 837 | 103 | g:PRIMARY rag:PRIMARY | full index scan of rag |
| Audio (real): Genre menu, one probe per genre (scalar subquery, LIMIT 1) | 0.5 | 738 | 103 | g:ALL rag:PRIMARY | FULL SCAN of g, filesort(g) |
| Audio (real): Genre menu, alternative with an Audio-band release, one probe per genre | 0.6 | 847 | 102 | g:ALL rag:PRIMARY r:PRIMARY | FULL SCAN of g, filesort(g) |
| Audio (real): Genre menu: is Unknown needed, LEFT JOIN anti-join LIMIT 1 | 0.1 | 2 | 1 | NULL:NULL r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (real): Genre Rock or Metal, B tag-led, GROUP BY on the PK, page 1 | 0.2 | 841 | 50 | r:PRIMARY rag:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Rock or Metal, B tag-led, GROUP BY on the PK, count | 0.2 | 659 | 1 | r:PRIMARY rag:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Rock or Metal, B tag-led, UNION of each genre, page 1 | 0.2 | 710 | 50 | r:PRIMARY rag:PRIMARY rag:PRIMARY |  |
| Audio (real): Genre Rock or Metal, B tag-led, UNION of each genre, count | 0.2 | 528 | 1 | r:PRIMARY rag:PRIMARY rag:PRIMARY |  |
| Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join, last page (mirrored: oldest first, offset 0, 22 rows) | 0.1 | 96 | 22 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (real): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, page 1 | 0.1 | 207 | 50 | r:ix_releases_band_posted u:ix_release_audio_genres_release rag:PRIMARY |  |
| Audio (real): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, worst page (offset 1550) | 0.8 | 4473 | 50 | r:ix_releases_band_posted u:ix_release_audio_genres_release rag:PRIMARY |  |
| Audio (real): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, count (3103) | 1.3 | 8198 | 1 | r:ix_releases_band_posted u:ix_release_audio_genres_release rag:PRIMARY |  |
| Audio (real): Genre Rock + Unknown, two parts (B Rock + A Unknown as LEFT JOIN), page 1 | 0.2 | 556 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), temporary |
| Audio (real): Genre Rock + Unknown, two parts (LEFT JOIN), worst page (offset 1550) | 1.1 | 7419 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), temporary |
| Audio (real): Genre Unknown + Year 2020s, C tag-led, candidate year index, LEFT JOIN anti-join, page 1 | 0.2 | 365 | 11 | t:ix_release_audio_tags_year_cand rag:ix_release_audio_genres_release r:PRIMARY | filesort(t), temporary |
| Audio (real): Genre Unknown + Year 2020s, C tag-led, LEFT JOIN anti-join, count | 0.1 | 342 | 1 | t:ix_release_audio_tags_year_cand rag:ix_release_audio_genres_release r:PRIMARY |  |
| Audio (real): Genre Unknown + Year 2020s, B tag-led, existing indexes, LEFT JOIN anti-join, page 1 | 0.2 | 749 | 11 | t:ALL rag:ix_release_audio_genres_release r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (real): Genre Unknown + Year 2020s, B tag-led, LEFT JOIN anti-join, count | 0.2 | 726 | 1 | t:ALL rag:ix_release_audio_genres_release r:PRIMARY | FULL SCAN of t |
| Audio (real): Genre Rock + search (Alice), B genre-first, tag row joined, search on its columns, page 1 | 0.2 | 249 | 2 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY | filesort(rag), temporary |
| Audio (real): Genre Rock + search (Alice), B genre-first, tag row joined, count | 0.3 | 244 | 1 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY |  |
| Audio (real): ALL (Lossless + 95%+ + Rock + 2020s + search Alice), B genre-first, tag row joined, page 1 | 0.2 | 179 | 0 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY | filesort(rag), temporary |
| Audio (real): ALL, B genre-first, tag row joined, count | 0.2 | 178 | 1 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY |  |
| Audio (real): Year 2020s + search (Alice), C year-first, search on its columns, page 1 | 0.4 | 332 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): Year 2020s + search (Alice), C year-first, count | 0.4 | 329 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |

```sql
-- Audio (real): Genre menu, loose index scan (DISTINCT genres_id of the PK, then genres)
select g.id, g.title from (select distinct rag.genres_id from release_audio_genres rag) x join genres g on g.id = x.genres_id where g.type = 3000 order by g.title, g.id;
-- Audio (real): Genre menu, one probe per genre (scalar subquery, LIMIT 1)
select g.id, g.title from genres g where g.type = 3000 and (select rag.releases_id from release_audio_genres rag where rag.genres_id = g.id limit 1) is not null order by g.title, g.id;
-- Audio (real): Genre menu, alternative with an Audio-band release, one probe per genre
select g.id, g.title from genres g where g.type = 3000 and (select rag.releases_id from release_audio_genres rag join releases r on r.id = rag.releases_id where rag.genres_id = g.id and r.categories_id between 3000 and 3999 limit 1) is not null order by g.title, g.id;
-- Audio (real): Genre menu: is Unknown needed, LEFT JOIN anti-join LIMIT 1
select (select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and rag.releases_id is null limit 1) is not null;
-- Audio (real): Genre Rock or Metal, B tag-led, GROUP BY on the PK, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag force index (primary) where rag.genres_id in (1000018,1000010) group by rag.releases_id) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock or Metal, B tag-led, GROUP BY on the PK, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag force index (primary) where rag.genres_id in (1000018,1000010) group by rag.releases_id) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Rock or Metal, B tag-led, UNION of each genre, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id = 1000018 union select rag.releases_id from release_audio_genres rag where rag.genres_id = 1000010) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock or Metal, B tag-led, UNION of each genre, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id = 1000018 union select rag.releases_id from release_audio_genres rag where rag.genres_id = 1000010) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Unknown, A release-led LEFT JOIN anti-join, last page (mirrored: oldest first, offset 0, 22 rows)
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate asc, r.id asc limit 22;
-- Audio (real): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres u on u.releases_id = r.id and u.position = 0 where r.category_band = 3000 and r.passwordstatus <= 0 and (u.releases_id is null or exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018))) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, worst page (offset 1550)
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres u on u.releases_id = r.id and u.position = 0 where r.category_band = 3000 and r.passwordstatus <= 0 and (u.releases_id is null or exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018))) order by r.postdate desc, r.id desc limit 50 offset 1550;
-- Audio (real): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, count (3103)
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_genres u on u.releases_id = r.id and u.position = 0 where r.category_band = 3000 and r.passwordstatus <= 0 and (u.releases_id is null or exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)));
-- Audio (real): Genre Rock + Unknown, two parts (B Rock + A Unknown as LEFT JOIN), page 1
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Audio (real): Genre Rock + Unknown, two parts (LEFT JOIN), worst page (offset 1550)
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 1600) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 1600)) u order by postdate desc, id desc limit 50 offset 1550;
-- Audio (real): Genre Unknown + Year 2020s, C tag-led, candidate year index, LEFT JOIN anti-join, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Unknown + Year 2020s, C tag-led, LEFT JOIN anti-join, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Unknown + Year 2020s, B tag-led, existing indexes, LEFT JOIN anti-join, page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Unknown + Year 2020s, B tag-led, LEFT JOIN anti-join, count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): Genre Rock + search (Alice), B genre-first, tag row joined, search on its columns, page 1
select r.id from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Genre Rock + search (Alice), B genre-first, tag row joined, count
select count(*) from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!'));
-- Audio (real): ALL (Lossless + 95%+ + Rock + 2020s + search Alice), B genre-first, tag row joined, page 1
select r.id from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): ALL, B genre-first, tag row joined, count
select count(*) from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!'));
-- Audio (real): Year 2020s + search (Alice), C year-first, search on its columns, page 1
select r.id from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (real): Year 2020s + search (Alice), C year-first, count
select count(*) from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Alice%' escape '!' or (g.album like '%Alice%' escape '!' or g.album_performer like '%Alice%' escape '!' or g.performer like '%Alice%' escape '!'));
```

### Second pass: plan fixes and genre-first reads (stress size, `q-variants-stress.tsv`)

Schema `auds`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (stress): Genre menu, loose index scan (DISTINCT genres_id of the PK, then genres) | 0.2 | 518 | 103 | g:PRIMARY rag:PRIMARY |  |
| Audio (stress): Genre menu, one probe per genre (scalar subquery, LIMIT 1) | 0.5 | 738 | 103 | g:ALL rag:PRIMARY | FULL SCAN of g, filesort(g) |
| Audio (stress): Genre menu, alternative with an Audio-band release, one probe per genre | 0.6 | 841 | 103 | g:ALL rag:PRIMARY r:PRIMARY | FULL SCAN of g, filesort(g) |
| Audio (stress): Genre menu: is Unknown needed, LEFT JOIN anti-join LIMIT 1 | 0.1 | 4 | 1 | NULL:NULL r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (stress): Genre Rock or Metal, B tag-led, GROUP BY on the PK, page 1 | 62.6 | 407980 | 50 | r:PRIMARY rag:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Rock or Metal, B tag-led, GROUP BY on the PK, count | 58.3 | 341332 | 1 | r:PRIMARY rag:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Rock or Metal, B tag-led, UNION of each genre, page 1 | 71.9 | 339969 | 50 | r:PRIMARY rag:PRIMARY rag:PRIMARY |  |
| Audio (stress): Genre Rock or Metal, B tag-led, UNION of each genre, count | 67.1 | 273321 | 1 | r:PRIMARY rag:PRIMARY rag:PRIMARY |  |
| Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join, last page (mirrored: oldest first, offset 0, 23 rows) | 0.1 | 72 | 23 | r:ix_releases_band_posted rag:ix_release_audio_genres_release |  |
| Audio (stress): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, page 1 | 5.2 | 42911 | 50 | r:ix_releases_band_posted u:ix_release_audio_genres_release rag:PRIMARY |  |
| Audio (stress): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, worst page (offset 205150) | 151.8 | 808931 | 50 | r:ix_releases_band_posted u:ix_release_audio_genres_release rag:PRIMARY |  |
| Audio (stress): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, count (410359) | 280.4 | 1583153 | 1 | r:ix_releases_band_posted u:ix_release_audio_genres_release rag:PRIMARY |  |
| Audio (stress): Genre Rock + Unknown, two parts (B Rock + A Unknown as LEFT JOIN), page 1 | 36.9 | 127593 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), temporary |
| Audio (stress): Genre Rock + Unknown, two parts (LEFT JOIN), worst page (offset 205150) | 228.7 | 1253970 | 50 | rag:PRIMARY r:PRIMARY r:ix_releases_band_posted rag:ix_release_audio_genres_release | filesort(rag), temporary |
| Audio (stress): Genre Unknown + Year 2020s, C tag-led, candidate year index, LEFT JOIN anti-join, page 1 | 37.8 | 158292 | 50 | t:ix_release_audio_tags_year_cand rag:ix_release_audio_genres_release r:PRIMARY | filesort(t), temporary |
| Audio (stress): Genre Unknown + Year 2020s, C tag-led, LEFT JOIN anti-join, count | 39.2 | 149177 | 1 | t:ix_release_audio_tags_year_cand rag:ix_release_audio_genres_release r:PRIMARY |  |
| Audio (stress): Genre Unknown + Year 2020s, B tag-led, existing indexes, LEFT JOIN anti-join, page 1 | 61.7 | 320100 | 50 | t:ALL rag:ix_release_audio_genres_release r:PRIMARY | FULL SCAN of t, filesort(t), temporary |
| Audio (stress): Genre Unknown + Year 2020s, B tag-led, LEFT JOIN anti-join, count | 59.8 | 310985 | 1 | t:ALL rag:ix_release_audio_genres_release r:PRIMARY | FULL SCAN of t |
| Audio (stress): Genre Rock + search (Velmir), B genre-first, tag row joined, search on its columns, page 1 | 95.6 | 128620 | 50 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY | filesort(rag), temporary |
| Audio (stress): Genre Rock + search (Velmir), B genre-first, tag row joined, count | 94.7 | 128110 | 1 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY |  |
| Audio (stress): ALL (Lossless + 95%+ + Rock + 2020s + search Velmir), B genre-first, tag row joined, page 1 | 58.5 | 98167 | 27 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY | filesort(rag), temporary |
| Audio (stress): ALL, B genre-first, tag row joined, count | 58.4 | 98112 | 1 | rag:PRIMARY t:release_audio_tags_releases_id_unique r:PRIMARY |  |
| Audio (stress): Year 2020s + search (Velmir), C year-first, search on its columns, page 1 | 160.0 | 140664 | 50 | t:ix_release_audio_tags_year_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): Year 2020s + search (Velmir), C year-first, count | 157.1 | 139899 | 1 | t:ix_release_audio_tags_year_cand r:PRIMARY |  |

```sql
-- Audio (stress): Genre menu, loose index scan (DISTINCT genres_id of the PK, then genres)
select g.id, g.title from (select distinct rag.genres_id from release_audio_genres rag) x join genres g on g.id = x.genres_id where g.type = 3000 order by g.title, g.id;
-- Audio (stress): Genre menu, one probe per genre (scalar subquery, LIMIT 1)
select g.id, g.title from genres g where g.type = 3000 and (select rag.releases_id from release_audio_genres rag where rag.genres_id = g.id limit 1) is not null order by g.title, g.id;
-- Audio (stress): Genre menu, alternative with an Audio-band release, one probe per genre
select g.id, g.title from genres g where g.type = 3000 and (select rag.releases_id from release_audio_genres rag join releases r on r.id = rag.releases_id where rag.genres_id = g.id and r.categories_id between 3000 and 3999 limit 1) is not null order by g.title, g.id;
-- Audio (stress): Genre menu: is Unknown needed, LEFT JOIN anti-join LIMIT 1
select (select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and rag.releases_id is null limit 1) is not null;
-- Audio (stress): Genre Rock or Metal, B tag-led, GROUP BY on the PK, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag force index (primary) where rag.genres_id in (1000018,1000010) group by rag.releases_id) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock or Metal, B tag-led, GROUP BY on the PK, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag force index (primary) where rag.genres_id in (1000018,1000010) group by rag.releases_id) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Rock or Metal, B tag-led, UNION of each genre, page 1
select r.id from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id = 1000018 union select rag.releases_id from release_audio_genres rag where rag.genres_id = 1000010) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock or Metal, B tag-led, UNION of each genre, count
select count(*) from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id = 1000018 union select rag.releases_id from release_audio_genres rag where rag.genres_id = 1000010) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Unknown, A release-led LEFT JOIN anti-join, last page (mirrored: oldest first, offset 0, 23 rows)
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate asc, r.id asc limit 23;
-- Audio (stress): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, page 1
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres u on u.releases_id = r.id and u.position = 0 where r.category_band = 3000 and r.passwordstatus <= 0 and (u.releases_id is null or exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018))) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, worst page (offset 205150)
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_genres u on u.releases_id = r.id and u.position = 0 where r.category_band = 3000 and r.passwordstatus <= 0 and (u.releases_id is null or exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018))) order by r.postdate desc, r.id desc limit 50 offset 205150;
-- Audio (stress): Genre Rock + Unknown, A release-led, LEFT JOIN on position 0, count (410359)
select count(*) from releases r force index (ix_releases_band_posted) left join release_audio_genres u on u.releases_id = r.id and u.position = 0 where r.category_band = 3000 and r.passwordstatus <= 0 and (u.releases_id is null or exists (select 1 from release_audio_genres rag where rag.releases_id = r.id and rag.genres_id in (1000018)));
-- Audio (stress): Genre Rock + Unknown, two parts (B Rock + A Unknown as LEFT JOIN), page 1
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 50)) u order by postdate desc, id desc limit 50;
-- Audio (stress): Genre Rock + Unknown, two parts (LEFT JOIN), worst page (offset 205150)
select id from ((select r.id, r.postdate from (select rag.releases_id as id from release_audio_genres rag where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 205200) union all (select r.id, r.postdate from releases r force index (ix_releases_band_posted) left join release_audio_genres rag on rag.releases_id = r.id where r.category_band = 3000 and r.passwordstatus <= 0 and rag.releases_id is null order by r.postdate desc, r.id desc limit 205200)) u order by postdate desc, id desc limit 50 offset 205150;
-- Audio (stress): Genre Unknown + Year 2020s, C tag-led, candidate year index, LEFT JOIN anti-join, page 1
select r.id from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Unknown + Year 2020s, C tag-led, LEFT JOIN anti-join, count
select count(*) from (select t.releases_id as id from release_audio_tags t force index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Unknown + Year 2020s, B tag-led, existing indexes, LEFT JOIN anti-join, page 1
select r.id from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Unknown + Year 2020s, B tag-led, LEFT JOIN anti-join, count
select count(*) from (select t.releases_id as id from release_audio_tags t ignore index (ix_release_audio_tags_year_cand) left join release_audio_genres rag on rag.releases_id = t.releases_id where (t.recorded_year between 2020 and 2029) and rag.releases_id is null) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): Genre Rock + search (Velmir), B genre-first, tag row joined, search on its columns, page 1
select r.id from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Genre Rock + search (Velmir), B genre-first, tag row joined, count
select count(*) from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!'));
-- Audio (stress): ALL (Lossless + 95%+ + Rock + 2020s + search Velmir), B genre-first, tag row joined, page 1
select r.id from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): ALL, B genre-first, tag row joined, count
select count(*) from (select rag.releases_id as id, t.album, t.album_performer, t.performer from release_audio_genres rag straight_join release_audio_tags t on t.releases_id = rag.releases_id where rag.genres_id in (1000018) and (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and r.categories_id in (3040) and r.completion >= 95 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!'));
-- Audio (stress): Year 2020s + search (Velmir), C year-first, search on its columns, page 1
select r.id from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!')) order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): Year 2020s + search (Velmir), C year-first, count
select count(*) from (select t.releases_id as id, t.album, t.album_performer, t.performer from release_audio_tags t force index (ix_release_audio_tags_year_cand) where (t.recorded_year between 2020 and 2029)) g straight_join releases r on r.id = g.id where r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 and (coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%Velmir%' escape '!' or (g.album like '%Velmir%' escape '!' or g.album_performer like '%Velmir%' escape '!' or g.performer like '%Velmir%' escape '!'));
```

### Details page (real size, `q-details-real.tsv`)

Schema `audc`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (real): details, the tag row + genres in order (release 1932887) | 0.1 | 4 | 1 | t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (real): details, newest evidence revision (revision 2) | 0.0 | 1 | 1 | e:release_audio_evidence_releases_id_revision_unique |  |
| Audio (real): details, track list of that evidence (by its id) | 0.1 | 3 | 2 | tr:audio_evidence_track_source |  |
| Audio (real): details, track list in ONE query (newest evidence as a subquery) | 0.1 | 4 | 2 | tr:audio_evidence_track_source e:release_audio_evidence_releases_id_revision_unique |  |
| Audio (real): details, MusicBrainz release group for the newest evidence hash (newest row by id) | 0.1 | 3 | 1 | i:release_music_identity_version | filesort(i) |
| Audio (real): details, MusicBrainz release group in ONE query (newest evidence joined) | 0.1 | 8 | 1 | i:release_music_identity_version e:release_audio_evidence_releases_id_revision_unique |  |
| Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), band 3000 only, release_audio_tags_album_index, page 1 | 0.1 | 14 | 2 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, biggest album, band 3000 only, release_audio_tags_album_index, count | 0.1 | 9 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), any band, release_audio_tags_album_index, page 1 | 0.1 | 18 | 4 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, biggest album, any band, release_audio_tags_album_index, count | 0.1 | 9 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1 | 0.1 | 14 | 2 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, count | 0.1 | 9 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1 | 0.1 | 18 | 4 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, biggest album, any band, ix_release_audio_tags_album_artist_cand, count | 0.1 | 9 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (real): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1 | 0.1 | 15 | 2 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count | 0.1 | 10 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), band 3000 only, release_audio_tags_album_index, page 1 | 0.1 | 34 | 1 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, the most common album name, band 3000 only, release_audio_tags_album_index, count | 0.1 | 31 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), any band, release_audio_tags_album_index, page 1 | 0.1 | 34 | 1 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, the most common album name, any band, release_audio_tags_album_index, count | 0.1 | 31 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1 | 0.1 | 6 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, count | 0.1 | 3 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1 | 0.1 | 6 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, the most common album name, any band, ix_release_audio_tags_album_artist_cand, count | 0.1 | 3 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (real): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1 | 0.1 | 7 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (real): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count | 0.1 | 4 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |

```sql
-- Audio (real): details, the tag row + genres in order (release 1932887)
select t.*, coalesce(t.album_performer, t.performer) as artist, (select group_concat(g.title order by rag.position separator '|') from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id = t.releases_id) as genres from release_audio_tags t where t.releases_id = 1932887;
-- Audio (real): details, newest evidence revision (revision 2)
select e.id, e.evidence_hash, e.revision from release_audio_evidence e where e.releases_id = 1932887 order by e.revision desc limit 1;
-- Audio (real): details, track list of that evidence (by its id)
select tr.source_kind, tr.source_ordinal, tr.disc_number, tr.track_number, tr.title, tr.performer, tr.raw_filename, tr.whole_duration_seconds, tr.codec from release_audio_evidence_tracks tr where tr.release_audio_evidence_id = 1760 order by tr.source_kind, tr.source_ordinal;
-- Audio (real): details, track list in ONE query (newest evidence as a subquery)
select tr.source_kind, tr.source_ordinal, tr.disc_number, tr.track_number, tr.title, tr.performer, tr.raw_filename, tr.whole_duration_seconds, tr.codec from release_audio_evidence_tracks tr where tr.release_audio_evidence_id = (select e.id from release_audio_evidence e where e.releases_id = 1932887 order by e.revision desc limit 1) order by tr.source_kind, tr.source_ordinal;
-- Audio (real): details, MusicBrainz release group for the newest evidence hash (newest row by id)
select i.id, i.state, i.musicbrainz_release_group_id, i.algorithm_version from release_music_identifications i where i.releases_id = 1932887 and i.evidence_hash = '6688236c25b579cc8a26de114239de56319241eee4511746618d1993c00f286e' and i.state in ('accepted_release_group', 'accepted_edition') and i.musicbrainz_release_group_id is not null order by i.id desc limit 1;
-- Audio (real): details, MusicBrainz release group in ONE query (newest evidence joined)
select i.id, i.state, i.musicbrainz_release_group_id, i.algorithm_version from (select e.releases_id, e.evidence_hash from release_audio_evidence e where e.releases_id = 1932887 order by e.revision desc limit 1) e join release_music_identifications i on i.releases_id = e.releases_id and i.evidence_hash = e.evidence_hash where i.state in ('accepted_release_group', 'accepted_edition') and i.musicbrainz_release_group_id is not null order by i.id desc limit 1;
-- Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), band 3000 only, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, biggest album, band 3000 only, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), any band, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, biggest album, any band, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, biggest album (Album A / Artist A: 4 tag rows; 4 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, biggest album, any band, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and coalesce(t.album_performer, t.performer) = 'Artist A' and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and (t.album_performer = 'Artist A' or (t.album_performer is null and t.performer = 'Artist A')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album A' and (t.album_performer = 'Artist A' or (t.album_performer is null and t.performer = 'Artist A')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), band 3000 only, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, the most common album name, band 3000 only, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), any band, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, the most common album name, any band, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, the most common album name (Album B / Artist B: 1 tag rows; 29 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, the most common album name, any band, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and coalesce(t.album_performer, t.performer) = 'Artist B' and r.passwordstatus <= 0;
-- Audio (real): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and (t.album_performer = 'Artist B' or (t.album_performer is null and t.performer = 'Artist B')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (real): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Album B' and (t.album_performer = 'Artist B' or (t.album_performer is null and t.performer = 'Artist B')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
```

### Details page (stress size, `q-details-stress.tsv`)

Schema `auds`.

| Query | ms (best of 3, warm) | rows read | rows returned | index used (EXPLAIN) | sort / scan |
|---|---:|---:|---:|---|---|
| Audio (stress): details, the tag row + genres in order (release 6881) | 0.1 | 4 | 1 | t:release_audio_tags_releases_id_unique rag:ix_release_audio_genres_release g:PRIMARY |  |
| Audio (stress): details, newest evidence revision (revision 2) | 0.0 | 1 | 1 | e:release_audio_evidence_releases_id_revision_unique |  |
| Audio (stress): details, track list of that evidence (by its id) | 0.1 | 10 | 9 | tr:audio_evidence_track_source |  |
| Audio (stress): details, track list in ONE query (newest evidence as a subquery) | 0.1 | 11 | 9 | tr:audio_evidence_track_source e:release_audio_evidence_releases_id_revision_unique |  |
| Audio (stress): details, MusicBrainz release group for the newest evidence hash (newest row by id) | 0.1 | 4 | 1 | i:release_music_identity_version | filesort(i) |
| Audio (stress): details, MusicBrainz release group in ONE query (newest evidence joined) | 0.1 | 10 | 1 | i:release_music_identity_version e:release_audio_evidence_releases_id_revision_unique |  |
| Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), band 3000 only, release_audio_tags_album_index, page 1 | 0.1 | 139 | 30 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, biggest album, band 3000 only, release_audio_tags_album_index, count | 0.1 | 78 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), any band, release_audio_tags_album_index, page 1 | 0.1 | 139 | 30 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, biggest album, any band, release_audio_tags_album_index, count | 0.1 | 78 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1 | 0.1 | 124 | 30 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, count | 0.1 | 63 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1 | 0.1 | 124 | 30 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, biggest album, any band, ix_release_audio_tags_album_artist_cand, count | 0.1 | 63 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (stress): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1 | 0.1 | 125 | 30 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count | 0.1 | 64 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), band 3000 only, release_audio_tags_album_index, page 1 | 0.5 | 772 | 15 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, the most common album name, band 3000 only, release_audio_tags_album_index, count | 0.6 | 741 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), any band, release_audio_tags_album_index, page 1 | 0.7 | 772 | 15 | t:release_audio_tags_album_index r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, the most common album name, any band, release_audio_tags_album_index, count | 0.6 | 741 | 1 | t:release_audio_tags_album_index r:PRIMARY |  |
| Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1 | 0.1 | 62 | 15 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, count | 0.1 | 31 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1 | 0.2 | 62 | 15 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, the most common album name, any band, ix_release_audio_tags_album_artist_cand, count | 0.1 | 31 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |
| Audio (stress): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1 | 0.1 | 63 | 15 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY | filesort(t), temporary |
| Audio (stress): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count | 0.1 | 32 | 1 | t:ix_release_audio_tags_album_artist_cand r:PRIMARY |  |

```sql
-- Audio (stress): details, the tag row + genres in order (release 6881)
select t.*, coalesce(t.album_performer, t.performer) as artist, (select group_concat(g.title order by rag.position separator '|') from release_audio_genres rag join genres g on g.id = rag.genres_id where rag.releases_id = t.releases_id) as genres from release_audio_tags t where t.releases_id = 6881;
-- Audio (stress): details, newest evidence revision (revision 2)
select e.id, e.evidence_hash, e.revision from release_audio_evidence e where e.releases_id = 6881 order by e.revision desc limit 1;
-- Audio (stress): details, track list of that evidence (by its id)
select tr.source_kind, tr.source_ordinal, tr.disc_number, tr.track_number, tr.title, tr.performer, tr.raw_filename, tr.whole_duration_seconds, tr.codec from release_audio_evidence_tracks tr where tr.release_audio_evidence_id = 410 order by tr.source_kind, tr.source_ordinal;
-- Audio (stress): details, track list in ONE query (newest evidence as a subquery)
select tr.source_kind, tr.source_ordinal, tr.disc_number, tr.track_number, tr.title, tr.performer, tr.raw_filename, tr.whole_duration_seconds, tr.codec from release_audio_evidence_tracks tr where tr.release_audio_evidence_id = (select e.id from release_audio_evidence e where e.releases_id = 6881 order by e.revision desc limit 1) order by tr.source_kind, tr.source_ordinal;
-- Audio (stress): details, MusicBrainz release group for the newest evidence hash (newest row by id)
select i.id, i.state, i.musicbrainz_release_group_id, i.algorithm_version from release_music_identifications i where i.releases_id = 6881 and i.evidence_hash = '706ff51ed9e55e1ead3aca513b453906d724a326b00e85020ab9789413365086' and i.state in ('accepted_release_group', 'accepted_edition') and i.musicbrainz_release_group_id is not null order by i.id desc limit 1;
-- Audio (stress): details, MusicBrainz release group in ONE query (newest evidence joined)
select i.id, i.state, i.musicbrainz_release_group_id, i.algorithm_version from (select e.releases_id, e.evidence_hash from release_audio_evidence e where e.releases_id = 6881 order by e.revision desc limit 1) e join release_music_identifications i on i.releases_id = e.releases_id and i.evidence_hash = e.evidence_hash where i.state in ('accepted_release_group', 'accepted_edition') and i.musicbrainz_release_group_id is not null order by i.id desc limit 1;
-- Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), band 3000 only, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, biggest album, band 3000 only, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), any band, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, biggest album, any band, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, biggest album (Bridrugal / Various Artists: 31 tag rows; 46 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, biggest album, any band, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and coalesce(t.album_performer, t.performer) = 'Various Artists' and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and (t.album_performer = 'Various Artists' or (t.album_performer is null and t.performer = 'Various Artists')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, biggest album, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Bridrugal' and (t.album_performer = 'Various Artists' or (t.album_performer is null and t.performer = 'Various Artists')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), band 3000 only, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, the most common album name, band 3000 only, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), any band, release_audio_tags_album_index, page 1
select r.id from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, the most common album name, any band, release_audio_tags_album_index, count
select count(*) from release_audio_tags t force index (release_audio_tags_album_index) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), band 3000 only, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, the most common album name (Unplugged / Helwex Zokaka & The Nexcals: 15 tag rows; 725 with that album name), any band, ix_release_audio_tags_album_artist_cand, page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, the most common album name, any band, ix_release_audio_tags_album_artist_cand, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and coalesce(t.album_performer, t.performer) = 'Helwex Zokaka & The Nexcals' and r.passwordstatus <= 0;
-- Audio (stress): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR (alternative), page 1
select r.id from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and (t.album_performer = 'Helwex Zokaka & The Nexcals' or (t.album_performer is null and t.performer = 'Helwex Zokaka & The Nexcals')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- Audio (stress): All releases of this album, the most common album name, band 3000 only, ix_release_audio_tags_album_artist_cand, artist as OR, count
select count(*) from release_audio_tags t force index (ix_release_audio_tags_album_artist_cand) straight_join releases r on r.id = t.releases_id where t.album = 'Unplugged' and (t.album_performer = 'Helwex Zokaka & The Nexcals' or (t.album_performer is null and t.performer = 'Helwex Zokaka & The Nexcals')) and r.categories_id between 3000 and 3999 and r.passwordstatus <= 0;
```
