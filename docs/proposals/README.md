# Proposals

Design records, specifications and plans, each written before the work it describes. A proposal is a record of what
was decided and why; the application code is the source of truth for what is built.

## Section redesigns

Each folder holds a section's specification (`SPEC.md`), the facts and measurements behind it, and the approved
clickable prototype with its behaviour checks (`prototype/check.mjs`) and reference screens. Each folder's own
`README.md` says which file to read for what; where it still says a screen is not built, the status below is
the later one.

| Folder | Records | Status |
|---|---|---|
| [`tv-redesign/`](tv-redesign/) | The TV section: releases list, shows wall, show page, release details page and their dialogs | Approved; built by #773 to #781, the changes approved after that build under #839 |
| [`movies-redesign/`](movies-redesign/) | The Movies section: releases list, films wall, film page, release details page | Approved; built under #839 |
| [`adult-redesign/`](adult-redesign/) | The Adult section: releases list and release details page | Approved; built under #890 |
| [`books-console-pc-redesign/`](books-console-pc-redesign/) | The Books, Console and PC sections, one set of screens on three datasets | Approved; built under #943 |
| [`audio-redesign/`](audio-redesign/) | The Audio section: releases list and release details page, with the album, tracks and preview | Approved; built under #966 |
| [`generic-release-lists/`](generic-release-lists/) | All releases, a group's releases, a poster's posts and the Other category, with their release details page | Approved; built by #1032 |
| [`home-redesign/`](home-redesign/) | The home page: shelves, one rail per section the user chooses, and the Shelves dialog | Approved; built by #1037 |

## Earlier front-end proposals

