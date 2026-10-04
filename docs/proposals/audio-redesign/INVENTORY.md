# Audio screens today: feature inventory

Every user-facing feature of today's Audio screens (band 3000: MP3 3010, Video 3020, Audiobook 3030, Lossless 3040,
Podcast 3050, Foreign 3060, Other 3999), read in the code on master `f8365df5f` (2026-10-04). All paths are relative to the repository root. Line numbers drift; re-check before relying on one.

**Up front**

- Audio is one of two roots still on the old shared browser (the other is Other). `BrowseController::show` returns 404
  for All, Adult, Console, Games and Books and redirects TV and Movies; Audio falls through to `renderBrowser`
  (`app/Http/Controllers/BrowseController.php:28`, `:37-44`).
- List URL `/browse/audio`; `/browse/music` (any case) is an alias (`app/Enums/BrowseRoot.php:14`, `:24`); a
  sub-category is `/browse/audio/{id or title}`.
- Title page `/title/audio/{musicinfo id}`, now Audio-only (`app/Http/Controllers/TitleController.php:17`).
- Details: the generic `/details/{guid}` (`app/Http/Controllers/DetailsController.php:75-90`); Audio has no branch.
- **Nothing has written `musicinfo` or `releases.musicinfo_id` since the iTunes match was retired** (#372, `35b47cbf1`).
  Every title-based feature below (Covers, the title chip, the title page, Music Information, "Other releases", the
  Year / Genre / Label / Artist filters) works only for old rows (section 7.1).

## 1. Routes, navigation, list screen

### Routes

- `browse/{parentCategory}/{id?}` → `BrowseController::show`, behind `clearance` (`routes/web.php:200`). An unknown
  sub-category is a 404, an excluded one a plain 403 (`BrowseController.php:30-35`).
- Legacy `Audio/{id?}` → `MusicController::show` → `LegacyCoverRedirect::redirect` (`routes/web.php:233`,
  `app/Http/Controllers/MusicController.php:14-17`). It drops `t`, `ob`, `id`, `_token`
  (`app/Services/Releases/LegacyCoverRedirect.php:17`); turns `title=` into `q=` (18-21) and `ob=` into `sort=` (22-27);
  a numeric `genre=` id into the genre title, Audio only (28-33); forces `view=covers` (34); reads the path as a
  sub-category title (43-45) or uses `?t=` (46-47).
- Clearance: `browse/{root}[/{sub}]` checks `view audio` through `fromRoute`, so `/browse/music` gets the same check
  (`app/Http/Middleware/ClearanceMiddleware.php:39-70`); legacy `/Audio` is checked separately (99-105).
- Sub-categories: 3010 MP3, 3020 Video, 3030 Audiobook, 3040 Lossless, 3060 Foreign, 3999 Other
  (`database/seeders/CategoriesTableSeeder.php:247-294`), 3050 Podcast (`:634-636`); root 3000 "Audio"
  (`RootCategoriesTableSeeder.php:44-45`); constants `app/Models/Category.php:104-116`, `:198`.

### Header drop-down (#908, `resources/views/partials/header-menu.blade.php`)

- Root order Movies, TV, Audio, Books, Console, PC, Adult, Other (`app/View/Composers/GlobalDataComposer.php:163`).
- Audio has no list route (`$list` is null, line 7): "All Audio" → `url('/browse/audio')` (11); each sub-category →
  `/browse/audio/{id}` (13-15); no extra items (16-24 are Movies and TV only).
- The Audio button is current on `browse` and `title` routes (`fromRoute`) and on a 3xxx release's details page
  (`GlobalDataComposer.php:141-143`). The search scope includes Audio (3000) (`header-menu.blade.php:37`).

### Browse screen (`resources/views/browse/index.blade.php` + `components/release-browser*`)

- Frame: breadcrumb "Browse › Audio" (`browse/index.blade.php:9`); title "Audio" or "Audio · {sub}", icon `fa-compass`
  (`:10`, `BrowseController.php:69`); modals from `partials/release-modals` (`:3-5`).
- Views Table, Cards, Covers (`BrowseRoot.php:115`); cover sizes S, L, XL (`:122`). Page size 24 / 48 / 100, default 48
  (`app/Data/ReleaseBrowserState.php:104`).
- Saved per user in `view_prefs['audio']` (view, size, per, thumbs) (`app/Models/User.php:210-216`). **Sort is not saved
  for Audio**; it is for TV, Movies, Adult, Books, Games and Console (`app/Http/Requests/UpdateReleaseViewRequest.php:28`).
  The legacy `users.musicview` column is used only by the admin user form
  (`app/Http/Controllers/Admin/AdminUserController.php:131`, `:232`).
- Count unit "releases" in Table and Cards, **"albums"** in Covers (`ReleaseBrowserState.php:42-53`).
- Letter jump (# A–Z), Audio Covers only (`ReleaseBrowserState.php:60-63`, `:95-97`), bar "Jump by initial · sorts by
  title" (`components/release-browser.blade.php:11-18`); the server redirects to the page holding the first matching
  `m.title` (`BrowseController.php:50-54`, `app/Services/Releases/ReleaseCoverBrowser.php:18-34`); JS
  `release-browser-component.js:116-120`.
- "Following" is not available for Audio (`ReleaseBrowserState.php:113`). URL-only inputs: `minc` (minimum
  completion), `group=` / `poster=` (table-only) (`:92`, `:115`).

#### Toolbar (`components/release-browser/toolbar.blade.php`)

- Search "Search in Audio" (3): Table / Cards match the release display name
  (`app/Services/Releases/ReleaseBrowserQuery.php:124-126`); Covers match `m.title` **or `m.artist`** (`:117-123`).
- Filters (`app/Services/Releases/ReleaseBrowserMetadata.php:22`), joining `musicinfo m ON m.id = r.musicinfo_id` and
  `genres ON genres.id = m.genres_id` (`:36-46`):

| Filter (URL key) | Column | UI |
|---|---|---|
| Year (`year`, `year_from`, `year_to`) | `m.year` (varchar 4) | `x-year-picker` (toolbar:5-6) |
| Genre (`genre`) | `genres.title` | select "Genre"; token match (`:70-74`) |
| Label (`label`) | `m.publisher` | select "Label"; exact match (`:76`) |
| Artist (`artist`) | `m.artist` | **no control**; options skipped (`:86-88`); exact match by URL only |

  Options are the distinct values of the unfiltered list (`:82-101`); a select with no options is hidden (toolbar:7).
- "Clear" (toolbar:19-21), total count (24). Sorts: Posted Newest / Oldest, Added Newest (default) / Oldest, Name A–Z,
  Grabs Most (`app/Enums/ReleaseSort.php:22-29`); in Covers applied per album as MIN / MAX of the release column
  (`app/Services/Releases/CoverBrowseScope.php:32-39`). View segment (toolbar:30-38); Cards tooltip "Renamed,
  post-processed releases only". Table thumbnails toggle (39-40); Covers size S / L / XL (41-47).

#### Pager, bulk actions

- Per-page segment, page window, "Go to page" (`components/release-browser/pager.blade.php`); a page past the end
  redirects to the last page (`BrowseController.php:66-68`).
- Bulk bar "N selected", "Add to basket", "Download N NZBs", "Clear" (`release-browser.blade.php:51-57`).

#### Table view (`table.blade.php:5-7`, `row.blade.php`)

- Columns: select, Release, Category, Size, Files, Added, Posted, Stats, Actions.
- Row: checkbox (5); optional **square** thumbnail (`artwork.blade.php:11-15`) from `covers/music/{musicinfo_id}`, else
  `getReleaseCover`, else `fa-music` (`app/Extensions/helper/helpers.php:595-597`, `ReleaseEntityDataLoader.php:23`); the
  name linking to details (13); facts (section 6) and origin chips (16); category pill (20); file count opening the
  file-list modal (22-24); Added, Posted, grabs, comments (25-30); actions (31-33).
- Actions: Download NZB, Details, Basket, Report; no watch button (`actions.blade.php:2-8`).

#### Cards view (`card.blade.php:4-17`)

Square art, title, facts, origin, Size / Added / Posted / Grabs, actions; post-processed `isrenamed=1` releases only
(`ReleaseBrowserQuery.php:26-30`).

#### Covers view (one tile per `musicinfo` row)

- Data `MusicService::getMusicRange` (`app/Services/ReleaseCoverBrowser.php:65` → `app/Services/MusicService.php:156-293`):
  INNER JOIN releases on `musicinfo_id`, `m.title != ''` (198, 216-239); up to 2 newest releases per album by `postdate`
  (251-266); cached unless watching / basket (205-213, 287-289).
- Tile (`ReleaseCoverBrowser.php:77-103`): title `m.title`; "artist · year"; size L footer badge genre; "N releases"; XL
  chips artist, genre, publisher; year from the first attached release's entity; link `/title/audio/{id}`.
- Grid `data-shape="square"` (`covers.blade.php:1`), count badge (9), title and sub-line (11-12), L footer (13-18); art
  falls back to `fa-music` (`cover-art.blade.php`); XL `cover-detail.blade.php` (title link and year 5, genres line 6
  never set, chips 7-9, release panels 15-17, "View all N releases" 18).
- Expanded panel (tile or "View all"): `?_fragment=cover&cover=<musicinfo id>` (`BrowseController.php:55-61`), filtered
  on `r.musicinfo_id`, newest added first, 24 / 48 / 100 a page (`ReleaseCoverBrowser.php:37-54`),
  `expanded-cover.blade.php` with a "Title page" button.

## 2. Title page `/title/audio/{id}`

- Access: the direct permission `view audio` (plain 403 otherwise); a positive integer id (`TitleController.php:17-19`);
  `?t=<sub>` restricts to one sub-category, 403 if it is excluded (`app/Services/Releases/TitleReleaseBrowser.php:26-32`).
- Data (`app/Services/Releases/TitleMetadataLoader.php:30-65`): genre `genres.title` by `genres_id` (37); metadata
  **Artist, Year, Label** (`publisher`), **Genre**, **Tracks** (count, or the raw value when numeric) (46-47); empty,
  `0` and `0000-` values dropped (50-51); subtitle the artist (61); overview always empty (`review` not shown, 52-55);
  track list `tracks` split on `<br>`, newline or `|`, leading numbers stripped, empty when `tracks` is numeric (40,
  68-77); links `musicinfo.url` labelled by host (MusicBrainz, iTunes, Deezer, else "Website") (95-104), no Amazon /
  `asin` link; art `covers/music/{id}` (60).
- View (`resources/views/title/index.blade.php`): breadcrumb Audio → `/browse/audio` (5); art or `fa-music` (7-10); h1
  title with artist (12); link buttons through the dereferrer, new tab (13-17); metadata `dl` (18-20); "Tracks" ordered
  list (22); stats Releases, Latest, **Best** (format) (23-27).
- Releases (`title/partials/releases.blade.php` + `TitleReleaseBrowser::load`): every release with this `musicinfo_id`,
  newest added first, 100 a page, no toolbar (`TitleReleaseBrowser.php:33-53`); **format chips** when there is more than
  one format, with All (`releases.blade.php:2-8`), the format read from the release name (24-bit FLAC, FLAC, MP3, AAC,
  ALAC, OGG, OPUS, WAV; `app/Support/ReleaseQuality.php:26-32`), ordered by quality (`TitleReleaseBrowser.php:63-69`).

## 3. Release details (generic path)

- `DetailsController.php`: loads `audioTags` (53); `musicinfo` with `genre` when `musicinfo_id > 0` (121-124,
  `MusicService.php:94-97`); `bookinfo` when set (126-129, relevant to Audiobook, 7.12); similar releases, failed count,
  reports, PreDB (91-95, 152); "Other releases" through `RelatedReleaseBrowser`, which needs an entity (170).
- Layout (`resources/views/details/index.blade.php`): breadcrumb Audio › album title (with an entity, to the title page) ›
  sub-category (11-15). Header (`details/partials/header.blade.php`): square art (2), facts + group chip (5); category,
  size, files, added, posted, grabs, comments link (6-11); buttons Download, Basket, NFO, Media info, Files (n), Report,
  Edit release (Admin / Moderator) (12-19); failure count (21). Tabs Overview, Files (n), Media info, NFO, Comments
  (19-23), lazy (41-56). Overview: preview-images, **audio-preview**, movie, tv, **music-info**, book, anime, predb,
  password, Group / Poster / Password status, reports (24-39).
- `music-info.blade.php`: "Music Information": Album, Artist, Publisher, Release Date (raw datetime), Genres (16-45);
  Genres never shows (it reads `genres`, which does not exist, 7.2); year, review, tracks, url, cover not shown.
- `audio-preview.blade.php` (when `audioTags->playablePreviewMimeType()` is not null, 2-9): heading "Audio Preview";
  `<x-audio-preview-player>` with `route('preview.audio')`, MIME type and summary (22-26); the spectrogram
  `covers/audiosample/{guid}_spectrum.png`, opening the image modal (13-15, 28-38).
- `preview-images.blade.php`: for 3xxx releases or any release with a spectrogram the `haspreview` image and video
  preview are suppressed (3-6, 18); the sample image (`jpgstatus`) still shows (7, 66-77).
- Media info tab (`/release/{id}/mediainfo`, `routes/web.php:275-277`): `MediaInfoPresentationService` merges
  `audio_data`, `media_info_*` and `release_audio_tags`
  (`app/Services/MediaInfo/MediaInfoPresentationService.php:106-203`); the identity label becomes "Embedded track title"
  for music (208-212); the JS **Music** section shows Album, Artist, Album artist, Track n of m, Disc, Genre, Recorded
  (`resources/js/alpine/components/media-info-block.js:223-230`); the audio table shows language, format / codec,
  channels, bit rate, sample rate (173-185).
- Files tab: generic `release_files` (`ReleaseFilesController`, `partials/file-summary-controls`); no track list.
- Aside (`details/partials/related.blade.php`): "Other releases of this title", each labelled by format or category,
  with size and completion (2-8, `RelatedReleaseBrowser.php:37`); "Similar releases" (10-13).

## 4. Audio preview

- Marker `release_audio_tags.has_preview = 1` plus a served extension (`app/Models/ReleaseAudioTag.php:170-204`), not
  `releases.haspreview` (which the audio processor also sets, `app/Services/AudioProcessing/AudioReleaseProcessor.php:321-330`,
  `:410-411`).
- Formats mp3, m4a, ogg, opus, flac, wav (`ReleaseAudioTag.php:117-124`); encoding stream copy, lossless re-encode (FLAC)
  or FLAC transcode (`:215-236`, `app/Enums/AudioPreviewEncoding.php:18-31`); summary e.g. "30s · MP3 · stream copy"
  (`:241-255`). Settings `audio_preview_seconds` (30), `audio_preview_start_seconds` (10), `audio_spectrogram`
  (`app/Support/Settings/Sections/PostProcessingSection.php:415-435`); generation can be off per root
  (`app/Services/Releases/PreviewGenerationPolicy.php:50-55`).
- Serving `GET /preview/audio/{guid}` (`preview.audio`), auth + verified (`routes/web.php:123-126`):
  `AudioPreviewController::show` checks the guid, requires `has_preview=1`, applies the hidden-category gate (403),
  resolves `covers/audiosample/{guid}.{ext}` (`app/Http/Controllers/AudioPreviewController.php:23-106`); private,
  revalidated, Range requests (54-72).
- Lists: the **"Listen"** chip (`fa-headphones`, `audio-preview-badge`) opens the preview modal
  (`resources/views/components/release-facts.blade.php:50-63`): art (album cover or `fa-compact-disc`), title, artist and
  an `<audio>` (`resources/views/partials/preview-modal.blade.php:6-14`,
  `resources/js/alpine/components/preview-modal-component.js:59-97`, `:197-204`). Row data from
  `ReleasePreviewDataLoader` (`app/Services/Releases/ReleasePreviewDataLoader.php:35-105`): `has_audio_preview`, mime,
  meta, title (track_name, else album), artist (performer, else album_performer).
- Details: inline `<audio controls preload="none">` (`resources/views/components/audio-preview-player.blade.php:9-19`)
  and the Listen chip in the header facts.

## 5. Stored music data

- `musicinfo` (`database/schema/mariadb-schema.sql:959-979`): id, title, asin (unique), url, salesrank, artist,
  publisher, releasedate, review (3000), year (varchar 4, NOT NULL), genres_id, tracks (varchar 3000), cover, timestamps;
  FULLTEXT (artist, title); `genre()` (`app/Models/MusicInfo.php:76-78`); covers in `covers_path/music/`
  (`MusicService.php:55`). The only writer `MusicService::updateMusicInfo` (417-503) and the iTunes builder (592-654) have
  **no caller**; the admin music edit form writes through `MusicService::update` (374-410).
- `genres` (`:678-685`): id, title, type, disabled; music rows `type = GenreService::MUSIC_TYPE`
  (`MusicService.php:595`, `:627`).
- `audio_data` (`:55-71`): releases_id, audioid, audioformat, audiomode, audiobitratemode, audiobitrate, audiochannels,
  audiosamplerate, audiolibrary, audiolanguage, audiotitle; the list "Media Info" chip uses format + channels
  (`app/Services/Releases/ReleaseMediaInfoAvailabilityLoader.php:70-76`).
- `release_audio_tags` (`:2592-2624`), one row per release (`ReleaseAudioTag.php:15-17`): album, album_performer,
  performer, track_name, track_position / total, genre, recorded_date / year, four MusicBrainz ids, source_file,
  audio_format, raw_tags (JSON), has_preview, preview_extension / mime / seconds / bytes, has_spectrogram;
  `Release::audioTags` (`app/Models/Release.php:275-278`).
- `media_info_probes` / `media_info_tracks` (`:764-826`): `music_tags` JSON, duration, overall bit rate; per track codec,
  bitrate_bps, channels, sample_rate_hz, bit_depth.
- `release_music_identifications` (`:2715-2755`): MusicBrainz recording / release / release-group ids, state, score;
  written by `MusicIdentity/*`; no web screen reads it and it never writes `musicinfo`.
- Track lists: `release_files` holds name, size, crc32, passworded (`:2648-2659`); the only track list a screen reads is
  `musicinfo.tracks` (old iTunes rows store a **count** there, `MusicService.php:650`).

## 6. Shared components, audio behaviour

- `release-facts.blade.php`: a release is audio when its root is 3000, or it has a spectrogram or an audio preview
  (12-14); **"Listen"** with an audio clip, else a "Preview" chip showing the spectrogram when `haspreview` and a
  spectrogram exist (21-24, 33-35, 50-63); never the `preview/_thumb` image (21-23, 27-29); also completion, Password,
  Media Info, NFO, Sample (38-70).
- Title chip `x-entity-chip`, root `audio`, `fa-music`, "title · year", → `/title/audio/{id}`
  (`components/entity-chip.blade.php:7`, `:13-14`, `app/Data/ReleaseEntityData.php:25`), from `musicinfo` (title, year)
  (`ReleaseEntityDataLoader.php:23`), needing root 3000 and `musicinfo_id > 0` (29-30).
- Square artwork everywhere for Audio (`artwork.blade.php:11-13`, `covers.blade.php:1`). Row `preview` kind `'audio'`
  with a clip (`app/Services/Releases/ReleaseRowDataLoader.php:117-123`).

## 7. Findings

1. **`musicinfo` is frozen.** #372 retired the iTunes match: `updateMusicInfo` and `fetchItunesMusicProperties` have no
   callers; nothing sets `releases.musicinfo_id` above 0 (it is only reset to null, `ReleaseUpdateService.php:536`,
   `:571`, `ReleaseRecategorizer.php:46`); MusicBrainz writes only `release_music_identifications`. New audio releases
   never appear in Covers (INNER JOIN, `MusicService.php:218`, `:234`), never get a title chip, title page, Music
   Information or "Other releases", and drop out as soon as a Year / Genre / Label / Artist filter is set.
2. Details "Genres" never renders (`music-info.blade.php:9` reads `genres`; the model has `genre` / `genres_id`,
   `MusicInfo.php:76`); "Release Date" prints the raw datetime (8, 37).
3. The Artist filter has no control (`ReleaseBrowserMetadata.php:86-88`); it works only through a hand-written `?artist=`.
4. Covers "Name · A–Z" sorts by release name (MIN of display_name, `ReleaseSort.php:37`, `:41-43`), not album title; only
   the letter jump orders by `m.title` (`CoverBrowseScope.php:34-35`).
5. Dead code in `getMusicRange`: the `$scope === null` paths can never run (`MusicService.php:161-192`; the only caller
   passes a scope, `ReleaseCoverBrowser.php:65`); `$order` is computed but unused (`ReleaseCoverBrowser.php:61`).
6. The XL genres line never shows (`ReleaseCoverItem::$genres` never set, `ReleaseCoverBrowser.php:93-102`,
   `cover-detail.blade.php:6`).
7. The disabled-genre sweep queries `musicinfo.genre_id`, which does not exist
   (`app/Services/ReleaseProcessingService.php:1579`, a phpstan ignore); the column is `genres_id`.
8. Title page "Tracks" is usually a count (`MusicService.php:650`); the list shows only for old text track lists
   (`TitleMetadataLoader.php:40`, `:70-71`).
9. Stored, shown nowhere: `musicinfo.review` (`TitleMetadataLoader.php:52-55`), `asin`, `salesrank`;
   `release_audio_tags.musicbrainz_*` (media-info JSON only); `release_music_identifications`; `audio_data.audiobitrate`
   / `audiosamplerate` (media info tab only).
10. The legacy `ob=` mapping produces `artist`, `year` and `rating` sorts that do not exist; they fall back to Added ·
    Newest (`LegacyCoverRedirect.php:22-26`, `ReleaseSort.php:16-19`).
11. The sort is not saved for Audio, unlike every redesigned root (`UpdateReleaseViewRequest.php:28`).
12. Audiobook (3030) is a Books lookup candidate (`app/Services/MetadataProcessing/BookProcessingCandidateQuery.php:28-31`);
    its `bookinfo` shows on details (`DetailsController.php:126-129`), but the browse entity loader maps 3xxx only to
    `musicinfo` (`ReleaseEntityDataLoader.php:23`, `:29`), so no title chip or Covers tile.
13. `releases.haspreview = 1` means "audio clip" for audio releases (`AudioReleaseProcessor.php:410-411`); facts and
    details suppress the preview image for them (`release-facts.blade.php:21-23`, `preview-images.blade.php:6`).
14. Title-page access errors are plain 403s (`TitleController.php:18`); browse shows the category-disabled page
    (`ClearanceMiddleware.php:61-67`).

## 8. Tests touching these screens

`tests/Feature/`: `ReleaseBrowserControllerTest.php`, `TitleControllerTest.php`, `DetailsControllerTest.php`,
`DetailsAudioPreviewViewTest.php`, `AudioPreviewControllerTest.php`, `AudioReleaseProcessorTest.php`,
`MusicIdentityRetirementTest.php`, `AdminMusicEditTest.php`, `AdminMusicListPageTest.php`, `PublicShellTest.php`; and the
`tests/Feature/MusicIdentity/` folder.
