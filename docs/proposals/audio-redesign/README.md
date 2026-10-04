# Audio section redesign

The design of the redesigned **Audio** section (category band 3000): a releases list and a release details page. A
release whose file tags name an album shows the album (artist, album, year, genres, a cover slot), its track list, its
30-second preview played in the page with the spectrogram under it, and every release of the same album. It follows the
process of the sections before it ([`../tv-redesign/`](../tv-redesign/), [`../movies-redesign/`](../movies-redesign/),
[`../adult-redesign/`](../adult-redesign/), [`../books-console-pc-redesign/`](../books-console-pc-redesign/)):
each screen was prototyped on the maintainer's real data, reviewed in a clickable prototype and approved, and every query
was measured on a restored production catalogue before it was written into the data contract. Nothing here is
implemented yet.

| Screen | Status |
|---|---|
| Audio releases (list) | **Approved** on the maintainer's prototype, 2026-10-04 |
| Release details, with an album and without one | **Approved** on the maintainer's prototype, 2026-10-04 |

| Read | For |
|---|---|
| [`SPEC.md`](SPEC.md) | what the section is for, the approved screens, every decision with the reasons, what was rejected |
| [`DATA-CONTRACT.md`](DATA-CONTRACT.md) | where every value the screens read is stored, which code writes it, and every read measured at full catalogue size |
| [`DATA-NOTES.md`](DATA-NOTES.md) | facts measured on production that shaped the design |
| [`evidence/`](evidence/) | the query-lab write-up the data contract cites |
| [`INVENTORY.md`](INVENTORY.md) | every feature of today's Audio screens, with `path:line` |
| [`prototype/`](prototype/) | the approved prototype on an **invented dataset**: `audio.html`, its behaviour checks (`check.mjs`) and 9 reference screens in both themes (`reference/`) |

```bash
cd docs/proposals/audio-redesign/prototype
python3 -m http.server 8766          # then open http://localhost:8766/audio.html
node check.mjs                        # must end "0 failures"  (needs Google Chrome)
```

Every release name, guid, group, poster, NFO text, file name, PreDB title, artist, album, track title and MusicBrainz id
in the prototype is invented and neutral. Only the shape of the real data is kept: the release count of each
sub-category, sizes, dates, completion, password status, media-info fields, the tag genres, years and formats, and which
releases have an NFO, stored files, media info, a PreDB match, tags, a preview, a spectrogram, a track list, a
MusicBrainz identity or Similar releases. The covers are generated placeholders standing in for the album art the
MusicBrainz integration is to store; every preview is one generated tone and every spectrogram one placeholder picture.
Desktop only, by the maintainer's decision.
