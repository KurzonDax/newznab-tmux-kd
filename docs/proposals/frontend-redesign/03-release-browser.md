# 03 · The release browser

Decision: **one component (option 2A)** renders every list of releases: browse by root or sub-category, All releases, search results, group and poster pages, the entity overview's releases, the basket, the expanded covers row. It replaces `components/release-results.blade.php`, `release-results-panel`, `cover-release-list`, `movies/partials/movie-card`, and the hand-rolled tables in `xxx/index`, `cart/index`, `series/partials/season-content`, `movies/viewmoviefull`.

Suggested Blade name: `<x-release-browser :rows :view :root :toolbar :pager>` with the three views as internal partials. Row markup exists once.

## Routes and parameters

```
/browse/{root}                 root = movies | tv | audio | console | games | books | xxx | other
/browse/{root}/{categoryId}    sub-category
/browse/all                    every release, newest first
/browse/all?group={name}       every release in a Usenet group  ("Releases in {group}")
/browse/all?poster={fromname}  every release by a poster        ("Posts by {poster}")
/browse/{root}?watching=1      only titles the user follows (movies, tv)
```

Query parameters shared by every listing: `view=table|cards|covers`, `size=s|l|xl` (covers only), `per=24|48|100`, `page=n`, `sort=`, `q=` (search within the listing), plus per-root filters (below). The last used `view`, `size`, `per` and `thumbs` are **remembered per user per root** (replaces `users.movie_layout`) and used when the parameter is absent.

## Row data (the DTO every view reads)

One loader builds this for every row, whatever the page (see 11 for sources). A view may omit a field; it may not invent one.

| Field | Table | Cards | Covers row | Notes |
|-------|-------|-------|------------|-------|
| `guid`, `id`, `name` (display name) | ✓ | ✓ | ✓ | name is the 14 px title |
| `category` (`Movies > HD`) | ✓ pill | only on All releases | ✓ | |
| `size` (formatted, MB/GB) | ✓ | ✓ | ✓ | never hard-coded GB |
| `files` (count → file list) | ✓ | – | ✓ | |
| `added` (relative) | ✓ | ✓ | ✓ | `adddate` |
| `posted` (absolute `Sep 12, 2026 23:30`, user timezone) | ✓ | ✓ | ✓ | `postdate` |
| `grabs`, `comments` | ✓ | grabs | ✓ | |
| `completion`, `repair_outcome`, `rescan_outcome` | chip | chip | chip | existing `x-release-completion-chips` semantics |
| `passworded` | chip | chip | chip | |
| `has_media_info` + summary text | chip | chip | chip | **must be loaded for every view** |
| `nfo` | chip | chip | chip | |
| `preview` kind: image / video / audio / sample / none | chip | chip | chip | |
| `group` name | chip | chip | chip | links to `/browse/all?group=` |
| `poster` (`fromname`) | chip | chip | chip | links to `/browse/all?poster=` |
| `entity` (root, id, title, year, artwork) | chip | chip | chip | links to `/title/{root}/{id}`; absent for Adult/Other |
| `renamed`, `pp_done` | – | filter | – | Cards eligibility (11) |
| `in_basket`, `watched` | actions | actions | actions | |

## Toolbar

One toolbar on every listing, in this order:

1. **Search in {root}** field (230 px) — filters the current listing by title/name as you type (debounced ~180 ms, client-side over the loaded page in the prototype; server-side `q=` in the implementation). `Search in All releases` on the All page.
2. **Filter selects**, only those with values for the root: Movies: Year, Genre · TV: Year, Genre, Network · Audio: Year, Genre, Label · Console: Year, Genre, Platform, Publisher · Books: Year, Genre, Author · Adult: Year · All releases: none. Each is a `<select>` with an empty first option named after the field.
3. **Clear** (ghost, `fa-xmark`) — appears only when any filter, search text, letter or `watching` is active.
4. spacer, then the count: `15,240 titles` (covers) / `48,211 releases` (table, cards).
5. **Sort** select: *Newest release* (default) · *Title A–Z* · *Year* and *Rating* (Movies, TV) · *Year* and *Artist* (Audio). Choosing a sort clears the A–Z letter.
6. **View** segmented control: **Table** · **Cards** · **Covers**. Cards appears only on Movies, TV, Audio, Console, Books, Adult. Covers appears only on roots with an entity (or, for Adult, preview tiles); never on All releases, group or poster pages.
7. A **secondary toggle for the active view only**: Table → thumbnails on/off (`fa-image`); Covers → **Cover size** `S · L · XL` (labelled "Cover size"; XL not offered on Adult). Cards has none.

