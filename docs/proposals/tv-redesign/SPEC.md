# NNTmux TV section: specification

Written 2026-09-21 from the maintainer's design review sessions (what Randall approved,
rejected and decided) and query-lab experiments on a restored production catalogue. It is one
of three documents: this one says what each screen does, `DATA-CONTRACT.md` says what is
stored and which code writes it, `VISUAL-CONTRACT.md` says how an implementation is matched
to the prototype and checked. The
clickable prototype `tv.html` is the visual and behavioural reference: where this document
and the prototype disagree about how something looks or behaves, the prototype wins; where
they disagree about data, storage or queries, this document wins.

Status: every screen below is **approved** and was built by #773–#781. Parts marked *Changed 2026-09-26* or *Changed
2026-09-27* were approved after that build (the filter bar, section 3.0; the releases-list changes; on 2026-09-27 the show page,
the details page's release table and the "Follow" wording) and are not built yet.

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
anything); the page today called Watchlist (a separate, low-priority design section; its name follows rule 12; the **Follow show**
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
   current view / tab / page, a set filter, an open episode's releases button, a pressed Full
   size button. Spend it on nothing else. Download NZB and Copy NZB link are actions with no
   on / off state. In every release table (the releases list, the show page's tables, the details
   page's table) a pressed Cart fills in the button's own hue, not coral ("Why would pressing a
   button make it stay coral?"), and so does the show page header's **Follow show**. The details
   page's header matches the Movies details page (his call, 2026-09-27): Copy NZB link and Add to
   cart neutral at rest, a pressed Add to cart green, **Follow show** violet like the show page's;
   coral only on Download NZB. *Changed 2026-09-26 and 2026-09-27 on the maintainer's review.*
9. No Report button anywhere. No separate details button in rows: the release name is the link.
10. A checkbox menu closes when focus leaves it, on Escape, and on a click outside; it stays
    open while ticking and keeps its scroll position; keyboard focus stays on the control
    that was pressed after any re-render.
11. An approved screen is frozen: a change to shared components must say exactly what changes
    on each approved screen.
12. **"Follow", never "Watch"**, for keeping up with a show's (or film's) new releases: buttons,
    tooltips, labels, toasts, menus and page names ("When a reasonable human being sees 'watch
    film' they think that means I'm going to view the film, not keep an eye on releases for this
    film"). The button reads **Follow show** / **Following show**; tooltips "Follow this show" and
    "Following this show · click to unfollow"; toasts "Following <show>" / "Unfollowed <show>". Its
    icon is a **bookmark**, an outline while not followed and filled solid while followed (the eye
    and a bell were tried; following alerts nobody, so a bell would promise too much). "Watch video
    preview" keeps its name: there the word means view. *Changed 2026-09-27.*
13. **Every link that leaves the site opens in a new tab** (`target="_blank" rel="noopener
    noreferrer"`, with a visually hidden "opens in a new tab"). *Changed 2026-09-27.*
14. **No "Same name posted more than once · this copy by … in …" line** under a release name, in any
    table: two releases with the same name and size are plainly two copies. *Changed 2026-09-27.*

---

## 3. Screens

Each subsection lists what the screen must do. Exact layout, sizes and copy are in the
prototype (`prototype/tv.html`) and its reference set (`VISUAL-CONTRACT.md`).

### 3.0 The filter bar (all list and wall filters)

*Changed 2026-09-26 on the maintainer's review* ("Okay, I am all right with this"). It replaces
the rows of separate dropdown pills on the TV releases list and the TV shows wall, and is the
same component the Movies screens use.

- The filters of one kind sit in **one continuous rounded bar**: on the releases list a
  **release bar** (Category, Resolution, Source, Audio, Completion) and a **show bar** (Genre,
  Premiered, Language, Network, Rating, Status); on the wall the show bar alone. Every cell has
  the **same width** (the list's eleven cells share the row; the wall's six are as wide as the
  list's). Cells are divided by hairlines.
- Each cell shows the **filter's name small above its value**: `Genre` over `any`, `Drama`,
  `2 chosen` (several values; the tooltip names them all). The names are **white** on the dark
  theme and ink on the light ("They're just lost in that gray-on-gray scheme"); an unset
  value reads `any` in the muted text.
- The **open cell lifts out of the bar** (a lighter ground than the bar and a shadow, in both
  themes) and the hairlines beside it hide. A **set filter** is marked by a **coral line under
  its value**; the cell is never filled coral.
- Menus open under their cell. **Lists over 10 options** (Category, Audio, Genre, Network,
  Language when long) open with a **search field at the top** (the magnifier inside the field,
  focus in it) and the options **scroll inside the menu** under it; the search narrows the list
  in place, survives ticking, and is **forgotten when the menu closes**. A menu opens scrolled
  to its first ticked option, and a long list's last visible row is cut in half so it visibly
  continues. Nothing in a menu stays expanded after it closes.
- Multi-select menus (everything except Completion) keep the menu open while ticking, as rule 10.
- **Completion** (his request: "only show releases that are 95% or more complete", and "an
  option for 100% only"): one choice, radio items in this order: **Any completion**, **100%
  only**, **95% or more**; the cell reads `100%` or `95%+`; picking closes the menu. It filters
  `releases.completion` (always recorded: on the production copy, 11,592 of 173,152 TV releases
  are under 95% and 156,216 are at 100%). This brings back the minimum-completion control that
  was dropped on 2026-09-21 (appendix A), by his decision.
- **Clear all** sits on the `Showing …` line of the releases list, in a fixed slot left of the
  page arrows, hidden (not removed) when nothing is set; the page text there has a fixed width
  so nothing moves. On the wall it follows the show bar, then the removable `Starring <name>`
  chip.
- Show filters on the releases list are the **list's own** (not shared with the wall). While any
  show filter is set, releases with no matched show are left out; Category, Resolution, Source,
  Audio and Completion apply to every release.
- **Audio** is the release's own audio languages (media info; a multi-dub matches each; releases
  with none read `Unknown`); **Language** is the show's original language. Language names drop
  the region (`English (US)`, `en`, `en-US` → English; Mandarin reads Chinese); codes that are not
  a language (`zxx`, `mul`, `und`, `qaa`–`qtz`) and "Multiple languages" are dropped; a value we
  cannot identify as a language names none (Movies SPEC 5.2's rule: a code `intl` can name, an
  English name from `intl` or the ISO 639-2 list, an own name or a name-table entry), so `mr`
  reads Marathi and "Original" is Unknown; menus list the languages present:
  Language most releases first; Audio `English` first, then A to Z, then `Unknown`.
- Nothing moves when a filter is set or cleared. Measured: the eleven list cells are 107 / 114 /
  127 px at 1280 / 1366 / 1600; with long values set, a long network name or `Portuguese` is cut
  with an ellipsis at 1280–1366 and named in the tooltip ("I think it's okay").

### 3.1 TV releases

- Header: title "TV releases", Releases / Shows switch, the "Search shows or actors" field and,
  at the right, **Sort** with four orders: Posted newest (default), Posted oldest, Added newest,
  Added oldest. The date column follows the sort. The chosen sort is remembered per user.
- Under it, **the filter bar** (3.0): the release bar **Category** (the TV sub-categories the user
  may see: HD, UHD, SD, Foreign, …), **Resolution**, **Source**, **Audio**, **Completion**, and the
  show bar **Genre**, **Premiered**, **Language**, **Network**, **Rating**, **Status** (the TV shows
  wall's six; "why can't we bring all of the filters that are on the TV shows wall page over to
  it?"). OR within a menu, AND between them. Changing a filter or the sort returns to page 1.
  *Changed 2026-09-26 on the maintainer's review* (was Category, Resolution and Source menus in
  the title row).
- Under the header, always present and never moving: `Showing 101–150 of 8,249 releases` ·
  previous · `Page 3 of 165` · next. One page reads `Page 1 of 1` with both arrows greyed; no
  results reads `Showing 0 releases`. Full pager with "Go to page" at the bottom. 50 per page.
- Table with fixed column widths: select, poster 88×132 (links to details), release cell
  (bold name → that release's details; grey `Show · S01E02 · Episode title` → show page with
  that episode open; one line of chips), resolution chip, source, size, posted / added, and
  the row buttons. **No Files and no Grabs column** on this list ("Neither are really that
  beneficial ... If the user wants to know the files, they can click on the release"): the
  file list is on the details page. Dropping them widens the release column so the chips fit
  on one line. The show page's tables keep Files but have no Grabs (2026-09-27); the details
  page's table keeps both. *Changed 2026-09-26 on the maintainer's review.*
- **Chip line**: the release chips (section 4), then the **group and poster chips**, outline
  style as on the details page, on every row that has a group or a poster. The group reads
  `a.b.` for `alt.binaries.`. They are same-tab links to `/browse/all?group=<group>` and
  `/browse/all?poster=<poster>`, titled "All releases in <group>" and "All posts by
  <poster>", as today's row chips link (appendix A). The two are **one unit that never
  splits** across lines ("I absolutely hate the inconsistent wrapping"): when room is short
  the poster name shortens with an ellipsis, and the pair moves to a line of its own only when
  less than 190 px is left. Measured: group and poster on the one chip line on 100% of TV rows
  at 1366–1600 px wide. *Changed 2026-09-26 on the maintainer's review* (they were details
  page only).
- **Row buttons, 2 × 2**: **Download NZB** and **Copy NZB link** on top, **Add to cart** and
  **Follow this show** below; with no Follow button, Cart sits alone under Download. Download
  is unchanged (coral). Copy link, Cart and Follow have a tinted ground and a coloured icon,
  each in its own hue (appendix A); a pressed Cart or Follow fills solid in its own hue, not
  coral. Download and Copy link have no on / off state. Keyboard focus on Copy link, Cart and
  Follow is drawn in the ink colour. The show page's and details page's tables use the same
  2 × 2 buttons **without** Follow (3.3, 3.4). *Changed 2026-09-26 and 2026-09-27 on the
  maintainer's review.*
- **Releases with no matched show** (`videos_id = 0`; 10,464 visible ones on the production
  copy) are listed in date order like the rest: there is no grey show line, the Follow button
  is absent (Cart sits alone under Download) and the row never joins a same-show batch.
  Decided 2026-09-24.
- Their poster cell holds a **poster-sized placeholder** linking to the details page like a
  poster: a **name card** with the show name and the episode or air date read from the
  release name (`S01E02`, a double episode `S01E01–E02`, four-digit episodes such as
  `S59E1234`, a bare `E12`, or a date such as `2026-09-22` from `2026.09.22`), else a tile
  with the TV icon and "No poster". The rule is in appendix A. Whatever title the name states
  gets a card, short numeric ones such as "24" and "911" included, and site tags in the name
  are not stripped: "I honestly don't care if sometimes the placeholder name card has trash
  in it." On the production copy (counted 2026-09-26) 9,005 of the 12,977 visible
  TV releases with no matched show get a name card. *Changed 2026-09-26 on the maintainer's
  review* (the cell was empty).
- Select-all in the header (with a partial state) selects the rows visible on the page.
  Selecting brings up a floating bar: `15 selected · Download NZBs · Add to cart · Clear selection`.
- Same-show batch expander: consecutive releases of one show posted the same day collapse to
  the first three plus "Show 13 more from <show> posted in the same batch".
- Chips (section 4).

### 3.2 TV shows

- Title "TV shows", the same switch. **No resolution or source filters here.**
- Six **multi-select** filters in the show bar (3.0): Genre, Premiered (decade), Language (the
  show's original language), Network, Rating (US TV Parental Guidelines), Status (Running /
  Ended). OR within a menu, AND between menus. Clear all and the `Starring <name>` chip follow
  the bar; Clear all keeps its place when hidden, so nothing moves. *Changed 2026-09-26 on the
  maintainer's review* (was a row of six separate dropdown pills).
- Sort, at the right of the title row (as on the Films wall): Newest releases first (default),
  Newest to the site first, Newest premiere first, A to Z. Remembered per user.
- The same "Showing 1–42 of N shows" line and pagers. 42 per page.
- Tile: poster (a title card when there is none), bold title, `Year · Genre, Genre`,
  `Language · Rating`. No release counts, no resolution chips.
- Search: a **"Search shows or actors" field in the toolbar** of the TV releases screen and
  the TV shows wall, immediately right of the Releases / Shows switch, finds **shows and
  people**, grouped (Shows, then People) in a panel dropping from the field. Picking a person
  filters the wall to their shows with a removable "Starring <name>" chip. The show page and
  the release details page carry no TV search. **The site's top-bar search is not touched**: it
  stays the shared release search with its scope select. Decided 2026-09-24, replacing
  "top bar, every screen".

### 3.3 Show page

*Changed 2026-09-27 on the maintainer's review* (the Follow show button, the section order, the
Episodes heading, ascending episodes, the empty-packs wording, no Grabs, the buttons, no
identical-name line, Similar shows). Approved as revised: "Both the movie detail and show detail
pages look good, I approve them."

- Back link to the list the user came from. Poster, title, `Network · year · N seasons on
  site · N releases` (the year lives here; **no "Premiered" tag on this page**), summary,
  tags (genres link to the wall filtered by that genre; language, rating, status are plain),
  "Starring" names linking to the wall filtered by that person, then a **Follow show** button
  (rule 12): tinted violet with the bookmark icon, filled solid violet while followed, never
  coral; its two labels share one width so pressing it moves nothing. The show is followed
  here only: no release row on this page has a Follow button ("I'm not sure why this isn't
  there already"). After Follow show come the **outside links** (decided 2026-09-28, #872),
  styled as the film page's: one secondary button per service whose id the show has, in the
  order IMDb, TMDB, TVDB, TVMaze, Trakt; a missing id means no button. Each goes through the
  site dereferrer and opens in a new tab (rule 13). The right half of the header stays empty.
- **Seasons are tabs** on a sticky bar, in the details page's tab style (coral underline on
  the current one), with Resolution and Source menus at the right of the same row. From 9
  seasons up the row reads `Season  Specials 1 2 3 … 24`. The tab row never wraps (it scrolls
  sideways if it must), so the bar's height never changes. **There is no "By air date" tab**
  (dropped 2026-09-24): a dated daily release gets its season and episode from its episode
  record, and providers number daily shows in ordinary seasons (SmackDown is season 28 on the
  production copy), so daily shows list under those seasons with the air date on each episode
  row. Numeric tabs carry the accessible name "Season 22". With no season in the link, the page
  opens on season 1 (else the lowest-numbered season, else Specials); a link that names a season
  the show has opens that season (maintainer's rule, 2026-09-27). A season switch replaces the
  history entry, so Back returns to the list the show was opened from. A keyboard focus ring
  on a season tab is drawn inside the tab, so the tab row never clips it; after a mouse season
  switch the current tab shows no ring.
- Under the tabs, in this order:
  1. **"Whole-season packs"**, first ("move the whole season packs to above the episodes
     table"): the season's packs as a release table; a season with none keeps the heading and
     reads **"None available for this season."** (his wording), or "None available for this
     season with your filter." when the filters hid them.
  2. **"Episodes"**: a heading in the packs heading's style with a hairline under it, shown
     when at least one episode matches the filters ("There needs to be a header or something
     separating the season packs from the episodes list").
  3. The episode rows, **ascending: E00 (specials) first, then E01, E02, …** (decided
     2026-09-26, #810).
  4. **"Other releases"**, omitted when empty: the show's releases that declare no season or
     episode and have no episode link. A show with no seasons at all shows only the filter row
     and this section, and its header omits "N seasons on site".
  5. **"Similar shows"**, the last section (below).
- When nothing on the season matches the filters, the page-wide line "No releases in this
  season match SD." comes first, above the packs section. Hiding the packs with a filter
  changes the height above the episode list, so the list moves up (about 90 px for one pack);
  that follows from his order.
- Episode row: number, title, aired date, resolution chips present, size range (one size
  when all releases are the same size), and a button-shaped **`4 releases ⌄`** control at
  the right. The whole row is the click target; the arrow flips and the button turns coral
  while the row is open. Nothing is open on arrival, except the episode the user arrived
  from.
- An open episode (and the packs and Other releases sections) shows a release table: name,
  the chips (without group and poster), Resolution, Source, Size, Files, Posted, and the
  releases list's **2 × 2 buttons without Follow** (Download and Copy link on top, Cart alone
  below, tinted, a pressed Cart green, never coral; 96 px), with a **select checkbox per row and
  no check-all box** and sortable Resolution / Size / Posted headers. **No Grabs column** (his
  call 2026-09-27); a Grabs sort left over from the details page returns to the page's default
  (largest first). **No line under identical release names** (rule 14). The same floating
  selection bar; the selection carries across episodes and seasons.
- **Similar shows** (his request 2026-09-27, "the way you did with the movies"): six tiles
  identical to the TV shows wall's tiles (poster, title, `Year · Genre, Genre`,
  `Language · Rating`), each as wide as a wall tile, opening that show's page. The picks follow
  the film page's rule (Movies `SPEC.md` 6.6): candidates share a genre or a person with the
  show and have a TV release the viewer may see; score = 2 × shared genres + 3 × shared people
  − |premiere year gap| / 10; top 6. A show with no picks (no stored genres or cast, or nothing
  shares them) has no section. In the lab the picks for 158 of the prototype's 170 shows come
  from every show with a release; the query is proven, with per-user visibility, in the data
  contract. Show details (genres, cast) are saved as each show's next release arrives (#775), so
  the section fills in over time.

### 3.4 Release details

- Breadcrumb; poster; heading `Show · S01E02 — Episode title` with the release name as the
  bold second line; resolution and source chips, the chip line, group and poster chips;
  buttons Download NZB (coral), Copy NZB link and Add to cart (neutral; a pressed Add to cart
  fills green, its two labels share one width so nothing moves), **Follow show** (violet with the
  bookmark, filled solid violet while followed, as on the show page; rule 12). *Changed 2026-09-27*
  to match the Movies details page: the coral pressed state is gone (rule 8).
- Tabs: Overview (preview thumbnail, aired date and summary, facts grid), Files, Media info,
  NFO, Comments (unchanged from today; no design work).
- Right of the tabs: **"About the show"**: `Network · N seasons on site` (no year on this
  line; **the "Premiered 2026" tag stays on this page**), the show's tags, Starring, and an
  "All seasons and episodes" link. Rows with nothing to show are omitted.
- Underneath, full width: **"All N releases of this episode"**, the show page's release table
  **without checkboxes**, with its **Grabs column** (not removed here), the **2 × 2 buttons
  without Follow** (the page header has Follow show; *changed 2026-09-27*: "it needs to use the
  same button layout") and no identical-name line (rule 14). The release being viewed has a
  tinted row, the words "The release on this page", and its name is not a link. The set is the
  show's visible releases that share any of this release's `(season, episode)` rows (NULL
  matches NULL); for a pack the heading reads "… of this season pack"; a release with no row
  shows no table.
- **A TV release with no matched show** (`videos_id = 0`): the release name is the heading,
  there is no poster, no show crumb or link, no "Follow show" button, no "About the show"
  aside (the tabs and facts span the full width) and no episode table. Decided 2026-09-24.

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

Then, on the releases list, the **group and poster chips** (outline, one unit that never
splits, links as in appendix A; 3.1). The details page shows them under its other chips. The
show page and details release tables do not carry them. *Changed 2026-09-26 on the
maintainer's review*: until then they appeared on the details page only.

Dropped from today's row: the separate repair chip (folded in above), "Reported" and
"Response" chips, the comments count, the details (info) button, the Report button. All chip
colours pass 4.5:1 in both themes.

---

## 5. Data the site must store

Specified exactly in **`DATA-CONTRACT.md`**; in short:

- `resolution` and `source` become columns on `releases`, **for every category**. Resolution is
  the measured video size when the site has one, else the resolution in the name, else
  unknown. Source comes from the name (media info cannot tell a source). Nothing is copied to
  side tables, and no counts or per-show aggregates are stored.
- What a release **declares** (its episode numbers, or a whole season) is stored in a child
  table (`episode = 0` is a real special; NULL means the whole season), so the
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
2. **Whole-season packs**: settled. A pack is a name with `Sxx COMPLETE|FULL|PACK|COMBINED`
   or a bare `Sxx` with no episode token (#774, widened by #792); it is a row with `episode`
   NULL and appears in the season's "Whole-season packs" section.
3. **Releases whose named episode has no `tv_episodes` row**: settled. They are listed under
   the episode number their name declares, titled "Episode N" (#774). Releases that declare
   nothing and have no link sit in "Other releases" (3.3).
4. **Network spellings**: the merge rule for 591 distinct values.
5. **Overall bit rate**: the stored number does not match size ÷ duration in any unit (checked
   on 2,847 complete releases); the media info block leaves it out until that is understood.
6. Media info reviewer notes he has not asked for: a few chip hues sit
   close together; the Audio glance can show Atmos beside a non-Atmos track's format.
7. `PRODUCT.md` sits untracked in the application repository root and needs his decision.
8. **Storage for the 2026-09-26 filters** is decided with the Movies data contract: the release
   audio languages behind Audio (today in `media_info_tracks` and the legacy `audio_data`, as
   names and codes) need a normalized, indexed form, and every list filter (the show filters on
   the releases list included) is proven on the full catalogue before a build issue is filed.
   Language, Genre, Network, Rating, Status and Premiered read the show details stored by #775.

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
acceptance test is the matching block of `check.mjs` (271 checks), ported to the
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
- Tooltips name each round action. Cart and Follow show a pressed state; Download and Copy link
  have none.
- **Release-table button colours** (changed 2026-09-26 and 2026-09-27 on the maintainer's review):
  Download keeps the accent pair (coral). Copy link, Cart and Follow each have a hue, Copy link
  235 (blue), Cart 150 (green), Follow 300 (violet) in OKLCH: off is a tinted ground with the
  icon in the same hue; on (in the cart, following) is a solid fill in that hue. Exact values
  are in `VISUAL-CONTRACT.md` section 2. Every icon is at least 3:1 on its button, off and on, in
  both themes. The focus ring on those three uses the ink colour. Cart's green sitting near the
  completion chip's green was accepted. They apply to every release table (releases list, show
  page, details page); only the releases list has a Follow button in its rows. The show page
  header's Follow show uses the Follow hue, and so does the details page header's Follow show; the
  details header's Add to cart is neutral and fills green when pressed (2026-09-27).
- **Follow icon**: a bookmark (Font Awesome `fa-bookmark`, regular while not followed, solid while
  followed). Following alerts nobody (it keeps the show on the user's list, its RSS feed and the
  home page section), which is why the bell was not chosen.
- **Offsite links** (rule 13): IMDb, TMDB, TVDB, TVMaze, Trakt, GitHub and anything through the
  dereferrer open in a new tab with `rel="noopener noreferrer"`.
- A release name links to **that release's** details page, never to the show. The grey line
  under it links to the show page with that episode already open.
- Group and poster chips (releases list and details page) link, in the same tab, to the
  existing cross-category pages for that group and that poster, `/browse/all?group=<group>` and
  `/browse/all?poster=<poster>` with the value URL-encoded, exactly as today's row chips do
  (`origin.blade.php`). Titles: "All releases in <group>", "All posts by <poster>". The group
  chip shows `a.b.` for `alt.binaries.`; the full name is in the title. They are not TV filters.
- **No-poster placeholder** (releases with no matched show, 3.1): the name is read as a title,
  a separator (`.` `_` `-` or space), then an episode token: `S` + 1–4 digits and `E` + 1–4
  digits (an optional separator between them), with an optional second `E` + digits
  for a double episode (shown `S01E01–E02`); or a date `YYYY.MM.DD` with any of those
  separators (shown `YYYY-MM-DD`); or a bare `E` + 2–4 digits. The token must be followed by a
  separator or the end of the name. The title is the text before it with dots and underscores
  as spaces; tokens are shown upper-case. Names that contain `.rar` or `.partN`, or begin with
  a quote or bracket, never get a card. No match gives the TV-icon tile reading "No poster".
  The prototype's `showName()` is the reference implementation.
- Of today's browse controls, the TV sub-category filter carries over, and **minimum completion
  is back** as the Completion filter (3.0, his request of 2026-09-26). Dropped by decision:
  "only watching", the in-list text filter, the page-size choice, the Title and Grabs sorts,
  "only my cart".
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
columns; phone layouts. Added 2026-09-26 on the maintainer's review: Files and Grabs columns
on the releases list; coral for a pressed Cart / Follow on the releases list; coloured-outline row buttons;
stripping site tags from, or a letter rule for, the names on no-poster placeholder cards. Added
2026-09-27: the word "Watch" for following (rule 12); the eye and the bell as the Follow icon; a
Follow button in the show page's or details page's release rows; a Grabs column on the show page's
tables; the "Same name posted more than once" line; whole-season packs under the episodes; episodes
newest first; "None on site for this season"; hiding the packs section on seasons without packs.
Filter layouts rejected on 2026-09-26 (see the Movies design, `../movies-redesign/SPEC.md`): nine
identical grey dropdown pills ("homogenous blob"); a hue per filter ("gaudy"); a
sidebar of filters beside the list; one all-in-one Filters pane (its long lists made it "look overbearing"); a "Show all" that stays expanded after its menu closes; grey grouped panels of
pills (kept only as the fallback, "unpolished"); a single-year list in the Year picker.

