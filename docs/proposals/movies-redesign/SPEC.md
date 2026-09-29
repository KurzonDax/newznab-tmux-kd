# NNTmux Movies section: specification

Written 2026-09-26 from the maintainer's design review session of that day (what he approved,
rejected and decided, with his reasons) and query-lab experiments on a restored production
catalogue. `DATA-NOTES.md` holds the measured facts; `INVENTORY.md` lists every feature of
today's movie screens. The maintainer's clickable prototype is the visual and behavioural
reference for the approved screen: where this document and the prototype disagree about how
something looks or behaves, the prototype wins. A sanitized copy of it, with an invented dataset
and placeholder art, is in `prototype/`.

Status: the **Movie releases** screen and the **Films wall** are **approved** (2026-09-26; the
filter bar of section 5.1 was approved later the same day, "Okay, I am all right with this").
The **film page** is **approved** (2026-09-27, section 5B: "Both the movie detail and show detail
pages look good, I approve them"). The **release details** page is **approved** (2026-09-27, section 5C:
"I approve the movies details page"). Every Movies screen is now designed. Nothing in the application has
been changed.

---

## 1. Scope

| Screen | Route in the prototype | Replaces | Status |
|---|---|---|---|
| Movie releases (list) | `#/`, `#/p/N` | today's Movies Table and Cards views | approved |
| Films (discovery wall) | `#/films`, `#/films/p/N` | today's Movies Covers view | approved |
| Film page | `#/film/<id>`, `#/film/<id>/p/N` | today's movie title page | approved |
| Release details (Movies) | `#/release/<id>`, `#/release/<id>/p/N` | today's details page, for movie releases | approved |

Out of scope, by his decision: phone layouts (desktop only); the page today called Watchlist (its
name follows the Follow rule of section 4); the rest of the site. The **Follow** button itself is
in scope: today's picker opens only to start following; on a followed film one click unfollows,
with no picker and no Undo, and the toast reads "Unfollowed <title>"; after a picker save,
"Following <title> · HD, UHD".

Hard constraints from the repository (`AGENTS.md`), unchanged from TV:

- **External API and RSS are frozen**, including additive changes. Everything here is web
  front end. The RSS feed `/rss/trending-movies` stays as it is (section 6.1).
- **No new Artisan command without the maintainer's explicit approval of that specific
  command.** This spec proposes none. Two values are stored "going forward" with no backfill
  (section 7), by his decision.
- Colours route through the existing token layer; the accent is a per-user setting.
- Per-user remembered choices (the sort) go in the existing view preferences
  (`users.view_prefs` via `User::releaseViewPreferences()`, `app/Models/User.php:209-215`),
  not a cookie.

---

## 2. What Movies is for

He opens Movies to do three jobs:

1. **Get a specific movie.**
2. **Find something new.**
3. **See what came in.**

He did not pick "check films I follow" (the same answer as for TV). Following a film stays
possible through the Follow button, but no screen is built around it.

---

## 3. Structure

The same shape as TV (decided 2026-09-26):

- **Movie releases**: the list of releases, newest first, a poster on every row. Serves "see
  what came in" and, with its filters, "get a specific movie".
- **Films**: a wall of films with discovery filters. Serves "find something new".
- **Film page**: one film, its details and all its releases.
- **Release details**: one release.
- A **Releases / Films** switch on the list and the wall.
- A **"Search films or actors"** field on the list and the wall, right of the switch. It finds
  films and people. The site's top-bar search is not touched: it stays the shared release
  search with its scope select.

Each screen is prototyped and approved one at a time.

---

## 4. Rules that apply to every screen

Every rule in TV `SPEC.md` section 2 applies ([`../tv-redesign/SPEC.md`](../tv-redesign/SPEC.md)):
never judge a release; never rank by popularity; numbered pagination only; only the release
name is bold in a row; nothing shifts when state changes; no instruction text; colour is
wanted; no Report button and no details button in rows; checkbox menus close on focus leaving,
Escape and a click outside; an approved screen is frozen.

The maintainer's rulings of 2026-09-26, made on the release lists of both sections, change
rule 8 (coral):

- **Coral means the primary action (Download) and the on-state of a real mode**: the current
  tab, view or page, a set filter, an open episode's releases button. It is **never** the
  pressed state of a toggle. ("Why would pressing a button make it stay coral?")
- **Download and Copy link have no on/off state.** Download's styling never changes.
- In every release table (the release lists, the film page, the TV show page and details page), a
  pressed **Cart** or **Follow** button fills solid **in its own hue**, not coral, and so do the
  film page's and TV show page's **Follow** header buttons (2026-09-27). The **details pages' header
  buttons** of both sections (5C.1, 2026-09-27): Copy NZB link and Add to cart neutral, a pressed Add to
  cart green, Follow violet; the TV details page's earlier coral pressed state is gone.

His rulings of 2026-09-27 add three rules for every screen of both sections:

