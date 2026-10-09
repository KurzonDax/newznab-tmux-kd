# NNTmux Audio section: data contract

Written 2026-10-04 after the Audio screens were approved (`SPEC.md` 5, 5A, 5B, 5C). It says what is stored, where, which
existing code writes it, how existing rows fill in, and what every screen reads, each read measured at full catalogue
size. It follows the maintainer's schema rules: **normalize wherever possible; nothing about releases may be specific to
one category; downtime is not a concern.**

Every claim about the application was read from the code on `master` at `f8365df5f` and carries a `path:line` (line
numbers move: re-check before relying on one). Every query shape was run on the restored production catalogue in the
maintainer's query lab at full size (**2,359,525 releases**; backup of 2026-09-20; 3,645 Audio releases, 548 tag rows),
and on a **stress copy** in which the Movies band was moved into the Audio sub-categories (**578,754 Audio releases**,
231,757 synthetic tag rows, 225,247 genre links, 4,533,066 synthetic tracks, 17,700 synthetic identifications). The lab
write-up is `evidence/audio-data-contract.md`. Times are the best of three warm runs; rows read are InnoDB handler reads.
**Every tag, track and identification value in the stress copy is synthetic**; only its releases are real.

Repository conventions followed (as in `../books-console-pc-redesign/DATA-CONTRACT.md`): anonymous-class migrations with
`declare(strict_types=1)`, the Schema builder, `->comment()` on new columns, explicitly named indexes of at most 64
characters (`tests/Unit/MigrationIdentifierLengthTest.php`), a working `down()`, plural-table key columns, no triggers,
model casts through `casts()`, explicit relationship keys; `database/schema/mariadb-schema.sql` refreshed with
`schema:dump` in every migration PR; tests build tables with `Tests\Support\ProductionTables::fromAuthority()`.

---

## 1. Facts about the application that shape the design

1. **The redesigned lists share one machine.** `BandReleaseList` (`app/Services/Releases/BandReleaseList.php:28`) gives
   the cached count (`:68`), the page read mirrored past the middle on `ix_releases_band_*` (`:96-118`), the `_cat_` /
   `_res_` / `_src_` index choice (`releaseIndex()`, `:125-139`), the Category menu (`:171`) and its counts
   (`:195`); `ShelfReleaseList` (`app/Services/Releases/ShelfReleaseList.php`) adds the name search
   `COALESCE(NULLIF(TRIM(display_name), ''), searchname) LIKE ? ESCAPE '!'` (`:34`, `:97-100`). `ConsoleReleaseList`
   (`app/Services/Releases/ConsoleReleaseList.php`) adds the title search as an `IN` list over `consoleinfo` (`:172-178`)
   and a title-led read for Genre / Year: a derived table of matching ids `STRAIGHT_JOIN releases` (`:192-200`); its
   Genre menu is cached for an hour (`:105-120`). Filters: `ShelfReleaseFilters` / `ConsoleReleaseFilters`
   (`app/Data/ConsoleReleaseFilters.php:22`, keys `genre`, `decade`, `year_from`, `year_to`); "Exclude Other" is
   `ReleaseListFilters::EXCLUDE_OTHER` (`app/Data/ReleaseListFilters.php:47`, `:117-122`, `:150-169`). Rows:
   `ShelfReleaseRows` (`app/Services/Releases/ShelfReleaseRows.php:19`) and `ConsoleReleaseRows` (`:28-35`, one query
   for the page's titles). Views `resources/views/shelf/releases/{index,list,row}.blade.php`; CSS `resources/css/tv.css`
   (`.is-shelf` `:332-348`). Routes `/books`, `/pc` (`routes/web.php:249-250`), `/console` (`:252`), inside `clearance`.
2. **The header** maps a root to its list route in `resources/views/partials/header-menu.blade.php:7`; Audio has no arm,
   so "All Audio" and the sub-category items fall back to `/browse/audio` (`:11`, `:14`). `GlobalDataComposer::
   currentSection()` (`app/View/Composers/GlobalDataComposer.php:131-142`) underlines the header button by path for the
   redesigned roots, Audio only through the old `browse` / `title` routes.
3. **Remembered choices**: `RememberedListFilters` (`app/Services/Releases/RememberedListFilters.php:29`, `:136-152`)
   keeps `view_prefs[<root>]['filters']`; `UpdateReleaseViewRequest` accepts a saved `sort` only for TV, Movies, Adult,
   Books, Games and Console (`app/Http/Requests/UpdateReleaseViewRequest.php:28`). `BrowseRoot::Audio` is `'audio'`
   (`app/Enums/BrowseRoot.php:14`).
4. **Details dispatch** is by root in `DetailsController::show` (`app/Http/Controllers/DetailsController.php:48`, `:75`):
   TV, Movies, Adult, Console with a game (`showConsoleGame`, `:85`, `:292-315`), Books / PC / Console without one
   (`showShelf` → `details.shelf.index`, `:88`, `:269-285`). **Audio falls through to the old page** (`:91-193`,
   `details.index`). Console's "All N releases of this game" is `ConsoleGamePage` (`app/Services/Releases/ConsoleGamePage.php:83-112`,
   `:177-183`), opening on the page that holds the release (`ConsoleGameReleaseDetails.php:89-104`); its Similar
   releases drop the same game (`:114-128`) from `ReleaseSearchService::searchSimilar()`
   (`app/Services/Search/ReleaseSearchService.php:1390`). The shelf tabs show Media info only when the row has it
   (`resources/views/details/shelf/tabs.blade.php:12-16`, `:44`; `ReleaseRowFacts.php:97`).
