> Historical source snapshot. Read ../README.md and the consolidated design contract first. Earlier proposals and superseded #627 dependencies are not implementation requirements.

# Issue 628 — local working design decisions

Status: design review in progress. This file is editable working context, not a finalized specification.

## Publication rule
Do not update GitHub with incremental decisions. Once the user finalizes the entire design, consolidate the design, scope, specifications and acceptance criteria into issue 628. Do not upload private screenshots. Production implementation is not authorized by prototype review.

## Confirmed directions
- Apply a consistent design across all nine browse categories with meaningful category-specific controls.
- Search is a comfortably wide field. Dropdowns stay compact.
- Sort shares the Search/filter row. View/size groups are left on the following row; count is right.
- Custom year bounds are inline and aligned with adjacent controls. No popover.
- Remove Books Author dropdown. Do not reintroduce an Author field under Advanced without agreement.
- Main Search matches release names plus relevant category metadata consistently across views; Advanced narrows specific fields. User answered yes to this on September 15.
- Remove Adult Year (it was posting year, not content year).
- Remove PC/Games Platform (constant PC).
- Prototype uniform inline expandable Advanced for Movies, TV, Audio, Console, PC/Games, Books; none for Adult, Other, All Releases.

## Working Advanced field proposal
- Movies: Title, Actor, Director, Plot.
- TV: Series title, Network, Summary.
- Audio: Album, Artist, Label.
- Console: Title, Platform, Publisher.
- PC/Games: Title, Publisher.
- Books: Title, ISBN, Publisher.

These fields are now being shown for visual review. This is not final design approval.

## Preserved constraints
80% centered desktop content; established site colors and fonts; six ordinary release sorts; category-specific supported views; Adult S/L cover sizes. Existing year range semantics retained where year makes sense. No browser-scale comparison task.

## Open details
- Final visual approval of expanded/collapsed Advanced and responsive wrapping.
- Exact search token semantics and relevant metadata fields by category need a final contract before implementation; no search-engine choice was approved.
- Final submission interaction. Prototype currently uses a shared Search button and Enter as a proposal.
- 420px desktop Search width and second metadata row for crowded categories remain visual proposals.

## Artifact
`toolbar-prototype.html` is a local throwaway demo with sample data, not a working production search backend.


## Movies identifiers and ratings discussion
Confirmed: expose IMDb ID, TMDB ID, Trakt ID in Movies Advanced. Added to prototype. Identifier search should be scoped as exact identifiers, with accepted formatting finalized before implementation.
User wants minimum rating as common use, with optional ranges. Ratings UI not finalized yet.
Proposal: Minimum and optional Maximum for each scale; normal rating 0–10 with decimals, Rotten Tomatoes 0–100 whole percentage points. Inclusive endpoints; blank bounds unrestricted; both active rating filters combine with AND; unknown scores excluded only for an active filter; invalid/reversed ranges show validation rather than silently changing input.
RT source verified: https://www.rottentomatoes.com/about describes Tomatometer as percentage of positive critic reviews.
Local source caveat: MovieService takes OMDb Ratings[1].Value by position, not by matching Source='Rotten Tomatoes'. Verify/fix attribution before relying on stored rtrating for filters; strings may contain percent signs or N/A. Regular rating can fall back across IMDb/TMDB/Trakt/OMDb and must not be labelled exclusively IMDb rating.
No GitHub update during design review.

## Confirmed rating layout
User selected BOTH Minimum and Maximum always visible for Rating and Rotten Tomatoes in Movies Advanced. Added both pairs to the prototype. Blank bounds represent no limit. Remaining rating semantics described above are still proposals unless separately approved. GitHub remains unchanged.

## Advanced layout revision 7 — pending review
Replaced arbitrary flex wrapping with three deliberate sections: Movie details (2×2), Identifiers (three aligned compact rows), Ratings (two stacked minimum/maximum pairs). Responsive sections stack at smaller widths. User rejected the scattered layout in revision 6; new layout is a proposal, not approved. No GitHub update.

## Revision 8 — pending review
Revision 7 explicitly rejected with screenshot: mixed label placement, misaligned rows, fragmented columns, clipped placeholders. Replaced with uniform four-column grid: four movie text fields; three IDs on next row; four rating bounds on final row. All labels above inputs, identical heights, consistent column boundaries. Short Any placeholder. No GitHub edits.

## Compact revision 9 — pending visual approval
User identifies excessive size and padding as a main problem. Show a denser Advanced panel: 32px inputs, 8px gaps, approximately 12px panel padding, unchanged readable text. Remove redundant Movies Title field. Compact inline labels and appropriately sized IDs/numeric bounds; both rating bounds remain visible. Previous three alternatives rejected. No GitHub edits.

