# Home page: specification

The approved design of the home page (`GET /`, route `home`): **shelves**. Approved by the maintainer on 2026-10-10 on a
clickable prototype built on a restored copy of the production catalogue ("I'm good with the shelves view"). The
prototype in `prototype/home.html` is the binding picture of every rule below; where this text and the prototype
disagree about how something looks or behaves, the prototype wins. `prototype/check.mjs` states each rule as a check.

Desktop only (the maintainer's standing decision). The visual world is the one every redesigned section already uses:
the header of the header drop-downs issue (#908), the generic release list's row, the TV / Movies poster tile, the
Adult list's picture tile, the dialog, the toast, coral only for the primary action and real on-states.

## 1. What the page is for

The home page is the user's own newest-first view of a catalogue that is mostly noise. It answers, in one screen:
what arrived for the titles I follow, what arrived in the sections I care about, how much, and it takes me to a release
row (Download) or into a section in one click. The user decides which sections the page is made of and in what order.

Today's page (`resources/views/content/home.blade.php`): "Latest releases" (eight cards of the newest renamed
releases from every section), "Following" (five rows), then the admin's front-page content. Why it is slow and useless
is in `DATA-NOTES.md` section 1. Nothing of its structure is carried; see section 8.

## 2. The page

- **Header:** the #908 header, unchanged. "Home" is the brand link; no section is underlined on the home page.
- **Heading row:** `Home` as the page title (the sections' `h1` style) and, at the right, one control: **Shelves**
  (a secondary button with the sliders icon). Nothing else in the row.
- **Shelves**, in the user's order, each a section of the page (3). No pagination (a shelf is a bounded rail, not a
  list), no search, no filter bar, no "Customize" copy in the page body.
- The admin's front-page content (`content` rows of type index, status 1) is **not part of the approved screen**:
  the prototype does not show it. See 8 for the open point.

## 3. A shelf

Every shelf has the same anatomy: a heading row, a rail of tiles, and (when a tile is open) a panel under the rail.

### 3.1 Heading row

`<h2>` with the shelf's name, a muted count line, then at the right two round arrow buttons (scroll the rail left /
right by 80% of its width, smooth) and a "See all" link with the open-arrow icon:

| Shelf | Heading | Count line (real numbers) | See all |
|---|---|---|---|
| Following | Following | "N of M with something new" (M: the followed titles on the shelf; N: those with a release added since the last visit, 3.4); nothing when nothing is followed | "Manage Following" → `/watchlist` |
| TV | TV | "N today · S shows with new episodes" (S: every show with a release added in the last 24 hours, not the number of tiles) | "All TV" → `/tv` |
| Movies | Movies | "N today · F films this week" (F: every film with a release added in the last 7 days, not the number of tiles) | "All Movies" → `/movies` |
| Audio, Books, Console, PC, Adult, Other | the section's name | "N today", or "N this week" when none today, or "none this week" | "All <Section>" → the section's list (`/audio`, `/books`, `/console`, `/pc`, `/adult`, `/browse/other`) |

"N today" is the count of releases **added** in the last 24 hours (not posted; the index is on `adddate`) that the
user may see: the password rule and the user's category exclusions apply to every count on the page (`DATA-NOTES.md`
5). Counts are written with thousands separators and tabular numerals.

### 3.2 The rail

A single row, horizontally scrollable (`overflow-x: auto`, scroll-snap to tiles, the thin scrollbar of the lists),
tiles 148 px wide at a 16 px gap, in the order below. The rail never wraps and never paginates: it holds the shelf's
first 60 tiles in that order (the Following rail holds every followed title; see `DATA-NOTES.md` 3). The arrows in the
heading scroll it. A tile is a `button` (`aria-expanded`), never a link; the whole tile is the hit area; focus shows
the page's focus ring on the artwork.

