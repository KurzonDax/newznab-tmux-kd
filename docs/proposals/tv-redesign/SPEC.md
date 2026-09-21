# NNTmux TV section: specification

Written 2026-09-21 from the maintainer's design review sessions (what Randall approved,
rejected and decided) and query-lab experiments on a restored production catalogue. It is one
of three documents: this one says what each screen does, `DATA-CONTRACT.md` says what is
stored and which code writes it, `VISUAL-CONTRACT.md` says how an implementation is matched
to the prototype and checked. The
clickable prototype `tv.html` is the visual and behavioural reference: where this document
and the prototype disagree about how something looks or behaves, the prototype wins; where
they disagree about data, storage or queries, this document wins.

Status: every screen below is **approved**. Nothing in the application has been changed yet.

---

## 1. Scope

In scope: the four TV screens and their dialogs.

| Screen | Route in the prototype | Replaces |
|---|---|---|
| TV releases (list) | `#/`, `#/p/N` | today's TV Table and Cards views |
| TV shows (discovery wall) | `#/shows`, `#/shows/p/N` | today's TV Covers view |
| Show page | `#/show/<videos_id>/<season>` | today's series page |
| Release details (TV) | `#/release/<id>` | today's details page, for TV releases |
| Dialogs: media info, NFO, preview / sample image, file list | opened from chips and counts | today's equivalents |

Out of scope, by his decision: phone layouts (desktop only); comments (lowest priority of
anything); the watchlist page (a separate, low-priority design section; the **Watch show**
button itself is in scope and keeps today's behaviour); "Listen" audio previews (audio
category only); the rest of the site (Movies, home, and so on).

Hard constraints from the repository (`AGENTS.md`):

- **External API and RSS are frozen**, including additive changes. Everything here is web
  front end. "Copy NZB link" uses the existing v1 `t=get` call unchanged (appendix A).
- **No new Artisan command without Randall's explicit approval of that specific command.**
  This spec proposes none. Filling values for releases and shows already in the catalogue is
  a retroactive-data decision to be taken with the others after the build (section 8).
- Colours route through the existing token layer (`DESIGN.md`): the accent is a per-user
  setting; no view hardcodes a hue. `tokens.css` lists the prototype's values.
- Settings, if any are needed, are declared in `app/Support/Settings/` section providers.
- Per-user remembered choices (the two sort orders) go in the existing view preferences
  (`users.view_prefs` via `User::releaseViewPreferences()`), not a cookie.

---

## 2. Rules that apply to every screen

Each is a test, not a preference.

1. Never judge a release. Show facts; let people filter. The one exception he chose himself:
   the completion chip's colour bands (95–99 green, 75–94 orange, under 75 red).
2. Never rank or feature by release count or popularity.
3. **Numbered pagination only.** No infinite scroll, no "load more". (Expanders inside a list
   are allowed.) The page number is in the URL.
4. Only the release name is bold in a row.
5. **Nothing may shift when state changes**: ticking a filter, selecting rows, a small or
   empty result, an open menu. Controls keep their size and place.
6. No explanatory text for state a control already shows; no instruction text ("click to…").
7. **Colour is wanted**: different kinds of chip get different hues, low-key. Words on chips,
   not cryptic icons.
8. **Coral (the accent) means the primary action or "this is on / open / current"**: download,
   current view / tab / page, a set filter, an open episode's releases button, a pressed
   cart / watch button, a pressed Full size button. Spend it on nothing else.
9. No Report button anywhere. No separate details button in rows: the release name is the link.
10. A checkbox menu closes when focus leaves it, on Escape, and on a click outside; it stays
    open while ticking and keeps its scroll position; keyboard focus stays on the control
    that was pressed after any re-render.
11. An approved screen is frozen: a change to shared components must say exactly what changes
    on each approved screen.

---

## 3. Screens

Each subsection lists what the screen must do. Exact layout, sizes and copy are in the
prototype (`prototype/tv.html`) and its reference set (`VISUAL-CONTRACT.md`).

### 3.1 TV releases

- Header: title "TV releases", Releases / Shows switch, **Category** (the TV sub-categories the
  user may see: HD, UHD, SD, Foreign, …; Releases list only), **Resolution** and **Source**
  multi-select checkbox menus (OR within a menu, AND between them; button reads
  `Resolution: 4K, 1080p` and turns coral when set; "Any resolution" clears), **Sort** with
  four orders: Posted newest (default), Posted oldest, Added newest, Added oldest. The date
  column follows the sort. The chosen sort is remembered per user. Changing a filter or the
  sort returns to page 1.