5. **The tags are written in one place.** `AudioReleaseProcessor::recordTags()`
   (`app/Services/AudioProcessing/AudioReleaseProcessor.php:271-296`) runs `ReleaseAudioTag::updateOrCreate(['releases_id'
   => …], $tags)` (`:286`) with what `AudioTagExtractor::extract()` read
   (`app/Services/AdditionalProcessing/AudioTagExtractor.php`: `genre` the raw MediaInfo value, its first scalar, cut to
   100 characters; `recorded_year` the first 19xx / 20xx in `recorded_date`; null when the file has neither album nor
   performer). `recordPreview()` writes only the preview columns on the same row (`:321-331`);
   `RequeueAudioPreviews` clears them (`app/Console/Commands/RequeueAudioPreviews.php:203`). Nothing else writes the
   table. `release_audio_tags` has one row per release (unique `releases_id`), an index on `album`
   (`release_audio_tags_album_index`) and none on `genre` or `recorded_year` (`database/schema/mariadb-schema.sql:2592`).
6. **Genres** are the shared `genres` table (`mariadb-schema.sql:678`: `id int unsigned`, `title`, `type`,
   `disabled`; key `(type, disabled)`); music genres are `type = GenreService::MUSIC_TYPE = Category::MUSIC_ROOT`
   (`app/Services/GenreService.php:16`). Console keeps its genres one row per genre in `console_genres` through
   `ConsoleGenres` (`app/Services/MetadataProcessing/ConsoleGenres.php`: `ids()` resolves a name to the lowest-id genre
   of the type, creating it under a lock, `:42`; `replace()` writes the rows through `ChildRows::replace()`, `:63`;
   `app/Support/ChildRows.php:26`) and filled them by a chunked, re-runnable migration
   (`database/migrations/2026_10_01_000100_fill_console_genres.php`).
7. **The preview and the spectrogram.** `GET /preview/audio/{guid}` (`routes/web.php:123-126`,
   `app/Http/Controllers/AudioPreviewController.php:23-106`) needs a login, a tag row with `has_preview = 1` and the
   hidden-category gate, and serves `covers/audiosample/{guid}.{ext}` with Range support. The spectrogram is
   `/covers/audiosample/{guid}_spectrum.png` (`app/Extensions/helper/helpers.php:546-551`), served by
   `CoverController::show` (`routes/web.php:112-116`), which has **no login and no hidden-category check**: a finding,
   left as it is (`SPEC.md` 9). Today's player is `components/audio-preview-player.blade.php`; the Listen chip and the
   preview modal are `components/release-facts.blade.php:50-63`, `partials/preview-modal.blade.php:6-14` and
   `resources/js/alpine/components/preview-modal-component.js:59-85`, `:188-215`; the row data comes from
   `ReleasePreviewDataLoader` (`app/Services/Releases/ReleasePreviewDataLoader.php:35-105`).
