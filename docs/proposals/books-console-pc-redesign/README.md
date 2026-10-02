# Books, Console and PC section redesign

The design of three redesigned sections that share one set of screens: **Books** (category band 7000),
**Console** (band 1000) and **PC** (band 4000). Each has a releases list and a release details page; Console
also shows the game a release belongs to, with its cover, genres and year, on the list and on the release page.
It follows the process of the TV, Movies and Adult sections
([`../tv-redesign/`](../tv-redesign/), [`../movies-redesign/`](../movies-redesign/),
[`../adult-redesign/`](../adult-redesign/)): each screen was prototyped on the maintainer's real data,
reviewed in a clickable prototype and approved, and every query was measured on a restored production catalogue
before it was written into the data contract. Nothing here is implemented yet.

| Screen | Status |
|---|---|
| Book releases (list) and release details | **Approved** on the maintainer's prototype, 2026-10-01 |
| Console releases (list), release details with a game, release details without one | **Approved** on the maintainer's prototype, 2026-10-01 |
| PC releases (list) and release details | **Approved** on the maintainer's prototype, 2026-10-01 |

The three sections are one page on three datasets: `books.html`, `console.html` and `pc.html` are the same
`shelf.html`, which picks its data folder (`bk/`, `cn/`, `pc/`) from its own file name.

| Read | For |
|---|---|
| [`SPEC.md`](SPEC.md) | what the sections are for, the approved screens, every decision with the reasons, what was rejected |
| [`DATA-CONTRACT.md`](DATA-CONTRACT.md) | where every value the screens read is stored, which code writes it, and every read measured at full catalogue size |
| [`DATA-NOTES.md`](DATA-NOTES.md) | facts measured on the restored catalogue that shaped the design |
| [`evidence/`](evidence/) | the query-lab write-up the data contract cites |
| [`INVENTORY.md`](INVENTORY.md) | every feature of today's Books, Console and PC screens, with `path:line` |
| [`prototype/`](prototype/) | the approved prototype on an **invented dataset** with placeholder covers: `shelf.html` (opened as `books.html`, `console.html` or `pc.html`), its behaviour checks (`check.mjs`) and 9 reference screens in both themes (`reference/`) |

```bash
cd docs/proposals/books-console-pc-redesign/prototype
python3 -m http.server 8766          # then open http://localhost:8766/books.html, console.html or pc.html
node check.mjs                        # all three pages; must end "0 failures"  (needs Google Chrome)
PAGE=console node check.mjs           # one page
```

Every release name, guid, group, poster, NFO text, file name and PreDB title in the prototype is invented and
neutral. Only the shape of the real data is kept: the release count of each sub-category, sizes, dates,
completion, password status, media-info fields and which releases have an NFO, stored files, media info, a PreDB
match or Similar releases. The Console game data (genres, year, cover, summary, developer, scores) is invented
too, and the covers are generated placeholders: no console release on the maintainer's server has game data.
Desktop only, by the maintainer's decision.