- Under the header, always present and never moving: `Showing 101–150 of 8,249 releases` ·
  previous · `Page 3 of 165` · next. One page reads `Page 1 of 1` with both arrows greyed; no
  results reads `Showing 0 releases`. Full pager with "Go to page" at the bottom. 50 per page.
- Table with fixed column widths: select, poster 88×132 (links to details), release cell
  (bold name → that release's details; grey `Show · S01E02 · Episode title` → show page with
  that episode open; one line of chips), resolution chip, source, size, files (opens the file
  list dialog), posted / added, grabs, four round buttons in this order: **Download NZB**
  (coral), **Copy NZB link**, **Add to cart**, **Watch this show**.
- Select-all in the header (with a partial state) selects the rows visible on the page.
  Selecting brings up a floating bar: `15 selected · Download NZBs · Add to cart · Clear selection`.
- Same-show batch expander: consecutive releases of one show posted the same day collapse to
  the first three plus "Show 13 more from <show> posted in the same batch".
- Chips (section 4).

### 3.2 TV shows

- Title "TV shows", the same switch. **No resolution or source filters here.**
- Six **multi-select** checkbox menus, the same component as Resolution / Source: Genre,
  Premiered (decade), Language (the show's original language), Network, Rating (US TV
  Parental Guidelines), Status (Running / Ended). OR within a menu, AND between menus. A set
  filter turns coral and reads `Genre: Drama` for one value, `Genre: 2 chosen` for several;
  **every filter button has one fixed width whatever is ticked and "Clear all" keeps its
  place even when hidden**, so the row never changes size or wraps. Long lists scroll inside
  the menu; menus stay inside the window and under the sticky top bar.
- Sort: Newest releases first (default), Newest to the site first, Newest premiere first,
  A to Z. Remembered per user.
- The same "Showing 1–42 of N shows" line and pagers. 42 per page.
- Tile: poster (a title card when there is none), bold title, `Year · Genre, Genre`,
  `Language · Rating`. No release counts, no resolution chips.
- Search (top bar, every screen) finds **shows and people**, grouped. Picking a person filters
  the wall to their shows with a removable "Starring <name>" chip.

### 3.3 Show page

- Back link to the list the user came from. Poster, title, `Network · year · N seasons on
  site · N releases` (the year lives here; **no "Premiered" tag on this page**), summary,
  tags (genres link to the wall filtered by that genre; language, rating, status are plain),
  "Starring" names linking to the wall filtered by that person. The right half of the header
  stays empty.
- **Seasons are tabs** sitting directly on the episode list, on a sticky bar, in the details
  page's tab style (coral underline on the current one), with Resolution and Source menus at
  the right of the same row. From 9 seasons up the row reads `Season  Specials 1 2 3 … 24`.
  The tab row never wraps (it scrolls sideways if it must), so the bar's height never
  changes. Date-named daily shows get a "By air date" tab. Numeric tabs carry the accessible
  name "Season 22".
- Episode row: number, title, aired date, resolution chips present, size range (one size
  when all releases are the same size), and a button-shaped **`4 releases ⌄`** control at
  the right. The whole row is the click target; the arrow flips and the button turns coral
  while the row is open. Nothing is open on arrival, except the episode the user arrived
  from.
- An open episode shows a release table: the Releases-page row minus the artwork, with a
  **select checkbox per row and no check-all box**, sortable Resolution / Size / Posted /
  Grabs headers, the same chips and four buttons. Identical release names get a grey line
  naming poster and group. The same floating selection bar; the selection carries across
  episodes and seasons.
- "Whole-season packs" section per season.
- Empty filter result: "No releases in this season match SD." and nothing moves.

### 3.4 Release details

- Breadcrumb; poster; heading `Show · S01E02 — Episode title` with the release name as the
  bold second line; resolution and source chips, the chip line, group and poster chips;
  buttons Download NZB, Copy NZB link, Add to cart, Watch show.
- Tabs: Overview (preview thumbnail, aired date and summary, facts grid), Files, Media info,
  NFO, Comments (unchanged from today; no design work).
- Right of the tabs: **"About the show"**: `Network · N seasons on site` (no year on this
  line; **the "Premiered 2026" tag stays on this page**), the show's tags, Starring, and an
  "All seasons and episodes" link. Rows with nothing to show are omitted.
- Underneath, full width: **"All N releases of this episode"** as the same release table as
  the show page, **without checkboxes**; the release being viewed has a tinted row, the words
  "The release on this page", and its name is not a link.

### 3.5 Dialogs