8. **Track lists.** `release_audio_evidence` holds revisions per release (unique `(releases_id, revision)`), each new one
   `max + 1` (`AudioEvidenceRecorder::persist()`, `app/Services/AudioProcessing/AudioEvidenceRecorder.php:119-179`), the
   newest being the highest `revision`, as `ResolveReleaseMusicIdentity` (`app/Services/MusicIdentity/ResolveReleaseMusicIdentity.php:90-93`)
   and `MusicIdentityCandidateQuery` (`app/Services/MusicIdentity/MusicIdentityCandidateQuery.php:80-90`) read it; its tracks are
   `release_audio_evidence_tracks` keyed `(release_audio_evidence_id, source_kind, source_ordinal)`, with
   `raw_filename`, `disc_number`, `track_number`, `title` and `whole_duration_seconds`. Nothing reads them for display.
9. **MusicBrainz identifications.** `release_music_identifications` (unique `(releases_id, evidence_hash,
   algorithm_version)`); states `accepted_release_group` (release group id), `accepted_edition` (release and release
   group ids), `accepted_recording` (recording id only), and the unaccepted ones
   (`app/Services/MusicIdentity/Enums/IdentificationStatus.php:9-16`). The worker's current row is the one for the newest
   evidence's hash and the configured `music-identity.algorithm_version`
   (`app/Services/MusicIdentity/MusicIdentityCandidateQuery.php:141-150`). Nothing reads them for display.
10. **Media info.** `MediaInfoPresentationService::forRelease()` (`app/Services/MediaInfo/MediaInfoPresentationService.php:28`)
    returns the streams with `language_name` and, for a release with tags, `music_tags` with `track_title` (from the
    snapshot or `release_audio_tags.track_name`, `:86-100`, `:185-201`). The tab and the dialog render the audio table
    in `resources/js/alpine/components/media-info-block.js:173-189` with the Language column.
11. **What links to the pages the design retires**: the header (fact 2); the old details page's breadcrumb
    (`resources/views/details/index.blade.php:12-13`) and "Other releases of this title" (`RelatedReleaseBrowser`,
    `app/Services/Releases/RelatedReleaseBrowser.php:19-41`, included at `details/index.blade.php:61`); the album title
    chip, `ReleaseEntityData::titleUrl()` for root `audio` (`app/Data/ReleaseEntityData.php:25`) from
    `ReleaseEntityDataLoader` (`MUSIC_ROOT => 'audio'`, `:23`, `:29-30`), shown by `components/release-browser/origin.blade.php:2`,
    `:6`, `expanded-cover.blade.php:5`, `:13`, `content/home.blade.php:17` and `details/partials/related.blade.php:7`;
    the cover browser (`app/Services/Releases/ReleaseCoverBrowser.php:101`); the title page's breadcrumb
    (`title/index.blade.php:5`); the legacy `/Audio` redirect (`app/Http/Controllers/MusicController.php:16`,
    `app/Services/Releases/LegacyCoverRedirect.php:15-51`); `/browse/{parentCategory}` (`routes/web.php:200`,
    `BrowseController.php:25-45`, the `music` alias in `BrowseRoot::fromRoute`, `:21-28`) and `/title/{root}/{id}`
    (`routes/web.php:213`, `TitleController.php:14-30`). The admin music pages (`routes/web.php:424-425`) and the frozen
    API and RSS stay.

---

## 2. New storage

### 2.1 `audio_genres` and `release_audio_genres` (a release's genres, one row per genre)

