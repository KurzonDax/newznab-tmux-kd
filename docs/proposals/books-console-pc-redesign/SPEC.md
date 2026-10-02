# NNTmux Books, Console and PC sections: specification

Written 2026-10-01 from the maintainer's design review session of that day (what he approved, rejected and decided,
with his reasons) and from read-only measurements on production. `DATA-NOTES.md` holds the measured facts;
`INVENTORY.md` lists every feature of today's Books, Console and PC screens with `path:line`. The maintainer's
clickable prototype is the visual and behavioural reference: where this document and the prototype disagree about how
something looks or behaves, the prototype wins. A sanitized copy of it, with an invented dataset and placeholder art,
is in `prototype/` (`books.html`, `console.html` and `pc.html` are one page, `shelf.html`, on three datasets).

Status: the **Books**, **Console** and **PC** release lists and release details pages are **approved** (2026-10-01:
"All right, I'm good with it. I'm good with the books and the PC pages as well."). Nothing in the application has been
changed. How each value is stored and read, with every query measured at full catalogue size, is the data contract
that follows this document (`DATA-CONTRACT.md`).

---

## 1. Scope

| Screen | Address in the app | Route in the prototype | Replaces | Status |
|---|---|---|---|---|
| Book releases (list) | **`/books`** | `books.html#/`, `#/p/N` | `/browse/books` (Table, Cards, Covers) | approved |
| Console releases (list) | **`/console`** | `console.html#/`, `#/p/N` | `/browse/console` (Table, Cards, Covers) | approved |
| PC releases (list) | **`/pc`** | `pc.html#/`, `#/p/N` | `/browse/games` and its alias `/browse/pc` (Table, Covers) | approved |
| Release details, Books and PC | `/details/<guid>` | `#/release/<id>` | today's details page, for those releases | approved |
| Release details, Console | `/details/<guid>` | `#/release/<id>` | today's details page, for console releases | approved |

The addresses are his pick (2026-10-01, "/books, /console, /pc"), matching `/adult`. The header's Books, Console and PC
drop-downs point their "All …" item at the new list, and each sub-category item at the new list with that Category set,
as TV, Movies and Adult do. The old browse pages go once the new screens are built, with no bookmark redirect (his rule:
a retired address that nothing in the app links to any more is deleted).

