# Generic release lists redesign

The design of the four release lists that have no section of their own: **All releases** (`/browse/all`), **a
group's releases** (`/browse/all?group=`), **a poster's posts** (`/browse/all?poster=`, `/poster`) and the
**Other category** (`/browse/other`, Misc and Hashed), with the release details page they open for a release that
has no redesigned details page of its own. It follows the process of the earlier sections
([`../tv-redesign/`](../tv-redesign/), [`../movies-redesign/`](../movies-redesign/),
[`../adult-redesign/`](../adult-redesign/), [`../books-console-pc-redesign/`](../books-console-pc-redesign/),
[`../audio-redesign/`](../audio-redesign/)): the screens were prototyped on the maintainer's real data, reviewed in a
clickable prototype and approved; no new storage is proposed, and every list read was measured on a restored
production catalogue. Nothing here is implemented yet.

| Screen | Status |
|---|---|
| All releases, a group's releases, a poster's posts, Other releases (one list form) | **Approved** on the maintainer's prototype, 2026-10-09 |
| Release details for a release without a redesigned details page | **Approved** on the maintainer's prototype, 2026-10-09 |

| Read | For |
|---|---|
| [`SPEC.md`](SPEC.md) | what the lists are for, the approved screens, every decision with the reasons, what was not carried |
| [`DATA-NOTES.md`](DATA-NOTES.md) | facts measured on the restored catalogue that shaped the design, and every list read timed at full catalogue size |
| [`prototype/`](prototype/) | the approved prototype on an **invented dataset** with placeholder pictures: `releases.html` (the four lists and the details page), its behaviour checks (`check.mjs`) and 17 reference screens in both themes (`reference/`) |

```bash
cd docs/proposals/generic-release-lists/prototype
python3 -m http.server 8766          # then open http://localhost:8766/releases.html
node check.mjs                        # must end "N/N passed"  (needs Google Chrome)
```

Routes inside the page: `#/` All releases, `#/group/<name>`, `#/poster/<identity>`, `#/other` (and
`#/other/Misc`, `#/other/Hashed`), `#/release/<id>`; the header's All and Other buttons open their menus.

Every release name, guid, group, poster identity, NFO text, file name, PreDB title, film and show title, album and
performer in the prototype is invented and neutral, and the 714 preview and sample thumbnails are drawn
placeholders. Only the shape of the real data is kept: the newest 25,000 releases of the restored catalogue by posting
date, with their sizes, dates, completion, password status, categories, groups and posters (renamed), media-info
fields, episode tags and years, and which releases have an NFO, stored files, media info, a PreDB match, a preview, a
sample, a clip or a film, show or album link. The reports, staff responses and comment counts on nine releases are
invented (the catalogue copy has none). Desktop only, by the maintainer's decision.
