# NNTmux generic release lists: specification

Written 2026-10-09 from the maintainer's design review of that day and from read-only measurements on a restored
production catalogue. `DATA-NOTES.md` holds the measured facts. The maintainer's clickable prototype is the visual and
behavioural reference: where this document and the prototype disagree about how something looks or behaves, the
prototype wins. A sanitized copy of it, with an invented dataset and placeholder pictures, is in `prototype/`
(`releases.html`, one page for the four lists and the release details page).

Status: the four **generic release lists** (All releases, a group's releases, a poster's posts, the Other category) and
the release details page they open are **approved** (2026-10-09, "write the spec and file the issue"). Nothing in the
application has been changed. These lists read what the application already stores; no new storage is proposed, and the
reads are measured in `DATA-NOTES.md` section 3.

---

## 1. Scope

| Screen | Address in the app (unchanged) | Route in the prototype | Replaces | Status |
|---|---|---|---|---|
| All releases | `/browse/all` | `releases.html#/`, `#/p/N` | today's All releases browser (Table) | approved |
| A group's releases | `/browse/all?group=<name>` | `#/group/<name>`, `#/group/<name>/p/N` | today's "Releases in <group>" | approved |
| A poster's posts | `/browse/all?poster=<identity>` and `/poster?name=<identity>` | `#/poster/<identity>`, `#/poster/<identity>/p/N` | today's "Posts by <identity>" page | approved |
| Other releases | `/browse/other` and `/browse/other/<10 or 20>` | `#/other`, `#/other/p/N`, `#/other/Misc`, `#/other/Hashed` | today's Other browser (Table) | approved |
| Release details, any release without a redesigned details page | `/details/<guid>` | `#/release/<id>` | today's details page for those releases | approved |

The addresses stay as they are: nothing in this design renames them, and the header's All menu ("Browse by group",
"All Releases") and Other menu ("All Other", "Misc", "Hashed") keep their targets. A group's list and a poster's list
are reached only from a row's group or poster chip (the maintainer's rule of 2026-09-30: the poster list is never linked
from navigation).

