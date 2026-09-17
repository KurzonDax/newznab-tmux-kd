# Reference prototypes

These are preserved **sample-data review artifacts**, not application code or
working search backends. Their original bytes are preserved; SHA-256 identities
and provenance are in [manifest.json](manifest.json). Start with the consolidated
[design contract](../01-design-contract.md), which resolves stale text and demo
shortcuts. Do not copy accumulated inline scripts/styles into Blade/Alpine.

## Final toolbar / Advanced design

Open [toolbar-prototype.html](toolbar-prototype.html?category=movies&revision=17)
in a browser, directly from disk or an approved local static preview. Category
links select all nine roots. `revision` is a review cache-busting marker, not a
version selector; the file contains the final revision-17 state.

| Example | Relative link |
| --- | --- |
| Movies, identifiers and both rating ranges | [Movies](toolbar-prototype.html?category=movies&revision=17) |
| TV, show fields and episode bounds | [TV](toolbar-prototype.html?category=tv&revision=17) |
| Music | [Music](toolbar-prototype.html?category=audio&revision=17) |
| Audiobooks | [Audiobooks](toolbar-prototype.html?category=audio&audio_category=3030&revision=17) |
| Console stored-choice Platform presentation | [Console](toolbar-prototype.html?category=console&revision=17) |
| PC/Games | [PC/Games](toolbar-prototype.html?category=games&revision=17) |
| Books | [Books](toolbar-prototype.html?category=books&revision=17) |
| Adult | [Adult](toolbar-prototype.html?category=xxx&revision=17) |
| All Releases / Other | [All](toolbar-prototype.html?category=all&revision=17) / [Other](toolbar-prototype.html?category=other&revision=17) |

The Audio demo still writes `revision=16` when its mode changes; this does not
load an older design. Its Music/Audiobooks choices are final. Static platform,
genre, year, count and release arrays are examples only. Form parameter names
such as `advanced-*` and `audio_category` are not a production URL contract.
Advanced is opened for review; that does not decide the production default.
Search button/Enter behavior and bounds validation in this artifact remain
proposals where the decision register says so. It uses system fonts and literal
demo colors; production must use the established Figtree/theme/design system.

## Earlier accepted TV and title examples

- [Final TV Shows directory and one-dialog paging](review.html?topic=paging):
  open Harbor Street and explore Season 4; 30 episodes, 60 episode variants and
  40 full-season releases demonstrate independent paging.
- [Episode Covers](review.html?topic=episodes): episode identity, title and pack
  inclusion; full-list demo actions are illustrative, not production endpoints.
- [Internal release order](review.html?topic=sorting): newest posted first inside
  every card, regardless of outer sort. It is not a removal of the six outer sorts.
- [Accepted typography comparison](review.html?topic=text): proposed side reflects
  the accepted 14/24/15/12/13px roles. Existing-small-text mode is historical.
- [Movie Cast](review.html?topic=cast): full-width cast arrangement.
- [Original selected B directory](index.html?variant=B): supporting earlier artifact
  linked by review.html. Its A/C switcher remains archival history and is not an
  implementation choice. The later `review.html?topic=paging` cards supersede B's
  older card sizes/actions. Do not use A/C or stale watch/default-season behavior.

The standalone `advanced-comparison.html` alternatives were explicitly rejected
and are deliberately not included. Original files contain successive overrides
and some historical helper labels; final written requirements take precedence.
Nothing here restores removed Select season or proves database performance.

## Dependencies, privacy and integrity

Both Font Awesome woff2 files match the repository's installed Font Awesome Free
files byte-for-byte. Their [license](FONT-AWESOME-LICENSE.txt) is included. The
review file requests Figtree from Google Fonts and falls back without network;
exact typography review therefore needs that font loaded. The archive does not
silently rewrite this dependency or claim pixel-identical offline rendering.

Artwork is generated/sample content. No private screenshot, screenshot crop,
live-page capture or attachment is included. These files use inline scripts and
styles as throwaway demos; production CSP and design-system rules still apply.
Artifact checks for this handoff verify copied bytes, local resource targets,
manifest hashes and script syntax. They are not a new visual acceptance or
production browser regression run.