| Folder | Records | Status |
|---|---|---|
| [`frontend-redesign/`](frontend-redesign/) | The first front-end redesign specification for every non-admin page (2026-09-13), with its own prototype and stylesheet | Earlier specification; the section redesigns above are the later record |
| [`frontend-redesign-v2/`](frontend-redesign-v2/) | The handoff of the accepted browse design and the TV query redesign proposal (2026-09-17, #693), with preserved review prototypes | Handoff record, not an implementation authorization; the section redesigns above are the later record |

## Plan documents

| Document | Records | Status |
|---|---|---|
| [`github-actions-ci-optimization-plan.md`](github-actions-ci-optimization-plan.md) | The CI optimization proposal of 2026-09-07 (#495) | Historical; superseded by #644, current policy in [`../agents/ci-policy.md`](../agents/ci-policy.md) |
| [`musicbrainz-audio-enrichment-plan.md`](musicbrainz-audio-enrichment-plan.md) | The MusicBrainz, AcoustID and Chromaprint audio identification proposal (research of 2026-08-23) | Research record |
| [`predb-enrichment-plan.md`](predb-enrichment-plan.md) | An RFC on PreDB enrichment: dump import, hash matching and predb.net lookups (2026-08-09) | Draft, not scoped |
| [`upstream-sync-plan.md`](upstream-sync-plan.md) | The plan for merging upstream NNTmux master (August 2026) while preserving fork behaviour | Marked ready for implementation |

## Building a prototype on the shared stylesheet

Every section prototype from `tv-redesign/` to `home-redesign/` is one HTML page whose inline `<style>`
block, icons and header are the same shared stylesheet, grown section by section and copied into each
`prototype/*.html`. The two `frontend-redesign*` folders do not use it. A new prototype starts from an
existing copy, so the classes below already mean something before the first new rule is written.

Line numbers in this section are lines of
[`generic-release-lists/prototype/releases.html`](generic-release-lists/prototype/releases.html), whose stylesheet
is lines 10 to 732. Other copies carry most of these rules at other line numbers; older ones lack the newest rules and
`home-redesign/prototype/home.html` adds its own.

### Reserved classes

The stylesheet defines 263 classes. Many are short and generic, most are styled only inside a parent
(`.tile .count`, `.pager .sum`), and some are styled in more than one context (`.count`, `.lbl`, `.what`, `.meta`,
`.ph`). Grep the stylesheet for a name before giving it to a new element.

| For | Classes |
|---|---|
| Page frame, icons and page-level text | `i` `wrap` `vh` `crumbs` `top` `back` `empty` `later` `loadnote` `note` `varsel` |
| Header | `nav` `brand` `l` `on` `nd` `root` `iconbtn` `lbl` `sitesearch` `scope` `field` `search` `results` `hl` `p` |
| Filter bar | `person` `filters` `grow` `bar2` `fg` `pill` `sub` `dfilters` `sel` `set` `count` `seg` `mrow` `mfilters` `two` `grp` `fbar` `pf` `fseg` `rel` `film` `game` `one` `poster` `nsearch` `clr` `clearall` `sortsel` `hastabs` `stabs` `many` `sfil` |
| Filter menus | `msel` `mbtn` `sfm` `mmenu` `lab` `v` `any` `k` `sep` `open` `mitem` `box` `dot` `mhead` `msep` `msearch` `searchable` `yrange` `yerr` `ymenu` `years` `sub2` |
| Feed table and its rows | `feed` `rt` `selall` `selcol` `what` `name` `rname` `showlink` `gameline` `epl` `dayrow` `moreof` `art` `ph` `card` `t` `nopic` `nw` `acts` `cat` `h-gen` `c-cat` `c-size` `c-date` `c-acts` `c-gen` `size` |
| Tiles and walls | `np` `tiles` `tile` `m2` `m3` `gn` `day` `wall` |
| Chips and tags | `rchips` `rc` `info` `comp` `c-ok` `c-mid` `c-low` `origin` `orig` `ot` `k-mi` `k-nfo` `k-pv` `k-sm` `k-cl` `k-rep` `k-resp` `chip` `res` `r-4k` `r-1080` `r-720` `r-sd` `r-unk` `src` `facts` `tags` `tag` `plain` `hue` `grey` `hv` `ha` `hs` `k-dv` `k-hdrp` `k-hdr` `k-atmos` `k-71` `k-51` `k-20` `k-def` `k-forced` `k-sdh` `k-img` `flag` `f` `h` |
| Buttons | `btn` `sec` `ext` `wat` `wl` `nt` `ct` `danger` `bl` `ia` `dl` `iconacts` `slot` `stack` `filesbtn` `relbtn` `bulk` `more` `fill` |
| Dialog | `scrim` `dlg` `dlghead` `body` `blk` `report` `inv` `danger-opt` `mono` `end` |
| Pager | `pager` `bottom` `list-end` `cur` `gap` `off` `sum` `slim` `pg` `fixed` `sweep` |
| Toast | `toast` |
| Details pages (release, show, film) | `dhead` `for` `relname` `relonly` `dacts` `dcols` `noshow` `tabs` `panel` `vals` `epbox` `tagl` `plot` `aboutshow` `sibs` `meta` `toshow` `me` `this` `filelist` `predb` `wide` `showhead` `relpage` `gline` `story` `starring` `fhead` `frel` `simf` `simrel` `mdet` `ep` `no` `ti` `rels` `packs` `fc` |
| Media info, NFO, pictures and clips | `mi2` `col` `glance` `chips` `vgrid` `langs` `n` `lead` `num` `nfo` `pvimg` `pvbar` `pvwrap` `canfull` `full` `pvthumb` `adpics` `cl` `play` `hasclip` `clipv` |

### Traps

| Trap | Where | What to do |
|---|---|---|
| Every `button` is reset: no border, no background, pointer cursor | `:18` `button{cursor:pointer;border:0;background:none}` | A new button needs its ground and border stated; it inherits none. |
| A `button` element centres its content vertically, a browser default the reset does not undo, so a tile built as `<button class="tile">` shows a gap above its artwork | `.tile{display:block}` at `:307` does not change it | Put `display:flex; flex-direction:column` on a tile button, as `home-redesign/prototype/home.html:714` does. |
| `.more` is the "load more" button: fixed 46px height, large margins, pill radius | `:329-330` | Do not name a container `.more`; it takes the button's fixed height, margins and shape. |
| `.note` and `.panel` are page-level elements: the dim 14px note line, and the details tab panel with 20px vertical padding | `:67`, `:250` | Pick another name for a new note or panel component. |
| `.tile` and `.tile .count` belong to the wall tile: `.count` is the badge pinned to the artwork's top-right corner | `:307`, `:312` | A count inside a new kind of tile needs its own class. |
| `.feed` cell colours are keyed to column position in a six-column table: the 5th cell in ink, the 6th dim (an earlier rule sets the 6th to ink and is overridden) | `:620-621`, earlier rule at `:277` | A feed table with other columns must restate the colours per cell class. |
| `href="#"` on a placeholder link changes the hash; every page routes on the hash, and the `hashchange` handler closes the open dialog | the handler at `:1059` calls `closeDialog()`; the published links at `:834` and `:865-866` are safe only because the click handler at `:1069` calls `preventDefault` | Call `preventDefault` in the click handler, or leave `href` off. |
| The bookmark icon fills only through one selector | `:607` `[data-watch][aria-pressed=true] svg.i.fill{fill:currentColor}` | A filled bookmark needs `data-watch` and `aria-pressed="true"` on its button and both `i` and `fill` on the `svg`; no other rule fills it. |