| Shelf | One tile per | Picture | Title line | Meta line | Order |
|---|---|---|---|---|---|
| Following | followed show or film, in a section the user may view | poster 2:3, or the TV / Films title card when none | the title | when its newest release was added ("1 day ago"), or "no releases yet" | newest release first, then the titles with no release; a title with nothing new keeps its place and its poster is faded (55 % opacity), never hidden |
| TV | show with at least one release added in the last 24 hours | poster 2:3 or title card | the show | "N episodes today" ("1 episode today"); N is the number of the show's releases added in the last 24 hours | by the show's newest posting time |
| Movies | film with at least one release added in the last 7 days | poster 2:3 or title card | "Title (Year)" | "N releases · 3 hr ago" (the film's releases added in the last 7 days; its newest posting) | by the film's newest posting time |
| Audio | album (performer + album from the audio tags; the performer is the album performer, else the performer, as on the Audio list; a release with no tags, or whose tags name no album, is its own tile) | a **square** typographic tile: the disc icon and the performer on it; no cover picture on this shelf | the album, or the release name when the release is its own tile | when added | newest first |
| Books, Console, PC, Other | release | a 150 px tall **release card**: the name (four lines, then cut) and its sub-category chip | none (the name is on the card) | "size · when added" | newest first |
| Adult | release | the Adult list's **16:9 picture tile**, 236 px wide, with the Adult list's picture rule: the preview thumbnail, else the sample thumbnail, else the Adult list's "No picture" tile (transparent, dashed hairline, image icon, "No picture") of the same size | the release name (two lines, bold) | "sub-category · size · when added" | newest first |

A "N new" badge sits in the top-right corner of a Following tile that has at least one release added since the last
visit (3.4): the shared dark pill of the TV wall's release count, in bold, never coral. N is the number of those
releases.

### 3.3 The panel

Clicking a tile opens a panel directly under that rail (a raised card with the floating shadow): the tile's title as
`<h3>`, for a show or film a muted count ("3 newest releases", or "newest release" for one row), a close button at the
right, then the releases in the **generic list's row form** without its select cell (name bold, entity line, chips,
group + poster chips, Category, Size, Added, the 2 × 2 buttons: Download, Copy link, Cart, Follow on a film / show
row) and, for a show or film, a secondary button "All episodes and seasons" → the show page / "All releases of this
film" → the film page. For a show or film the rows are the title's newest releases, newest added first, up to 60; in
the Following shelf the follow's category list applies to them (3.4). An album tile opens a panel with the releases
grouped into that tile, newest first; a release card or an Adult tile opens a panel with that one row; these three
panels carry no count. Inside a single-section shelf the Category cell shows the sub-category alone; in the Following
panel it shows "Root > Sub" (the generic list's rule for mixed lists).

The page is rendered without any panel's rows. Opening a tile fetches its panel from the server (`DATA-NOTES.md` 3):
the tile is marked open at once and the panel appears when it arrives. When the load fails the tile closes again and
the error toast "Could not load the releases. Reload the page and try again." shows, as the show page's episode list
does.

Clicking another tile swaps the panel to it (one panel per page, nothing else moves); clicking the open tile, the close
button or Escape closes it. The open tile carries a 3 px ink outline around its artwork. The panel scrolls into view
when it opens below the fold.

### 3.4 Following and the last visit

- "Something new" for a followed title means a release of it added after the user's **previous visit** to the home
  page. The page stores two per-user times in the home preference (5): `seen_at`, written on every full-page render
  of the home page, and `last_visit`, which takes the old `seen_at` whenever the new render is more than 30 minutes
  after it. A panel or the shelves fetched again after a change in the dialog (`DATA-NOTES.md` 3) is not a render in
  this sense and writes neither time. Badges and the count line compare against `last_visit`: a tile's "N new" is the
  number of the title's releases added after it. A first visit (no stored time) counts nothing as new.
- The followed set is the user's `user_series` (shows) and `user_movies` (films) rows, as the Following page reads
  them. The per-title category list on those rows applies as it does on `/watchlist`, and to everything the shelf
  shows for the title: its newest release and when it was added, its place in the rail, its "N new" badge and its
  panel's rows. A release outside the list never moves a title forward or gives it a badge. The password rule and the
  user's category exclusions apply the same way.
- A row's Follow button (violet 300) toggles the follow as it does on every list; unfollowing removes the title from
  the Following shelf on the next render.

### 3.5 Empty states

- **Nothing followed:** the Following shelf's rail is replaced by the dashed empty panel: "Nothing followed yet." and
  "Follow a show or a film from its page, or with the bookmark button on any release row, and its newest releases will
  be waiting here." with the links "Browse shows" (`/tv/shows`) and "Browse films" (`/movies/films`).
- **A section with nothing:** "Nothing in <Section> in the last days." in the dashed empty panel; the shelf keeps its
  heading row (a user who ticked it wants to see that nothing came).
- **No shelves ticked:** "No shelves." "Use Shelves to choose which sections appear." in place of the shelves.

## 4. The Shelves dialog (the customization)

The **Shelves** button opens a dialog (the sections' dialog form: title "Shelves", subtitle "Tick the shelves you want
and put them in order.", close button). It lists the shelves in the user's order (all nine for a user who may view
every section; a shelf whose section the user may not view is not listed, 5), each row:

- a **checkbox button** (`role=checkbox`, the coral box when ticked) with the shelf's name and a one-line description:
  Following "Your followed shows and films, the newest release first" · TV "Shows with new episodes in the last 24
  hours" · Movies "Films posted in the last 7 days" · Audio "The newest albums" · Books, Console, PC "The newest
  releases" · Adult "The newest releases, with their preview pictures" · Other "The newest releases (Misc and Hashed)";
- a **grip** at the right (the six-dot icon) for reordering.

Rules:

- Ticking or unticking saves at once. When the save succeeds the shelves behind the dialog are fetched again and
  replaced (`DATA-NOTES.md` 3); the dialog stays open.
- **Reordering is drag and drop, never arrow buttons** (the maintainer's rule, 2026-10-10). Pointer: press the grip,
  the row lifts as a ghost that follows the pointer (slightly rotated, with the floating shadow) while the list
  reflows live under it, leaving a faded gap where the row was; releasing drops it there and saves the order (toast
  "Order saved" when the save succeeds). Keyboard, on the grip: Space grabs the row (the grip fills in ink, the row
  takes an ink outline, the status toast says "Grabbed · arrow keys move it, Space drops it"), arrow keys move it one
  place and announce "<Shelf> · position N of T" (T: the number of rows listed, 9 for a user who may view every
  section), Space drops it and saves, Escape puts it back where it was and keeps the dialog open. Every listed row can
  be moved, ticked or not, and the order of all of them is remembered.
- A save that fails (an expired session, a server error) puts the row or the tick back where it was and shows the
  error toast "Could not save your view preference. Please try again.", as every list's preference save does.
- The default set and order: Following, TV, Movies, Audio, Books ticked; Console, PC, Adult, Other unticked, in that
  order.
- Escape (with nothing grabbed) and the close button close the dialog; the first row's checkbox takes focus on open;
  focus returns to the Shelves button on close.

## 5. What the page remembers

Per user, in the existing release-view preference store (`users.view_prefs`, the JSON column every list already uses,
under the key `home`): the order of all nine shelves and which of them are ticked (two lists, as the prototype keeps
them), `seen_at` and `last_visit` (3.4). Saved through the existing preference endpoint (`POST /profile/update-view`),
which accepts `home` beside its list roots with those two lists (`DATA-NOTES.md` 4); the prototype uses `localStorage`
as the stand-in. Nothing else is remembered: no scroll position, no open panel.

A user with no stored lists gets the default set and order (4). An empty ticked list is a real choice and shows the
"No shelves" state (3.5). A shelf whose section the user may not view keeps its stored place and tick and is left out
when the page and the dialog are drawn.

## 6. Row and tile behaviour inherited from the sections (binding, unchanged)

Download (coral), Copy link (blue 235), Cart (green 150, filled when in the cart, the header's cart count follows),
Follow (violet 300, filled when following, only on film / show rows); the release name opens the release's details
page; the entity line opens the show / film page; group and poster chips open those lists; the completion, Password,
NFO, Preview chips as readouts; "Follow", never "watch"; nothing ranked by grabs or popularity anywhere; nothing judges
a release; offsite links open a new tab; headings and labels in words.

## 7. Cost rule

Every shelf is a fixed number of bounded reads that do not grow with the catalogue (`DATA-NOTES.md` 3): every count is
bounded by an `adddate` window or by one followed title, there is no window function, and no query per tile or per
row. The number of statements of a full page is fixed per ticked shelf and does not change with the number of
followed titles. Each shelf renders on its own data; the page never waits on a count.

## 8. Not carried, rejected, open

**Not carried from today's home page:** the eight "Latest releases" cards (their job is done by the section shelves),
the five "Following" rows (the Following shelf and its panels), the full-catalogue count behind the cards, and the
cards' rule that only renamed, post-processed releases are shown (a shelf shows what its section's list shows).

**Rejected by the maintainer (2026-10-10) from the four mockups:** "Following first" (a poster strip and the newest
rows with a last-visit line), "Saved views" (tabs of saved filter sets), "Blocks" (a board of blocks the user places:
"interesting, but cluttered"), and within it the **Recent downloads** and **Busiest groups** blocks ("absolutely
pointless"); up / down arrow buttons for reordering ("archaic, the cheapest, laziest, unpolished way; NEVER the best
idea"). None of these may return.

**Open, raised with the maintainer (not decided):** where the admin's front-page content goes. Today it renders under
the lists; the approved prototype has no place for it. Until decided, the build keeps rendering it under the shelves as
today's `home-content` articles, unchanged.

## Appendix A. Easy to miss

- The Following rail shows every followed title, sorted by newest release, the titles with no release last ("no
  releases yet"). Titles with nothing new are faded, never hidden (the maintainer's ruling, 2026-10-10). The "N new"
  badge counts the releases added since the last visit, under the follow's own category list.
- The TV shelf is "shows with new episodes in the last 24 hours" (by `adddate`), not the newest TV releases; the Movies
  shelf is "films posted in the last 7 days". A show with 17 releases added today is one tile saying "17 episodes
  today": the number counts releases (the maintainer kept the wording, 2026-10-10).
- The count line's S and F are totals; the rail shows the 60 with the newest postings.
- Audio tiles are square and typographic; the Adult tiles are 16:9 and wider (236 px) than every other tile (148 px);
  the release cards are 148 × 150 px. A rail's tiles are all the same width.
- A show's or film's panel says "newest release" for one row and "N newest releases" otherwise; it never claims a
  total. An album, release card or Adult panel has no count.
- The count line of a shelf with nothing today falls back to "N this week" and then "none this week"; it never says
  "0 today".
- The dialog's rows are checkbox buttons beside the grip, never a checkbox that contains a button (axe: nested
  interactive controls).
- A ghost being dragged is a clone of the row inside a carrier that wears the dialog's class, so it is styled like the
  row; the original row stays in the list at 28 % opacity until the drop.
- "Order saved" is the only toast for a pointer drop; a keyboard move announces the position; grabbing announces the
  keys.

## Appendix B. What the prototype invents

The followed titles (the catalogue copy has no `user_series` / `user_movies` rows), the basket, the recent downloads,
the last-visit time (72 hours before the data's "now") and the front-page note. Everything else is real lab data; the
public copy under `prototype/` replaces every name with an invented one and every picture with a drawn placeholder.
