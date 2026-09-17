# Accepted design and behavior

Status: accepted product/visual decisions with explicitly identified open semantics.
Source: the final revision-17 toolbar and later decisions in the source task;
earlier accepted TV examples from #621 remain relevant except where superseded.
See the [prototype guide](prototypes/README.md) and [decision register](04-decisions-and-acceptance.md).

## Shared browse layout

**Accepted:** consistent design across all nine browse roots, with meaningful
category-specific controls. Search is comfortably wide; dropdowns stay compact.
Sort belongs on the Search/filter row. View and cover-size groups are on the left
of the following row; the result count is on the right. Keep pagination/per-page
controls distinct. Custom year From/To inputs remain inline, with input borders
aligned to adjacent controls. The custom-year popover is rejected.

Main Search matches release names plus relevant category metadata consistently
across supported views. Advanced narrows named fields. Switching views must not
change the meaning of the search, even where different view eligibility rules
mean a release without metadata cannot produce a cover. Exact token rules,
field coverage, submission, and legacy URL behavior remain open below.

Advanced expands inline for Movies, TV, Audio, Console, PC/Games, and Books.
There is no Advanced for Adult, Other, or All Releases. Remove redundant Title,
Series title, and Album fields from Advanced because basic Search includes them.
Do not add controls simply to fill visual space.

Use shared application design standards and shared components for compact
search/filter controls. The prototype's successive per-category CSS overrides
are not the production implementation. Update the maintained design rules/checks
when implementation is authorized; do not globally shrink unrelated forms,
admin/forum controls, or dialogs without checking their intended roles.

## Category matrix

All rows retain Search, six ordinary Sort choices, and applicable view/per-page controls.
“Basic” lists metadata controls, not a requirement to retain the old toolbar layout.

| Root / route value | Supported views | Basic metadata controls | Accepted Advanced fields |
| --- | --- | --- | --- |
| All Releases / `all` | Table | None | None |
| Movies / `movies` | Table, Cards, Covers | Year, Genre | Actor, Director, Plot; IMDb ID, TMDB ID, Trakt ID; Rating minimum/maximum; Rotten Tomatoes minimum/maximum |
| TV / `tv` | Table, Cards, Covers | Series Year | Network, Country, series Summary; IMDb ID, TMDB ID, TVDB ID, TVMaze ID, Trakt ID; Season, Episode, episode aired From/To |
| Audio / `audio`, Music mode | Table, Cards, Covers | Music/Audiobooks selector, Year, Genre | Artist, Label |
| Audio / `audio`, Audiobooks mode | Table, Cards, Covers | Music/Audiobooks selector, Publication year, book Genre | Author, ISBN, Publisher |
| Console / `console` | Table, Cards, Covers | Year, Genre | Platform dropdown, Publisher |
| PC/Games / `games` | Table, Covers | Year, Genre | Publisher |
| Books / `books` | Table, Cards, Covers | Year, Genre | ISBN, Publisher |
| Adult / `xxx` | Table, Cards, Covers | None | None |
| Other / `other` | Table | None | None |

Adult has no Genre or Year: its old Year meant Usenet posting year, not content
year. PC Platform was the constant PC and is removed. Books Author is removed;
do not restore it in Advanced. The separately accepted audiobook Author field
is intentional. Do not add TV episode title/summary, AniDB/TVRage IDs, or combined
season/episode notation merely because those fields exist in the schema.

Console Platform uses distinct nonempty stored `consoleinfo.platform` values in
the appropriate listing scope. The prototype's five sample platforms are not a
whitelist; preserve older platforms and the selected value through filter changes.
The exact option scope and text-match semantics for other metadata controls need
the decision register. A compulsory 25-option popup from old #627 is not approved.

## Audio identity

**Accepted:** exactly two selector choices. Audiobooks means Audio category
`3030`; Music means all other categories **within Audio root 3000**. No All Audio
mode and no individual MP3/Video/Lossless/Foreign/Other selector entries. Never
implement Music as an unrestricted site-wide `categories_id != 3030` predicate.
Music is the prototype default; an explicitly selected audiobook mode persists.

Audiobooks use book title/author/ISBN/publisher metadata via `bookinfo_id` and
book artwork; music uses `musicinfo_id`. Mode must participate in group identity,
count, expansion authorization, search fields, filter options and URL state.
Equal numeric book and music IDs must not collide. Ordinary release-name search
must still work without a metadata link. Existing non-audiobook Audio categories
remain in Music mode even if they have no album metadata.

Publication year describes the linked book metadata, not a verified audiobook
edition/recording year. Final clearing/mode-switch behavior and the nonmusic Audio
metadata edge cases must be specified before implementation; the sample UI is
not evidence of production query completeness.

## Movies identifiers and ratings

**Accepted:** expose the three provider identifiers and both Minimum and Maximum
fields for both rating scales, always visible within expanded Advanced. Use the
full visible label **Rotten Tomatoes**, not RT. “Rating” must not be labelled
exclusively IMDb: existing data can come from several providers.

**Proposed, not separately approved:** exact identifier predicates; accepted IMDb
prefix/leading-zero formatting; 0–10 decimal Rating and 0–100 integer-percent
Rotten Tomatoes bounds; inclusive endpoints; blank bounds unrestricted; both
active ranges combine with AND; unknown values excluded only when their filter
is active; invalid or reversed ranges produce validation errors rather than
silent correction. These behavior details must be finalized even though the
layout is accepted.

The current `MovieService::fetchOmdbAPIProperties()` obtains the Rotten Tomatoes
value by `Ratings[1]` position. Source attribution/normalization must be verified
before using stored `rtrating` as a trustworthy numeric predicate; it can contain
percent signs or N/A. No data repair or ingest change is authorized by this
handoff. See the [current-state map](05-sources-and-current-state.md).

