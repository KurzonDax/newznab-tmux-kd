# Front-end redesign specification (non-admin)

Status: **agreed and prototyped; ready for implementation planning**
Design session: 2026-09-13 (Randall + Claude). Decisions were made against a clickable prototype; this spec describes that prototype and is the contract for implementation.
Scope: every non-admin page. The admin area is out of scope. The v1 XML API, v2 JSON API and RSS feeds are frozen and must not change.

## Reference implementation

`prototype/nntmux-prototype.html` is a self-contained, client-side prototype of the agreed design on sample data. **When this document and the prototype disagree, the prototype wins for anything visual or interactive**, and this document wins for data rules and backend behaviour (which the prototype only simulates). Open the HTML file in a browser; every control on it works.

- `prototype/proto-app.js` — the prototype's behaviour (router, state, renderers). Read it when a behaviour in the spec is ambiguous.
- `prototype/proto-base.css`, `prototype/proto-app.css` — the prototype's styling (tokens, type scale, control scale, chips, cards, tables). The implementation uses the project's Tailwind + design-system classes, not these files, but the values (sizes, colours by token, spacing) are the ones to match.
- `prototype/test-harness.js`, `prototype/test-entity.js` — scripted click-throughs used to verify the prototype; `13-rollout-and-acceptance.md` turns them into acceptance criteria.
- `prototype/review.html` — the review of the current front end that led here (findings with file:line references, the option mockups that were rejected, and the decisions panel).

## Documents

| # | Document | Covers |
|---|----------|--------|
| 01 | [Design system](01-design-system.md) | Tokens, type scale, control scale, links, chips and their colour semantics, row action buttons, icons, artwork boxes and the no-artwork placeholder, dark/light |
| 02 | [Shell and navigation](02-shell-and-navigation.md) | Top bar only: Browse mega-menu, Trending, Watchlist, scoped search, basket, user menu; mobile; toasts |
| 03 | [Release browser](03-release-browser.md) | The one listing component: routes, toolbar, pager, Table view, Cards view (eligibility rules), bulk actions, group and poster pages, persistence |
| 04 | [Covers view](04-covers-view.md) | Entity grid per root: sizes S/L/XL, entity cards, the click-to-expand releases row, A–Z strip, per-root differences (Audio square, Adult preview/sample tiles, Console, Books) |
| 05 | [Entity overview pages](05-entity-overview-pages.md) | One template for movie / show / album / game / book: metadata per root, links, Watch, releases; TV season tabs |
| 06 | [Release details](06-release-details.md) | Header-first page with tabs Overview / Files / Media info / NFO / Comments; rail with other releases and similar releases |
| 07 | [Search](07-search.md) | One scoped search box; results page with query chips; prefix syntax |
| 08 | [Watchlist](08-watchlist.md) | The add flow (title page, cover heart, row action), the category picker, the Watchlist page replacing My Shows + My Movies, the "only titles I follow" filter |
| 09 | [Account, basket, home](09-account-basket-home.md) | Account page with sections, basket as a release table, home dashboard, auth and error pages |
| 10 | [Modals](10-modals.md) | One modal base; NFO, preview (image / clip / listen / sample), watch picker, media info, file list, report, confirm |
| 11 | [Data and backend](11-data-and-backend.md) | Row DTO, entity sources per root, Cards eligibility flags, posted/added, group/poster filters, adult tiles, cover URLs, view persistence, search scope |
| 12 | [Must-fix defects](12-must-fix-defects.md) | Bugs found in the review that are independent of the redesign |
| 13 | [Rollout and acceptance](13-rollout-and-acceptance.md) | Phases, issue breakdown, acceptance checklist, how to run the prototype's scripted checks |

## Decisions (do not reopen without Randall)

| Area | Decision |
|------|----------|
| Shell | **Top bar only.** No sidebar. Category mega-menu, single scoped search box, Watchlist, Basket, user menu. |
| Browse | **One release browser** with three views: **Table** (unfiltered), **Cards** (renamed + post-processed releases only; offered on Movies, TV, Audio, Console, Books, Adult), **Covers** (one tile per entity; releases on click). View, cover size and per-page are remembered per user. |
| Covers | Cover size S / L / XL is layout only. Per page (24 / 48 / 100, default 48) is one control in the pager and applies to every view. Entity cards carry entity data only: artwork, title, one identifying line, release count, watchlist heart. No release facts and no download/basket on an entity card. |
| Rows | Every row (Table and Cards) shows: release name as a plain-text title (not link-blue), the facts chips after it on the same line, then an origin line with the **entity chip**, **group chip** and **poster chip**. Columns include **Added** (relative) and **Posted** (absolute date/time). |
| Row actions | Exactly the project's four (Download, Details, Basket, Report) plus **Watch** when the release is matched to a movie or show. Same 28 px filled buttons everywhere. No "more" menu. |
| Chips | Colours follow the project's existing badges (completion green/yellow/red, password red, media info primary, NFO yellow, preview cyan, sample green). NFO chips open the NFO modal; preview/clip/listen/sample chips open the preview modal. |
| Entity pages | One overview template per root with metadata, artwork, links, Watch, and every linked release. **TV seasons are tabs**, ascending, newest selected by default. |
| Search | One scoped search; results are the release browser with a query-chip bar. |
| Details | Header-first with tabs. |
| Watchlist | One Watch button in three places; category picker; the merged **Watchlist page** (My Movies / My Shows tabs) as prototyped. |
| Account | One Account page with sections; basket is a release table; home is a dashboard. |
| Roots | Audio uses square album art. Adult has no entity: tiles are releases showing the preview image or sample sheet. Console and Books behave like Movies. |
| Cast | TV cast is **not** captured today and is **not** a blocker: see issue #556; the show page omits Cast until that lands. |

Open, parked by Randall: network logos on TV covers (feasible via TMDB; do nothing until raised).

## Glossary

- **Root** — a top-level category: Movies, TV, Audio (Music), Console, PC/Games, Books, Adult (XXX), Other. "All releases" is the un-rooted list.
- **Entity** — the metadata record a release is matched to: a movie (`movieinfo`), a show (`videos` + `tv_info`), an album (`musicinfo`), a console game (`consoleinfo`), a book (`bookinfo`). Adult and Other have no entity.
- **Facts strip / facts chips** — the chips that describe one release: completion, password, media info, NFO, preview.
- **Origin chips** — the group chip and the poster chip on a row.
- **Entity chip** — the chip on a row that opens the entity overview page.
- **Cards eligible** — a release that has been renamed and has finished post-processing (see 11).
