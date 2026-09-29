# NNTmux Adult section: specification

Written 2026-09-29 from the maintainer's design review session of that day (what he approved,
rejected and decided, with his reasons) and query-lab measurements on a restored production
catalogue. `DATA-NOTES.md` holds the measured facts; `INVENTORY.md` lists every feature of
today's Adult screens with `path:line`. The maintainer's clickable prototype is the visual and
behavioural reference: where this document and the prototype disagree about how something looks
or behaves, the prototype wins. A sanitized copy of it, with an invented dataset and placeholder
art, is in `prototype/`.

Status: the **Adult releases** list and the **Adult release details** page are **approved**
(2026-09-29: "ok, I approve the adult pages"). Nothing in the application has been changed. How
each value is stored and read, with every query measured at full catalogue size, is the data
contract that follows this document.

---

## 1. Scope

| Screen | Route in the prototype | Replaces | Status |
|---|---|---|---|
| Adult releases (list) | `#/`, `#/p/N` | today's Adult browse page (`browse/xxx`: Table, Cards and Covers views) | approved |
| Release details (Adult) | `#/release/<id>` | today's details page, for adult releases | approved |

Out of scope, by his decision: a covers view or wall ("It doesn't need a "covers" or "wall" view. At
least not yet."); a title page (adult releases have no title entity); phone layouts (desktop only);
the rest of the site.

Hard constraints from the repository (`AGENTS.md`), unchanged from TV and Movies:

- **External API and RSS are frozen**, including additive changes. Everything here is web front end.
- **No new Artisan command without the maintainer's explicit approval of that specific command.**
  This spec proposes none.
- Colours route through the existing token layer.
- Per-user remembered choices (the sort and, by his decision of 2026-09-29, the list's dropdown
  filters, section 5.2) go in the existing view preferences (`users.view_prefs` via
  `User::releaseViewPreferences()`), not a cookie.

Related issues filed from this design session: **#879** (the VR category exists on every install;
OnlyFans is dropped and its releases refiled), **#880** (single-release pages and downloads, and the
`adult` / `music` / `other` browse aliases, respect a user's hidden categories), **#881** (the TV and
Movie release lists remember each user's last dropdown filters; this section follows the same rule).

---

## 2. What Adult is for

In his words: "Generally, I want the adult section to follow the same format that we've done for the
tv and movie releases views. It doesn't need a "covers" or "wall" view. At least not yet."

Adult releases carry **no title, performer or studio data** anywhere: the old adult metadata table
was dropped upstream in February 2026 (`INVENTORY.md` section 6). A release has its name, size,
dates, sub-category, completion, media info, and sometimes a preview frame, a sample image and a
30-second clip taken from its files. The screens are built from those alone.

---

## 3. Structure

Two screens, as in TV and Movies without their title screens: a releases list and a release
details page. There is no Releases / titles switch, no wall, no title page, no Follow.

---

## 4. Rules that apply to every screen

Every rule in TV `SPEC.md` section 2 and Movies `SPEC.md` section 4 applies
([`../tv-redesign/SPEC.md`](../tv-redesign/SPEC.md), [`../movies-redesign/SPEC.md`](../movies-redesign/SPEC.md)):
never judge a release; never rank by popularity; numbered pagination only, the page in the URL;
only the release name is bold in a row; nothing shifts when state changes; no instruction text;
colour is wanted, a distinct low-key hue per chip kind with words, not icons; coral only for the
primary action (Download) and the on-state of a real mode (current page, a set filter), never a
pressed toggle; no Report button and no details button in rows; checkbox menus close on focus
leaving, Escape and a click outside; offsite links open in a new tab; no "Same name posted more
than once" line; an approved screen is frozen.

What does not apply, because an adult release has no title entity: the Follow button and its
wording, the title line under a release name, the same-title batch expander, the title page, the
"About the …" aside, "All N releases of this …", and the title filters (genre, year, score, rating,
language).

---

## 5. Adult releases (APPROVED 2026-09-29)

The Movie releases list (Movies `SPEC.md` section 5) without its film parts, with the changes below.

### 5.1 Header

- **Adult releases** heading, then the **name search** field (5.9), then the sort menu at the right.
- The four sorts of the TV and Movies lists: Posted newest / oldest, Added newest / oldest; default
  Posted newest first; remembered per user as today.

### 5.2 The filters

- **One bar of four equal cells: Category, Resolution, Audio, Completion.** Each cell is as wide as a
  cell of the Movie releases list's release bar, so the bar is narrower than half the row. Name above
  value, `any` muted when unset, a set cell marked by a coral line under its value, the open cell
  lifted, OR within a menu and AND between menus, a change returns to page 1 (Movies `SPEC.md` 5.1).
