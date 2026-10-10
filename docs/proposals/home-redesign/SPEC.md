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
| Following | Following | "N of M with something new" (M followed titles with at least one release; N with a release added since the last visit); nothing when nothing is followed | "Manage Following" → `/watchlist` |
| TV | TV | "N today · S shows with new episodes" | "All TV" → `/tv` |
| Movies | Movies | "N today · F films this week" | "All Movies" → `/movies` |
| Audio, Books, Console, PC, Adult, Other | the section's name | "N today", or "N this week" when none today, or "none this week" | "All <Section>" → the section's list (`/audio`, `/books`, `/console`, `/pc`, `/adult`, `/browse/other`) |

"N today" is the count of releases **added** in the last 24 hours (not posted; the index is on `adddate`). Counts are
written with thousands separators and tabular numerals.

### 3.2 The rail

A single row, horizontally scrollable (`overflow-x: auto`, scroll-snap to tiles, the thin scrollbar of the lists),
tiles 148 px wide at a 16 px gap, in the order below. The rail never wraps and never paginates: it holds what the
shelf's query returns (60 at most; see `DATA-NOTES.md` 3). The arrows in the heading scroll it. A tile is a `button`
(`aria-expanded`), never a link; the whole tile is the hit area; focus shows the page's focus ring on the artwork.