## Revision 10 — pending review
Revision 9 rejected for lack of alignment. Keep 32px controls and compact spacing; use three shared 240px desktop columns with labels above. Actor/IMDb/Rating share column one; Director/TMDB/RT share column two; Plot/Trakt share column three. Each rating pair fits its parent column with both bounds visible. No GitHub changes.

## Revision 11 — pending review
User says revision 10 is better but dislikes unused space on the right. Use four columns across Advanced: Actor/Director/Plot and Rating bounds in row one; IMDb/TMDB/Trakt and RT bounds in row two. Retain 32px controls and aligned labels. Responsive fallback retains stacked layout at narrow widths. No final approval or GitHub update.

## Rating label correction
Use the full name Rotten Tomatoes in visible labels. User rejected RT abbreviation. Restored full name in revision 12; no GitHub update.

## Accepted Movies design and category rollout — revision 13
User settles on revision 12 Movies design and requests applying it to TV, Audio, Console, PC/Games, Books. Movies design is accepted despite expressed dissatisfaction. No Advanced for Adult, All Releases, Other. Keep all decisions local until whole design is finalized.
Applied shared 32px input height, 13px labels above fields, 4px label gaps, 10px row gaps, 16px column gaps, compact panel padding. Do not invent metadata controls to fill space. Remove redundant title/album/series-title fields consistently because basic Search includes these.
Prototype Advanced fields: TV Network/Summary; Audio Artist/Label; Console Platform/Publisher; PC/Games Publisher; Books ISBN/Publisher. Movies Actor/Director/Plot, IMDb/TMDB/Trakt IDs, regular and Rotten Tomatoes min/max. Category-specific designs remain available for review; no production changes or GitHub writes.

## TV Advanced fields approved — revision 14
User explicitly requested adding the recommended TV fields: Country, IMDb ID, TMDB ID, TVDB ID, TVMaze ID, Trakt ID, Season, Episode, and episode air-date from/to bounds, alongside Network and series Summary. Added these to the local TV prototype with compact aligned controls. Country shown as two-letter code input for review; UI choice not separately finalized. Did not add AniDB/TVRage IDs, episode title/summary or season-episode notation because they were in the inventory but not the recommended set user accepted. Episode date bounds refer to episode firstaired, distinct from basic series year. No GitHub changes.

## Audio/audiobook investigation — pending scope decision
Audiobooks are Audio subcategory 3030 (MUSIC_AUDIOBOOK). BookService and BookProcessingCandidateQuery process them as books. Existing Audio metadata joins musicinfo and assumes music fields, so the prototype's Artist/Label-only model does not represent all Audio content. Propose a basic Audio Category selector including Audiobook and category-aware metadata search: book title/author/ISBN/publisher for audiobooks, with year/genre from appropriate metadata. Preserve release-name search when metadata absent. Do not assume album-only Covers correctly handles book-linked audio releases. No prototype or GitHub changes for this finding yet.

## Audiobook prototype — revision 15
User requested prototyping Audio category selector and audiobook-specific Advanced. Selector includes seeded Audio categories: MP3 3010, Video 3020, Audiobook 3030, Lossless 3040, Foreign 3060, Other 3999, plus All Audio. Audiobook switches Advanced to Author/ISBN/Publisher, search wording to audiobook title/author/release name, year label to Publication year, genre demo to book genres, count unit to audiobooks and sample covers to books with audio formats. No redundant title field. Author is included specifically for audiobooks per accepted proposal, not restored to Books. Music-only states use Artist/Label as existing prototype. All Audio mixed-metadata behavior and nonmusic audio types still require final specification; demo is not a search backend. No GitHub changes.

## Audio selector correction — revision 16
User explicitly requires only two choices: Music and Audiobooks. Audiobooks means Audio category 3030; Music means every other category within Audio (not every category in the site). No All Audio option and no individual MP3/Video/Lossless/Foreign/Other options. This supersedes the revision 15 selector and mixed All Audio discussion. Prototype uses Music by default and retains Audiobooks when explicitly selected. No GitHub update.

## Console Platform — revision 17
User requests pre-filled Platform dropdown instead of free text. Prototype uses sample platforms and All platforms default. Production options must use distinct nonempty stored consoleinfo.platform values within the appropriate listing scope; do not hardcode the demonstration list or omit older platforms. Preserve selected platform through other filter changes. Same compact control styling. No GitHub update.

## Design acceptance and issue 627 compatibility review
User accepted the overall revision 17 design and requires shared application design standards/components rather than category-specific overrides. Read all of issue 627 body and Part 2 continuation. See issue-627-compatibility-review.md for concrete W14 control conflicts, intended product changes, W03 search integration, W02 episode membership, W05 audiobook identity, W11 narrow projections and regression gates. This audit did not change either issue or the prototype. Do not mark the final spec conflict-free until those integration contracts are reconciled.
