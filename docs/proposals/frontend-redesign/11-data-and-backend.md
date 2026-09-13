# 11 · Data and backend

What the views need from the server, and where it comes from. The APIs (v1 XML, v2 JSON, RSS) are frozen: every route below is a web route, every change is in the web layer, services and loaders.

## The row DTO

One loader (extend `app/Services/Releases/ReleaseBrowseService.php`; the movie/music/games/books browse services call the same loader for their release lists) returns, for every release in any listing, the fields in 03 "Row data". Sources:

| Field | Source |
|-------|--------|
| `name` | `display_name` ?? `searchname` (existing `release_display_name()` helper) |
| `category` | `categories_id` → category and root titles |
| `size` | `size` through one shared formatter (MB / GB) — remove the four hand-rolled `number_format(... / 1073741824)` copies |
| `files` | `totalpart` |
| `added` / `posted` | `adddate` / `postdate`, rendered with `userDateDiffForHumans()` and `userDate('M d, Y H:i')` (user timezone) |
| `grabs`, `comments` | existing columns (comments = visible comments only, see 12 #12) |
| completion chips | `completion`, `repair_outcome`, `rescan_outcome` (existing `x-release-completion-chips` logic) |
| `passworded` | `passwordstatus` |
| `has_media_info` + summary | `ReleaseMediaInfoAvailabilityLoader` (unions `media_info_probes`, `media_infos`, `video_data`, `audio_data`, `release_subtitles`) **applied to every listing query**, including the entity-grouped queries in `MovieBrowseService`, `MusicService`, `GamesService`, `BookService`, `ConsoleService`. The summary text (`1080p · x264 · DTS-HD 5.1`) comes from `video_data` / `audio_data` when present. |
| `nfo` | `nfostatus = 1` |
| `preview` kind | `haspreview = 1` → image; a video clip row (`ReleaseVideoClip`) → clip; playable audio preview (`audioTags->playablePreviewMimeType()`) → listen; `jpgstatus = 1` → sample. The existing `ReleasePreviewDataLoader` covers most of this; extend it to all roots. |
| `group` | `groups_id` → `usenet_groups.name` |
| `poster` | `fromname` |
| `entity` | by root: `imdbid` → `movieinfo`; `videos_id` → `videos` (+ `tv_episodes_id` for season/episode); `musicinfo_id` → `musicinfo`; `consoleinfo_id` → `consoleinfo`; `gamesinfo_id` → `gamesinfo`; `bookinfo_id` → `bookinfo`; `anidbid` → `anidb_info` for anime. None for Adult/Other. |
| `renamed` | `isrenamed = 1` |
| `pp_done` | "finished post-processing": `additional_pp_claim_token IS NULL` (no additional-processing claim outstanding) **and** `passwordstatus >= 0` (password check has run; `-1` = not yet checked) **and** `nfostatus` not pending. Define this predicate once, in the loader, and use it for the Cards filter; do not scatter it. If the team prefers a stricter definition (e.g. also `haspreview != -1` for video roots), change it in that one place. |
| `in_basket` | `users_releases` for the current user |
| `watched` | the entity is in `user_movies` (by `imdbid`) or `user_series` (by `videos_id`) for the current user |

## Cards eligibility

`renamed && pp_done`. Applied server-side to the query when `view=cards`; the pager reports the hidden count (`total_unfiltered - total`) so the UI can say "(7 not shown)". Table and search are never filtered by this rule.

## Entity sources per root

| Root | Entity table | Title | Identifying line | Artwork | Notes |
|------|-------------|-------|------------------|---------|-------|
| Movies | `movieinfo` | `title` | `year · ★ rating` | `getImageAssetUrl('movies', imdbid)` (existing) | actors, director, plot, genre, runtime, links via `imdbid`, `tmdbid`, `traktid` |
| TV | `videos` + `tv_info` | `videos.title` | `tv_info.publisher · status` | `tv_info.image` → `covers/tvshows` | seasons/episodes from `tv_episodes`; summary from `tv_info.summary`. No cast (issue #556). |
| Audio | `musicinfo` | `title` | `artist · year` | `getReleaseCover()` → `covers/music/{id}` (**fix 12 #1 first**); 1:1 | tracks, label (`publisher`), `genres_id` → genre |
| Console | `consoleinfo` | `title` | `platform · year` | `getReleaseCover()` | publisher, esrb, genre, releasedate |
| PC/Games | `gamesinfo` | `title` | `PC · year` | `getReleaseCover()` (**fix 12 #1**) | publisher, genre, releasedate |
| Books | `bookinfo` | `title` | `author · year` | `getImageAssetUrl('book', id)` | publisher, publishdate, pages, isbn, overview |
| Adult | none | release name | `size · added` | preview (`preview/{guid}_thumb`) else sample (`sample/{guid}_thumb`) else placeholder | one tile per release |

Entity grouped queries already exist (`MovieBrowseService::getMovieRange`, `MusicService`, `GamesService`, `BookService`, `ConsoleService` with `ROW_NUMBER() OVER (PARTITION BY …)` for the top releases). Reuse them; add the DTO loader over their release rows; return **all** releases per entity for the expanded row (or the top N with a total, and fetch the rest on expand).

## Routes

| Route | Handler |
|-------|---------|
| `GET /browse/{root}/{id?}` | `BrowseController::show` (Table/Cards) or the root's covers service (Covers) selected by the remembered view |
| `GET /browse/all` (+`group`, `poster`, `watching`) | `BrowseController::index`; `group` filters `groups_id`, `poster` filters `fromname` (exact), `watching` joins the user's lists |
| `GET /title/{root}/{id}` | new `TitleController::show`; `movie.view` and `series` remain as redirects |
| `GET /details/{guid}` | `DetailsController::show`, now also passing `$similars` and `$otherReleases` |
| `GET /search` | `SearchController::search` with `q`, `t`, chip params |
| `GET /watchlist` | new controller merging `MyShowsController` / `MyMoviesController`; `POST /watchlist/{root}/{id}` (add/edit categories), `DELETE /watchlist/{root}/{id}` |
| `GET /basket`, `GET /account`, `GET /` | existing controllers behind new views; `cart/index`, `profile`, `profileedit` redirect |
| `POST /profile/update-view` | new: persists view/size/per-page/thumbs per root (a JSON column on `users` or a small `user_view_prefs` table) |

## Persistence of view preferences

Per user, per root: `view`, `size`, `per`, `thumbs`. Store as JSON on the user (`users.view_prefs`) and expose through the existing user-settings path; the browser posts on change (same pattern as `movies/update-layout`, which this replaces).

## Watchlist

`user_series` (`users_id`, `videos_id`, `categories`) and `user_movies` (`users_id`, `imdbid`, `categories`) unchanged. The picker posts the category ids; the "find a title to add" box searches `videos.title` / `movieinfo.title`. "View releases from these" reuses `MyShowsController::browse` / `MyMoviesController` `browse` logic under `?watching=1`.

## Search

`SearchController` already takes `t` (category) and free text; add the chip parameters (age, size range, completion) and route the movie structured fields through `MovieSearchQuery` as today. Suggestions stay on `api/search/suggest`.

## Group and poster pages

`groups_id` and `fromname` are indexed columns on `releases`; the poster page today (`/poster?name=`) already lists by `fromname` and can be folded into `/browse/all?poster=`.

## Cover URLs

Always through `getReleaseCover($release)` / `getImageAssetUrl($type, $basename)`; never string-concatenate a flag column into a path (12 #1). Missing files yield `null`, which the views turn into the placeholder box.

## Comments

`ReleaseComment::getComments()` must filter `isvisible = 1` and paginate; the count on rows and the details tab must agree.

## Similar releases

`DetailsController::searchSimilar()` already runs; pass its result to the view instead of discarding it.
