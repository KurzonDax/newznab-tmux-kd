# Home page redesign

The design of the home page (`GET /`, route `home`): **shelves**, one horizontal rail per section the user chooses,
with the followed titles first, each tile opening that title's newest releases inline. It follows the process of the
earlier sections ([`../tv-redesign/`](../tv-redesign/), [`../movies-redesign/`](../movies-redesign/),
[`../adult-redesign/`](../adult-redesign/), [`../books-console-pc-redesign/`](../books-console-pc-redesign/),
[`../audio-redesign/`](../audio-redesign/), [`../generic-release-lists/`](../generic-release-lists/)): four
compositions were prototyped on the maintainer's real data, reviewed in a clickable prototype, one was approved; no new
storage is proposed beyond a per-user preference, and every read the page makes was measured on a restored production
catalogue. Nothing here is implemented yet.

| Screen | Status |
|---|---|
| The home page as shelves, with the Shelves dialog (tick, drag to reorder) and the tile panels | **Approved** on the maintainer's prototype, 2026-10-10 |

| Read | For |
|---|---|
| [`SPEC.md`](SPEC.md) | what the page is for, the approved screen, every rule and decision with the reasons, what was not carried |
| [`DATA-NOTES.md`](DATA-NOTES.md) | why today's home page is slow, every read of the new page timed at full catalogue size, the preference storage |
| [`prototype/`](prototype/) | the approved prototype on an **invented dataset** with placeholder pictures: `home.html`, its behaviour checks (`check.mjs`) and 9 reference screens in both themes (`reference/`) |

```bash
cd docs/proposals/home-redesign/prototype
python3 -m http.server 8766          # then open http://localhost:8766/home.html
ONLY=2 node check.mjs                 # must end "N checks, 0 failures"  (needs Google Chrome)
```

Every release name, guid, group, poster identity, show, film, album, performer and episode title in the prototype is
invented and neutral, and the 125 posters and preview pictures are drawn placeholders. Only the shape of the real data
is kept: the newest 60 releases of every section of the restored catalogue by posting date, with their sizes, dates,
completion, password, preview and NFO flags, categories, groups and posters (renamed); the per-section counts and 14 days
of arrivals; which shows had new episodes in the last 24 hours and which films were posted in the last 7 days. The
followed titles, the basket, the downloads and the front-page note are invented (the catalogue copy has none). Desktop
only, by the maintainer's decision.