Out of scope: the group list page itself ("Browse by group", today's `/browsegroup`, unchanged); a picture or
thumbnail column (section 5.4); a covers view, cards view or wall; title pages; phone layouts (desktop only); the API and
RSS, which are frozen.

Hard constraints from the repository (`AGENTS.md`), unchanged from the earlier sections:

- **External API and RSS are frozen**, including additive changes. Everything here is web front end.
- **No new Artisan command without the maintainer's explicit approval of that specific command.** This spec proposes
  none.
- Colours route through the existing token layer.
- Per-user remembered choices (the sort and the list's dropdown filters) go in the existing view preferences
  (`users.view_prefs` via `User::releaseViewPreferences()`, the #881 rule), not a cookie.

---

## 2. What these lists are for

The maintainer's brief: "mock up a basic release listing that would be used when showing releases by group, all
releases in the system, releases by a specific poster, and releases in the 'other' category (e.g. misc and hashed)",
"using everything we've done in the frontend redesigns".

These lists have no section of their own and mix every category. Three facts shape them (`DATA-NOTES.md`): the
category is the fact that varies from row to row, so it gets its own column; most rows are hashed or misc junk with no
title entity (on production two in three releases are in the Other band), while a few rows are matched to a film or a
show, where the Movies / TV lists' entity line and Follow apply; and nothing has artwork in common (a 2:3 poster, a 16:9
picture, a square cover), so there is no picture column.

---

## 3. Structure

One list form in four contexts, each with its own heading and address, plus the release details page. There is no
titles switch, no wall, no title page.

---

## 4. Rules that apply to every screen

Every rule in TV `SPEC.md` section 2, Movies `SPEC.md` section 4, Adult `SPEC.md` section 4 and Books / Console / PC
`SPEC.md` section 4 applies ([`../tv-redesign/SPEC.md`](../tv-redesign/SPEC.md),
[`../movies-redesign/SPEC.md`](../movies-redesign/SPEC.md), [`../adult-redesign/SPEC.md`](../adult-redesign/SPEC.md),
[`../books-console-pc-redesign/SPEC.md`](../books-console-pc-redesign/SPEC.md)): never judge a release; never rank by
popularity; numbered pagination only, the page in the URL; only the release name is bold in a row; nothing shifts when
state changes; no instruction text; colour is wanted, a distinct low-key hue per chip kind with words, not icons; coral
only for the primary action (Download) and the on-state of a real mode (current page, a set filter), never a pressed
toggle; no Report button and no details button in rows; checkbox menus close on focus leaving, Escape and a click
outside; offsite links open in a new tab; no section headings that announce the obvious; an approved screen is frozen.

---

## 5. The lists (APPROVED 2026-10-09)

The Books / PC releases list (Books / Console / PC `SPEC.md` section 5) with the changes below.

### 5.1 Header

- The heading: **All releases**; **Releases in <group>** (the full group name, never shortened); **Posts by
  <poster identity>** (the identity as stored, "Bob <bob@home.mex>"); **Other releases**. Then the **name search**
  field (5.6), then on a poster's list the admin's Blacklist button (5.8), then the sort menu at the right.
- A group's list and a poster's list carry a breadcrumb above the heading: **All releases › Group** / **All releases
  › Poster**, the first part a link to `/browse/all`. It replaces today's "Clear filter" button. All releases and Other
  have no breadcrumb.
- A poster identity may be long (51 characters on production). The heading may break before "@" and "<" (the details
  page's rule for poster names) and the search, Blacklist button and sort wrap to a second row under it; nothing is cut.
- **Sorts:** Posted newest / oldest, Added newest / oldest, and **Name: A to Z** (today's generic list has it). Today's
  **Grabs · Most is dropped** (never rank by popularity). Default Posted newest first; remembered per user per root
  (`all` for the All, group and poster lists; `other` for Other).

### 5.2 The filters

- **One bar of two equal cells, Category and Completion**, each as wide as a cell of the Movie releases list's release
  bar. Name above value, `any` muted when unset, a set cell marked by a coral line under its value, the open cell lifted,
  OR within a menu and AND between menus, a change returns to page 1.
- **Category** on the All, group and poster lists lists the **root categories** that have a release in that list
  (Movies, TV, Audio, Books, Console, PC, Adult, Other), in the header bar's order, each as the root's label; a group's
  list offers only the roots it has. **"Exclude Other"** behaves exactly as on the other lists (Adult `SPEC.md` 5.2,
  #886): an item under "Any category", shown only while the menu lists Other and at least one other root; a mode, not a
  list of ids; the maintainer's wording. Sub-categories are not offered here: the header's per-section menus already
  open each section's list with a sub-category set.
- **Category on the Other list** lists **Misc** and **Hashed** (categories 10 and 20), in that order; no Exclude
  Other. The header's Misc and Hashed items open the Other list with that Category set (today's `/browse/other/10`
  and `/browse/other/20`).
- **Completion**: Any / 100% only / 95% or more, one choice.
- **No other filters.** No Password filter (the site setting decides whether passworded releases are listed at all,
  the maintainer's rule of 2026-10-01).
- **Remembered filters**: the two dropdown filters are remembered in the profile (the #881 rule) under the root: one
  set for the All, group and poster lists (root `all`), one for Other (root `other`); the name search is never
  remembered, nor the page; Clear all clears the remembered set too.

### 5.3 The pager line

As Books / PC: `Showing X–Y of N releases`, Clear all in a fixed slot hidden in place when nothing is set (it also
empties the name search), `Page X of Y` with fixed-width page text and the arrows; the bottom pager with numbers and
Go to page. 50 releases a page. With no result: "No releases match …" naming the filters and the search in words. On a
poster's list, the blacklist sweep status (5.8) sits in this line, between the count and Clear all.

### 5.4 The table

Columns, fixed widths: select box 34 · Release (auto) · **Category** (measured, 5.4.1) · Size 82 · Posted (or Added,
following the sort) 112 · buttons 96.

- **5.4.1 The Category column** reads **"Root > Sub"** ("TV > HD", "Movies > UHD", "Other > Hashed") in dim text on
  one line, never cut short. On the Other list it reads the sub-category alone ("Misc", "Hashed") in a 92 px column,
  as the Books list reads its sub-category. Elsewhere the column is as wide as the widest label the catalogue can
  produce, "Console > Xbox 360 DLC", measured at the column's font (13.5 px, the page's face, after the webfont has
  loaded) plus the cell padding (24 px): 160 px in the prototype. The root label is the header bar's ("PC", "TV",
  "Adult"), the sub-category the categories table's title.
- **No picture column**, and today's Thumbnails toggle is not carried: a mixed list has no common artwork shape, and
  two rows in three (Other) have none. Size in ink, the date dim, the date on one line. No Files, Grabs, Stats or
  Resolution column.

### 5.5 The release cell, buttons and selection

- The release name, bold, two lines at most, links to the release's details page.
- **The entity line** under the name, on a row matched to a title: a film's row shows **"Title · Year"** as a link to
  the film page (the Movies list's film line); a show's row shows **"Show · S01E07"** as a link to the show page (the
  episode tag of the stored episode link, as the TV list shows it; a stored episode without a season and episode number
  shows its first-aired date, as stored, and a release whose stored episode disagrees with its name is shown as stored,
  never corrected: the maintainer's rule, never judge a release); a tagged audio release shows **"Performer · Album"**
  as plain text (the Audio list's music line). A console release with a game shows the Console list's game line. No line
  otherwise.
- The chip line, in the TV / Movies / Adult order: completion (under 100%), Password, media info summary (opens the
  media info dialog), NFO, Preview, Sample, Clip (each opens its dialog, exactly as on the Adult list); then today's
  **Reported (N)** chip (a flag and the word, N when more than one report; opens the release page) and **Response**
  chip (a reply arrow and the word, when a public staff response exists; opens the release page); then the group and
  poster outline chips as one unit that never splits, each opening that group's or poster's list.
- **The chip naming the list's own context is left off**: a group's list shows no group chip, a poster's list no poster
  chip (every row would repeat the heading).
- Row buttons 2 × 2: Download (coral) and Copy NZB link (blue) on top, Cart (green) and **Follow** (violet, a bookmark)
  below; Follow only on a row matched to a film or a show (the Movies / TV rule), an empty slot otherwise.
- Selection as the other lists: a select box per row, select-all, the floating bar (Download NZBs, Add to cart, Clear
  selection).

### 5.6 The name search

As Books / PC `SPEC.md` 5.6: "Search release names", narrowing the list as the user types (today's 180 ms pause),
combining with the filters, returning to page 1, keeping focus and caret, with a clear button, emptied on Escape and
on Clear all, never remembered. The no-match line reads "No releases match names containing “…”".

### 5.7 The header bar

The header bar of #908, with the **All** button's menu (Browse by group, All Releases) and the **Other** button's menu
(All Other in bold, a separator, Misc, Hashed). The current section is underlined: **All** on the All, group and poster
lists, **Other** on Other. The search scope picker preselects All or Other to match.

### 5.8 A poster's list: the admin's blacklist action (today's feature, kept)

Shown to administrators only, as today (`poster-identity/index.blade.php`):

- **"Blacklist this poster"** beside the name search: a **tinted red button** (rose ground with rose text, the
  release-list buttons' form, hue 12; never a solid red and never coral, which belongs to Download). It opens the
  confirmation dialog.
- **The confirmation dialog** (680 px): title "Blacklist this poster", "Confirm the exact rule that will be saved.",
  then today's four facts: **Regex** (`^<identity>$`, every regex character escaped, in a monospace box), **Rule**
  ("Posted By · Type: Black · Status: enabled", today's wording), **Group scope** (`^(?:group|group)$` built from the
  groups the poster has posted to, monospace), **Description** ("Poster identity blocked from poster page by
  <admin>"); then the option **"Also permanently remove this poster's N existing releases now"** (N = the poster's
  release count, formatted) as a checkbox that tints its box red when ticked; then Cancel and **Confirm blacklist**
  (the tinted red button). Escape, the close button and Cancel close it without a rule.
- **After Confirm**: the button becomes the muted **"Blacklisted (rule #N)"** (a link to the rule in the admin
  blacklist, as today). With removal not ticked, a notice "Rule #N added · sweep not started". With removal ticked, no
  notice; the **sweep status** sits in the pager line (5.3), "Rule #N added · sweep running. Removing this poster's N
  releases…" and, when the sweep has finished, "Rule #N added · sweep finished. N releases by this poster were
  removed."; the list then shows no rows and reads "No releases remain: the blacklist sweep removed them." Nothing
  moves when the status appears (it was a panel above the filter bar in a first build; the reviewer's fix).
- A poster already blacklisted opens with the muted "Blacklisted (rule #N)" in the button's place, as today.

### 5.9 Empty states

A group or poster with no listed release: the heading and bar as usual, "Showing 0 releases", and the no-match line.

---

## 6. The release details page (APPROVED 2026-10-09)

The Books / PC release details page (Books / Console / PC `SPEC.md` 5A) for every release that has no redesigned
details page of its own, with:

- the breadcrumb **"<list heading> › Root > Sub"** (the list the page was opened from: "All releases", "Other releases",
  "Releases in <group>", "Posts by <poster>"; "All releases" when opened from elsewhere), the first part a link back;
- the entity line (5.5) under the name, and **Follow film / Follow show** in the button row on a matched row (the
  Movies / TV details form);
- the chip line (5.5) with the Reported and Response chips; on a reported release the Overview opens with a note in
  the sweep status's form (panel ground, the Reported hue on the flag and the bold words only): "Reported N times ·
  Under review." or "· A staff response was posted.";
- the Preview and Sample pictures at the top of Overview when the release has them (the Adult form);
- the facts grid with **Category "Root > Sub"**, Group and Poster;
- the Media info tab only when the release has media info;
- Similar releases (today's first-two-words rule within the same root, newest first, at most 50).

---

## 7. Data

No new storage. Every value the lists read is stored today and read by today's generic list (`ReleaseBrowserQuery`):
the release row, its category and root, group name, poster identity, completion and repair state, password status,
NFO / preview / sample / clip flags, media info summary, the film / show / episode / audio-tag links, report counts
and public responses, the cart. `DATA-NOTES.md` section 3 measures every list read at full catalogue size.

---

## Appendix A. Easy to miss

1. The Category column's width is measured once from "Console > Xbox 360 DLC" at the column's font after the webfont
   has loaded; measured before, the fallback font gives a different width.
2. A finished blacklist sweep removes the poster's releases from every list (the All, group and Other counts go down
   too), not only from the poster's list.
3. The Category menu of a group's list offers only the roots that group has; a remembered root the list does not have
   is ignored there and kept for the next list that has it.
4. "Exclude Other" is a mode: when a remembered set is exactly every root but Other, the cell reads "Exclude Other".
5. On the Other list the Category column is the sub-category alone and 92 px; everywhere else "Root > Sub".
6. The entity line of a show is the stored episode's tag as stored (S01E07, or a first-aired date when the stored
   episode has no number), never derived from the name.
7. The Reported chip is teal-green (hue 170) and the Response chip blue (hue 245), the only two hues no other chip
   wears beside them; orange (reads as the amber completion chip) and blue for Reported (reads as the violet Media
   info chip) were tried and rejected by the finish reviewer.
8. The Blacklist and Confirm buttons are rose (hue 12), not the coral of Download (hue 32).
9. The sweep status in the pager line is the only place the sweep is reported; no toast doubles it.
10. Name A to Z sorts the whole catalogue by name (2.1 s on 2.36M releases, `DATA-NOTES.md` 3); it is today's sort,
    at today's cost.

## Appendix B. Not carried from today's lists, by the approved rules

The Files and Stats (grabs, comments) columns; the Category chip (now the column); per-page 24 / 48 / 100 (50 fixed);
the Table view switch and the Thumbnails toggle; the Details and Report row buttons; the Grabs · Most sort; "Clear
filter" (now the breadcrumb); today's "Browse" breadcrumb.
