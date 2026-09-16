# 13 · Rollout and acceptance

## Phases

Each bullet is sized for one issue / one PR through the normal loop (`scripts/agent-issue-start` → work → `scripts/agent-issue-finish`). Phases 0 and 1 have no design dependency; 2 and 3 depend on the primitives.

**Phase 0 — defects (12).** One PR per row, or grouped by file. Includes the linter extension.

**Phase 1 — primitives (01, 02, 10, 11).**
1. Control scale on `<x-input>`, `<x-select>`, `<x-button>`, sort/filter triggers; custom select chevron; `appearance:none` on buttons.
2. `<x-modal>` base; migrate the seven modals onto it (10).
3. `<x-page-header>` + `<x-breadcrumb>` adoption sweep; delete the nine hand-written breadcrumbs.
4. Row DTO loader that always includes media-info availability, `renamed`, `pp_done`, `posted`, `added`, group and poster (11); shared size formatter.
5. Chip component with the semantic variants (01) and the origin/entity chips.
6. Toast-only flash channel (02).

**Phase 2 — the browser (03, 04).**
1. `<x-release-browser>` with Table view + toolbar + pager + bulk bar + per-user persistence; replace `browse/index`, `search/index`, the basket, the Adult table, group/poster pages.
2. Cards view with the eligibility rule.
3. Covers view: entity grid, sizes, expand row, A–Z; replace the five cover pages and `viewserieslist`; Adult preview/sample tiles.

**Phase 3 — pages (02, 05, 06, 07, 08, 09).**
1. Top-bar-only shell with the mega-menu and the scoped search; remove sidebar and the duplicated menus.
2. Entity overview template with TV season tabs; retire `viewmoviefull` and `viewseries`.
3. Details page, header-first with tabs and rail.
4. Search results with the query-chip bar.
5. Watchlist: picker, page, filter; retire myshows/mymovies pages.
6. Account page, basket, home dashboard, auth and error pages.

## Acceptance checklist

Derived from the prototype's scripted checks (`prototype/test-harness.js`, `test-entity.js`) and the decisions. Every item must hold in light and dark and in all three colour schemes.

**Shell**
- [ ] Only a top bar; no sidebar; one category list drives the mega-menu, the mobile drawer and the search scope.
- [ ] Browse, Trending, Watchlist look identical (no native button chrome); current section underlined.
- [ ] `/` focuses the search; Enter goes to `/search?q&t`.
- [ ] Flash messages appear once, as toasts.

**Browser**
- [ ] View, cover size, per-page and thumbnails are remembered per user per root.
- [ ] Per page 24/48/100 changes the count in every view; cover size never changes the count; the pager cannot pass the last page; "Go to page" rejects out-of-range.
- [ ] Table columns: checkbox, Release (name + chips; entity/group/poster chips), Category, Size, Files, Added, Posted, Stats, actions.
- [ ] Release name is 14 px semibold `--text`, not link-blue, in every view.
- [ ] Group chip → `/browse/all?group=`; poster chip → `/browse/all?poster=`; those pages show the name once, in the heading, with Clear filter.
- [ ] Cards is offered only on Movies, TV, Audio, Console, Books, Adult; shows only renamed + post-processed releases; the pager says how many are hidden; one release per full-width row with aligned Size/Added/Posted/Grabs columns.
- [ ] Row actions: Download, Details, Basket, Report, Watch (Watch only when matched); basket button goes green when in basket; watch button primary when followed.
- [ ] Chips: completion / password / media info / NFO / preview colours per 01; NFO opens the NFO modal; preview kinds open the preview modal; media info and files open their modals.
- [ ] Bulk bar appears on selection with Add to basket, Download N, Clear.

**Covers**
- [ ] One tile per entity; tile shows artwork, title, identifying line, count badge, heart (movies/tv only); nothing release-specific.
- [ ] S/L/XL column counts per root aspect; XL not on Adult; measured columns.
- [ ] Click expands the entity's release table after its grid row; Esc closes; ←/→ move; the "Title page" button opens the overview.
- [ ] A–Z strip on TV, Audio, Books jumps by initial and sorts by title.
- [ ] Audio tiles are square with artist on the identifying line; Adult tiles are 16:9 preview or sample frames with a tag; placeholder tiles for missing art.

**Entity pages**
- [ ] Entity chip on every matched row (Table and Cards) opens `/title/{root}/{id}`; same page as the covers "Title page".
- [ ] Per-root metadata rows and links as in 05; movie plot and cast; album track list; book overview; empty rows omitted.
- [ ] TV: seasons as tabs, ascending, newest selected, count on each tab, one season table at a time, bulk selection includes only checked rows on the current page.
- [ ] Quality/format chips filter in place.

**Details**
- [ ] Header card with artwork, name, facts chips, category/size/files/added/posted/grabs/comments, full-size actions; tabs Overview/Files/Media info/NFO/Comments; rail with other releases and similar releases; nothing rendered before the doctype.

**Search**
- [ ] One search box; results page with removable query chips and scope chip; prefix syntax becomes chips.

**Watchlist**
- [ ] Watch from the overview page, the cover heart and the row action opens the picker; Add stores categories; all hearts for that title update; toast with Open.
- [ ] Watchlist page: My Movies / My Shows tabs with counts, find-to-add box, cards with latest release and categories, Edit, Remove with Undo, View releases from these, RSS.
- [ ] "Only titles I follow" filters Movies and TV browse.

**Account / basket / home**
- [ ] Account sections edit in place with the shared form components; API key has Copy; usage cards have titles and counters; theme and scheme apply immediately.
- [ ] Basket is the release table with a footer count, Empty basket, Download N NZBs.
- [ ] Home renders a dashboard even with no admin content.

**Modals**
- [ ] One base; close button top-right; Esc closes; overlay click closes; no caption text in footers; NFO on the dark mono pane with Copy / Download .nfo / Details / Download NZB.

**Design system**
- [ ] No inline styles, no `blue-*` / `indigo-*` / `purple-*`, no FA4 names, no `bg-white`/`bg-gray-*` surfaces outside components; the linter enforces these.
- [ ] One type scale (11/12/13/14/16/20); monospace only in NFO content; two control heights.

## Running the prototype's checks

```bash
cd docs/proposals/frontend-redesign/prototype
{ printf '<!doctype html><html><head><meta charset="utf-8">'; cat nntmux-prototype.html; printf '<script>'; cat test-harness.js; printf '</script></head><body></body></html>'; } > /tmp/preview.html
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --virtual-time-budget=15000 --dump-dom "file:///tmp/preview.html" | sed -n '/<pre id="testlog">/,/<\/pre>/p'
```

Every line should read `OK …` and `ERRORS: []`. Swap `test-entity.js` for the entity-page checks. The implementation should get equivalent Feature tests (PHPUnit) for the same behaviours; the harness is the list of what to cover.