- **Media info** (one renderer for the details tab and the dialog): a four-part glance row
  (Video, Audio, Subtitles, File); Video as a labelled grid; Audio and Subtitles as aligned
  tables led by language; plain values ("H.265 (HEVC)", "Dolby Digital Plus" with "E-AC-3"
  small, "5.1" / "Stereo", "HDR10+", "Dolby Vision · profile 5"); a flag only when it is
  set; subtitle lists with no per-track detail and more than 8 tracks collapse to a language
  grid with counts. Colour: section chips Video indigo / Audio teal / Subtitles amber; the
  Releases page's solid resolution chip, taken from the release's own resolution; HDR,
  channel, Atmos and subtitle-flag chips each in their own hue; "Image" chip on picture-based
  subtitle formats only, **no "Text" chips**; no coral.
- **Preview / Sample image**: shows the image's pixel size and a **Full size** button only
  when the image is larger than shown; Full size grows the dialog to the window and shows real
  pixels (scrolling if needed); the button turns coral and reads "Fit to window"; clicking
  the image toggles too. **Clip** (not enabled for TV today) and **Listen** reuse this dialog
  with the right player, keeping the behaviour they have in the current app.
- **File list**: a plain two-column list, file and size; sizes under 1 MB in KB.
- **NFO**: the real text, monospace.
- All dialogs close with X, Escape or a click outside; focus returns to what opened them.

---

## 4. Chips

Resolution (own column, solid, fixed width): 4K violet, 1080p blue, 720p teal, SD grey,
Unknown dashed outline.

Chip line under a release name (tinted ground + coloured text), in this order:

| Chip | When | Click |
|---|---|---|
| `94% complete` / `94% complete · still repairing` | only under 100%; "· still repairing" while `repair_outcome` or `rescan_outcome` is not final (the rule in `App\Support\ReleaseCompletion::repairAttemptsExhausted`); his colour bands | none |
| Password | passworded | none |
| ⓘ `H.265 · E-AC-3 5.1` | media info exists (codec and audio only, friendly names) | media info dialog |
| NFO | has an NFO | NFO dialog |
| Preview | has a preview image | image dialog |
| Sample | has a sample image | image dialog |
| Clip, Listen | when those features apply to the category | the image dialog with a player |

Dropped from today's row: the separate repair chip (folded in above), "Reported" and
"Response" chips, the comments count, the details (info) button, the Report button. Group and
poster chips appear on the details page only. All chip colours pass 4.5:1 in both themes.

---

## 5. Data the site must store

Specified exactly in **`DATA-CONTRACT.md`**; in short:

- `resolution` and `source` become columns on `releases`, **for every category**. Resolution is
  the measured video size when the site has one, else the resolution in the name, else
  unknown. Source comes from the name (media info cannot tell a source). Nothing is copied to
  side tables, and no counts or per-show aggregates are stored.
- The season and episode numbers a release **declares** are stored in a child table, so the
  show page no longer re-parses every name on every view, and releases whose episode has no
  `tv_episodes` row (11% in production) are still listed.
- Show details (genres, cast, original language, US rating, Running / Ended, premiere date,
  network) come from TMDB first, are saved when a show is first matched, and are refreshed
  only when a new release arrives for the show and not within 24 hours of the last refresh.
  **No scheduled or background refresh**: "This app isn't supposed to be an authoritative source
  on show information." Genres, people and networks are lookup tables with link tables.
- Coverage to expect, measured: language and status 93% of shows with releases, genres 91%,
  cast 85%, network 97%, premiere year 99.9%, **US rating only 57%**, so the Rating filter
  always leaves a large unrated remainder.

---

## 6. Queries

His rule: a page reads thousands of rows, not hundreds of thousands, and costs the same on
page 1 and page 200. Every query shape in `DATA-CONTRACT.md` section 4 was run on a restored
production catalogue at full size (2.36 million releases, 173 thousand of them TV): TV list
page 1 0.4 ms, page 200 about 2 ms, exact middle page 12 ms; counts that are correct for each
user 0.4 to 16 ms; Shows wall 0.4 to 8 ms; show page 1 to 3 ms. The two places the rule is not
met (the unfiltered count and the exact middle page) are named there with their cost.

---

## 7. Open questions

1. **Very large episodes are bad data, not a design case.** One episode in production holds
   933 releases because of a name-fixing fault that is being investigated separately; the next
   biggest has 31. The unpaged episode table stands.
2. **Whole-season packs**: how a pack is recognised and linked to a season. Only 15 releases
   are stored as packs in the lab's membership data; the prototype infers packs from names.
3. **Releases whose named episode has no `tv_episodes` row** (about 12%): where they appear on
   the show page.
