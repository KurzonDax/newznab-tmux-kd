# NNTmux Movies section: specification

Written 2026-09-26 from the maintainer's design review session of that day (what he approved,
rejected and decided, with his reasons) and query-lab experiments on a restored production
catalogue. `DATA-NOTES.md` holds the measured facts; `INVENTORY.md` lists every feature of
today's movie screens. The maintainer's clickable prototype is the visual and behavioural
reference for the approved screen: where this document and the prototype disagree about how
something looks or behaves, the prototype wins. A sanitized copy of it is added to this folder
when the Movies design is complete.

Status: the **Movie releases** screen is **approved** (2026-09-26). The Films wall, the film
page and the release details page are **not designed yet**; the decisions already taken for
them are in section 6. Nothing in the application has been changed.

---

## 1. Scope

| Screen | Route in the prototype | Replaces | Status |
|---|---|---|---|
| Movie releases (list) | `#/`, `#/p/N` | today's Movies Table and Cards views | approved |
| Films (discovery wall) | `#/films` | today's Movies Covers view | placeholder |
| Film page | `#/film/<id>` | today's movie title page | placeholder |
| Release details (Movies) | `#/release/<id>` | today's details page, for movie releases | placeholder |

Out of scope, by his decision: phone layouts (desktop only); the watchlist page (the **Watch**
button itself is in scope and keeps today's picker); the rest of the site.

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
possible through the Watch button, but no screen is built around it.

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
- On the release lists, a pressed **Cart** or **Watch** button fills solid **in its own hue**,
  not coral. The TV show page and details page were not asked about: they keep their approved
  buttons, including a coral pressed Cart and Watch, until they are revisited.

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
- Second row: **seven filter menus**, always in this order: **Category, Resolution, Source,
  Genre, Year, Score, Certificate** (US certificate), then **Clear all**. TV had three menus in
  the title row; seven need their own row.
  - Every menu button has **one fixed width** whatever is chosen, so the row never changes
    size or wraps. The width follows the window between 128 and 183 px; the seven fit one row
    at 1440 px. A long value is cut with an ellipsis and the button's tooltip names every
    chosen value.
  - A button reads `Genre: any` when nothing is chosen, `Genre: Horror` for one value and
    `Genre: 3 chosen` for several (the TV wall's style). A set filter turns coral.
  - **Clear all** clears every menu. It is hidden when nothing is set but **keeps its place**
    (a fixed 64 px slot), so nothing moves.
  - Menus combine with AND; values inside one menu with OR.
  - Checking a value keeps the menu open and keeps its scroll position; keyboard focus stays on
    the ticked item. Long menus scroll inside themselves and stay inside the window.
  - Any change of filter returns to page 1.

### 5.2 The seven menus

Each checkbox menu starts with its "Any …" item (`Any category`, `Any resolution`, `Any
source`, `Any genre`, `Any score`, `Any certificate`), which clears that menu.

| Menu | Kind | Options |
|---|---|---|
| Category | checkboxes | the Movies sub-categories the user may see (HD, UHD, SD, BluRay, DVD, 3D, X265, Foreign, Other). This menu is how a user leaves out Movies > Other (section 6.1). |
| Resolution | checkboxes | 4K, 1080p, 720p, SD, Unknown, each shown as its resolution chip |
| Source | checkboxes | WEB, Blu-ray, DVD, HDTV, Unknown. A remux is listed under Blu-ray and reads "Remux" in the Source column, as on TV. |
| Genre | checkboxes | the film genres, A to Z. A dropdown with checkboxes, the TV design (his specification). |
| Year | **one choice** | see below |
| Score | checkboxes | `9+`, `8–8.9`, `7–7.9`, `6–6.9`, `5–5.9`, `Under 5`, `Too few votes` |
| Certificate | checkboxes | the US certificates present, in this order: G, PG, PG-13, R, NC-17, NR (NR included when stored) |

**Year** is the current site's year picker in menu form (his specification: decades at the top,
a custom from–to range, then single years; `resources/views/components/year-picker.blade.php:21-37`).
It is **not** a multi-select: its items are radio items, one choice at a time. Top to bottom:

1. `Any year` (clears the year).
2. Heading "Decades": 2020s, 2010s, … 1900s. Picking one closes the menu; the button reads
   `Year: 1990s`.
3. Heading "Range": two four-digit fields, `From` `to` `To`, and **Apply**. The fields accept
   digits only. **Apply is disabled until both fields hold four digits.** A range outside
   1900 to the current year, or with the later year first, is refused: the error **"Years run
   1900–2026, earliest first"** (the current year in place of 2026) **replaces the "Range"
   heading**, so nothing below moves, and focus returns to `From`. A valid range closes the
   menu and the button reads `Year: 1980–1989`; the same year twice is a single year.
4. Heading "Years": every single year from the current year back to 1900, three to a row.
   Picking one closes the menu; the button reads `Year: 2024`.

**Score** (his pick of the recommended design, 2026-09-26): bands of the stored score, plus
**Too few votes**.

- A film with **fewer than 10 TMDB votes, or no score**, is in "Too few votes", not in a band.
  Reason: in a sample of 8,959 films, 106 of the 107 films at 9+ had under 10 votes
  (`DATA-NOTES.md` section 3).
- The vote count is stored going forward only (section 7). **Films saved before the count is
  stored stay in their score band** until their next release brings a count.

**Genre, Year, Score and Certificate describe the film.** A release with no matched film is
excluded while any of them is set; Category, Resolution and Source apply to every release.

### 5.3 The pager line

Under the header, always present and never moving: `Showing 101–150 of 8,249 releases` ·
previous · `Page 3 of 165` · next. One page reads `Page 1 of 1` with both arrows greyed. No
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

- Four round buttons as **2 × 2**: **Download** and **Copy link** on top, **Cart** and **Watch**
  below. A release with no matched film has no Watch button: Cart sits alone under Download.
- Colours (he chose **tinted** over neutral grounds): **Download** coral, unchanged. **Copy link**
  blue (hue 235), **Cart** green (hue 150), **Watch** violet (hue 300), each a tinted ground with
  a coloured icon. On (in cart, watching) = a **solid fill in the button's own hue**, never
  coral. Coloured outlines were rejected. Cart's green beside the completion chip's green is
  fine ("not even remotely in the same neighbourhood").
- Download and Copy link have no on/off state.
- Tooltips: "Download NZB", "Copy NZB link for SABnzbd or NZBGet", "Add to cart" /
  "In cart · click to remove", "Watch this film" / "On My Movies · click to remove".
- **Watch** follows the **film**, through today's watchlist picker (section 6.1). Every row of
  the same film shows the same state.

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

## 6. Decisions for the screens still to design

Taken on 2026-09-26, before those screens are prototyped. They are not asked again.

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
  film line and no Watch button.
- **Watch** keeps today's picker (the Movies sub-categories to follow).
- **Year and Genre go on both screens**, the list and the wall (his choice over "Films wall
  only").

### 6.2 Filters and their split

- **Movie releases: 7 menus**: Category, Resolution, Source, Genre, Year, Score, US certificate.
- **Films wall: 4 menus**: Genre, Year, Score, US certificate. No Resolution, Source or
  Category, as on the TV wall.
- The same components on both screens: Genre, Score and Certificate multi-select checkbox menus;
  Year the one-choice menu of 5.2.

### 6.3 Films wall

- **Sorts: TV's four, adapted**: Newest releases first (default), Newest to the site first,
  Newest films first (the film's year), A to Z by film title. **No score sort, no oldest-first.**
- The Releases / Films switch and the "Search films or actors" field, as on the list.
- Picking a person (from search or a film page) filters the wall to that person's films.

### 6.4 Film page

- A header like the TV show page, then **one release table**: the show page's release table
  (sortable Resolution / Size / Posted / Grabs headers, checkboxes, chips, the four buttons),
  with **Resolution** and **Source** menus above it. **Not grouped** by resolution or source.
- **People are links, like TV**: "Directed by" and "Starring" (the first 12 cast) link to the
  Films wall filtered by that person. "Search films or actors" finds them.
- **Kept from today's title page**: the IMDb, TMDB and Trakt links (only when that id is
  known); the **Trailer** button (only when the site setting is on; it is off on the
  maintainer's instance and no trailers are stored there); the Releases / Latest / Best stats.
- **Dropped**: the "On your My Movies for" line with its Edit and Remove buttons. The Watch
  button alone, as on TV.
- **Similar films**: a row of 6 posters (6.6).

### 6.5 Release details

- **Plan stated to him, not objected to**: the approved TV details page adapted. The heading is
  the film title and year, with the release name as the bold second line; the same tabs; an
  **"About the film"** aside (score, certificate, genres, Directed by, Starring, a link to the
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

No schema is proposed yet (`DATA-NOTES.md`). What the approved decisions require:

- **US certificate**: stored **going forward**, as films are saved. No one-off backfill of
  existing films.
- **TMDB vote count**: stored **going forward**. No backfill. It drives "Too few votes" (5.2).
- **Score**: the stored `movieinfo.rating` stays the score. It is the first non-empty of the
  IMDb, TMDB, Trakt and OMDb values (`app/Services/MovieService.php:478`), and its source is not
  recorded. The IMDb source returns nothing today; the maintainer handles that in a separate
  piece of work.
- **Genres as rows**: the Genre filter on the list is only fast when driven from the genre side
  (`DATA-NOTES.md` section 5), which needs a genre-to-film link rather than the comma-joined
  `movieinfo.genre` string. **Open**, for the data contract: the natural home is the existing typed
  `genres` table with `type = 2000` (the Movies root), as TV's genres use `type = 5000` (#775), plus a
  film-to-genre link; not yet decided.
- **Movie people onto the shared people tables** (#556): the directors and cast move from the
  `movieinfo.director` and `movieinfo.actors` strings onto `people`, which TV already uses.
  **Open**: how a film is keyed there. `video_people` is keyed by `videos_id`; films are keyed by
  `movieinfo.imdbid` (releases link to films through `releases.imdbid`, and `videos_id` is 0 on
  every movie release).

---

## 8. Open items

1. **Films wall, film page and release details** are still to be prototyped and approved, in
   that order.
2. **Storage** (the columns and tables for section 7) is decided after those screens are
   designed, on measured queries, as TV's `DATA-CONTRACT.md` was.
3. **The people key** for films on the shared people tables (section 7).
4. **Similar films** needs the viewer's excluded categories added and re-measured.
5. **Movies > Other**: the rule that files 519,173 releases as Movies > Other is not traced.
   The design lists them either way; the list must still meet its cost on the full band
   (`DATA-NOTES.md` section 4).
6. **The IMDb score source** (section 7): a separate piece of work.
7. **Build issues**: when the Movies build issues are filed, they include build issues for the
   2026-09-26 changes to the TV releases list (TV is already built).
8. **Reviewer calls not applied** to the approved screen: decades as a three-column grid so the
   range and single years show when the Year menu opens; silently swapping a backwards range
   instead of refusing it; the error red sitting close to coral. The approved screen stands as
   built.

---

## Appendix A. Details an implementer needs that are easy to miss

- **Copy NZB link** is TV's (TV `SPEC.md` appendix A): the existing v1 `t=get` URL with the
  user's API key, a tick for about 1.6 seconds, and the toast "NZB link copied. It contains your
  API key, so only paste it into your own downloader." No API change.
- The film line and the placeholder are two different links: the film line opens the **film
  page**, the poster or placeholder opens the **release's details**.
- A release with no matched film: no film line, no Watch button (Cart alone under Download), a
  placeholder, never part of a batch, and excluded while Genre, Year, Score or Certificate is
  set.
- The Score band "Too few votes" holds films with under 10 votes **or no score**. A film whose
  vote count is not stored yet is banded by its score alone.
- The Year menu is the only one-choice menu; its button still has the fixed width of the others.
  The range error replaces the "Range" heading in place.
- Today's year code accepts years up to next year (`app/Support/YearRange.php:30`); the
  approved menu and its range check stop at the current year.
- The Certificate menu lists only certificates present in the data, in the fixed order G, PG,
  PG-13, R, NC-17, NR.
- A menu button's tooltip names every chosen value, because the fixed width can cut a long one
  (for example `Genre: Science Fiction` at 1600 px).
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
- The first filter proposal (Genre, a decades-only menu, "8+, 7+, 6+" scores, a language
  filter).
- Year as a multi-select dropdown.
- Year and Genre on the Films wall only.
- A film page grouped by resolution or source.
- The "On your My Movies for" line with Edit and Remove.
- The "N users reported download failure" line and the **Edit release** button on details.
- **Files** and **Grabs** columns on the releases list ("Neither are really that beneficial ...
  If the user wants to know the files, they can click on the release").
- A hue per kind for the group and poster chips on rows (outline was chosen).
- Group and poster chips splitting across lines.
- Neutral-ground row buttons (tinted was chosen); **coloured-outline** buttons.
- **Coral on a pressed toggle** (Cart, Watch); an on/off state on Download or Copy link.
- **Site-tag stripping** and a **letter rule** on placeholder name cards.
- An empty poster cell.

Not rejected: the reviewer's recommendation to use the plain film tile ("No poster") for every
release without a poster. He chose the name card instead; the tile remains its fallback.