## Dimensions and shared styling

**Accepted:** approximately 80% centered desktop content with 10% framing on
each side, no fixed 1500px ceiling; usable narrow-screen gutters. Preserve the
application's Figtree font, semantic colors, light/dark modes, and Blue/Emerald/
Violet schemes. No browser-scale comparison task is wanted.

The accepted compact Advanced rendering uses 32px controls, readable 14px input
text, 13px labels above fields, 4px label gaps, 10px row gaps and 16px column gaps.
These describe the final reference rendering, not permission to duplicate its
literal styles in every category. Movies uses four aligned desktop columns:
Actor / Director / Plot / Rating pair, then IMDb / TMDB / Trakt / Rotten Tomatoes
pair. Both bounds fit their parent column. TV uses aligned fields with Summary
spanning space and Season/Episode paired. Responsive layouts reduce columns.

The prototype still has 40px basic controls and a 420px desktop Search width.
The decision log explicitly leaves precise Search width, submission and some
responsive details as proposals. Overall visual acceptance does not justify
claiming separate approval of those mechanics. Preserve the accepted appearance
while resolving shared token sizing and responsive rules explicitly.

Earlier accepted public typography remains the baseline outside the later
compact Advanced controls: navigation/filters 14px, TV heading 24px, release
names 15px, facts/status chips 12px, bottom statistics 13px. Episode headings
18px and genres 13px; directory titles 16px with 13px secondary metadata.
Compact release actions retain 28×28px boxes, 13px icons, 7px radius and 4px gaps.
Release panel padding is 10px 12px, gap 12px, radius 8px and accent edge 4px.
The earlier 230px toolbar Search reference is superseded by the wider-search
direction. Do not treat that older number as the new target.

## TV Covers, release lists, and sorts

**Accepted/existing:** a TV cover represents one identified episode, never a
whole series or a season. Its heading combines series, episode code and episode
title. Use the series poster without text overlays; display all known genres
below the heading when data is available. No alphabet-jump bar in TV Covers.
Only episodes with eligible releases produce tiles; unidentified releases do not.

Show two newest-posted eligible release panels per tile. Each tile's View all,
count and pagination refer only to releases containing that episode. Full-season
packs contribute once to each identified contained episode; explicit partial
packs contribute once to each declared, identified episode. One underlying
release identity remains one basket item across those contexts.

The six outer sorts are fixed. Their existing TV aggregation contract is:

| Selection / key | Episode-group key | Direction |
| --- | --- | --- |
| Posted · Newest / `posted` | MAX member postdate | Descending |
| Posted · Oldest / `posted_oldest` | MIN member postdate | Ascending |
| Added · Newest / `newest` | MAX member adddate | Descending |
| Added · Oldest / `oldest` | MIN member adddate | Ascending |
| Name · A–Z / `title` | Smallest case-folded member display name | Ascending |
| Grabs · Most / `grabs` | MAX member grabs | Descending |

Episode-group ties use canonical episode ID ascending. Existing trending uses
seven-day member grabs summed descending, then episode ID, with current show
admission preserved. It is not an extra ordinary sort option. Inner releases
**always** use `postdate DESC, id DESC`, independently of the outer sort. Table/
Cards keep release identity; new episode filters must not duplicate their rows.
Existing 24/48/100 page choices remain; Covers defaults to 48 and show-dialog
lists to 24. Do not import the search engine's reachable-page cap into Covers.

All shared release panels keep the full linked name, then a separate wrapping
facts/status row, then the existing size/files/added-age/grabs/comments positions.
Retain conditionally available facts and real actions: Download, Details, Basket,
Report, and applicable TV Watch. Long values must wrap without clipping,
horizontal overflow, omitted actions or smaller-font workarounds. Adjacent TV
network and Watch controls match rendered height while remaining visually distinct.

## TV Shows directory and the show dialog

This is separate from release-based Covers. **Accepted/existing:** default to
all stored shows, including shows without releases. Keep title/year/initial/
network/availability/watching filtering and real release visibility. The final
directory example is `review.html?topic=paging`, not the older A/C layouts.

- Compact poster cards use 160px-minimum grid tracks, 20px gaps, 18px panel
  padding; two columns with 12px gaps/padding on phones. Artwork fills card width
  at 2:3 with no side gutters or text overlays.
- Poster and title open the same show dialog; no separate Open show button.
  Watch is an independent accessible heart, 32px square, 8px from top/right.
- Show complete title/year/available metadata, season count and distinct status/
  release-availability chips. Continuing uses green/play; Ended slate/stop;
  availability has text and an icon. Prototype status/genre data does not prove
  equivalent production fields exist; see the current-state caveat.
- One dialog: at most 1050px and viewport minus 40px, maximum height 85vh;
  artwork 140×210 desktop and 80×120 phone. Default newest numbered season,
  ascending season tabs with Specials last.
- Full-season releases have their own section. Episode and pack releases expand
  inside that same dialog, with independent pagination and complete counts.
  Full packs are not repeated under its episode lists. This differs intentionally
  from their membership under every contained episode in Covers.
- Preserve season, expanded-episode and independent page state, using the delivered
  scalar-state implementation rather than retained DOM/HTML payload caches.
  Preserve focus, Escape, backdrop close, focus trapping/return and no-release states.
- Real Watch uses the existing category picker (first-use UHD+HD, remembered
  choices, at least one category), not the demo's local toggle.

The accepted long-list example contains 30 episodes, 60 episode variants and 40
full-season releases: pages 24/6, 24/24/12 and 24/16 respectively. These are
illustrations of independent paging, not production limits. Earlier Movie Cast
layout acceptance also remains: compact short facts, synopsis, and full cast
across available width, without truncation or a tall narrow cast column.
