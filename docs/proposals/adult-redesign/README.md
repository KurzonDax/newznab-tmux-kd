# Adult section redesign

The design of the redesigned Adult section (the XXX root, category band 6000): the releases list and the
release details page. It follows the process of the TV and Movies sections
([`../tv-redesign/`](../tv-redesign/), [`../movies-redesign/`](../movies-redesign/)): each screen was
prototyped on the maintainer's real data, reviewed in a clickable prototype and approved, and every query was
measured on a restored production catalogue before it was written into the data contract. Nothing here is
implemented yet.

| Screen | Status |
|---|---|
| Adult releases (list) | **Approved** on the maintainer's prototype, 2026-09-29 |
| Release details (Adult) | **Approved** on the maintainer's prototype, 2026-09-29 |

The Adult design inherits the rules of both earlier sections (`SPEC.md` section 4). It has no wall and no
title page: adult releases carry no title, performer or studio data.

| Read | For |
|---|---|
| [`SPEC.md`](SPEC.md) | what the section is for, the two approved screens, every decision with the reasons, what was rejected |
| [`DATA-CONTRACT.md`](DATA-CONTRACT.md) | where every value the screens read is already stored (no new storage), which code writes it, and every read measured at full catalogue size |
| [`DATA-NOTES.md`](DATA-NOTES.md) | facts measured on the restored catalogue that shaped the design |
| [`evidence/`](evidence/) | the query-lab write-up the data contract cites |
| [`INVENTORY.md`](INVENTORY.md) | every feature of today's Adult screens, with `path:line` |
| [`prototype/`](prototype/) | the approved prototype on an **invented dataset** with placeholder pictures and no clips: `adult.html`, its behaviour checks (`check.mjs`) and 18 reference screens in both themes (`reference/`) |

```bash
cd docs/proposals/adult-redesign/prototype
python3 -m http.server 8766          # then open http://127.0.0.1:8766/adult.html
node check.mjs                        # must end "0 failures"  (needs Google Chrome)
```

Every release name, group, poster, NFO text, file name and PreDB title in the prototype is invented and
neutral; the pictures are generated placeholders. Only the shape of the real data is kept: sizes, dates,
completion, resolution, sub-categories, media-info fields and which releases have a picture, a clip, a PreDB
match or Similar releases. Desktop only, by the maintainer's decision.
