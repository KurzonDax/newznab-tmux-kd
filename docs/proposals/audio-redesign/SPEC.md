# NNTmux Audio section: specification

Written 2026-10-04 from the maintainer's design review session of that day (what he approved, changed and decided, with
his reasons) and from read-only measurements on production. `DATA-NOTES.md` holds the measured facts; `INVENTORY.md`
lists every feature of today's Audio screens with `path:line`. The maintainer's clickable prototype is the visual and
behavioural reference: where this document and the prototype disagree about how something looks or behaves, the
prototype wins. A sanitized copy of it, with an invented dataset, placeholder covers, one placeholder tone and one
placeholder spectrogram, is in `prototype/` (`audio.html`).

Status: the **Audio releases** list and the **Audio release details** page are **approved** (2026-10-04: "ok, I approve
the audio pages"). Nothing in the application has been changed. How each value is stored and read, with every query
measured at full catalogue size, is the data contract that follows this document (`DATA-CONTRACT.md`).

---

## 1. Scope

| Screen | Address in the app | Route in the prototype | Replaces | Status |
|---|---|---|---|---|
| Audio releases (list) | **`/audio`** | `audio.html#/`, `#/p/N` | `/browse/audio` and its alias `/browse/music` (Table, Cards, Covers) | approved |
| Release details, a release whose tags name an album | `/details/<guid>` | `#/release/<id>` | today's details page, for audio releases | approved |
| Release details, a release with no album | `/details/<guid>` | `#/release/<id>` | today's details page, for audio releases | approved |

The address follows the sections already built (`/adult`, `/books`, `/console`, `/pc`) and the root's own name. The
header's Audio drop-down points its "All Audio" item at the new list and each sub-category item at the new list with that
Category set, as every redesigned section does. The old pages go once the new screens are built, with no bookmark
redirect (his rule: a retired address that nothing in the app links to any more is deleted):

- `/browse/audio`, `/browse/music` and `/browse/audio/<sub-category>`;
- the legacy `/Audio/<sub-category>` redirect (`MusicController`);
- the album title page `/title/audio/<id>` (`INVENTORY.md` section 2), whose track list, links and format labels move onto
  the release page (5B) and "All N releases of this album" (5B.5).

What links to them is repointed or removed (his rule: "we don't leave things just because they're linked from somewhere
else. We update those links so that they point to the new pages"): the header items; the album title chip that shared
lists (All releases, search results, the basket, poster lists, the home page) show on an audio release from the old
`musicinfo` match is **removed**, as the Books and PC chips were (his words of 2026-10-01: "forget the fucking chip for
music and PC releases and book releases"), since the album it names comes from the retired iTunes match and is often
wrong (`DATA-NOTES.md`); the old details page's "Other releases of this title" aside goes with the old details page for
audio releases.

Out of scope: a covers view or wall, an album page, Follow; storing album art (it belongs to the unfinished MusicBrainz
integration, 6.2); new metadata lookups; the categoriser (6.1); phone layouts (desktop only); the rest of the site; the
admin music pages, which stay as they are.

Hard constraints from the repository (`AGENTS.md`), unchanged from TV, Movies, Adult, Books, Console and PC:

- **External API and RSS are frozen**, including additive changes. Everything here is web front end; the audio data the
  design reads is never added to an API or RSS response.
- **No new Artisan command without the maintainer's explicit approval of that specific command.** This spec proposes
  none.
- Colours route through the existing token layer.
- Per-user remembered choices (the sort and the list's dropdown filters) go in the existing view preferences
  (`users.view_prefs` via `User::releaseViewPreferences()`, the #881 rule), not a cookie.

---

## 2. What Audio is for

The maintainer's brief (2026-10-04): "Based on the work we did with the other categories we've already worked on, can
you take your best stab at mocking up what the audio page(s) should look like? ... Reuse components wherever possible.
Try not to ask me a ton of questions. Instead make judgement calls based on evidence from the previous redesign work."

What production holds (`DATA-NOTES.md`): about 3,700 audio releases. The old album match (`musicinfo`, iTunes) has not
been written since #372 retired it, so today's Covers view, title chip, title page and Year / Genre / Label filters only
reach a few hundred old rows, many of them the wrong album. The music data that is written today is the **tags of the
previewed audio file** (`release_audio_tags`: artist, album, genre, year, format), a **30-second preview** and a
**spectrogram** of it, the **track list** read from the release's files (`release_audio_evidence_tracks`), and, for a few
releases, an accepted **MusicBrainz** identity. The screens are built from those.

---

## 3. Structure

Two screens, as Console: a releases list and a release details page. The details page of a release whose tags name an
album borrows the Movies **film page's** layout while staying a release page, exactly as Console's game release page
does (5B); a release with no album keeps the release-details form of Books and PC (5A). There is no titles switch, no
wall, no album page, no Follow.

---

## 4. Rules that apply to every screen

Every rule in TV `SPEC.md` section 2, Movies `SPEC.md` section 4, Adult `SPEC.md` section 4 and Books / Console / PC
`SPEC.md` section 4 applies ([`../tv-redesign/SPEC.md`](../tv-redesign/SPEC.md),
[`../movies-redesign/SPEC.md`](../movies-redesign/SPEC.md), [`../adult-redesign/SPEC.md`](../adult-redesign/SPEC.md),
[`../books-console-pc-redesign/SPEC.md`](../books-console-pc-redesign/SPEC.md)): never judge a release; never rank by
popularity; numbered pagination only, the page in the URL; only the release name is bold in a row; nothing shifts when
state changes; no instruction text; colour is wanted, a distinct low-key hue per chip kind with words, not icons; coral
only for the primary action (Download) and the on-state of a real mode, never a pressed toggle; no Report button and no
details button in rows; checkbox menus close on focus leaving, Escape and a click outside; offsite links open in a new
tab; no section headings that announce the obvious; a release page stays a release page (the release name is its
heading); an approved screen is frozen.

---

## 5. The releases list (APPROVED 2026-10-04)

The Console releases list (Books / Console / PC `SPEC.md` section 5) on the Audio band, with the changes below.

### 5.1 Header

- The heading **Audio releases**, then the **name search** field (5.6), then the sort menu at the right.
- The four sorts of the earlier lists: Posted newest / oldest, Added newest / oldest; default Posted newest first;
  remembered per user (today the Audio sort is not saved, `INVENTORY.md` 7.11).

### 5.2 The filters

- **Two panels of two equal cells**, as Console: the release panel **Category · Completion**, then the music panel
  **Genre · Year**. Each cell is as wide as a cell of the Movie releases list's release bar. Name above value, `any`
  muted when unset, a set cell marked by a coral line under its value, the open cell lifted, OR within a menu and AND
  between menus, a change returns to page 1.
- **Category** lists the band's sub-categories that hold a release, in the site's order (MP3, Video, Audiobook,
  Lossless, Podcast, Foreign, Other). **"Exclude Other"** exactly as on every other list (#886): shown while the menu lists
  Other and at least one other sub-category; a mode, not a list of ids.
- **Completion**: Any / 100% only / 95% or more, one choice.
- **No other filters.** Today's Label filter goes (only old iTunes rows have a label); the hand-typed Artist URL filter
  goes (the name search finds artists). No Password filter (the site setting decides, Books / Console / PC 6.2).
- **Remembered filters**: the four dropdown filters are remembered in the profile (#881); the name search and the page
  never are; Clear all clears the remembered set too.

### 5.3 The pager line

As Console: `Showing X–Y of N releases`, Clear all in a fixed slot hidden in place when nothing is set (it also empties
the name search), `Page X of Y` with fixed-width page text and the arrows; the bottom pager with numbers and Go to page.
50 releases a page. With no result: "No releases match …" naming the filters and the search in words.

### 5.4 The table

Columns, fixed widths: select box 34 · **cover 110** · Release (auto) · **Category 128** · **Genre 150** · Size 82 ·
Posted (or Added, following the sort) 112 · buttons 96.

- "Release" spans the cover and the name, as on Movies and Console.
- Category in dim text on one line, never cut short; Genre (5.8); Size in ink; the date dim, on one line. No Files or
  Grabs column.

### 5.5 The release cell, buttons and selection

- The release name, bold, two lines at most, links to the release's details page.
- **The music line** under the name when the release's tags name an album or an artist: `Artist – Album · Year` in dim
  13.5 px text, plain text, not a link (there is no album page). The artist is the tag's album artist, else its
  performer. Parts the tags lack are left out; no line when neither artist nor album is tagged.
- The chip line, in the TV / Movies order: completion (under 100%), Password, media info summary (opens the media info
  dialog), NFO, then **Listen** (5.10) where Adult's Clip sits; then the group and poster outline chips as one unit that
  never splits. The media info summary of an audio-only release reads codec and channels, e.g. `FLAC 2.0`.
- Row buttons: Download (coral) and Copy NZB link (blue) on top, Cart (green) alone under Download; no Follow.
- Selection as every list: a select box per row, select-all, the floating bar.

### 5.6 The name search

As Console (Books / Console / PC 5.6): a field beside the heading that narrows the list as the user types (today's
180 ms pause), combines with the filters, returns to page 1, keeps focus and caret, has a clear button, empties on Escape
and on Clear all, and is never remembered. **"Search releases, artists or albums"**: it matches the release name **or the
tags' album, album artist or performer**, as typed (today's Covers search matched an album's title or artist,
`INVENTORY.md` 1). The no-match line reads "No releases match release names, artists or albums containing “…”".

### 5.7 The cover

- A square slot first under "Release": an **88 × 88** tile (album art is square, as Adult kept its picture's own 16:9
  shape) in a 110 px column. It links to the release's details page and is skipped by keyboard and screen readers, as on
  Movies and Console; images load at once.
- A release without a cover shows the **Adult "No cover" tile**: dashed outline, no fill, no shadow, a disc icon over
  "No cover" (Adult's rule for a column most rows lack: the empty boxes must not outweigh the real ones).
- **Where covers come from (his decision, 6.2):** the unfinished MusicBrainz integration. Nothing stores an audio cover
  that can be trusted today, so until that integration stores one **every row shows the No cover tile**; the prototype's
  covers are placeholders standing in for it.

### 5.8 The Genre column and menu

- **Genre column**: the release's tag genres as one comma list (dim, wrapping, at most four lines, the full list on
  hover); "—" when the tags give none.
- A tag genre is taken **as written**; a multi-value tag ("Rock; Pop", the separator ID3 and Vorbis comments use) is
  several genres; no other cleaning (the maintainer's rule for display values: one structural rule, no junk filters).
- **Genre menu**: the genres the band's releases have, A to Z ignoring case, then **Unknown** for releases with no genre
  tag. A tag that literally reads "Unknown" is that item, not a genre of its own. OR within the menu; it searches inside
  itself (over ten options); each option sits on one line.

### 5.9 The Year menu

The Console / Movies Year menu as it is: Any year; **decades** ticked together; a typed From–To range with the Movies
bounds (1900 to the current year) and its error "Years run 1900–<year>, earliest first". **Decades 2020s back to the
1940s** (his call: "I think it probably needs to go back to the 1940s"); nine decades fill the menu's three-column grid
exactly. The year is the tags' recorded year. A release with no tagged year drops out when a year is set.

### 5.10 The Listen chip and dialog (lists)

- A release with a preview (`release_audio_tags.has_preview`) shows **Listen** (today's word, `INVENTORY.md` 4), last in
  the chip line where Adult's Clip chip sits, in the **Clip chip's magenta 305**: it is the same kind of chip, a preview
  that plays.
- It opens the **Listen dialog**, 560 px wide: the title "Listen" and the release name; the release's cover (120 px)
  when it has one; the track's title tag in ink, the artist under it in dim; the browser's own audio player, which plays
  at once. Escape, the close button and a click outside close it and stop the sound; focus returns to the chip. (Today's
  preview modal holds the same parts, `INVENTORY.md` 4.)
- On the release details page itself the chip is not shown (5C.1).

---

## 5A. Release details, a release with no album (APPROVED 2026-10-04)

The Books / PC release-details page (Books / Console / PC `SPEC.md` 5A):

- Breadcrumb `Audio releases › <sub-category>`; the release name as the heading, full width, no aside, no cover.
- The chip line of 5.5 **without Listen** (5C.1), the group and poster chips on their own line; buttons **Download NZB**
  (coral), **Copy NZB link**, **Add to cart** (pressed it reads "In cart" and fills green without changing width), and
  **MusicBrainz** (5B.4) when the release has an accepted identity. No Follow.
- **Tabs**: Overview, **Tracks (N)** only when a track list is stored (5C.2), Files (N), **Media info** only when the
  release has media info, NFO, Comments (N). A remembered tab that is absent falls back to Overview.
- **Overview**: the preview (5C.1) when the release has one, then the facts grid (Category, Genre, Size, Files,
  Completion, Posted, Added, Grabs, Group, Poster, Password status; Genre reads the tag genres or "—"), then the PreDB
  block when the release has a match.
- **Similar releases**: today's query, within the band, newest posted first, at most 50, without this release; the table
  of Books / Console / PC 5A. No section when nothing matches.

## 5B. Release details, a release whose tags name an album (APPROVED 2026-10-04)

Console's game release page (Books / Console / PC `SPEC.md` 5B) with the album in the game's place:

1. **Breadcrumb** `Audio releases › <sub-category>`.
2. **The cover** at the left, **200 × 200** (square), the text column beside it, 640 px at most. Without a cover: the
   film page's placeholder tile in the same square, a disc icon, the album name and the year (until covers are stored,
   5.7, every album page shows it).
3. **The release name** as the heading at the release-details size (24 px), wrapping anywhere; under it **the music
   line**: `Artist – **Album** · Year · <sub-category>`, the album in ink semibold (Console bolds the work, the game), the
   rest dim.
4. The release's chip line **without Listen** and the group and poster chips; then **tags**: the genres as filled tags
   that are links, each opening the list on **that genre alone** (the other dropdown filters and the name search
   cleared); then an outlined **format tag** (the tags' audio format: FLAC, WavPack, DSD, …) only when it adds to what the
   sub-category and the media info chip already say. Then an info line in the film page's "Directed by" style: **Tracks**
   `N · <total length>` (the length only when every track's length is stored) when a track list is stored. Then **one
   button row**: Download NZB (coral), Copy NZB link, Add to cart, then **MusicBrainz** (`btn sec ext`, external-link
   icon, new tab, `noopener noreferrer`, "(opens in a new tab)" for screen readers) to the identified release group, only
   when the release has an accepted MusicBrainz identity.
5. **The tabs and facts** straight after, with no heading: as 5A, without the Genre fact (the genres are in the tags).
6. **"All N releases of this album"** after the facts: Console's "All N releases of this game" (Movies `SPEC.md` 5C.4):
   every release of the band whose tags name the same album and the same artist (case-insensitive), newest posted
   first, sortable by Category, Size and Posted, 50 a page with the Movies pager; this release's row is marked "The
   release on this page" in coral, its name not a link, the row on the panel ground (`aria-current="true"`). One release:
   "The only release of this album". The table is 5A's Similar releases table; its rows keep Listen (they are other
   releases; their chip opens the dialog).
7. **Similar releases** as 5A, **leaving out releases of the same album** (the Movies rule). No section when nothing
   else matches.

## 5C. Parts both release pages share

### 5C.1 The preview, in the page (his ruling, 2026-10-04)

"In the release details page, we shouldn't need to bring up a separate dialog to play the audio (unless it's a music
video release). If it's audio only, the player should be directly embedded in the page, either directly above or below
the spectrogram image." Then: "The player is perfect. However, the listen chip under the release title is redundant and
not preferred."

- A release with a preview opens its **Overview** with: a line naming what plays (the track's title tag in ink semibold,
  then a dim "· 30-second preview" with the preview's real length; just "30-second preview" without a title tag); the
  **browser's own audio player** (not autoplaying, loading only its length); **directly under it the spectrogram**,
  220 px high at its own width, the player exactly as wide, 10 px between them; a small "Spectrogram" label in the
  picture's top-left corner (its own text sits in the other corners). The spectrogram opens in the image dialog
  (its size, Full size) as today's does. 24 px below, the facts.
- **No Listen chip on the release page's own chip line**; the player is the way to play the preview there.
- A **music video** release (a release with a video clip) keeps the dialog: the Adult Clip chip and clip dialog
  (Adult `SPEC.md` 5.10). Production has no audio release with a video clip today.

### 5C.2 The Tracks tab

- Shown only when the release has a stored track list: the newest audio evidence revision's tracks, from one source in
  this order of preference: the archive listing, else the NZB's audio files, else the release's files, else the one
  sampled file (the order the prototype reads them).
- A dim line with the total length when every track's length is stored; then a table **#, Title, Length** (Length only
  when lengths are stored), 760 px at most, the number right-aligned in tabular figures; a dim disc row ("Disc 2") before
  each disc when the tracks span more than one disc.
- One structural rule for a title: the track's title tag, else the file name without its folders and extension, without
  a leading track number the # column already shows ("104 - Catapult" → "Catapult"). No other cleaning. Track numbers
  are shown as stored (a tag of 102 stays 102).

### 5C.3 Media info: Title instead of Language (his ruling, 2026-10-04)

"On the media info tab, the 'language' column is useless. It should be replaced with the ID3 tag that shows the track's
title instead." On an Audio release's media info (the tab and the dialog the media info chip opens), the audio table's
**Language** column is replaced by **Title**: the stream's title, else the file's track title tag (the "Embedded track
title" today's media info already carries); "—" when neither exists. The other columns stay. Other sections' media info
is unchanged.

---

## 6. Decisions taken in the session

### 6.1 Misfiled releases

Most of Audio › Video and Audio › Foreign on production is anime and TV episodes filed before the categorisation fixes
of 2026-08 and early 2026-09 (`DATA-NOTES.md`). His ruling: no issue and no recategorisation ("We will never be 100%
accurate with categorization and the relatively small number that have come in since the changes aren't that
concerning"). The screens show what is filed.

### 6.2 Album art

"For album art, I'm hoping that will eventually come from the MusicBrainz integration that hasn't been completed yet."
The cover slots are built (5.7, 5B.2) and show the No cover tile until that integration stores covers.

### 6.3 Today's features

Every feature of today's Audio screens is in `INVENTORY.md`. Carried: the list, the release name link, the chips, the
Listen chip and its dialog, the group and poster chips, selection and the bulk bar, a name search that finds artists and
albums, the Year and Genre filters (now on the data that is written today), the details tabs, the audio preview and
spectrogram, PreDB and Similar releases; from the title page, the track list (Tracks tab), the release links
(MusicBrainz) and the album's other releases ("All N releases of this album", whose Category column names MP3 or
Lossless as the title page's format chips did). Dropped as every redesigned section dropped them: the Cards and Covers
views and their A–Z letter jump, the Name and Grabs sorts, the page-size choice, the Report and Details row buttons, the
Files and Grabs columns, the Label filter (no live data), the hand-typed Artist filter, the album title page and title
chip.

---

## 7. Data the design needs

To be proven in the data contract at full catalogue size before any build issue:

- The list's reads: the band newest first by posted and by added, page 1 and the last page; Category (with Exclude Other)
  and Completion alone and combined; Genre (including Unknown) and Year (decades and a range) alone and combined; the name
  search over release names and the tags' album, album artist and performer, alone and with filters, including a word
  that matches nothing.
- Per row: the tags' artist, album, year, genres and preview flag for a page of 50 without a per-row query.
- **Genres stored one row per genre**, as TV, Movies and Console store theirs (the maintainer's rule: normalize), written
  where the tags are written, existing rows filled by the migration.
- Details: the tag row; the track list of the newest evidence revision; the accepted MusicBrainz release group; the
  album's releases, newest posted first, paged, with their count.
- Media info presence per release without loading the media info (today's loaders).
- Remembered filters and the sort: beside the other roots in `users.view_prefs`.
- Similar releases: today's search-index query, unchanged.

---

## 8. Open items

None on the screens.

---

## 9. Findings from today's screens

From `INVENTORY.md` section 7 (read in the code on master `f8365df5f`). Those the redesign removes with the old pages:
`musicinfo` is frozen, so every title feature reaches only old rows (finding 1); details "Genres" never renders (2); the
Artist filter has no control (3); Covers "Name · A–Z" sorts by release name (4); dead code in `getMusicRange` (5); the XL
genres line never shows (6); title-page "Tracks" is usually a count (8); the legacy `ob=` sorts that do not exist (10);
the Audio sort is not saved (11); title-page access errors are plain 403s (14).

Left as they are, outside this design: the disabled-genre sweep queries `musicinfo.genre_id`, which does not exist (7);
stored values shown nowhere (`musicinfo.review`, `asin`, `salesrank`; 9); Audiobook releases get a Books lookup but no
chip (12); `releases.haspreview = 1` means an audio clip for audio releases (13); the spectrogram picture is served by the
public cover route, without login or the hidden-category check that the audio preview has (`DATA-CONTRACT.md` 1).

---

## Appendix A. Details an implementer needs that are easy to miss

- The cover is square: 88 × 88 in a 110 px column on the list, 200 × 200 on the album page; without a cover the list
  shows the Adult dashed tile and the album page the film page's placeholder tile with a disc icon.
- The music line's artist is the album artist tag, else the performer; parts missing from the tags are left out.
- A multi-value genre tag splits on ";"; a tag reading "Unknown" is the Unknown item.
- The Year menu's decades run 2020s to 1940s; its range bounds stay 1900 to the current year.
- Listen is not on the release page's own chip line; rows in the release page's tables keep it.
- The embedded player is as wide as the spectrogram under it and does not autoplay; the Listen dialog's player does.
- The Tracks tab and the Media info tab are absent, not disabled, when there is nothing to show.
- The format tag shows only when the media info chip does not already start with that format and it differs from the
  sub-category.
- The genre tag on the release page replaces the list's filters with that genre alone.
- "All N releases of this album" includes this release, marked, not linked; Similar releases leaves out every release of
  the same album.
- The MusicBrainz button is a link (`<a>`), `target="_blank"`, `rel="noopener noreferrer"`, to
  `https://musicbrainz.org/release-group/<id>`.
- On an Audio release's media info, the audio table's first column after # is Title, not Language.
- Copy NZB link is TV's (TV `SPEC.md` appendix A): the v1 `t=get` URL with the user's API key.

## Appendix B. Rejected: do not bring back

- A Listen chip under the release title on the release page ("redundant and not preferred").
- A dialog to play an audio-only preview on the release page; a separate player box or a Listen tile beside the
  spectrogram.
- The Language column in an Audio release's media info audio table ("useless").
- Decades stopping at the 1960s.
- A Password filter; filters derived from release names; the Label filter.
- A covers view or wall; the Cards view; the A–Z letter jump; Name and Grabs sorts; a page-size choice; Report and Details
  row buttons; Files and Grabs list columns; the album title page and the album title chip.
