# NNTmux TV section: visual contract

Written 2026-09-21. `SPEC.md` says what each screen does; `DATA-CONTRACT.md` says what is
stored; this document says **how an implementation is made to look like the approved
prototype, and how that is checked mechanically instead of by eye.**

It travels with a sanitized copy of the prototype (invented shows, people, releases and
placeholder art; nothing from a real catalogue) that lives in the application repository
under `docs/proposals/tv-redesign/prototype/`:

| File | What it is |
|---|---|
| `tv.html`, `tokens.css` | the approved prototype, byte-for-byte the one Randall reviewed |
| `data.json` … `repair.json`, `posters/`, `previews/`, `fixtures.json` | the invented dataset (`gen-demo-data.mjs`, `make-demo-art.mjs` regenerate it) |
| `check.mjs` | 225 behaviour checks; `0 failures` on this dataset |
| `reference/<state>-<dark|light>.webp` | 26 screen states × 2 themes at 1600 × 1000 |
| `reference/measurements.json` | computed type, colour, radius, padding and size of 82 named parts, per theme |
| `reference/tokens.json` | the resolved value of every colour token and chip hue, per theme |
| `reference/parts.json` | the part names, the properties compared, and the list of states |
| `reference.mjs` | regenerates `reference/` from a served copy |
| `compare.mjs` | compares a running implementation with `measurements.json`, part by part |

Serve it with `python3 -m http.server` from that folder. Desktop only: phone layouts are not
designed, not built and not reviewed, by Randall's decision.

---

## 1. Decisions that frame the build (Randall, 2026-09-21)

1. **The new look applies to the whole site, through the token file.** `DESIGN.md` already
   says a redesign replaces the vocabulary by editing `resources/css/app.css`, because views
   reach colours through tokens. TV is the first section *built* for the new look; other pages
   keep their layouts and pick up the new grounds, text colours, accent and typeface. What
   does **not** change site-wide in this work: control shapes and sizes outside the TV
   screens (the app's 42px / 8px-radius `.ui-control` scale stays for every page not
   redesigned yet). Before the token change merges, screenshot Home, Movies browse, Search,
   a Movies details page, Account and one admin page in both themes, before and after, and
   attach them to the PR.