Controls are all md (36 px). The toolbar is sticky at the top of the card while the list scrolls.

## Pager

Above and below the list: `Page 1 of 318 · 15,240 titles` · **Per page** `24 | 48 | 100` (segmented, default 48, applies to every view, remembered) · spacer · pagination (`‹ 1 2 3 4 5 … 318 ›`, prev/next disabled at the ends, never navigates past the last page) · **Go to page** input (sm; Enter; rejects out-of-range with a warning toast). Per page and cover size are independent: changing size never changes the count.

For Cards the count line reads `48 releases · renamed and post-processed only (7 not shown)` so the filtering is visible.

## Table view

Columns: select checkbox · **Release** · Category · Size (right) · Files (right, link opens the file list) · Added · Posted · Stats (grabs, comments) · actions.

The Release cell is two lines:

1. **Title line**: the release name (14 px, `--text`, semibold) followed on the same line by the facts chips (completion, password, media info, NFO, preview/sample). Chips wrap after the name only if they run out of room.
2. **Origin line**: entity chip · group chip · poster chip (12 px line).

With the thumbnails toggle on, a 40 px artwork box sits left of the cell (2:3, 1:1 or 16:9 by root, placeholder when none). Rows highlight on hover; a selected row gets a faint primary tint. Table is **unfiltered** (except on search results, where the query applies).

## Cards view

**Purpose and eligibility (Randall's rule):** Cards exists for Movies, Console, TV, Audio, Adult and Books. It shows **only releases that have been renamed and have finished post-processing**. Table stays unfiltered. Cards is not offered on All releases or on group/poster pages.

Layout is one release per row, full width, with fixed column positions so the eye can run down the page (a grid of cards was rejected as unscannable). Grid columns: `28px | artwork | 1fr | 84px | 84px | 150px | 72px | actions`:

- checkbox
- artwork box (56 × 84 / 56 × 56 / 96 × 54, placeholder when none)
- main: release name (14 px), then the facts chips on their own line, then the origin line (entity chip, group chip, poster chip)
- **Size**, **Added**, **Posted**, **Grabs** as labelled values (11 px uppercase label over a 13 px value)
- the five action buttons, right-aligned

No category (you are inside the root; it reappears only on All releases), no file count, no comment count, no "Select" word. 12 px vertical padding, hairline between rows, hover fill. Below 900 px the labelled values and actions wrap under the main block.

## Covers view

See 04.

## Bulk actions

Selecting any row (checkbox, "Select all" in the table header, "Select all" in an expanded covers row) shows a sticky bar at the bottom of the card: `**3 selected**` · spacer · **Add to basket** (secondary) · **Download 3 NZBs** (success) · **Clear** (ghost). Selection includes only checked rows on the current page. Downloading selected produces one archive (existing multi-download); the bar clears afterwards.

## Group and poster pages

`/browse/all?group=alt.binaries.movies` and `/browse/all?poster=name@host` render the All-releases browser filtered to that group or poster. Breadcrumb `Browse › All releases`; heading `Releases in alt.binaries.movies` / `Posts by name@host` (the name appears **once**, in the heading); a **Clear filter** button in the header actions returns to `/browse/all`. Table view; the origin chips in each row still link, so a user can hop group → poster → group.

## Empty states

Use `<x-empty-state>` with a root icon and one sentence (`No releases match.` / `No titles match.`), plus a Clear-filters button when filters are active.