```sql
CREATE TABLE `audio_genres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'A genre name as an audio tag or MusicBrainz writes it',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_audio_genres_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `release_audio_genres` (
  `releases_id` int(10) unsigned NOT NULL,
  `audio_genres_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL COMMENT '0-based order of the genre in the release''s genre list',
  PRIMARY KEY (`audio_genres_id`,`releases_id`),
  KEY `ix_release_audio_genres_release` (`releases_id`,`position`),
  CONSTRAINT `fk_release_audio_genres_audio_genres_id` FOREIGN KEY (`audio_genres_id`) REFERENCES `audio_genres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_release_audio_genres_releases_id` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
```

- Styled as `release_audio_languages` (#828) and `console_genres` (#917). A row per genre of a release's genres: its
  accepted MusicBrainz album's genres (2.4, 3.4) when its current music identity accepts an album whose release group
  has genre rows, else its tag value's genres; for **every release with audio tags, whatever its category** (the tag
  table is not per category either). A release without a tag row has none.
- The names are `audio_genres` rows (unique on `name` under the table's `utf8mb4_unicode_ci`), one per name; a name
  resolves case-insensitively to its row, created when none exists. Names that differ only by case or accent share one
  row (`house`/`House`, `Opera`/`Opéra`), and screens show the row's name, the first one stored; the prototype's menu,
  which lists both spellings, is overridden on this point only. MusicBrainz's lower-case names share rows with the tag
  spellings the same way (`rock` shows the stored `Rock`). The names are kept out of `genres`, so the frozen API
  capabilities genre list and the admin music form never list them (the #826 rule).
- **"Unknown" stores nothing.** A tag value of `Unknown` (any case) is dropped, so Unknown on the list is one test, "no
  `release_audio_genres` row", which covers no tag row, a tag row without a genre and a genre of only "Unknown" alike
  (evidence: conclusions 4).
- **`release_audio_tags.genre` stays** as written: the media info dialog shows it (`music_tags.genre`, fact 10).
- Size at stress: 12.0 MB for 225,247 links (primary key 7.5 MB, release key 4.5 MB).

### 2.2 Index `ix_release_audio_tags_recorded_year`

`release_audio_tags (recorded_year, releases_id)`, 4.5 MB at stress. It makes the Year reads tag-led on an index
(decade 2020s 67-70 ms at stress against 97-102 ms scanning every tag row; it removes a ~20 ms floor from every small
Year read). Not required for correctness; proposed because it is cheap and cuts every Year read.

### 2.4 `musicbrainz_release_group_genres` (an accepted album's MusicBrainz genres, issue #313)

```sql
CREATE TABLE `musicbrainz_release_group_genres` (
  `musicbrainz_release_group_id` char(36) NOT NULL,
  `audio_genres_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL COMMENT '0-based: vote count highest first, then name A to Z',
  PRIMARY KEY (`musicbrainz_release_group_id`,`position`),
  UNIQUE KEY `ux_mb_release_group_genres_genre` (`musicbrainz_release_group_id`,`audio_genres_id`),
  KEY `fk_mb_release_group_genres_audio_genres_id` (`audio_genres_id`),
  CONSTRAINT `fk_mb_release_group_genres_audio_genres_id` FOREIGN KEY (`audio_genres_id`) REFERENCES `audio_genres` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

KEY `release_music_identity_release_group` (`musicbrainz_release_group_id`) -- on release_music_identifications
```

- MusicBrainz genres are a fact about the release group, shared by every release of the album, so they are stored
  once per group, on the `audio_genres` lookup (names as MusicBrainz writes them, no cleaning). The new index on
  `release_music_identifications` finds a group's other releases (3.4).

### 2.3 What is not stored

- **Covers.** No audio cover is stored that can be trusted; the cover slots show the No cover tile until the
  MusicBrainz integration stores album art (`SPEC.md` 6.2). Nothing in this contract reads `musicinfo`.
- No album key, no copy of the artist or album on another table: the tag row is the one source; "All N releases of this
  album" reads it on the existing album index (4.5).
- No new index for the album siblings (measured, 4.5) or the tag search (a covering index was tried: 68 against 71 ms,
  the cost is the `LIKE` work).

---

## 3. Write paths

### 3.1 The tag genres: `AudioReleaseProcessor::recordTags()`

When `recordTags()` writes the tag row (fact 5), the release's `release_audio_genres` rows are replaced in the same
transaction by the shared rule (`AudioGenres::effectiveIds()`, 3.4): an accepted album's MusicBrainz genres when the
release's current decision accepts one whose release group has genre rows, so re-reading tags never replaces them;
otherwise the tag genres, through a class with the `ConsoleGenres` method shape (`App\Services\AudioProcessing\AudioGenres`, the same
`ids()` / `replace()` / `stored()` shape): the tag's `genre` split on `;` and on ` / ` (a slash with a space on each
side), each part trimmed, empty parts and `Unknown` (any case) dropped, a name repeated in one value kept once
(case-insensitively), each resolved to its `audio_genres` row (inserted with insert-or-ignore on the unique name and
read back, as `ReleaseDerivedFacts::languageId()` does), positions 0, 1, 2 in the value's order, written with
`ChildRows::replace()` keyed on `releases_id` (parent table `releases`), the tag row's `updateOrCreate` passed as the
`alsoWrite` closure so both commit together. A tag value with no genre writes no rows (and removes stored ones). When
the extractor returns no tags (`$tags === null`) nothing is written, as today. `recordPreview()` and
`RequeueAudioPreviews` never touch the genres.

The `;` and ` / ` rule is the only split (`SPEC.md` 5.8; the maintainer's decision of 2026-10-04): on the lab's 548 real
tag rows (backup of 2026-09-20) none holds a `;`, 12 hold a comma and 6 an unspaced slash (`Synth-pop, Disco`,
`Pop/Rock`), and each of those is one genre, as written. Production has since gained tag values that hold several
genres: 8 joined with ` / ` (`Pop / Rock`, `Rock / Folk Rock / Psychedelic Rock / Classic Rock`) and 1 with `;`; those
are split. The real fill also creates `Hip Hop` beside `Hip-Hop` (both are tag values) and a few junk values
(`www.mp3-ogg.ru`, one base64 string): genres are taken as written.

### 3.2 Fill for existing rows (migration)

A second migration after the table's (the #917 pair: create, then fill) reads `release_audio_tags` rows with a genre in
primary-key chunks and writes each release's rows through `AudioGenres` (3.1), leaving a release whose stored rows
already match, so a failed run can be re-run. No Artisan command. On production 680 of the 865 tag rows on audio
releases have a genre; the fill reads those and any genre on the 9 tag rows of releases filed elsewhere (874 tag rows in
all, `DATA-NOTES.md`); the lab's set-based form of the same fill (two `INSERT … SELECT`, join order forced) wrote
225,247 links in 5.6 s at stress, so the fill is bounded by the tag table, which grows only with previewed audio
releases.

### 3.3 The Year index

Added by the storage migration with the table; `schema:dump` refreshed in the same PR.

### 3.4 MusicBrainz genres (issue #313)

- **Lookup.** When `MusicIdentityResolver` decides `accepted_release_group` or `accepted_edition`, it reads the accepted
  release group's genres with one release-group lookup through the gateway (`MusicBrainzGateway::releaseGroup()`: the
  hydration request, with its pacing, circuit breaker and response cache, so a group the resolution already hydrated
  is a cache hit; the group's editions are not browsed). An edition uses its release group's genres. A recording
  acceptance makes no lookup. A failed lookup makes the attempt a retryable error, so an accepted album is never stored
  without the lookup's answer. The normalizer reads `genres` as name and vote count; a payload without them gives none.
  Genres never score.
- **Store.** `IdentificationDecisionStore::persist()` replaces the group's `musicbrainz_release_group_genres` rows
  from the newest lookup in the decision's transaction, vote count highest first, then name A to Z; no genres (or no
  group) leaves the group without rows. It deletes only when the group holds rows (the `ChildRows` gap-lock rule). No
  other code writes the table, and nothing refreshes it in the background.
- **The release's rows.** After the commit, beside the search re-sync, the written release's `release_audio_genres` rows
  are re-derived by the shared rule (`AudioGenres::rederive()`); a failure is logged and never fails the decision. That
  covers a new acceptance, a replacement by another album and a withdrawal by a completed non-album decision or an
  album without genres. When the group's rows changed, every other release whose current decision accepts that group
  is re-derived too, found through the new index.
- **Existing decisions.** `music-identity.algorithm_version` is `music-identity-v3`; the worker re-resolves eligible
  releases under it, through the gateway's pacing. No migration fills genre rows, and there is no command.
- The reads (4.3, 4.5) are unchanged: they read `release_audio_genres` with the same queries and indexes.

---

## 4. Reads (each measured; `evidence/audio-data-contract.md`)

Real size: **every read 6.7 ms or less**. Stress figures below are best of three warm, ms / rows read.

### 4.1 The list without music filters

Today's `ShelfReleaseList` on band 3000 (`categories_id` 3000-3999, `passwordstatus <= 0` by default), unchanged:
page 1 0.1 ms; the worst (middle) page 41-48 ms (~285,000 rows); the count 53-62 ms (578,755 rows), cached as today.
Category, Exclude Other and Completion as every list; two sub-categories that hold at most half the band read the
`_cat_` index as two ranges and sort them (40.6 ms page 1, 59.3 ms worst page), today's behaviour everywhere. The
Category menu counts are today's (89.8 ms at stress, cached an hour).

### 4.2 The name search

Release name **or** the tags: `… OR releases.id IN (SELECT releases_id FROM release_audio_tags WHERE album LIKE ? OR
album_performer LIKE ? OR performer LIKE ?)`, the words escaped as today, as Console's title search. Page 1 70-91 ms
(522 ms when nothing matches); count 488-532 ms (810,713-1,389,467 rows), against 395-412 ms for today's release-name
search alone: the tag side adds about 85-110 ms, one pass over the tag rows. `EXISTS` has the same plan; the `LEFT JOIN
… OR` form is rejected (878-915 ms).

### 4.3 Genre

- **One or more genres: tag-led**, as Console: the genres' primary-key ranges of `release_audio_genres` (`DISTINCT
  releases_id` for several) `STRAIGHT_JOIN releases` on `PRIMARY`, the band and the release filters on it, sorted by the
  date; the same cost on every page. Rock (41,836, the largest): 37.1 ms page and count; Rock or Metal (66,597):
  65.7 ms.
- **Unknown alone: release-led**, as the Audio-language Unknown (`BandReleaseList::whereAudio()`, `:253`): `LEFT JOIN
  release_audio_genres g ON g.releases_id = r.id AND g.position = 0 … WHERE g.releases_id IS NULL` on the band index.
  Page 1 and the last page 0.1 ms; the middle page 130.8 ms; the count 241.3 ms (`NOT EXISTS` counts in 148 ms; either,
  cached). The Console two-part `UNION ALL` is not used (274 ms on the middle page).
- **Genres + Unknown**: release-led, `(EXISTS genre OR no position-0 row)`: 5.2-36.9 ms page 1, 152 ms middle page,
  200-280 ms count.
- **Genre menu**: `SELECT DISTINCT audio_genres_id FROM release_audio_genres` (a loose scan of the primary key) joined
  to `audio_genres`, keeping a genre only while a band-3000 release has it (one `LIMIT 1` probe per genre), A to Z,
  then Unknown when `release_audio_genres` has no row for some band release (`LEFT JOIN … IS NULL LIMIT 1`): 0.2 +
  0.6 + 0.1 ms. Cached for an hour as Console's. (`EXISTS` forms are materialised by MariaDB: 24-130 ms; not used.)
  The figures were measured with the links pointing at `genres`, where the join is likewise one primary-key lookup per
  genre (`genres` primary key, `mariadb-schema.sql`).
- **Combined with the name search** (measured for the list build, issue #962; best of three warm on the stress copy,
  578,754 Audio releases and 231,757 synthetic tag rows, MariaDB 11.4.13; page / count). Release-led reads keep the IN
  list of 4.2 beside the anti-join: Unknown + a word 156.9 / 514.8 ms (a word nothing contains: 470.7 ms count); Rock +
  Unknown + a word 109.2 / 524.2 ms. The release-led counts read `ix_releases_band_count`: Unknown alone 238.0 ms
  (242.9 ms on `ix_releases_band_posted`), Rock + Unknown 267.2 ms. Genres without Unknown are genre first: `g` is the
  `DISTINCT releases_id` of the genres' links, the tag row joined after `g` only while a Year or the name search reads
  it, and the word tested on that tag row: Rock + a word 102.1 ms; Rock or Metal + a word 154.9 / 156.5 ms (`DISTINCT`
  over the tag columns inside `g` instead: 267.7 ms, not used). Real-size copy: Unknown + a word count 4.7 ms.

### 4.4 Year

- **Tag-led on the Year index** (2.2): decades and ranges as `recorded_year` ranges `STRAIGHT_JOIN releases`: the 2020s
  67.1-69.8 ms, two decades 36-39 ms, a four-year range 10-12 ms, the 1940s 0.4 ms. A release with no tagged year never
  matches.
- **Genre + Year: genre first** (the genre's range, the tag row by `releases_id`, the year tested on it): 59.8 ms.
- **Unknown + Year: year first** on the Year index with the `LEFT JOIN` anti-join: 37.8-39.2 ms.
- **The search with a tag-led filter** tests the joined tag row's columns instead of a second pass: everything set
  (Lossless + 95%+ + Rock + 2020s + a word) 60.8 ms; Rock + a word 97.8 ms; the 2020s + a word 158.3 ms.
- **Combined reads** (issue #962, measured as in 4.3; page / count). Genre first, `DISTINCT` ids then the tag row with
  the year tested on it: Rock + 2020s 64.8 / 64.5 ms; Rock or Metal + 2020s 112.7 ms; Lossless + 95%+ + Rock + 2020s +
  a word 66.0 ms. Year first with Unknown, the anti-join on the position-0 genre row inside `g` on the Year index: Rock
  + Unknown + 2020s 128.6 / 126.7 ms (the genres as an `EXISTS` beside the anti-join); Unknown + 2020s + a word 102.2 /
  106.6 ms, the word tested on the tag columns `g` carries. Real-size copy: Rock + Unknown + 2020s count 0.5 ms. Every
  tag-led read (a Year, or genres without Unknown) joins `releases` on `PRIMARY` and costs the same on every page, so it
  is never mirrored; the release-led reads (no Year, with Unknown or no Genre) keep the release indexes and mirror past
  the middle as every list.

### 4.5 Per row, details and album

- **Row extras for a page of 50**: one query over the 50 ids' tag rows (artist = `COALESCE(album_performer, performer)`,
  album, `recorded_year`, `has_preview`, the preview fields, `audio_format`, `track_name`) with the genres in position
  order (`ix_release_audio_genres_release`): 0.2-0.3 ms. Media info presence and the rest of the row are today's loaders
  (`ReleasePreviewDataLoader`, `ReleaseMediaInfoAvailabilityLoader`).
- **Details**: the tag row with its genres 0.1 ms; the newest evidence revision (`ORDER BY revision DESC LIMIT 1` on the
  `(releases_id, revision)` key) 0.0 ms and its tracks by `release_audio_evidence_id` in `source_kind, source_ordinal`
  order (no sort) 0.1 ms; **the tracks shown are one complete source, never a mix**, in this order: the archive listing
  when the revision's `archive_manifest_complete` is true, else the NZB's files, else the accepted album's tracks read
  from `musicbrainz_release_tracks` by the decision's release id (issue #313; a `ref` on the primary key, no sort,
  0.03 ms); no list otherwise (`SPEC.md` 5C.2). The current music identity follows the shared rule
  (`CurrentMusicIdentityReader`, #1015): the completed decision for the newest evidence revision's hash under the
  configured `algorithm_version`; when that target has no row or only an unfinished attempt, the release's newest
  completed decision by id; the decision is chosen before its state is checked. It gives the release group of an
  accepted album, and that album's release id, 0.1 ms.
- **All releases of this album**: `album = ? AND COALESCE(album_performer, performer) = ?` on the existing
  `release_audio_tags_album_index` (the table's `utf8mb4_unicode_ci` makes both case-insensitive) `STRAIGHT_JOIN
  releases`, band 3000 only and visible, newest posted first, 50 a page: 0.1 ms for the biggest stress album (31
  releases), 0.5-0.7 ms when the album name is shared by 725 rows of other artists; count 0.1-0.6 ms. Band 3000 only, as
  Console; on the lab's real data the biggest album has 2 of its 4 releases in Misc, which therefore do not show.

### 4.6 Media info

`forRelease()` adds whether the release is in band 3000 to its payload; `media-info-block.js` renders, for such a
release, the audio table's first column after # as **Title**: the stream's `title`, else (one audio stream) the payload's
`music_tags.track_title`, else "—" (`SPEC.md` 5C.3). Other releases keep Language. Nothing new is read.

---

## 5. Remembered choices

`view_prefs['audio']` holds the sort and the four dropdown filters (`RememberedListFilters`, fact 3);
`UpdateReleaseViewRequest` accepts `sort` for Audio. The name search and the page are never stored.

## 6. Tests the build issues add

Built on `ProductionTables::fromAuthority()` with the new table; on SQLite: the genre write (split, order, dedupe,
Unknown dropped, a value changing to none removing the rows, the tag row and the genres in one transaction); the fill
(idempotent, re-runnable); the list's filters and search against hand-built tag rows; the release page's tracks, release
group and album siblings; the media info Title column for an Audio release and Language for any other.