2. **Coral is the only accent. The accent picker is removed.** "Coral means the primary action
   or this-is-on" holds on every screen, and an accent can never collide with a chip hue.
   Removed: the emerald and violet blocks in `resources/css/app.css` (`[data-color-scheme=…]`,
   from line 29), `resources/views/partials/scheme-switcher.blade.php` (no view includes it today), the
   scheme items in `partials/header-menu.blade.php`, the scheme control in `resources/views/account/appearance.blade.php`,
   the scheme half of `resources/js/alpine/stores/theme.js` and `partials/theme-init.blade.php`,
   the `color-scheme-preference` meta in the three layouts, the `color_scheme` rule in
   `UpdateThemeRequest` / `UpdateProfileRequest` / `ProfileController`, and its use in
   `GlobalDataComposer` / `AdminDataComposer`. The `users.color_scheme` column
   (`2026_03_03_120000_add_color_scheme_to_users_table.php`) is dropped by a migration. Light /
   dark stays a per-user choice. `DESIGN.md` is rewritten to match (it currently calls the
   swappable accent the system's "defining structural fact").
3. **Manrope replaces Figtree**, self-hosted as a variable font in `resources/fonts/` with its
   OFL licence beside it, declared once for weights 400–800, and named in `--font-sans`.
4. **Font Awesome stays the icon system** (the design lint enforces one icon library). The
   prototype's stroke icons are a stand-in; section 5 maps each one. This is the one place an
   implementation will not be pixel-identical to the reference screenshots, and it is accepted.
5. **The top bar in the prototype is a stand-in for the app's existing `partials/header-menu`.**
   It is not redesigned and not changed at all (decided 2026-09-24): the prototype draws it as
   the app has it, scope select plus release search, as a dummy. The TV section's
   shows-and-people search is its own field in the toolbar of the releases screen and the
   wall (`SPEC.md` 3.2), a new component, not the header's suggest list.

---

## 2. Tokens

The app's convention is a light value and a `-dark` twin per variable, with class-based dark
mode. Values below are the prototype's, verified in `reference/tokens.json`.

| App token (`resources/css/app.css`) | Light | Dark | Prototype name |
|---|---|---|---|
| `--surface-body` / `-dark` | `#f5f5f2` | `#0f1014` | `--bg` |
| `--surface-card`, `--surface-dropdown` / `-dark` | `#ffffff` | `#181a21` | `--panel` |
| `--surface-panel-alt` / `-dark` | `#e4e4dd` | `#20232c` | `--panel2` |
| `--surface-hover` / `-dark` | `#d3d3cb` | `#2b2f3a` | `--line` (hover fill of neutral controls) |
| `--border-default` / `-dark` | `#d3d3cb` | `#2b2f3a` | `--line` |
| `--text-default` / `-dark` (**new**) | `#17181c` | `#f4f4f6` | `--ink` |
| `--text-muted` / `-dark` | `#5c606b` | `#a4a8b4` | `--dim` |
| `--surface-header`, `--surface-footer`, `--surface-sidebar` / `-dark` | same as `--surface-body` | same | the bar sits on the page ground with a `--border-default` rule under it |
| `--surface-release-row` / `-dark`, `--border-release-row` / `-dark` | `transparent`, `--border-default` | same | rows are hairline-divided, not filled |

**Accent ramp** (`--color-primary-50 … 950`), one ramp, pinned to the two approved corals
(500 and 700) and derived in OKLCH at their hue:

```
50 #fef3f0   100 #fde7e2   200 #ffd0c6   300 #ffb1a0   400 #fd8d77   500 #ff5a3c
600 #e5462c  700 #c8331a   800 #9a2b19   900 #782416   950 #471108
```

Two semantic tokens carry the rule the ramp alone cannot: **`--accent-surface`** and
**`--accent-on`**. Light: `#c8331a` with `#ffffff` text (5.3:1). Dark: `#ff5a3c` with
**`#1d0a05`** text (6.2:1). White on `#ff5a3c` is 3.1:1 and fails, so the app's habit of
`bg-primary-600 text-white` must not be used for filled accent controls; use the pair.

**Chip tokens** (new; tinted ground + coloured text, all ≥ 4.5:1 in both themes). Name them
`--chip-<kind>-bg` / `--chip-<kind>-fg` with `-dark` twins; values in `reference/tokens.json`
(`--k-mi-*` media info, `--k-nfo-*` NFO, `--k-pv-*` Preview, `--k-sm-*` Sample, `--c-ok-*` /
`--c-mid-*` / `--c-low-*` completion bands, `--r4k-*` / `--r1080-*` / `--r720-*` / `--rsd-*`
the solid resolution chips, and under `hues` the media info block's section and value chips).
These are status colours in `DESIGN.md`'s sense: literal and reserved, never the accent.

**Releases-list row button tokens** (new 2026-09-26, on the maintainer's review; `SPEC.md`
3.1 and appendix A). One hue per button, in OKLCH: Copy NZB link 235, Add to cart 150, Watch
300 (the prototype's `--b-cp`, `--b-ct`, `--b-w`). With `h` the button's hue:

| State | Dark ground / icon | Light ground / icon |
|---|---|---|
| off | `oklch(0.31 0.06 h)` / `oklch(0.86 0.11 h)` | `oklch(0.92 0.045 h)` / `oklch(0.45 0.14 h)` |
| off, hover | ground `oklch(0.37 0.08 h)` | ground `oklch(0.87 0.07 h)` |
| on (Cart, Watch only) | `oklch(0.72 0.15 h)` / `oklch(0.18 0.04 h)` | `oklch(0.50 0.15 h)` / `#ffffff` |
| on, hover | ground `oklch(0.78 0.13 h)` | ground `oklch(0.44 0.15 h)` |

Download keeps `--accent-surface` / `--accent-on` and has no on state; Copy link has no on
state. The focus ring on Copy link, Cart and Watch is `--text-default`. Every icon is at
least 3:1 on its ground, off and on, in both themes (`check.mjs`). These apply to the
releases list only; the show page and details tables keep the neutral round button with a
coral pressed state.

**Shadows**: floating layers (menus, dialogs, search results, selection bar) use shadow and
**no border**: light `0 14px 30px -14px rgb(30 30 20 / .35)`, dark `0 18px 40px -14px rgb(0 0 0 / .7)`.

---

## 3. Type and shape on the TV screens

Exact values for every part are in `reference/measurements.json`; the rules behind them:

- Manrope. Weight 800 for page and show titles (30px page title, 44px show title, 30px details
  heading); **700 only for the release name** (15.5px) and tile titles; 600 for controls
  (13.5px); 400–500 for everything else; body 15px / 1.5. Tabular numerals for sizes, counts
  and dates. Only the release name is bold in a row.
- Controls are **pills** (radius = half the height): filter and sort buttons 38px, details
  buttons 40px, the episode "N releases" button 34px, round row actions 32px. Menu panels
  radius 14px, menu items 40px tall with radius 9px, dialogs radius 18px, posters radius 12–14px
  with a soft offset shadow, chips radius 8px (28px tall) and resolution chips radius 7px
  (72 × 26px, fixed width so they form a column).
- Content column: max 1500px with 40px gutters (`.wrap`).
- Releases table: `table-layout: fixed`, column widths in `measurements.json`
  → `releasesTableColumns` (34, 116, auto, 100, 80, 82, 112, 96 px: select, poster,
  release, Resolution, Source, Size, Posted / Added, the 2 × 2 buttons). There is no Files
  and no Grabs column (changed 2026-09-26 on the maintainer's review; the release column is
  800 px at a 1600 px window). The show page and details release tables are unchanged: they
  keep Files 64 and Grabs 76 and the one-line buttons (168 px).
- Releases-list row buttons: a 2 × 2 grid of 32 px round buttons with 6 px gaps, Download and
  Copy link on top, Cart and Watch below.
- Group and poster chips on a releases-list row: the outline chip (`.rc.origin`: no fill,
  1 px `--border-default` border, `--text-muted` text, `--text-default` on hover), 8 px apart,
  as one unit (`inline-flex`, minimum 190 px) whose poster chip shortens with an ellipsis.
- No-poster placeholder: the poster's 88 × 132 box on `--surface-panel-alt`; the name card
  in 11 px weight 500 `--text-muted`, clamped to 4 lines, with the episode or date below in
  tabular numerals; the tile a 30 px TV icon over "No poster" in 11 px.

---

## 4. Components: what is reused, what is new

| Prototype part | In the app | Change |
|---|---|---|
| Top bar | `partials/header-menu`, `public-navigation-component.js` | unchanged (decision 5) |
| TV search field and results panel | new `x-tv-search` + Alpine component `tvSearch` registered in `lazy-loader.js`; endpoint `GET /tv/search` (`DATA-CONTRACT.md` 4) | new; rendered in the toolbar of the releases screen and the wall only, right of `x-segmented` |
| Theme toggle | `theme-toggle.js`, `partials/theme-switcher` | scheme half removed (decision 2) |
| Releases / Shows switch | new `x-segmented` (two links, `aria-current`) | new |
| Category, Resolution, Source and the six Shows menus | **new** `x-checkbox-menu` + Alpine component `checkboxMenu` registered in `resources/js/alpine/lazy-loader.js` | new. One component for all nine. Behaviour rules are `SPEC.md` section 2 rule 10 and `check.mjs` |
| Sort | `x-sort-dropdown`, `sort-dropdown.js` | restyled to the pill; writes `users.view_prefs` |
| "Showing X–Y of N" line and bottom pager | new `x-pager-line`, `x-pager` | new; never omitted, never moves |
| Release name / show line / chips | `x-release-facts`, `x-chip`, `x-release-completion-chips` | `x-chip` gains the tones `media`, `nfo`, `preview`, `sample`, `clip`, `listen`, `password`, `completion-ok / -mid / -low`; the separate repair chip and the Reported / Response chips are removed (`SPEC.md` 4) |
| Group and poster chips on releases-list rows (2026-09-26) | `x-origin-chip` (`kind="group"` / `"poster"`, `href` `route('browse.all', ['group' => …])` / `['poster' => …]`, as `release-browser/origin` passes it) | at the end of the chip line on every releases-list row, wrapped together so the pair never splits and the poster name shortens with an ellipsis; the group label reads `a.b.` for `alt.binaries.`, the full name stays in the title; not on the show page or details release tables |
| No-poster placeholder (2026-09-26) | new, in the releases list row | name card or "No poster" tile for releases with no matched show; the name is parsed per `SPEC.md` appendix A (the prototype's `showName()`) |
| Resolution chip | new `x-resolution-chip` | new, fed by `releases.resolution` |
| Row actions | `release-action*`, `cart-button.js`, `x-watch-button` (keeps today's picker behaviour) | restyled round; Report and Details buttons removed; **Copy NZB link** is new (`copyNzbLink` Alpine component, clipboard with an `execCommand` fallback, toast through `toast-notification.js`). On the **releases list** (2026-09-26): 2 × 2 (Download + Copy link on top, Cart + Watch below; Cart alone when there is no Watch), Copy link / Cart / Watch in their own tinted hue and a pressed Cart / Watch filled in that hue (section 2), Download unchanged. The show page and details tables keep the one-line row with a coral pressed state |
| Row selection and the floating bar | `release-browser-component.js` | restyled; the bar floats (fixed), it does not push the list |
| Same-show batch expander | new, inside the releases list component | new |
| Shows wall tile | `tv-show-directory-component.js` | rebuilt to the tile in the reference |
| Show page: tabs, episode rows, release tables | `title-overview-component.js`, `tab-switcher.js` | season tabs are links (`aria-current`); episode rows are one Alpine component `tvEpisodeList` |
| Release details | `release-details-component.js`, `x-breadcrumb` | rebuilt to the reference |
| Media info block | `mediainfo-modal-component.js` + a **new PHP presenter** for the friendly names (none exist today, `DATA-CONTRACT.md` section 4) | one renderer for the tab and the dialog |
| Image dialog with Full size | `image-modal-component.js`, `preview-modal-component.js`, `x-image-fullscreen-control`, `fullscreen-stage.js` | behaviour per the reference: pixel size shown, button only when larger than shown, grows to the window |
| File list, NFO dialogs | `filelist-modal-component.js`, `nfo-modal.js`, `x-modal` | restyled; sizes under 1 MB in KB |
| Empty results | `x-empty-state` | copy from the prototype |

**Platform constraints that bind all of it** (`.ai/rules/resources.md`, `DESIGN.md`):

- **No inline `style` attributes** in views: the Alpine CSP build rejects them. The prototype
  uses inline styles in a few places (table column widths, the rotated chevron, hidden "Clear
  all"); in the app these are classes in `resources/css/csp-safe.css`.
- Alpine **CSP build**: no complex expressions in attributes; page-specific components must be
  registered in `resources/js/alpine/lazy-loader.js` under the same name as their `x-data`, or
  their handlers never load.
- Reach colours through tokens and `primary-*`; `scripts/check-design-system.sh` rejects literal
  accent utilities and `bg-white` / `bg-gray-N` in public views.
- Use `<x-button>` / `<x-button-link>` where a plain button is meant; compact row actions may
  use `release-action*`; menu togglers, close buttons, chips and pagination may keep bespoke markup.

---

## 5. Icons (prototype sprite → Font Awesome 6 Free, solid unless noted)

`search` fa-magnifying-glass · `down` fa-download · `link` fa-link · `cart` fa-cart-shopping ·
`eye` fa-eye · `check` fa-check · `info` fa-circle-info · `lock` fa-lock · `users` fa-users ·
`user` fa-user · `x` and the rotated `plus` fa-xmark · `back` fa-arrow-left · `chev`
fa-chevron-down / -up / -right / -left as the state needs · `sort` fa-sort · `sun` fa-sun /
fa-moon · `tv` fa-tv · `audio` fa-volume-high · `file` fa-file-lines · `image` fa-image.
Chips carry **words**, not icons, except the lock on Password and the ⓘ on the media info chip.

---

## 6. How "matches the prototype" is checked

An implementation is accepted when all four hold. None of them is a judgement call.

1. **Behaviour**: every check in `check.mjs` has an equivalent in the application's tests and
   passes (PHP feature tests for what the server renders and filters; `tests/js` for the Alpine
   components). `check.mjs` is the list of required behaviours; its check names are the
   acceptance names.
2. **Parts**: the app marks each part with **`data-part="<name>"`** using the names in
   `reference/parts.json` (82 parts: "release name", "filter menu button, set", "releases
   button, open", …). Where a part repeats (rows, cells, tabs) mark the **first**
   one; `parts.json` shows which element the prototype's measurement came from. With the branch running under `scripts/agent-preview start`:

   ```bash
   node docs/proposals/tv-redesign/prototype/compare.mjs \
     --base <preview URL> --pages pages.json --cookie "<session cookie>" \
     --ref docs/proposals/tv-redesign/prototype/reference
   ```

   `pages.json` maps page names to paths (`{"releases": "/tv", "shows": "/tv/shows",
   "show": "/tv/show/<id>/<season>", "details": "/details/<guid>"}`; several entries per page
   are allowed, e.g. the four fixture shows #779 names). It must end with **`0 differences`**;
   the tool visits each page with no interaction, so parts that exist only in an interaction
   state (open menus, the selection bar, "releases button, open", the dialogs and media-info
   parts: 17 of the 82) are reported as not found even against the prototype itself, and
   those MISSING lines are expected. Every part found on a page must match. Tolerances: lengths ±1px (font size ±0.5px), colours
   exact after normalising to rgba, first font family, exact weight; fixed-size parts (chips,
   buttons, posters, tabs) also match in height. The tool is proven both ways: 0 differences
   against the prototype itself, and it reports every changed part on a copy with a different
   accent and a larger release name.
3. **States**: for each of the 26 states in `reference/parts.json`, a screenshot of the app in
   that state, dark and light, at 1600 × 1000, attached to the PR beside the reference image.
   Data differs, so this is reviewed by a person (Randall); the part comparison above is what
   makes that review short.
4. **Rule 5, "nothing shifts"**, measured: the geometry checks in `check.mjs` (filter row
   height and button widths with nothing set and with everything set; list top before and
   after selecting rows; season bar height with all filters ticked) are reproduced against the
   app.

`scripts/check-design-system.sh` and the rest of `scripts/agent-verify` apply as usual.

---

## 7. Known, accepted differences from the reference images

- Icons are Font Awesome (decision 4).
- The top bar is the app's own (decision 5).
- The watch button opens today's watch-list picker rather than toggling in place.
- Real data: long names, missing posters and shows without details render as the "thin state"
  references show.
