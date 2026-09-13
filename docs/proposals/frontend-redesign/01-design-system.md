# 01 · Design system

The project's design system in `resources/css/app.css` and `AGENTS.md` ("Design system") stays the foundation: three colour schemes (`data-color-scheme="blue|emerald|violet"`), class-based dark mode, the `--surface-*` variables, the `primary-*` ramp, `.card` / `.surface-panel` / `.surface-panel-alt`, `<x-button>`, `<x-input>`, `<x-select>`, `<x-page-header>`, `<x-breadcrumb>`, Font Awesome. This document adds the rules the redesign needs on top and fixes the ones that drifted. Values below are what the prototype uses (`prototype/proto-base.css`, `prototype/proto-app.css`).

## Type

One face: **Figtree** (already the project font). Monospace (**JetBrains Mono**, or the existing mono stack) is used **only for NFO content**. Release names are not monospace.

One scale, no other sizes:

| Size | Used for |
|------|----------|
| 11 px | chips, pills, table headers, eyebrows, labelled-value labels (uppercase, `letter-spacing:.04em`) |
| 12 px | secondary lines (origin line, breadcrumbs, hints), small buttons |
| 13 px | body, buttons, fields, table cells, menu items |
| 14 px | **release name** wherever it appears (semibold 600) |
| 16 px | card and section headings (semibold) |
| 20 px | page heading and entity/details heading (bold 700) |

Line height 1.5 body, 1.3 for release names, 1.25 for headings.

## Links and the release name

- Link colour token `--link`: `primary-700` on light, `primary-300` on dark. Never `primary-700` on a dark surface (that was the unreadable "blue title").
- **Release name** (table row, cards row, XL cover rows, basket, search, watchlist "latest", details heading): 14 px, weight 600, colour `--text` (the normal text colour), `word-break: break-all`. On hover it takes `--link` and underlines. It is a link to the details page.
- Ordinary links use `--link`.

## Controls

Two heights only. Every input, select, button and dropdown trigger in a toolbar shares one of them.

| Size | Height | Padding | Font | Radius |
|------|--------|---------|------|--------|
| md | 36 px | 0 12–14 px | 13 px | 8 px |
| sm | 30 px | 0 10 px | 12 px | 8 px |

- Icon-only buttons are square (36 or 30).
- Native `<select>`: `appearance:none`, same box as `<x-input>`, custom chevron (inline SVG background), 26 px right padding. The browser's own arrow never shows.
- Native `<button>`: `appearance:none`; no browser chrome anywhere.
- Dropdown panels: `min-width` and content-sized, never a fixed width class.
- Segmented controls (`.seg`): bordered group, 8 px radius, buttons 32–36 px, pressed = `primary-600` fill, white text.

Button variants (map to `<x-button>` variants): `primary` (primary-600), `secondary` (card surface, 1 px border), `success` (green), `ghost` (transparent, panel-alt on hover), `muted` (panel-alt fill).

## Row action buttons

The compact actions at the end of every release row, in this order, always the same size:

| Action | Fill | Icon |
|--------|------|------|
| Download NZB | green (`release-action-download`) | `fa-download` |
| Details | primary (`release-action-primary`) | `fa-info` |
| Add to basket | muted (`release-action-muted`; token `--btn-muted-bg` = gray-200 light / gray-700 dark so it is visible on both) — turns green when the release is in the basket | `fa-shopping-basket` |
| Report | muted | `fa-flag` |
| Watch | muted; primary fill with a solid heart when the entity is on the watchlist. **Only when the release is matched to a movie or show.** | `fa-heart` (regular when not watched, solid when watched) |

28 × 28 px, radius 7 px, 3–4 px gap, white icon (muted: `--text-2`). No "more" / ellipsis menu. The same five buttons appear in the Table, Cards, expanded covers row, XL cover rows, basket, search results, entity pages and (as full-size `<x-button>`s) on the details page.

## Chips

20 px tall, 11 px text, 5 px radius, 6–7 px horizontal padding, icon + text. Colour is semantic and follows the badges the app already uses in `release-results.blade.php`:

| Chip | Colour | Behaviour |
|------|--------|-----------|
| Completion 100 % | green (`ok`) with check icon | none |
| Completion 95–99 % | yellow (`warn`), shows the percentage | none |
| Completion < 95 % | red (`bad`), shows the percentage | none |
| Password | red with lock icon | none |
| Media info (e.g. `1080p · x264 · DTS-HD 5.1`) | primary tint (`primary-100/800`, dark `primary-900/200`) with info icon | opens the media-info modal |
| NFO | yellow with file icon | opens the NFO modal |
| Preview (image) / Clip (video) / Listen (audio) | cyan (`info`) with image / video / headphones icon | opens the preview modal |
| Sample (adult sample sheet) | green with images icon | opens the preview modal |
| Group (`alt.binaries.movies`) | neutral: chip background, 1 px border, `--text-2`, users icon | links to the group's releases (03) |
| Poster (`name@host`) | neutral, user icon | links to the poster's posts (03) |
| Entity (`Dune: Part Two · 2024`) | primary-tinted background and border, root icon (film / tv / music / gamepad / book) | links to the entity overview (05) |
| Category pill (`Movies > HD`) | primary-100/800, fully rounded, 22 px | none |
| Watchlist categories (`UHD`, `HD`) | primary tint | none |

Do not invent other chip colours. `indigo-*` and `purple-*` are not used.

## Artwork boxes

Artwork is always a fixed box; it never stretches to its container.

| Root | Aspect | Table thumbnail | Cards row | Covers S / L | XL card | Hero |
|------|--------|-----------------|-----------|--------------|---------|------|
| Movies, TV, Console, Books | 2:3 | 40 × 60 | 56 × 84 | 10 / 6 across | 140 × 210 | 180 wide |
| Audio | 1:1 | 40 × 40 | 56 × 56 | 8 / 5 across | 140 × 140 | 180 wide |
| Adult | 16:9 | 56 × 32 | 96 × 54 | 5 / 3 across | (no XL) | (no hero) |

**No artwork placeholder**: the same-size box, `--surface-panel-alt` fill, 1 px `--border-default`, the root's Font Awesome icon centred at 55 % opacity. In the covers grid the title still sits on the tile. Never the `no-cover.png` image, never a collapsed or missing box.

## Icons

Font Awesome 6 (the project already loads it). Root icons: Movies `fa-film`, TV `fa-tv`, Audio `fa-music`, Console/PC `fa-gamepad`, Books `fa-book-open`, Adult `fa-venus-mars`, Other `fa-box`. FA4-only names (`fa-hdd-o`, `fa-clock-o`, `fa-external-link`) render blank and must not be used.

## Surfaces, themes

- Page background `--surface-body`; cards `.card` (`--surface-card`, 1 px `--border-default`, 12 px radius); toolbars, footers and sub-panels `--surface-panel-alt`.
- Boxed sub-rows (XL cover rows) use a fill that contrasts with the card in both themes (`--row-bg`: `#eef2f7` light / `#0b1220` dark), a solid border and a 4 px `primary-500` left edge. A hairline alone is not enough separation.
- Every colour comes from a token or a `primary-*` / status class with a `dark:` variant. Nothing is hard-coded per theme. Test every screen in light and dark and in all three schemes.

## Motion

Only what the app already has: hover fills, the dropdown transition, toast slide. No page-load animation.