4. **Network spellings**: the merge rule for 591 distinct values.
5. **Overall bit rate**: the stored number does not match size ÷ duration in any unit (checked
   on 2,847 complete releases); the media info block leaves it out until that is understood.
6. Media info reviewer notes he has not asked for: a few chip hues sit
   close together; the Audio glance can show Atmos beside a non-Atmos track's format.
7. `PRODUCT.md` sits untracked in the application repository root and needs his decision.

---

## 8. Existing data

Decided 2026-09-21: downtime is not a concern, so **the migrations fill what they add**
(`DATA-CONTRACT.md` section 5): resolution and source for every existing release in one
statement, the declared season / episode rows in chunks, the network lookup from the existing
text. Show details are the exception by his decision: they fill on each show's next release.
No command is added.

---

## 9. Suggested build order (one issue each; each needs his authorisation)

Storage and write paths are specified in `DATA-CONTRACT.md`; the numbers below follow it.

1. `resolution` and `source` on `releases` for every category, the generated `category_band`,
   the five indexes, the rules, `ReleaseDerivedFacts::refresh()` called from
   `SearchService::updateRelease()`, and the migration that fills every existing release.
2. `release_tv_episodes` (the season and episode numbers a release declares), written by the
   same `refresh()`, filled by its migration.
3. Show details: the `tv_info` columns, `networks`, `genres`, `people`, `video_genres`,
   `video_people`, and `TvShowDetails::refreshIfDue()` hooked after `setVideoIdFound()`.
4. TV releases screen. 5. TV shows screen. 6. Show page. 7. Release details page for TV.
8. Dialogs: media info presenter and renderer, image dialog with Full size, file list, NFO.
9. Retire `TvBrowseMembershipTable`, `TvEpisodeBrowser`, the TV branch of `ReleaseCoverBrowser`
   and the old TV Covers / Table / Cards views.

Screens 4–7 can be built against fixtures once 1–3 define the stored shapes. Each screen's
acceptance test is the matching block of `check.mjs` (199 checks), ported to the
application's test tooling, plus rule 5 ("nothing shifts") measured, not eyeballed.

---

## Appendix A. Details an implementer needs that are easy to miss

- **Copy NZB link** copies `https://<site>/api/v1/api?t=get&id=<release guid>&apikey=<the user's api_token>`:
  the existing newznab v1 call, so SABnzbd and NZBGet can add the NZB by URL with no website
  login. No API change. On click the icon becomes a tick for about 1.6 seconds and a toast says
  "NZB link copied. It contains your API key, so only paste it into your own downloader."
  Where the site is served over plain http the Clipboard API is unavailable: fall back to
  `document.execCommand('copy')`. It appears on release rows (list, show page, details-page
  table) and in the details header; nowhere on the Shows wall.
- **Same-show batch expander**: within a page, a run of consecutive releases of one show whose
  sort date falls on the same day collapses when the run is longer than 4: the first three
  stay, then one quiet row "Show 13 more from <show> posted in the same batch"; open, it reads
  "Show fewer from <show>". It is an expander inside the list, not pagination.
- The date column's heading and values follow the sort (Posted / Added); hovering a date shows
  both. Under a day old a date reads "2 hr ago", after that a date.
- Tooltips name each round action. Cart and Watch show a pressed (coral) state.
- A release name links to **that release's** details page, never to the show. The grey line
  under it links to the show page with that episode already open.
- Group and poster chips (details page) link to the existing cross-category pages for that
  group and that poster. They are not TV filters.
- Of today's browse controls, only the TV sub-category filter carries over. Dropped by decision:
  minimum completion, "only watching", the in-list text filter, the page-size choice, the Title
  and Grabs sorts, "only my cart".
- Pages are 50 releases and 42 shows. The page number is in the URL so Back works.

## Appendix B. Rejected: do not bring back

The episode-tile TV Covers page (one tile per episode, packs repeated) and its per-request
membership query; "most releases" rails or any popularity ranking; "Just came in" as a title;
Resolution / Source filters on the Shows wall; A–Z letter buttons; a details (info) button in
rows; the Report button; infinite scroll and "load more"; day-divider rows; an "Only showing…
Show everything" line; a bare count replacing the pager line on small results; bold anywhere
in a row except the release name; pill rows of filters in the header; a one-colour
solid / filled / outline ladder for resolution; neutral same-colour chips; icon-only NFO /
Preview / Sample chips; "Text" chips on subtitle formats; instruction text such as "click to
show releases"; opening the newest episode automatically; a tree-style arrow at the left of
episode rows; a "check all" box on the show page; 16:9 episode stills instead of show posters;
a weekly or any scheduled refresh of show details; a TV-only side table of copied release
columns; phone layouts.