**The old per-book and per-game pages go too** (`/title/books|console|games/<id>`, `INVENTORY.md` section 4; his rule:
"we don't leave things just because they're linked from somewhere else. We update those links so that they point to the
new pages"). Audio keeps its page until Audio is redesigned. What linked to them is repointed: on the shared lists (All
releases, search results, the basket, poster lists) a **Console** release's title chip opens **that release's details
page**, which lists every release of its game (5B); **Books and PC releases show no title chip** (his words: "forget the
fucking chip for music and PC releases and book releases"; the Music chip stays as it is).

Out of scope: a covers view or wall, a title page (book, game) and Follow for any of the three; artwork for Books and
PC ("for all three of these, we don't need artwork in this initial mockup"; Console got covers later the same day,
section 6.4); new metadata lookups ("I don't want to add additional metadata lookups"); phone layouts (desktop only);
the rest of the site.

Hard constraints from the repository (`AGENTS.md`), unchanged from TV, Movies and Adult:

- **External API and RSS are frozen**, including additive changes. Everything here is web front end. The console data
  this design adds (section 7) is never added to an API or RSS response.
- **No new Artisan command without the maintainer's explicit approval of that specific command.** This spec proposes
  none.
- Colours route through the existing token layer.
- Per-user remembered choices (the sort and the list's dropdown filters) go in the existing view preferences
  (`users.view_prefs` via `User::releaseViewPreferences()`, the #881 rule), not a cookie.

Related issues from this design session, all merged 2026-10-01: **#914** (the console ESRB column holds the age rating
from IGDB's supported fields, never a critic score), **#915** (a console game's release date is its platform's, never
"today"), **#916** (new console rows record their downloaded cover), **#917** (console genres one row per genre, like
TV and Movies), **#923** and **#926** (admin edit pages with a stored date).

---

## 2. What these sections are for

The maintainer's brief: "mockup release listings for books, console, and PC ... Use your best judgement based on the
release listings we did for the other categories ... Add filters that make sense based on the data that's currently
available in each category. I don't want to add additional metadata lookups. Also go ahead and mockup release details
screens for each ... based on what the current app does and more importantly, what we designed for the previous
sections in this latest redesign effort."

On production the book, console and PC-game metadata tables are empty (`DATA-NOTES.md`): a release has its name,
sub-category, size, dates, completion, password status, NFO, file list, PreDB match, group and poster, and rarely media
info. The sub-category is the one classifying fact (Console: the platform; PC: 0day, ISO, Mac, Games, the phones;
Books: Magazines, Ebook, Comics), so it has its own column and the Category filter.

Console is the exception the maintainer chose to build out: when the console lookup is on (`lookupgames`), a release is
matched to a game on IGDB, and the game's genres, year, cover, summary, storyline, credits, scores and links show on the
Console screens (sections 5B, 6). He asked why the lookup throws most of IGDB's answer away ("So why the hell are we not
retaining any of those"); section 7 keeps them. In the prototype every game value is invented, because no console
release on production has a game yet.

PC: the maintainer gives the PC band no effort beyond parity with Books (his words: PC releases "are almost always
dangerous releases that are packed with viruses, root kits, or crypto miners"). The PC screens are the Books screens on
PC data; nothing PC-specific is designed.

---

## 3. Structure

Two screens per section, as Adult: a releases list and a release details page. There is no titles switch, no wall, no
title page, no Follow. The Console details page of a release that has a game borrows the Movies **film page's** layout
while staying a release page (section 5B).

---

## 4. Rules that apply to every screen

Every rule in TV `SPEC.md` section 2, Movies `SPEC.md` section 4 and Adult `SPEC.md` section 4 applies
([`../tv-redesign/SPEC.md`](../tv-redesign/SPEC.md), [`../movies-redesign/SPEC.md`](../movies-redesign/SPEC.md),
[`../adult-redesign/SPEC.md`](../adult-redesign/SPEC.md)): never judge a release; never rank by popularity; numbered
pagination only, the page in the URL; only the release name is bold in a row; nothing shifts when state changes; no
instruction text; colour is wanted, a distinct low-key hue per chip kind with words, not icons; coral only for the
primary action (Download) and the on-state of a real mode (current page, a set filter), never a pressed toggle; no Report
button and no details button in rows; checkbox menus close on focus leaving, Escape and a click outside; offsite links
open in a new tab; an approved screen is frozen.

Two rules from this session apply to all three sections:

- **No section headings that announce the obvious** (his words about a "This release" heading: "I hate on-the-nose
  shit like that"). A page's parts follow one another without labels like "This release" or "About the game".
- **A release page stays a release page.** "Make it look like page X" borrows X's layout and parts, not its content
  hierarchy: the release name is the heading of every release details page (section 5B).

What does not apply, because these releases have no title entity: the Follow button, the same-title batch expander, the
title page, "All N releases of this …". Books and PC also have no title line, no title filters and no cover.

---

## 5. The releases lists (APPROVED 2026-10-01)

The Adult releases list (Adult `SPEC.md` section 5) without its picture, Resolution, Audio and Preview / Sample / Clip
parts, with the changes below. Console adds a cover, a game line and the game's filters (5.6 to 5.9).

### 5.1 Header

- The heading **Book releases**, **Console releases** or **PC releases**, then the **name search** field (5.5), then the
  sort menu at the right.
- The four sorts of the earlier lists: Posted newest / oldest, Added newest / oldest; default Posted newest first;
  remembered per user.

### 5.2 The filters

- **Books and PC: one bar of two equal cells, Category and Completion.** Each cell is as wide as a cell of the Movie
  releases list's release bar. Name above value, `any` muted when unset, a set cell marked by a coral line under its
  value, the open cell lifted, OR within a menu and AND between menus, a change returns to page 1.
- **Console: the same release panel, then a second panel, "The game", of two cells: Genre and Year** (5.8, 5.9), as
  Movies puts the film's menus after the release's. Both panels are two cells wide.
- **No other filters.** His rulings: "for now, I don't want to add any filters to books"; nothing is derived from release
  names (platform, language, format would be guesses, not stored data); no PC filters beyond these.
- **No Password filter** (his call: "Drop the password filter. Those should be hidden or not based off the system
  settings, right?"). Right: the site setting already decides whether passworded releases are listed at all
  (`ReleaseBrowserQuery::baseQuery()` applies `releases.showPasswords()` to every list). The Password chip on a listed
  release stays.
- **Category** lists the section's sub-categories that have a release, in the site's order. **"Exclude Other"** behaves
  exactly as on the Adult, TV and Movies lists (Adult `SPEC.md` 5.2, #886): an item under "Any category", shown only
  while the menu lists Other and at least one other sub-category; a mode, not a list of ids; his wording, never "All
  except Other".
- **Completion**: Any / 100% only / 95% or more, one choice.
- **Remembered filters**: the dropdown filters (and on Console the Genre and Year menus) are remembered in the profile
  (the #881 rule); the name search is never remembered, nor the page; Clear all clears the remembered set too.

### 5.3 The pager line

As Adult: `Showing X–Y of N releases`, Clear all in a fixed slot hidden in place when nothing is set (it also empties
the name search), `Page X of Y` with fixed-width page text and the arrows; the bottom pager with numbers and Go to page.
50 releases a page. With no result: "No releases match …" naming the filters and the search in words.

### 5.4 The table

Columns, fixed widths:

| Section | Columns |
|---|---|
| Books, PC | select box 34 · Release (auto) · **Category 128** · Size 82 · Posted (or Added, following the sort) 112 · buttons 96 |
| Console | select box 34 · **cover 116** · Release (auto) · **Category 128** · **Genre 150** · Size 82 · Posted 112 · buttons 96 |

- **The Category column** (his ruling: "I'm fine with it being in its own category") names the sub-category in dim text
  on one line; it is never cut short (128 px fits "Phone-Android"). Its heading lines up with its text.
- On Console, "Release" spans the cover and the name, as on Movies.
- Size in ink, the date dim, the date on one line. No Files or Grabs column.

### 5.5 The release cell, buttons and selection

- The release name, bold, two lines at most, links to the release's details page.
- On Console, when the release has a game, **the game line** under the name: `Game · Year` in dim 13.5 px text, plain
  text, not a link (there is no game page). None when there is no game.
- The chip line, in the TV / Movies order: completion (under 100%), Password, media info summary (opens the media info
  dialog), NFO; then the group and poster outline chips as one unit that never splits.
- Row buttons: Download (coral) and Copy NZB link (blue) on top, **Cart** (green) alone under Download; no Follow.
- Selection as TV / Movies / Adult: a select box per row, select-all, the floating bar.

### 5.6 The name search

As Adult `SPEC.md` 5.9: a field beside the heading that narrows the list as the user types (today's 180 ms pause),
combines with the filters, returns to page 1, keeps focus and caret, has a clear button, empties on Escape and on Clear
all, and is never remembered.

- Books and PC: **"Search release names"**, matching release names as typed.
- Console: **"Search releases or games"**, matching the release name **or the game's name** as typed (his "Yes": a release
  with a scrambled name is found by typing its game's name). The no-match line reads "No
  releases match release or game names containing “…”".

### 5.7 Console: the cover

- A cover column first under "Release": the Movies poster slot, a 88 × 132 tile (2:3) in a 116 px column; IGDB's cover
  (`cover_big`, 264 × 374) is cropped a little at the sides (`object-fit: cover`). It links to the release's details
  page and is skipped by keyboard and screen readers, as on Movies; images load at once, as on Movies.
- A release with no game, or a game without a cover, shows the Movies placeholder tile reading **"No cover"** with an
  image icon.

### 5.8 Console: the Genre column and menu

- **Genre column**: the game's genres in IGDB's order as one comma list (dim, wrapping, at most four lines, the full
  list on hover); "—" when the release has no game. Four lines add a few pixels to a row, as a two-line name does.
- **Genre menu**: the genres the section's games have, A to Z, then **Unknown** for releases with no game or whose game
  has no genre (the Movies rule for unidentified releases). OR within the menu. It searches inside itself over ten
  options. Each option sits on one line (the menu is as wide as its longest IGDB genre name, "Hack and slash/Beat 'em
  up").
- Genres are stored one row per genre since #917 (`console_genres`).

### 5.9 Console: the Year menu

The Movies / TV Year menu as it is (his words: "the same thing we've done with TVs and movies"): Any year; **decades**,
ticked together; a typed From–To range with the Movies bounds (1900 to the current year) and its error "Years run
1900–<year>, earliest first". The only change: **the decades go back to the 1990s, no older** ("The only change, though,
is that the decades only need to go back to the 1990s"). The year is the game's release year. A release with no game
drops out when a year is set, as Movies drops a release with no film. The 1990s sit alone on the decade grid's second
row and the range accepts years back to 1900: both kept by his decision ("I'm okay with the way the year dropdown works
or looks ... let's not worry about that").

---

## 5A. Release details, Books and PC, and Console releases with no game (APPROVED 2026-10-01)

The Adult details page (Adult `SPEC.md` 5A) without pictures and without the resolution chip:

- Breadcrumb `<Section> releases › <sub-category>`; the release name as the heading, full width, no aside.
- The chip line of 5.5, the group and poster chips on their own line; buttons **Download NZB** (coral), **Copy NZB
  link**, **Add to cart** (pressed it reads "In cart" and fills green without changing width). No Follow.
- **Tabs**: Overview, Files (N), **Media info only when the release has media info** (his ruling: "I would hide it if
  the release doesn't have media info available"), NFO, Comments (N). A remembered Media info tab falls back to
  Overview. Files reads "Files" with no number and the facts grid shows "—" when no file count is stored.
- **Overview**: the facts grid (Category, Size, Files, Completion, Posted, Added, Grabs, Group, Poster, Password status;
  a Console release with no game also lists Genre "—", as approved), then the PreDB block when the release has a match.
  Long values wrap; a poster address breaks before its `@`, never inside a word.
- **Similar releases**: today's query (`ReleaseSearchService::searchSimilar()`, fixed for every root by #859), within
  the section's categories, newest posted first, at most 50, without this release; the table has Release (name, game
  line on Console, chips), Category, Size, Files, Posted and the 2 × 2 buttons without Follow, sortable by Category,
  Size and Posted. No section when nothing matches.

## 5B. Release details, Console release with a game (APPROVED 2026-10-01)

A release page laid out like the Movies **film page** (Movies `SPEC.md` 5B). History, because the maintainer rejected
two versions on the way and both must stay rejected (Appendix B):

1. An "About the game" aside beside the tabs with a summary box on Overview (the Movies release-details form): "I hate
   your design. I want the details page to look more like ... the actual movie film page ... it's not consistent."
2. The film page copied literally, with the game's name as the 44 px heading and the release under a "This release"
   section heading: "Don't put the game title in big letters at the top of the page. The release title needs to be at
   the top ... Put the game name underneath the release title. Remember, this is a release detail page. All I was
   asking for was the layout to look similar to what the film page looked like."

The approved page, top to bottom:

- **Breadcrumb** `Console releases › <sub-category>`.
- **The cover** at the left, 200 px wide (the film page's poster), the "No cover" placeholder when the game has none;
  the text column beside it, 640 px at most, as the film page's.
- **The release name** as the heading, at the release-details size (24 px), wrapping anywhere.
- **The game line** under it: the game's name (ink, semibold) · year · platform (the sub-category).
- The release's **chip line** and the **group and poster** chips.
- **The summary** paragraph (stored today as `consoleinfo.review`), then the **storyline** paragraph when the game has
  one: a dim run-in label "Storyline" and the text in ink (the "Directed by" pattern).
- **Tags** (the film page's): the genres as filled tags that are links, each opening the Console list on **that genre
  alone** (the other dropdown filters and the name search cleared), as the film page's genre tags open the films wall;
  then outlined tags **Critic score N**, **User score N** and the age rating (**ESRB M**, or **PEGI 12** when there is no
  ESRB rating, #914). A missing value leaves its tag out.
- **Info lines** in the film page's "Directed by" style (dim label, ink value, not links: there is no developer or
  publisher filter): **Developed by**, **Published by**, **Released** (the full date), **Game modes**, **Perspective**. A
  missing value leaves its line out.
- **One button row**: **Download NZB** (coral), **Copy NZB link**, **Add to cart**, then **IGDB** (the game's IGDB page)
  and **Website** (the game's official site, only when it has one), styled as the film page's IMDb and TMDB buttons
  (`btn sec ext`, external-link icon, new tab, `noopener noreferrer`, "(opens in a new tab)" for screen readers).
- **The tabs and facts** straight after, with no heading: as 5A, without the Genre fact (the genres are in the tags).
- **"All N releases of this game"** (his ask, 2026-10-01: "Is there a way that we can show other releases that are also
  linked to that same game? I think we do something similar with movies"): the Movies release details page's "All N
  releases of this film" section (Movies `SPEC.md` 5C.4), after the facts. Every release of the game (the releases
  with this release's `consoleinfo_id`), newest posted first, sortable by Category, Size and Posted, 50 a page with the
  Movies pager; this release's row is marked "The release on this page" in coral, its name not a link, the row on the
  panel ground (`aria-current="true"`). One release: the heading reads "The only release of this game". The table is
  5A's Similar releases table.
- **Similar releases** as 5A, **leaving out releases of the same game** (the Movies rule, his call 2026-09-27: the table
  above lists them). No section when nothing else matches.

---

## 6. Decisions taken in the session

### 6.1 Today's features

Every feature of today's Books, Console and PC screens is in `INVENTORY.md`. Carried: the list, the release name link,
the chips, group and poster chips, selection and the bulk bar, a name search, the details page's tabs, PreDB and Similar
releases. Dropped the way TV, Movies and Adult dropped them: the Cards and Covers views, the Name and Grabs sorts, the
page-size choice, the metadata filters with no stored data behind them, the Report and Details row buttons, the Files and
Grabs list columns. Section 9 lists what `INVENTORY.md` found that this design changes.

### 6.2 Password filter

Dropped (5.2). Never offer a password filter on a release list: the site setting decides.

### 6.3 Console genres

Stored one row per genre (#917) so the Genre menu filters properly (his question: "Can we treat the console genres
similarly to what we did with TV and movies so that the genres can be filtered on properly?").

### 6.4 Console covers, game name and year

Shown on the list and the details page (his requests of 2026-10-01: "add the covers to the mockup with some made-up
art"; the game name "let's try it and see"; the year after the name and the game-name search "Yes to both").

### 6.5 The IGDB data kept

His list, verbatim: "developer, the critic score, the user score, the game modes, the player perspective, storyline,
official website links", and "similar to how the movies page and the TV page have those buttons that link out to IMDb
and TMDB ... those same buttons, but linking out to the IGDB page. Also, if there's an official website link, then we
should have a button that links out to that as well." Why they were missing: `consoleinfo` is the table of the old
Amazon lookup (`asin`, `salesrank`, `esrb`, `review`); when IGDB replaced Amazon the save step filled only those
columns, so everything else IGDB returns on every lookup is dropped. Storage: section 7 and `DATA-CONTRACT.md`.

---

## 7. Data the design needs

To be proven in the data contract at full catalogue size before any build issue:

- The three lists' reads: each band newest first by posted and by added, page 1 and the last page; Category (with
  Exclude Other) and Completion alone and combined; the name search alone and with filters, including a word that
  matches nothing; on Console the game-name search, the Genre menu (including Unknown) and the Year menu (decades and a
  range), alone and combined.
- Console per row: the game's name, year, genres and cover, without a per-row query.
- Console details: the game's summary, storyline, genres, critic score, user score, age rating, developer, publisher,
  release date, game modes, player perspectives, IGDB URL and official website.
- **New storage** for the values IGDB already returns and the lookup drops: developer, critic score, user score, game
  modes, player perspectives, storyline, official website. Normalized; written by the console lookup's one save step;
  never exposed to the API or RSS.
- Media info presence per release (the tab rule of 5A) without loading the media info.
- Remembered filters: beside the sort in `users.view_prefs` (the #881 rule).
- Similar releases: today's search-index query, unchanged.

---

## 8. Open items

None on the screens.

---

## 9. Findings from today's screens

From `INVENTORY.md` section 7 (read in the code on master `85a680951`). Those the redesign removes with the old browse
pages: the Covers view hides every release without a metadata row and counts titles, not releases (finding 2); its
"Name · A–Z" sorts by release name (4); PC's Platform filter is the constant "PC" (5); Console's Publisher options are
comma-joined strings (6); Books covers search ignores the author (7); the XL cover genres line never shows (10); legacy
`ob=` sorts that do not exist (11) and `/Console/WiiVare` looking up a title that is not seeded (12).

Those the Console design fixes: the console summary (`consoleinfo.review`) is stored but no page shows it (8); the
console details partial shows only Title, Publisher and Release Date (9).

Left as they are, outside this design: PC metadata is looked up only for PC › Games (3); the PC "Other" naming (13); the
lookup settings' descriptions name GiantBomb, which has no code, and leave out Google Books and Open Library (14); book
covers are saved under a different folder from console and PC covers (15); RSS labels the console link "Amazon:" (16,
frozen surface); IGDB `alternative_names` reaches the matcher only through `fields *` (17); title-page access errors are
plain 403s (18).

---

## Appendix A. Details an implementer needs that are easy to miss

- The Category column is 128 px (not the 120 px of the first draft): "Phone-Android" must never be cut.
- The Media info tab is absent, not disabled, when a release has none; the tab row closes up.
- On Console, the Genre column is 150 px, wraps to at most four lines and shows the full list on hover.
- The Genre menu is as wide as its longest option on one line; it lists Unknown last.
- The Year menu's decades stop at the 1990s; its range bounds stay 1900 to the current year.
- A genre tag on the details page replaces the list's filters with that genre alone; it does not add to them.
- The "All N releases of this game" table includes this release, marked, not linked; Similar releases leaves out every
  release of the same game.
- The details page's IGDB and Website buttons are links (`<a>`), not buttons, with `target="_blank"` and
  `rel="noopener noreferrer"`.
- The release name is the details heading on every release page; the game name sits under it, never above it.
- Copy NZB link is TV's (TV `SPEC.md` appendix A): the v1 `t=get` URL with the user's API key.

## Appendix B. Rejected: do not bring back

- A Password filter on any release list.
- Filters on Books ("for now"); filters derived from release names.
- The "About the game" aside beside the tabs; a summary box on the Overview tab.
- The game's name as the details page's big heading; a "This release" (or any similar) section heading.
- A covers view or wall; the Cards view; Name and Grabs sorts; a page-size choice; Report and Details row buttons; Files
  and Grabs list columns.
- PC-specific features of any kind.