- **"Follow", never "Watch"**, for keeping up with a film's or show's new releases: buttons,
  tooltips, labels, toasts, menus and page names. His reason: "When a reasonable human being sees
  'watch film' they think that means I'm going to view the film, not keep an eye on releases for
  this film." Buttons read **Follow film** / **Following film**; tooltips "Follow this film" and
  "Following this film · click to unfollow"; toasts "Following <title>" / "Unfollowed <title>".
  The icon is a **bookmark**, an outline while not followed and filled solid while followed. The
  eye and a bell were built and set aside; following alerts nobody (it keeps the title on the
  user's list, its RSS feed and the home page section), so a bell would promise too much.
  "Watch video preview" keeps its name: there the word means view.
- **Every link that leaves the site opens in a new tab** (`target="_blank" rel="noopener
  noreferrer"`, with a visually hidden "opens in a new tab"): IMDb, TMDB, Trakt, GitHub and
  anything through the dereferrer.
- **No "Same name posted more than once · this copy by … in …" line** under a release name in any
  table: two releases with the same name and size are plainly two copies.

---

## 5. Movie releases (APPROVED 2026-09-26)

It is the approved TV releases screen, adapted. His words on first sight: "I like it." He
approved the final version with the rest of the day's changes ("I like all of it").

### 5.1 Header

- First row, left to right: title **"Movie releases"**; the **Releases / Films** switch
  (Releases current, coral); the **"Search films or actors"** field; then, at the right, the
  **sort** select with TV's four orders: `Posted: newest first` (default), `Posted: oldest
  first`, `Added: newest first`, `Added: oldest first`. The sort is remembered per user. Changing
  it returns to page 1.
- Under it, **the filter bar**: the filters of one kind in one continuous rounded bar (the pattern
  of a booking site's search bar). The **release bar** holds **Category, Resolution, Source, Audio,
  Completion**; the **film bar** holds **Genre, Year, Score, MPAA Rating, Language**. The two bars
  share the row equally; all ten cells have one width, divided by hairlines. His reasons: the
  earlier row of separate dropdown pills "just blend together into this homogenous blob"; the
  bar was the last of several designs tried (Appendix B).
  - Each cell shows the **filter's name small above its value**: `Genre` over `any`, `Horror`,
    `2 chosen` (several values; the tooltip names them all). Names are **white** on the dark
    theme and ink on the light ("They're just lost in that gray-on-gray scheme"); an unset value
    reads `any` in the muted text.
  - The **open cell lifts out of the bar** (a lighter ground than the bar and a shadow, in both
    themes) and the hairlines beside it hide. A **set filter** is marked by a **coral line under
    its value**; a cell is never filled coral.
  - Menus open under their cell. **Lists over 10 options** (Category, Genre, Audio, Language)
    open with a **search field at the top** (magnifier inside the field, focus in it) and their
    options **scroll inside the menu**: however many genres are added, the page never grows ("That
    will just increase"). The search narrows the list in place, survives ticking, and is
    **forgotten when the menu closes**. A menu opens scrolled to its first ticked option, and a
    long list's last visible row is cut in half so it visibly continues. Nothing in a menu stays
    expanded after it closes.
  - Checking a value keeps the menu open and keeps its scroll position; keyboard focus stays on
    the ticked item. Menus close on Escape, a click outside and focus leaving.
  - Menus combine with AND; values inside one menu with OR. Any change of filter returns to
    page 1. **Nothing moves** when a filter is set or cleared.
  - Measured: cells 117 / 126 / 139 px at 1280 / 1366 / 1600; no value is cut short with the
    filters set, `Completion` reading `95%+` (not "95% or more") so it fits at 1280.
  - **Clear all** sits on the `Showing …` line (5.3), not in the bar, so both bars run the full
    width.

### 5.2 The filters

Each checkbox menu starts with its "Any …" item (`Any category`, `Any resolution`, `Any
source`, `Any audio`, `Any genre`, `Any score`, `Any MPAA rating`, `Any language`), which clears
that menu.

| Bar | Filter | Kind | Options |
|---|---|---|---|
| release | Category | checkboxes, search | the Movies sub-categories the user may see (HD, UHD, SD, BluRay, DVD, 3D, X265, Foreign, Other). This menu is how a user leaves out Movies > Other (section 6.1). |
| release | Resolution | checkboxes | 4K, 1080p, 720p, SD, Unknown, each shown as its resolution chip |
| release | Source | checkboxes | WEB, Blu-ray, DVD, HDTV, Unknown. A remux is listed under Blu-ray and reads "Remux" in the Source column, as on TV. |
| release | **Audio** | checkboxes, search | the languages of the release's own audio tracks (media info), `English` first, then A to Z, then `Unknown` (no language known). A multi-dub matches each of its languages. |
| release | **Completion** | **one choice** | radio items in this order: `Any completion`, `100% only`, `95% or more`; the cell reads `100%` or `95%+`; picking closes the menu |
| film | Genre | checkboxes, search | the film genres, A to Z |
| film | Year | decades **multi-select** + a range | see below |
| film | Score | checkboxes | `9+`, `8–8.9`, `7–7.9`, `6–6.9`, `5–5.9`, `Under 5`, `Too few votes` |
| film | **MPAA Rating** | checkboxes | the US ratings present, in this order: G, PG, PG-13, R, NC-17, NR (NR included when stored). He renamed it: "No one knows what a certificate is." |
| film | **Language** | checkboxes, search | the film's **original** language, most releases first |

**Audio and Language** (his request, 2026-09-26, "a language filter"; he chose **both meanings**):
Audio is what you hear in that release (a Hindi dub of an English film is Hindi); Language is the
film's own language. Names drop the region (`English (US)`, `en`, `en-US` all read English;
Mandarin reads Chinese); codes that are not a language (`zxx` no speech, `mul`, `und`,
`qaa`–`qtz`) and "Multiple languages" are dropped. A value we cannot identify as a language is
Unknown (his decision, 2026-09-28): a value is identified, case- and accent-insensitively, as a code
`intl` can name, a language's English name (`intl` or the ISO 639-2 list, so "Panjabi" reads
Punjabi), its own name ("Deutsch", "日本語") or a name-table entry; anything else names no language.

**Completion** (his request: "only show releases that are 95% or more complete", then "an option
for 100% only", and the order Any / 100% / 95%): it filters `releases.completion`, which is always
recorded (`DATA-NOTES.md` section 9).

**Year** (his rulings of 2026-09-26): **decades are multi-select tick boxes** ("I want to be able to
select more than one decade"): `Any year`, then the decades 2020s … 1900s in three columns. Ticking
keeps the menu open; a film matches any ticked decade; the cell reads `1990s` for one and `2
chosen` for several. Under them the **Range**: two four-digit fields, `From` `to` `To`, and
**Apply**. Apply is enabled once **From** holds four digits; **To is optional: From alone picks
that one year** ("If the user wants a specific year, that should be able to just fill in the from
field"). There is **no list of single years**. A range outside 1900 to the current year, or with
the later year first, is refused: the error **"Years run 1900–2026, earliest first"** (the current
year in place of 2026) **replaces the "Range" heading**, so nothing below moves, and focus returns
to `From`. A valid range closes the menu and reads `1980–1989`; **a range replaces the ticked
decades, and ticking a decade replaces a range**.

**Score** (his pick of the recommended design): bands of the stored score, plus **Too few votes**.

- A film with **fewer than 10 TMDB votes, or no score**, is in "Too few votes", not in a band.
  Reason: in a sample of 8,959 films, 106 of the 107 films at 9+ had under 10 votes
  (`DATA-NOTES.md` section 3).
- The vote count is stored going forward only (section 7). **Films saved before the count is
  stored stay in their score band** until their next release brings a count.

**Genre, Year, Score, MPAA Rating and Language describe the film.** A release with no matched
film is excluded while any of them is set; Category, Resolution, Source, Audio and Completion
apply to every release.

### 5.3 The pager line

Under the header, always present and never moving: `Showing 101–150 of 8,249 releases` ·
**Clear all** (a fixed slot left of the page arrows, hidden but keeping its place when nothing is
set; the page text beside it has a fixed width so nothing moves) · previous · `Page 3 of 165` ·
next. One page reads `Page 1 of 1` with both arrows greyed. No
results reads `Showing 0 releases`, `Page 1 of 1`, and under it "No releases match" followed by
the filters in words, for example `No releases match HD · 1990s · score 9+ · rated R.` The full
pager with "Go to page" is at the bottom. 50 releases per page; the page number is in the URL.

### 5.4 The table

Fixed column widths, at every window width:

| Column | Width | Content |
|---|---|---|
| select | 34 px | checkbox; the header holds select-all with a partial state |
| poster | 116 px | the 88 × 132 poster, linking to the release's details |
| release | the rest | see 5.5 |
| Resolution | 100 px | the resolution chip (4K violet, 1080p blue, 720p teal, SD grey, Unknown dashed outline) |
| Source | 80 px | WEB, Blu-ray, Remux, DVD, HDTV |
| Size | 82 px | right-aligned |
| Posted / Added | 112 px | follows the sort; under a day old "2 hr ago", after that a date; hovering shows both dates |
| actions | 96 px | the four buttons, 2 × 2 (5.7) |

The header reads Release (over poster and release), Resolution, Source, Size, and Posted or
Added. **There is no Files column and no Grabs column** (5.6).

### 5.5 The release cell

- Line 1: the release name, **bold**, at most two lines, linking to **that release's** details
  page.
- Line 2: the grey film line **`Title · Year`**, linking to the film page. Absent when the
  release has no matched film.
- Line 3: **all chips on one line**: the chip line in TV's order (`94% complete` /
  `· still repairing`, Password, ⓘ media info, NFO, Preview, Sample), then the **group** and
  **poster** chips.
- Group and poster chips: the **outline** chips of the details page (he chose outline over a
  hue per kind). They link, in the same tab, to the existing cross-category lists
  `/browse/all?group=` and `/browse/all?poster=`, exactly like today's row chips
  (`resources/views/components/release-browser/origin.blade.php:8-9`). They are not Movies
  filters. The group name shortens `alt.binaries.` to `a.b.`.
- **Group and poster are one unit that never splits** and never passes the release column: when
  room runs short, the **poster name shortens with an ellipsis** first (the pair keeps at least
  190 px); only then does the pair move as a whole. ("I absolutely hate the inconsistent
  wrapping.")
- Measured on the prototype's real data: **99.9% of rows keep their chips on one line at 1600 and
  1440 px, 98% at 1366 px.**

### 5.6 Placeholder when there is no poster

His request: "visually jarring to see just empty space". He chose **B** of two built options
("go with b").

- A release with **no matched film** whose name states **`Title.Year.quality`** gets a
  poster-sized **name card**: the title from the name (dots and underscores read as spaces),
  at most four lines, and the year under it, in muted type.
- A **matched film with no artwork** gets the same name card with the matched title and year.
- Every other case gets the **film tile**: a film icon and the words "No poster".
- The card is a guess read from the name and he accepts that: **no letter rule** (a title that is
  only digits still gets a card) and **no site-tag stripping** (a site name at the front of a
  release name may appear on the card). "You can't account for every scenario." "I honestly
  don't care if sometimes the placeholder name card has trash in it."
- Names with an episode marker (`S01E02`), archive parts (`.rar`, `.part01`) or a leading quote
  or bracket get no card.
- The placeholder links to the release's details, like a poster, and is skipped by the keyboard
  and screen readers (the name link carries the same target).

The TV releases screen takes the same placeholder for rows with no matched show, with the same
two rulings (2026-09-26).

### 5.7 Row buttons

- Four round buttons as **2 × 2**: **Download** and **Copy link** on top, **Cart** and **Follow**
  below. A release with no matched film has no Follow button: Cart sits alone under Download.
- Colours (he chose **tinted** over neutral grounds): **Download** coral, unchanged. **Copy link**
  blue (hue 235), **Cart** green (hue 150), **Follow** violet (hue 300), each a tinted ground with
  a coloured icon. On (in cart, following) = a **solid fill in the button's own hue**, never
  coral. Coloured outlines were rejected. Cart's green beside the completion chip's green is
  fine ("not even remotely in the same neighbourhood").
- Download and Copy link have no on/off state.
- Tooltips: "Download NZB", "Copy NZB link for SABnzbd or NZBGet", "Add to cart" /
  "In cart · click to remove", "Follow this film" / "Following this film · click to unfollow".
- **Follow** follows the **film**. Today's picker opens only to start following; on a followed
  film one click unfollows, with no picker and no Undo, and the toast reads "Unfollowed <title>";
  after a picker save, "Following <title> · HD, UHD" (section 6.1). Every row of the same
  film shows the same state. Its icon is the bookmark (section 4). *Changed 2026-09-27.*

### 5.8 Batches and selection

- **Same-film batch expander**: within a page, a run of consecutive releases of one film whose
  sort date falls on the same day collapses when the run is longer than 4: the first three
  stay, then one quiet row "Show 13 more from <film> posted in the same batch"; open, it reads
  "Show fewer from <film>". A release with no film never joins a batch.
- Selecting rows brings up a floating bar: `15 selected · Download NZBs · Add to cart · Clear
  selection`. Select-all selects the rows visible on the page. Nothing moves when rows are
  selected.

### 5.9 Search

"Search films or actors" opens a panel under the field: **Films** first (up to 6: poster,
title, `year · two genres`), then **People** (from two characters, up to 5, each with its film
count and first three films). Arrow keys move, Enter opens, Escape closes. A film opens the
film page; a person opens the Films wall filtered to that person's films.

---

## 5A. Films wall (APPROVED 2026-09-26)

Serves "find something new". Shaped with the maintainer on 2026-09-26 (his answers below) and
built to that brief; his verdict on the whole: "I think it's okay." Route `#/films`, pages
`#/films/p/N`.

### 5A.1 Header and filters

- Title row: **"Films"**, the **Releases / Films** switch (Films current, coral), "Search films or
  actors", and at the right the **sort**: **Newest releases first** (default), **Newest to the site
  first**, **Newest films first**, **A to Z**. Remembered per user; changing it returns to page 1.
- Under it, the **film bar** of 5.1 (Genre, Year, Score, MPAA Rating, Language), each cell as wide
  as a cell on the Movie releases list; the wall's filters are its own (not shared with the list).
  Then **Clear all** in a fixed slot and, when a person is picked, a removable coral chip **"Films
  with <name>"** (a person can be a director or an actor). Picking a person in the search, or on a
  film page, opens the wall filtered to that person.
- The `Showing 1–42 of N films` line and the pagers, **42 films a page**; one film reads `Showing 1
  film`; no match reads `Showing 0 films` and "No films match" with the filters in words.

### 5A.2 Which films, and the sorts

- The wall lists every film with **at least one release the viewer may see**.
- **Newest releases first** orders by the film's latest `releases.postdate`; **Newest to the site
  first** by its earliest `releases.adddate` (the same aggregates as the built TV wall,
  `TvShowWall`); **Newest films first** by the film's year; **A to Z** by film title.

### 5A.3 The tile

His answer to "which facts should the grey lines show": **Year · two genres**, **Score ·
certificate**, **number of releases**.

- The poster (2:3); a film with no artwork gets the name card with its **matched title and year**
  (5.6). The tile opens the **film page**. **No buttons on tiles** (as the TV wall).
- The **title**, bold, at most two lines.
- Grey line 1: `1994 · Drama, Comedy`: two genres; **a genre the Genre filter matched comes
  first**, so the tile shows why it is there; a genre name never breaks across lines.
- Grey line 2: `8.5 · PG-13`, the score **as stored** (whole numbers stay whole: `7`) and the MPAA
  rating; a film with under 10 votes or no score reads `Too few votes`, as the Score filter bands
  it; no rating shows the score alone. He chose this over `Score 8.5 · PG-13`.
- Grey line 3: **`3 releases`** (the viewer's visible releases of the film). He chose a grey line
  over a badge on the poster.
- Tiles never rank or feature films by release count; there is no count sort.

---

## 5B. Film page (APPROVED 2026-09-27)

Serves "get a specific movie": the user arrives from a film line on the Movie releases list, a
Films wall tile, the search or a Similar films tile, and picks a release. Built to the decisions
of 6.4 and a brief he confirmed ("Build it"); revised on his review the same day and approved:
"Both the movie detail and show detail pages look good, I approve them." Routes `#/film/<id>` and
`#/film/<id>/p/N`. There is no "Search films or actors" field on this page (as on the TV show page).

### 5B.1 Header (like the TV show page)

- A back link to the list or wall the user came from ("Movie releases" or "Films").
- The poster (200 px wide, 2:3); a film with no artwork gets the name card with its matched title
  and year (5.6).
- The title, then one grey line: `2010 · 23 releases · latest Sep 16, 2026 · best` followed by the
  resolution chip of the best resolution among the film's releases. These are today's title page's
  Releases / Latest / Best stats, counted over the releases the viewer may see; "latest" reads
  "2 hr ago" under a day old, a date after that.
- The plot.
- Tags: the genres, each linking to the Films wall filtered by that genre; then plain tags `Score
  8.4` (the stored score, with the word Score, because a bare number among tags reads as nothing)
  or `Too few votes` (5.2), the MPAA rating and the film's original language, each when known.
- "Directed by" and "Starring" (the first 12 cast), each name a link to the Films wall filtered by
  that person.
- Buttons, in this order:
  - **Follow film**: tinted violet (hue 300) with the bookmark icon, filled solid violet while
    followed ("Following film"), never coral. Both labels share one width, so pressing it moves
    nothing. In the app, today's picker opens only to start following; on a followed film one
    click unfollows, with no picker and no Undo, and the toast reads "Unfollowed <title>"; after
    a picker save, "Following <title> · HD, UHD". The film is followed **only** from here: no row
    in the release table has a Follow button.
  - **IMDb**, always (the film is keyed by its IMDb id).
  - **TMDB**, when its id is known. **Trakt**, when its id is known (no film has one today).

  The IMDb, TMDB and Trakt links open in a new tab.
- The right half of the header stays empty (as on the TV show page).

### 5B.2 Releases

- A **"Releases"** heading, then a **filter bar of two cells**, **Resolution** and **Source** (5.1's
  bar: name above value, the coral line when set, the open cell lifting), each cell as wide as a
  cell on the Movie releases list's bars. Options: 4K, 1080p, 720p, SD, Unknown / WEB, Blu-ray,
  DVD, HDTV, Unknown, as on the list. The cells are the page's own: they reset when another film
  opens.
- The **`Showing 1–23 of 23 releases`** line with **Clear all** in its fixed slot and the page
  arrows (5.3); **50 releases a page**, numbered, with the bottom pager and Go to page. On the
  whole catalogue the largest film has 152 releases (`DATA-NOTES.md` section 11). A filter or sort
  change returns to page 1; another page of the same film opens at the Releases heading; another
  film opens at the top. No match reads `Showing 0 releases` and "No releases of this film match
  4K · DVD."
- **One table, not grouped**, newest posted first: a select box per row and **no check-all box**
  (as the TV show page); columns Release, Resolution (sortable), Source, Size (sortable), Files
  (opens the file list), Posted (sortable, the date; hovering shows posted and added), and the
  buttons. **No Grabs column** ("one thing I want to dump is the grabs column"). The sorted
  column's heading is in ink, the others muted; the first click on a heading sorts descending.
- The release cell: the bold release name (at most two lines) linking to that release's details,
  and the chip line (5.5) **without** the group and poster chips. **No "Same name posted more than
  once" line** (section 4).
- The buttons are the releases list's (5.7): **2 × 2**, Download (coral) and Copy link on top,
  **Cart alone below**, tinted, a pressed Cart green, never coral; **no Follow** button ("with
  the same rule about not showing the follow button on each release"). Column 96 px.
- Selecting rows brings up the floating selection bar (5.8); the selection carries across pages.

### 5B.3 Similar films

- The last section: **"Similar films"**, six tiles identical to the Films wall's tiles (5A.3), each
  as wide as a wall tile at that window width (a row of six keeps the wall's seven-to-a-row width),
  opening that film's page.
- The rule of 6.6: candidates share a genre or a person with the film and have a release; score =
  2 × shared genres + 3 × shared people − |year gap| / 10; top 6. Candidates are **every film with a
  release** in the catalogue, not only those on the list's pages. A film with no picks has no
  section. The viewer's excluded categories are applied in the data contract (section 8).

---

## 5C. Release details (APPROVED 2026-09-27)

One release. It is the approved TV details page (`../tv-redesign/SPEC.md` 3.4) adapted, as planned in
6.5; built to that plan and approved: "I approve the movies details page." Route `#/release/<id>`,
and `#/release/<id>/p/N` for a page of its release table. There is no "Search films or actors" field on
this page (as on the film page).

### 5C.1 Header

- A breadcrumb: **Movie releases** › the film (its film page) › the category (`Movies > HD`).
- The poster (190 px, 2:3), opening the film page; a film with no artwork gets the name card with its
  matched title and year (5.6).
- The heading **`Title · Year`** (the title links to the film page), then the **release name, bold**, on
  the second line.
- The resolution and source chips and the chip line (5.5), then the group and poster outline chips on
  their own line (same-tab links to the all-categories lists, as on the list).
- Buttons: **Download NZB** (coral), **Copy NZB link** and **Add to cart** (neutral at rest), **Follow
  film** (violet with the bookmark, as on the film page).
  A pressed Add to cart fills **green** (the row Cart's hue) and a followed film fills **violet**: never
  coral (section 4). Each toggle's two labels share one width (`Add to cart` / `In cart`, `Follow film`
  / `Following film`) with the label next to its icon, so pressing moves nothing; the focus ring on
  the toggles is ink. He picked this over tinting Copy link and Cart like the row buttons: with labels
  the hue adds nothing, and a tinted Copy link read as a large chip beside the media-info and Preview
  chips.
- No Report button, no admin **Edit release**, no "N users reported download failure" line (6.5).

### 5C.2 Tabs

**Overview**, **Files (n)**, **Media info**, **NFO**, **Comments (n)**, as on TV. Files, Media info and
NFO load when opened; the tab keeps keyboard focus when its data arrives. The file list is reached
here only (the release list has no Files column).

- **Overview**, in this order: the preview thumbnail (opens the image dialog) when the release has one;
  a box with the film's **tagline in quotes** and its **plot**, the plot running the full width of the
  box (his pick: a typical plot of 238 characters takes 2 lines at 1600 px); the facts grid (Category,
  Size, Files, Completion, Posted, Added, Grabs, Group, Poster, Password status); then, when the
  release has a PreDB match, a **PreDB** block: today's four fields (Title across the full row, Source,
  Pre date, Category when known).

### 5C.3 About the film

Right of the tabs, as TV's "About the show": the genres (links to the Films wall filtered by that
genre), then plain tags `Score 7.5` or `Too few votes`, the MPAA rating and the original language
(each when known); **Directed by** and **Starring** (the first 8), each name a link to the Films wall
filtered by that person; and a **Film page** link.

### 5C.4 All N releases of this film

- Underneath, full width: **"All N releases of this film"** ("The only release of this film" for one).
  It is the film page's table (5B.2) **without the select boxes** (as on the TV details page): Release,
  Resolution (sortable), Source, Size (sortable), Files (opens the file list), Posted (sortable; hover
  shows posted and added), and the **2 × 2 buttons without Follow**. No Grabs column, no "Same name
  posted more than once" line.
- The release on this page has a tinted row, the words **"The release on this page"**, and its name is
  not a link.
- **50 releases a page**, numbered, with the Showing line and the bottom pager when there is more
  than one page. The table **opens on the page that holds this release**; `#/release/<id>/p/N` names
  another page, which opens at the table; a sort change returns to the page holding this release. A
  release of another film opens at the top, on Overview.

### 5C.5 Similar releases

- The last section: **"Similar releases"**, today's feature (`DetailsController.php:69`): the first two
  words of the release name (`getSimilarName()`) searched in the release names of the Movies
  categories, newest posted first, at most 50. **Releases of the same film are left out** (his call,
  2026-09-27): the table above lists them. A release with no other match has no section.
- The same table as 5C.4, with the Movie releases list's grey film line (`Title · Year`, to the film
  page) under each name. No pager: the search returns at most 50.

### 5C.6 A release with no matched film

As TV's unmatched releases: the release name is the heading, and there is no poster, film crumb,
Follow film, About the film or releases table; the tabs and facts span the full width. Its Similar
releases are shown.

---

## 6. Decisions taken before the screens were designed

Taken on 2026-09-26, before those screens were prototyped. They are not asked again.

### 6.1 Today's features

- **Trending is dropped** (his choice, as TV's `/trending-tv`): the top-bar "Trending" item,
  "Trending Movies" in the Browse menu, `/trending-movies` and the home page's "Trending this
  week" row go. The frozen RSS feed `/rss/trending-movies` is untouched.
- **Carried over from TV's decisions, not re-asked**: no in-list text search, no "only titles I
  follow", no Name or Grabs sort, no page-size choice, one list (no thumbnails toggle, no cards
  view, no covers view).
- **Movies > Other is listed like any release** (his rule "never judge a release"). Most of it
  looks like a categorisation fault (`DATA-NOTES.md` section 1); the rule that files it as Movies
  has not been traced. The Category menu can untick Other.
- **Releases with no matched film are listed** in date order, with the placeholder (5.6), no
  film line and no Follow button.
- **Follow**: today's picker (the Movies sub-categories to follow) opens only to start following;
  on a followed film one click unfollows, with no picker and no Undo, and the toast reads
  "Unfollowed <title>"; after a picker save, "Following <title> · HD, UHD".
- **Year and Genre go on both screens**, the list and the wall (his choice over "Films wall
  only").

### 6.2 Filters and their split

Settled and approved: the Movie releases list has the ten filters of 5.2 in two bars; the Films
wall has the film bar's five (Genre, Year, Score, MPAA Rating, Language), as TV's wall has no
Resolution, Source or Category.

### 6.3 Films wall

Approved: section 5A.

### 6.4 Film page

Approved as section 5B (2026-09-27). The decisions taken beforehand:

- A header like the TV show page, then **one release table**: the show page's release table
  (sortable Resolution / Size / Posted / Grabs headers, checkboxes, chips, the four buttons),
  with **Resolution** and **Source** menus above it. **Not grouped** by resolution or source.
  (Changed on review, 2026-09-27: no Grabs column, the 2 × 2 buttons without Follow, the filter
  bar's cells for the menus; 5B.)
- **People are links, like TV**: "Directed by" and "Starring" (the first 12 cast) link to the
  Films wall filtered by that person. "Search films or actors" finds them.
- **Kept from today's title page**: the IMDb, TMDB and Trakt links (only when that id is
  known); the Releases / Latest / Best stats.
- **Dropped**: the "On your My Movies for" line with its Edit and Remove buttons. The Follow
  button alone, as on TV.
- **Similar films**: a row of 6 posters (6.6).

### 6.5 Release details

Approved as section 5C (2026-09-27). The decisions taken beforehand:


- **Plan stated to him, not objected to**: the approved TV details page adapted. The heading is
  the film title and year, with the release name as the bold second line; the same tabs; an
  **"About the film"** aside (score, MPAA rating, genres, Directed by, Starring, a link to the
  film page); **"All N releases of this film"** underneath.
- **Files (n) and Comments (n) tabs are required.** The release list has no Files column, so the
  file list is reached from the details page only.
- **Kept from today's movie details page**: **Similar releases** (today's name matching,
  `app/Http/Controllers/DetailsController.php:69`) and the **PreDB** block.
- **Dropped**: the "N users reported download failure" line and the admin **Edit release**
  button.

### 6.6 Similar films

His idea ("similar movies ... based on genre"). Tested on stored data (`DATA-NOTES.md`
section 6): genre alone is useless (7,379 films are Drama); shared people carry most of the
signal. **Decided: Similar releases on the details page, a "Similar films" row of 6 posters on
the film page.** The rule measured: candidates share a genre or a person with the film and have
a release; score = 2 × shared genres + 3 × shared people − |year gap| / 10; top 6. The viewer's
excluded categories are not applied yet; they are added and the query re-measured before it is
specified.

---

## 7. Data the design needs

Specified in [`DATA-CONTRACT.md`](DATA-CONTRACT.md) (2026-09-27), every read measured at full catalogue size
([`evidence/movies-data-contract.md`](evidence/movies-data-contract.md)). In short:

- **Films' genres and people become rows** on the shared `genres` (type 2000) and `people` tables, keyed by
  `movieinfo_id` (`movie_genres`, `movie_people`; the #556 key); the migration moves today's text into them.
- **US certificate, TMDB vote count and original language** are three columns on `movieinfo`, stored **going forward**, and
  a film is refreshed from TMDB whenever a new release of it arrives and its record is over 30 days old.
- **A release's audio languages** are rows (`release_audio_languages` on a `languages` lookup), for every category, filled
  from the media info already stored.
- **Six filter-led indexes** on `releases` (Category, Resolution, Source × Posted, Added) keep filtered pages the same cost on
  page 1 and page 200 despite Movies > Other; `completion` and `videos_id` join existing indexes.
- **Score** stays the stored `movieinfo.rating`, the first non-empty of the IMDb, TMDB, Trakt and OMDb values
  (`app/Services/MovieService.php:478`); the IMDb source returns nothing today and is a separate piece of work.
- The frozen API keeps reading `movieinfo.genre`, `director` and `actors`, so those text columns stay; its genre list keeps
  what it listed before the redesign.

---

## 8. Open items

1. **Release details** was approved on 2026-09-27 (5C): every Movies screen is designed.
2. **Storage** is decided: `DATA-CONTRACT.md` (2026-09-27).
3. **The people key** is decided: `movie_people` keyed by `movieinfo_id` (`DATA-CONTRACT.md` 2.4).
4. **Similar films** with the viewer's excluded categories is measured: 14–34 ms (`DATA-CONTRACT.md` 4.5).
5. **Movies > Other**: the rule that files 519,173 releases as Movies > Other is not traced.
   The design lists them either way; the list must still meet its cost on the full band
   (`DATA-NOTES.md` section 4).
6. **The IMDb score source** (section 7): a separate piece of work.
7. **Build issues**: when the Movies build issues are filed, they include build issues for the
   changes approved after the TV build: the 2026-09-26 changes to the TV releases list and filter
   bar, and the 2026-09-27 changes to the TV show page, the TV details page's release table and the
   "Follow" wording and bookmark icon everywhere (`../tv-redesign/SPEC.md` 3.3, 3.4, rules 12-14),
   plus the offsite-link fixes in today's app (the footer and admin-footer GitHub links, the IMDb
   chip on the old Movies covers view).
8. **Reviewer calls not applied** to the approved screen: silently swapping a backwards range
   instead of refusing it; the error red sitting close to coral; `1080p +1` instead of `2 chosen`
   in a cell. (Decades now are a three-column grid, with the filter bar.)
9. **Every list and wall filter is proven on the full catalogue** (`DATA-CONTRACT.md` 4). Two reads stay above 60 ms:
   the Audio filter's English (88 ms) and Unknown (100–135 ms) on the Movie releases list, because Movies > Other is in the
   band; nothing normalized measured better.
10. **Film page calls the reviewer raised, not taken up by him** (they stand as built): the six
    Similar films tiles keep the wall's tile width, leaving about 205 px empty at 1600 px; the
    Resolution and Source menus list every value, so DVD on a film with no DVD release gives an
    empty result; a selection carries to the next film; after a Similar films click the back link
    still returns to the list the user came from (the browser's Back returns to the previous film).
11. **Similar releases without the same film** (5C.5): left out in the search itself, in every search path
    (`DATA-CONTRACT.md` 4.5).

---

## Appendix A. Details an implementer needs that are easy to miss

- **Release details** (5C): the header's Add to cart and Follow film keep one width for both labels,
  the label next to its icon; a pressed Add to cart is green and a followed film violet, never coral.
  The "All N releases" table has no select boxes and opens on the page holding this release; a sort
  change returns to that page. Similar releases never lists the film's own releases and has no pager
  (at most 50). The PreDB block shows only with a match. A release with no film keeps its Similar
  releases.

- **Copy NZB link** is TV's (TV `SPEC.md` appendix A): the existing v1 `t=get` URL with the
  user's API key, a tick for about 1.6 seconds, and the toast "NZB link copied. It contains your
  API key, so only paste it into your own downloader." No API change.
- **Film page** (5B): the film is followed from the header's Follow film only; its release table has
  no Follow button, no Grabs column and no identical-name line. Its Resolution / Source cells are the
  page's own and reset when another film opens. The page number is in the URL
  (`#/film/<id>/p/N`); another page of the same film opens at the Releases heading, another film at
  the top. The header's counts, latest date and best resolution come from all of the film's
  releases the viewer may see, never from one page.
- **Offsite links** (section 4): IMDb, TMDB and Trakt open in a new tab with `rel="noopener
  noreferrer"`; group and poster chips stay same-tab links inside the site.
- The film line and the placeholder are two different links: the film line opens the **film
  page**, the poster or placeholder opens the **release's details**.
- A release with no matched film: no film line, no Follow button (Cart alone under Download), a
  placeholder, never part of a batch, and excluded while Genre, Year, Score, MPAA Rating or
  Language is set.
- The Score band "Too few votes" holds films with under 10 votes **or no score**. A film whose
  vote count is not stored yet is banded by its score alone.
- Completion is the only one-choice filter. Year's decades are multi-select; a range replaces
  them. The range error replaces the "Range" heading in place.
- Today's year code accepts years up to next year (`app/Support/YearRange.php:30`); the
  approved menu and its range check stop at the current year.
- The MPAA Rating menu lists only ratings present in the data, in the fixed order G, PG, PG-13, R,
  NC-17, NR. Its data is TMDB's US certificate (the label changed, not the data).
- A filter cell's tooltip names every chosen value (`Year: 2010s, 1990s`), because a cell shows
  `2 chosen` for several and can cut a long single value.
- Search inside a menu is per menu and forgotten on close; it never filters the list itself.
- A Films wall tile's `N releases` counts the viewer's visible releases of the film. The "newest"
  sort dates come from all of the film's releases in the Movies categories, not per viewer, as
  the TV wall's do. Neither comes from the page of releases being shown.
- The date column's heading and values follow the sort (Posted or Added).
- Group and poster chips link to the existing all-categories lists, same tab.
- Pages are 50 releases. The page number is in the URL so Back works.

## Appendix B. Rejected: do not bring back

- **Trending**, in every form (top bar, Browse menu, `/trending-movies`, home page row).
- A screen or filter built on "only titles I follow".
- **Name** and **Grabs** sorts; a page-size choice; a **score sort** or **oldest-first** on the
  wall.
- The thumbnails toggle, the **Cards** view and the **Covers** view.
- A text search inside the list.
- The first filter proposal as a whole (Genre, a decades-only menu, "8+, 7+, 6+" scores, a
  language filter). A language filter came back later at his request, as Audio and Language (5.2).
- A list of single years in the Year picker (a year is typed in From). Year as one choice only
  (decades became multi-select, 5.2).
- Filter layouts tried on 2026-09-26 before the filter bar: nine identical grey dropdown pills
  ("homogenous blob"); a hue per filter, with or without labels above values ("gaudy"); a sidebar of filters beside the list (it squeezes the rows: 11 of 50 rows kept their
  chips on one line at 1366 px); one all-in-one Filters pane, alone or with chips (its long lists made it "look overbearing", "an unwieldy mess" as genres grow); a "Show all" expander that
  stays open (he rejected it outright); grey grouped panels of pills (kept for a while as the fallback:
  "unpolished").
- The grouped panels are removed from the prototype (2026-09-27); the filter bar is the only
  filter layout.
- A **Trailer** button, on the film page and the details page (2026-09-27): "This app isn't a
  movie theatre."
- A **Certificate** label (it reads **MPAA Rating**).
- On the Films wall: the release count as a badge on the poster; `Score 8.5 · PG-13` (the bare
  score was kept); buttons on tiles.
- Year and Genre on the Films wall only.
- A film page grouped by resolution or source.
- The "On your My Movies for" line with Edit and Remove.
- The "N users reported download failure" line and the **Edit release** button on details.
- **Files** and **Grabs** columns on the releases list ("Neither are really that beneficial ...
  If the user wants to know the files, they can click on the release").
- A hue per kind for the group and poster chips on rows (outline was chosen).
- Group and poster chips splitting across lines.
- Neutral-ground row buttons (tinted was chosen); **coloured-outline** buttons.
- **Coral on a pressed toggle** (Cart, Follow); an on/off state on Download or Copy link.
- **Site-tag stripping** and a **letter rule** on placeholder name cards.
- An empty poster cell.
- Added 2026-09-27: the word **"Watch"** for following (section 4); the **eye** and the **bell** as
  the Follow icon; a Follow button in the film page's release rows; a **Grabs** column on the film
  page; the **"Same name posted more than once"** line; one-line buttons in the film page's table.

- Added 2026-09-27 (release details): header buttons tinted in the row buttons' hues (Copy link
  blue, Cart green at rest); the plot capped at 75 characters a line inside a full-width box; the same
  film's releases in Similar releases.

Not rejected: the reviewer's recommendation to use the plain film tile ("No poster") for every
release without a poster. He chose the name card instead; the tile remains its fallback.