| Shelf | One tile per | Picture | Title line | Meta line | Order |
|---|---|---|---|---|---|
| Following | followed show or film that has a release | poster 2:3, or the TV / Films title card when none | the title | when its newest release was added ("1 day ago") | newest release first; titles with nothing new keep their place (no dimming, no hiding) |
| TV | show with at least one release added in the last 24 hours | poster 2:3 or title card | the show | "N episodes today" ("1 episode today") | by the show's newest posting time |
| Movies | film with at least one release added in the last 7 days | poster 2:3 or title card | "Title (Year)" | "N releases · 3 hr ago" (the film's newest release) | by the film's newest posting time |
| Audio | album (performer + album from the audio tags; a release without tags is its own tile) | a **square** typographic tile: the disc icon and the performer on it (no cover art is trusted: the Audio section's rule) | the album, or the release name when untagged | when added | newest first |
| Books, Console, PC, Other | release | a 150 px tall **release card**: the name (four lines, then cut) and its sub-category chip | none (the name is on the card) | "size · when added" | newest first |
| Adult | release | the Adult list's **16:9 picture tile**, 236 px wide: the preview thumbnail, or the Adult list's "No picture" tile (transparent, dashed hairline, image icon, "No picture") of the same size when the release has none | the release name (two lines, bold) | "sub-category · size · when added" | newest first |

A "N new" badge sits in the top-right corner of a Following tile that has at least one release added since the last
visit (3.4): the shared dark pill of the TV wall's release count, in bold, never coral.

### 3.3 The panel

Clicking a tile opens a panel directly under that rail (a raised card with the floating shadow): the tile's title as
`<h3>`, a muted count ("3 newest releases" / "newest release"), a close button at the right, then the releases in the
**generic list's row form** (name bold, entity line, chips, group + poster chips, Category, Size, Added, the 2 × 2
buttons: Download, Copy link, Cart, Follow on a film / show row) and, for a show or film, a secondary button "All
episodes and seasons" → the show page / "All releases of this film" → the film page. The rows are the title's newest
releases, newest first, up to 60 (the shelf query's limit; DATA-NOTES 3). A release card or an Adult tile opens a panel
with that one row. Inside a single-section shelf the Category cell shows the sub-category alone; in the Following
panel it shows "Root > Sub" (the generic list's rule for mixed lists).

Clicking another tile swaps the panel to it (one panel per page, nothing else moves); clicking the open tile, the close
button or Escape closes it. The open tile carries a 3 px ink outline around its artwork. The panel scrolls into view
when it opens below the fold.

### 3.4 Following and the last visit

- "Something new" for a followed title means a release of it added after the user's **previous visit** to the home
  page. The page stores two per-user times in the home preference (5): `seen_at`, written on every home render, and
  `last_visit`, which takes the old `seen_at` whenever the new render is more than 30 minutes after it. Badges and the
  count line compare against `last_visit`. A first visit (no stored time) counts nothing as new.
- The followed set is the user's `user_series` (shows) and `user_movies` (films) rows, as the Following page reads
  them; the per-title category filter on those rows applies to the panel's rows exactly as it does on `/watchlist`.
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
and put them in order.", close button). It lists the nine shelves in the user's order, each row:

- a **checkbox button** (`role=checkbox`, the coral box when ticked) with the shelf's name and a one-line description:
  Following "Your followed shows and films, the newest release first" · TV "Shows with new episodes in the last 24
  hours" · Movies "Films posted in the last 7 days" · Audio "The newest albums" · Books, Console, PC "The newest
  releases" · Adult "The newest releases, with their preview pictures" · Other "The newest releases (Misc and Hashed)";
- a **grip** at the right (the six-dot icon) for reordering.

Rules:

- Ticking or unticking applies at once (the page behind the dialog re-renders); the dialog stays open.
- **Reordering is drag and drop, never arrow buttons** (the maintainer's rule, 2026-10-10). Pointer: press the grip,
  the row lifts as a ghost that follows the pointer (slightly rotated, with the floating shadow) while the list reflows
  live under it, leaving a faded gap where the row was; releasing drops it there and saves the order (toast "Order
  saved"). Keyboard, on the grip: Space grabs the row (the grip fills in ink, the row takes an ink outline, the status
  toast says "Grabbed · arrow keys move it, Space drops it"), arrow keys move it one place and announce "<Shelf> ·
  position N of 9", Space drops it and saves, Escape puts it back where it was and keeps the dialog open.
- The default set and order: Following, TV, Movies, Audio, Books ticked; Console, PC, Adult, Other unticked, in that
  order.
- Escape (with nothing grabbed) and the close button close the dialog; the first row's checkbox takes focus on open;
  focus returns to the Shelves button on close.

## 5. What the page remembers

Per user, in the existing release-view preference store (`users.view_prefs`, the JSON column every list already uses,
under the key `home`): the ticked shelves and their order, `seen_at` and `last_visit` (3.4). Saved through the existing
preference endpoint (`POST /profile/update-view`) extended with root `home` and the `shelves` key; the prototype uses
`localStorage` as the stand-in. Nothing else is remembered: no scroll position, no open panel.

## 6. Row and tile behaviour inherited from the sections (binding, unchanged)

Download (coral), Copy link (blue 235), Cart (green 150, filled when in the cart, the header's cart count follows),
Follow (violet 300, filled when following, only on film / show rows); the release name opens the release's details
page; the entity line opens the show / film page; group and poster chips open those lists; the completion, Password,
NFO, Preview chips as readouts; "Follow", never "watch"; nothing ranked by grabs or popularity anywhere; nothing judges
a release; offsite links open a new tab; headings and labels in words.

## 7. Cost rule

Every shelf is one bounded query that does not grow with the catalogue (`DATA-NOTES.md` 3): no `COUNT(*)` over the
catalogue, no window function, no per-row query. Each shelf renders on its own data; the page never waits on a count.

## 8. Not carried, rejected, open

**Not carried from today's home page:** the eight "Latest releases" cards (their job is done by the section shelves),
the five "Following" rows (the Following shelf and its panels), the full-catalogue count behind the cards.

**Rejected by the maintainer (2026-10-10) from the four mockups:** "Following first" (a poster strip and the newest
rows with a last-visit line), "Saved views" (tabs of saved filter sets), "Blocks" (a board of blocks the user places:
"interesting, but cluttered"), and within it the **Recent downloads** and **Busiest groups** blocks ("absolutely
pointless"); up / down arrow buttons for reordering ("archaic, the cheapest, laziest, unpolished way; NEVER the best
idea"). None of these may return.

**Open, raised with the maintainer (not decided):** where the admin's front-page content goes. Today it renders under
the lists; the approved prototype has no place for it. Until decided, the build keeps rendering it under the shelves as
today's `home-content` articles, unchanged.

## Appendix A. Easy to miss

- The Following rail shows every followed title that has a release, sorted by newest release; titles with nothing new
  are not dimmed and not hidden (the maintainer's "never judge" rule applied to his own list). The "N new" badge is the
  only difference.
- The TV shelf is "shows with new episodes in the last 24 hours" (by `adddate`), not the newest TV releases; the Movies
  shelf is "films posted in the last 7 days". A show with 17 episodes today is one tile saying "17 episodes today".
- Audio tiles are square and typographic; the Adult tiles are 16:9 and wider (236 px) than every other tile (148 px);
  the release cards are 148 × 150 px. A rail's tiles are all the same width.
- The panel's count says "newest release" for one row and "N newest releases" otherwise; it never claims a total.
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
