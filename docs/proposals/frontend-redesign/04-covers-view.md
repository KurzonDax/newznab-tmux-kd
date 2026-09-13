# 04 · Covers view

Covers is the entity-first view of the release browser: **one tile per entity** (movie, show, album, game, book), releases folded beneath and shown on click. It replaces `movies/index`, `music/index`, `games/index`, `console/index`, `books/index`, `xxx/index` and `series/viewserieslist`. The live catalogue is large (≈15,000 movies, thousands of shows, tens of thousands of albums and books), so the view is built for scanning: many tiles per page, nothing on a tile that isn't about the entity.

## Sizes

Chosen with the **Cover size** control in the toolbar (labelled; visible only in Covers view). Layout only; per-page is separate (03).

| Size | Movies / TV / Console / Books (2:3) | Audio (1:1) | Adult (16:9) | Tile content |
|------|------|------|------|------|
| **S** (default) | 10 across | 8 across | 5 across | artwork, title (one line, ellipsis), identifying line, count badge, heart |
| **L** | 6 across | 5 across | 3 across | as S plus a genre/rating badge and "n releases" on a footer line |
| **XL** | 2 across | 2 across | not offered | detail card: artwork 140 wide, title + year, metadata chips, then **each release as its own boxed row** (see below) |

Below 1100 px viewport width the grid drops to 6 / 4 columns; below 900 px to 3 (mobile). Column count is measured from the rendered grid, not assumed.

## Entity tile (S and L)

- Artwork in the root's aspect box, title overlaid at the bottom in white on a dark gradient (on the placeholder, the title in `--text-2` with no gradient).
- **Release count badge**: top-right, `primary-600` pill, the number of releases for this entity. Not shown for Adult (one tile = one release).
- **Watchlist heart**: top-left, 24 px round button, only on Movies and TV. Hollow when not followed, primary fill with a solid heart when followed. Click opens the watch picker (08) without opening the tile.
- Below the artwork: title (13 px semibold, one line) and an **identifying line** (12 px muted): Movies `2024 · ★ 8.7` · TV `FX · Returning` · Audio `Radiohead · 2021` · Console `PS5 · 2022` · Books `Stephen King · 2023` · Adult `32.1 GB · 2 h ago`.
- L adds a footer: a badge (Movies: genre · TV: `★ rating` · Audio: genre · Console: ESRB · Books: genre · Adult: category) and `n releases` in `--link` colour.
- **Nothing about any individual release** on a tile (no facts chips, no size of the "best" release, no download/basket). With several releases per title there is no honest way to summarise one.

## Click to expand

Clicking a tile (anywhere except the heart) opens an **expanded row** inserted after the end of the grid row that contains the tile, spanning the full width, with a pointer from the open tile (the tile gets a primary outline). It contains:

- header: `**Dune: Part Two** · 3 releases` · spacer · **Select all** · **Download selected** (success) · **Title page** (→ 05; not for Adult) · close ✕
- the **Table view** of that entity's releases (same rows, same chips, same actions as 03)

Rules: one expanded row at a time; clicking the open tile or ✕ closes it; **Esc** closes; **←/→** move the expansion to the neighbouring tile; changing page, size, sort or filters closes it; the row scrolls into view when opened. On phones the expansion is a bottom sheet instead of an inline row.

## A–Z strip

TV, Audio and Books show a letter strip (`# A B … Z`) between the toolbar and the pager. Clicking a letter switches sort to *Title A–Z* and jumps to the page containing the first title starting with that letter; the active letter is highlighted; clicking it again clears it. Movies, Console and Adult do not show the strip.

## Per-root notes

**Movies** — entity `movieinfo`. Filters Year, Genre; sort adds Year, Rating. Trending is this view sorted by grabs with a rank badge.

**TV** — entity `videos` + `tv_info`. Filters Year, Genre, Network. Identifying line `network · status`. The A–Z strip replaces today's `viewserieslist`.

**Audio** — entity `musicinfo`. **Square** album art. Identifying line `artist · year`. Filters Year, Genre, Label; sort adds Year, Artist. Album tiles show the album title; the artist is on the identifying line, not in the title.

**Console** — entity `consoleinfo`. Identifying line `platform · year`; L badge = ESRB. Filters Year, Genre, Platform, Publisher.

**Books** — entity `bookinfo`. Identifying line `author · year`; L badge = genre. Filters Year, Genre, Author. All releases of a book are in the expanded row (today's page shows only the first).

**Adult** — **no entity** (`xxxinfo` was dropped in Feb 2026). Each tile is one release: a **16:9 frame** showing the release's **preview image** if it has one, else its **sample sheet** if it has one, else the placeholder; a `PREVIEW` / `SAMPLE` tag in the top-right corner; the release name as the title; `size · added` as the identifying line. No count badge, no heart, no XL, no title page; the expanded row shows that one release. The Sample chip in the row is green; Preview is cyan; both open the preview modal.

**PC/Games** — entity `gamesinfo`, same as Console with Platform = PC. **Other** — no entity: no Covers view.

## Empty and broken artwork

A missing image shows the placeholder box (01), never a broken image and never a collapsed tile. Cover URLs come from `getReleaseCover()` / `getImageAssetUrl()`; see 12 for the current Audio/Games bug that must be fixed first.