- **No Source filter** (his call 2026-09-29: "remove the source filter"; source is known for 4% of adult
  releases, `DATA-NOTES.md`).
- **Category** lists today's adult sub-categories present in the data, in the site's order: DVD, WMV,
  XviD, x264, HD Clips, SD Clips, UHD, VR, Packs, Imageset, SD, WEBDL, Other. More than ten, so the
  menu searches inside itself. **VR** is a real sub-category on every install and **OnlyFans** is gone
  (#879).
- **"Exclude Other"** (his request and wording, 2026-09-29, on every release list: Adult, TV and Movies):
  an item under "Any category", then a separator, then the sub-categories. Picking it ticks every
  category but Other; the cell then reads **Exclude Other** (its tooltip "Category: Exclude Other");
  picking it again clears the category filter; ticking Other as well turns the cell into "N chosen". It is a mode,
  not a list of ticked ids: remembered, it keeps meaning every category but Other, including a sub-category that gains
  its first release later (`DATA-CONTRACT.md` 4.1). Ticking by hand every category but Other is the same mode: the cell
  reads "Exclude Other" and the URL and the remembered filters carry the mode, not the ids (his decision, 2026-09-29).
  The item shows only while the menu lists Other and at least one other category (his decision, 2026-09-29). While it
  is not shown (the user hides Other under Account → Appearance, or the menu has no Other, or only Other), a remembered
  or linked Exclude Other sleeps: it filters nothing, the cell reads "any", it counts as no filter for the Showing line
  and Clear all, and it stays in the URL and the remembered filters until the Category menu changes or Clear all; when
  the item shows again it applies again (his decision, 2026-09-29). It never resolves to an empty list of categories.
  The empty-result line names it "excluding Other". Why: Other holds 55% of the adult catalogue, mostly
  unprocessed or passworded posts, and "Manually checking every single category except Other is a poor
  user experience". His wording is binding: never "All except Other" or "Except Other".
- **Resolution**: 4K, 1080p, 720p, SD, Unknown, with the resolution chip colours.
- **Audio** (kept, his pick over removal): the languages of a release's audio tracks, English first,
  then A to Z, then Unknown (#868). 14% of adult releases have a known language (`DATA-NOTES.md`).
- **Completion**: Any / 100% only / 95% or more, one choice.
- **Remembered filters** (his decision 2026-09-29): the list remembers the user's last dropdown
  filters (these four cells) in the profile, so they follow the user to any browser, and applies
  them when the list is opened with no filter in the URL. The name search is never remembered, nor
  the page. Clear all clears the remembered set too. The TV and Movies lists get the same rule (#881).

### 5.3 The pager line

`Showing X–Y of N releases`, Clear all in a fixed slot hidden in place when nothing is set (it also
empties the name search), `Page X of Y` with fixed-width page text and the arrows; the bottom pager
with numbers and Go to page. 50 releases a page. With no result: "No releases match …" naming the
filters and the search in words.

### 5.4 The table

Columns, fixed widths: select box 34 · picture 196 · Release (auto) · Resolution 100 · Size 82 · Posted
(or Added, following the sort) 112 · buttons 96. **No Source column** (his call 2026-09-29: "we can get
rid of the source column in the adult section"). No Files or Grabs column. Size in ink, the date dim,
the date on one line.

### 5.5 The release cell

- The release name, bold, two lines at most, links to the release's details page.
- The chip line, in the TV / Movies order: completion (under 100%), Password, media info summary (opens
  the media info dialog), NFO, Preview, Sample, **Clip** (5.10); then the group and poster outline
  chips as one unit that never splits, same-tab links to the all-releases lists. The unit wraps to its
  own line before the poster name would be cut below 96 px (the wider picture column leaves less room).

### 5.6 The picture

- **The whole frame, 176 × 99** (16:9), in the 196 px column (his pick A, 2026-09-29, over the TV /
  Movies 88 × 132 poster slot, which crops about two thirds of a preview and most of a sample).
- It shows the release's **preview** frame, else its **sample** image (today's order,
  `ReleaseCoverBrowser::adult()`), else a **"No picture"** tile: an image icon and the words, no fill,
  no shadow, a **dashed outline** like the Unknown resolution chip (his pick B, 2026-09-29, over the
  raised grey Movies tile: 55% of the section has no picture, so the empty boxes must not outweigh the
  real ones).
- **Clicking the picture opens its image dialog** (Preview, or Sample when there is no preview) and
  stays on the list (his words: "clicking on the artwork in the releases view needs to open the preview
  dialog, not the release details"). Ctrl-, Cmd- and Shift-click follow the picture's link to the
  details page as a browser does. The picture is skipped by keyboard and screen readers (the Preview /
  Sample chip is the accessible way to the same dialog); closing the dialog returns focus to that
  chip. The "No picture" tile links to the details page.

### 5.7 Row buttons

Download (coral) and Copy NZB link (blue) on top, **Cart** (green) alone under Download: there is no
Follow. A pressed Cart fills in its own green, never coral.

### 5.8 Selection

As TV / Movies: a select box per row, select-all for the visible rows, the floating bar
`N selected · Download NZBs · Add to cart · Clear selection`.

### 5.9 Name search

Kept from today's "Search in Adult" box (his pick, 2026-09-29: Adult has no titles or performers
stored anywhere, so a name search is the only way to look for something specific). This is a
deliberate difference from TV and Movies, whose section search finds shows or films and people and
whose lists rejected an in-list text search.

- A field "Search release names" in the TV / Movies search field's size and place, beside the heading.
- It narrows the list to releases whose name contains what was typed, as typed, after today's 180 ms
  pause (`release-browser-component.js`); it combines with the filters; the list returns to page 1;
  focus and caret stay in the field.
- A clear button (×) inside the field empties it (it keeps its place when hidden); Escape in the field
  empties it; Clear all empties it too.
- Never remembered (5.2).

### 5.10 The Clip chip and dialog

- A release with a video clip (today's `videostatus = 1`; today's player also plays an older clip that has no
  `release_video_clips` row) shows a **Clip** chip, last in the chip line, in **magenta 305** (his pick of three built, 2026-09-29):
  dark theme fill `oklch(0.31 0.085 305)`, text `oklch(0.87 0.09 305)`; light theme fill
  `oklch(0.93 0.03 305)`, text `oklch(0.40 0.17 305)`. Rejected: teal-green 172 (looked like Preview
  beside it), orange 45 (near the coral Download colour, and read like a warning beside a red
  completion chip).
- The chip opens a **Video clip** dialog that plays the clip (today's player route, `preview.video`),
  with the release name under the title; Escape, the close button or a click outside close it and
  focus returns.

---

## 5A. Release details (APPROVED 2026-09-29)

The Movies details page's form for a release with no film (Movies `SPEC.md` 5C.6): the release name is
the heading, the page is full width, there is no aside.

### 5A.1 Header

- Breadcrumb: `Adult releases › <sub-category>`.
- The release name as the heading.
- The chips: the resolution chip, then the chip line of 5.5 (completion, Password, media info, NFO,
  Preview, Sample, Clip). **No source chip** (his call, 2026-09-29).
- The group and poster outline chips on their own line.
- Buttons: **Download NZB** (coral), **Copy NZB link** (neutral), **Add to cart** (neutral; pressed it
  reads "In cart" and fills green without changing width). No Follow.

### 5A.2 Tabs

Overview, Files (N), Media info, NFO, Comments (N), as TV and Movies. When the release has no stored
file count the tab reads **Files** without a number and the facts grid shows "—", never 0.

### 5A.3 Overview

- **The pictures**, side by side, each at 220 px high: the preview, then the sample.
  - **A preview whose release has a clip plays the clip**: clicking it opens the same Video clip dialog
    as the Clip chip (his ruling: "if you click the preview image, the clip should show in a dialog the
    way it should be if you click the clip chip on the releases view"). There is **no separate clip
    box** (rejected in both a magenta and a neutral version: he asked why the clip should be in a box
    separate from the preview at all).
  - **The preview says it plays a clip** (his request: "there needs to be some sort of indication on the
    preview image that there is a clip that can be played"): a 56 px round play button in the middle of
    the picture (dark translucent ground, white icon; magenta 305 on hover and keyboard focus) and a
    magenta **Clip · N s** tag in the bottom-right corner, in the dark chip values in both themes
    because it sits on the picture; **Preview** stays in the bottom-left. Its accessible name starts
    with the visible word: "Preview, play the N-second video clip".
  - A preview without a clip opens the preview image dialog.
  - **The sample opens straight at full size** (his words: "clicking a "sample" image should go straight
    to the full size view and skip the small dialog"): the image dialog opens in its Full size state when
    the image is larger than the dialog; it appears only once it is laid out at full size. Only 392 of
    2,589 adult samples on production have a full-size copy; the rest are 650 px images that already
    show at their own size. The Preview and Sample chips keep the fitted dialog.
- **The facts grid**: Category, Size, Files, Completion, Posted, Added, Grabs, Group, Poster, Password
  status.
- **PreDB** block, when the release has a match (title, source, pre date, category).

### 5A.4 Similar releases

Today's query (`ReleaseSearchService::searchSimilar()`, fixed for every root by #859): the first two
words of the release name matched in the search index's release names, within the adult categories,
newest posted first, at most one page of 50, without this release. The table is the Movies details
page's Similar releases table without a Source column: Release (name and chips), Resolution, Size,
Files ("—" when not stored), Posted, and the 2 × 2 buttons without Follow. Sortable by Resolution, Size
and Posted. No section when there is no match.

---

## 6. Decisions taken before the screens were designed

### 6.1 Today's features

Every feature of today's Adult screens is in `INVENTORY.md`. Carried: the list itself, the release name
link, every chip (with Clip added as its own chip), group and poster chips, selection and the bulk bar,
the name search, the preview / sample picture, the details page's tabs, pictures, clip, PreDB and
Similar releases. Dropped the way TV and Movies dropped them (told to him 2026-09-29): the Cards and
Covers views, the Name and Grabs sorts, the page-size choice, the posted-year filter, the Report and
Details row buttons, the Files and Grabs list columns, the thumbnails toggle, the details page's "users
reported download failure" line and "Edit release" button.

### 6.2 Categories

VR stays and must exist on every install; OnlyFans is dropped (his words: "Keep the VR category and
create it, drop the onlyfans category"). Today's sorter without its OnlyFans rule files production's 30
OnlyFans releases as 15 HD Clips and 15 Other; the prototype shows the lab's 28 there (15 and 13).
Filed as #879.

### 6.3 Access

A user who has Adult (or any category) hidden gets the "hidden in your account preferences" page from
every single-release web page and download, and the `adult` browse alias gets the same permission check
as `xxx`. Filed as #880; the API and RSS stay as they are.

---

## 7. Data the design needs

To be proven in the data contract at full catalogue size before any build issue:

- The list's reads: the adult band newest first by posted and by added, page 1 and the last page; each
  filter alone and combined (Category including Exclude Other, Resolution, Audio, Completion); the
  name search on its own and with filters, including a word that matches nothing.
- The Clip chip: whether a release has a clip, per row, without a per-row query.
- Remembered filters: stored beside the sort in `users.view_prefs` (the #881 rule).
- Similar releases: today's search-index query, unchanged.

---

## 8. Open items

None on the screens. Next: the data contract (section 7), then the build issues under a map issue. The
maintainer will look at the Movies > Other categorisation in a separate session.

---

## Appendix A. Details an implementer needs that are easy to miss

- The picture column is 196 px, not the TV / Movies 116 px; the frame is 176 × 99 with the frame's own
  aspect, `object-fit: cover` (a 2:1 sample loses only its edges).
- A row picture's click opens the image dialog; a modified click follows the link; the "No picture"
  tile is a plain link to details.
- The dashed "No picture" tile has no fill and no shadow in both themes; the Movies "No poster" tile
  keeps its look.
- The Category menu's separator under "Exclude Other" must be visible on the raised menu ground in the
  dark theme (the page's line colour matches that ground); on the TV list the Category cell cuts
  "Exclude Other" to fit, by his choice, with the full text in the tooltip.
- Clear all empties the name search as well as the filters; the name search's × does not touch the
  filters.
- The Clip tag on the details preview keeps the dark chip values in the light theme.
- The details preview's click plays the clip only when the release has one; the Sample's click opens
  at full size only on the details page's Overview.
- Files "—" when not stored, on the details tab label, the facts grid and the Similar releases table.
- The breadcrumb names the sub-category; VR releases read "VR".
- Copy NZB link is TV's (TV `SPEC.md` appendix A): the v1 `t=get` URL with the user's API key.

## Appendix B. Rejected: do not bring back

- A covers view or a wall for Adult (for now: "at least not yet"); the Cards view; the thumbnails toggle.
- The 88 × 132 poster slot for adult pictures (picture B).
- A Source column, a Source filter and a source chip on the details page.
- A separate Clip box beside the preview on the details page, in a magenta or a neutral fill.
- The Clip chip in teal-green (172) or orange (45).
- The raised grey "No picture" tile.
- The wording "All except Other" / "Except Other".
- Name and Grabs sorts; a page-size choice; the posted-year filter; Report and Details row buttons;
  Files and Grabs list columns.
- An OnlyFans sub-category.
