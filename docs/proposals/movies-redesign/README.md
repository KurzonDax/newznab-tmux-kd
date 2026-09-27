# Movies section redesign

The design of the redesigned Movies section, recorded while it is being designed. It follows
the same process as the TV section ([`../tv-redesign/`](../tv-redesign/)): each screen is
prototyped, reviewed by the maintainer in a clickable prototype and approved one at a time,
and every query is measured on a restored production catalogue before it is written down.
Nothing here is implemented yet.

| Screen | Status |
|---|---|
| Movie releases (list) | **Approved** on the maintainer's prototype, 2026-09-26 (its filter bar approved later the same day) |
| Films (discovery wall) | **Approved** on the maintainer's prototype, 2026-09-26 |
| Film page | **Approved** on the maintainer's prototype, 2026-09-27 |
| Release details (Movies) | Design not started; a placeholder route in the prototype |

Decisions already taken for the screen still to design (release details) are recorded in
`SPEC.md` section 6.5, so they are not asked again. The rules of 2026-09-27 ("Follow" wording and
the bookmark icon, offsite links in a new tab, no "same name posted more than once" line) apply to
every screen of both sections (`SPEC.md` section 4). The filter bar of `SPEC.md` 5.1 is shared with the TV
section (`../tv-redesign/SPEC.md` 3.0).

| Read | For |
|---|---|
| [`SPEC.md`](SPEC.md) | what the section is for, the approved screens (Movie releases, Films wall, film page), every decision so far with the reasons, what was rejected |
| [`DATA-NOTES.md`](DATA-NOTES.md) | facts measured on the restored catalogue and the query experiments run so far; later storage decisions rest on them |
| [`INVENTORY.md`](INVENTORY.md) | every feature of today's movie screens, with `path:line`; the input for the screens still to design |
| [`prototype/`](prototype/) | the approved prototype on an **invented dataset** with placeholder art: `movies.html`, its 253 behaviour checks (`check.mjs`) and 13 reference screens in both themes (`reference/`) |
| [`../tv-redesign/`](../tv-redesign/) | the TV specification, contracts and prototype; the Movies design inherits its rules |

```bash
cd docs/proposals/movies-redesign/prototype
python3 -m http.server 8766          # then open http://127.0.0.1:8766/movies.html
node check.mjs                        # must end "0 failures"  (needs Google Chrome)
```

In the prototype's data every name is invented: film titles, people, plots, release names, Usenet
groups and posters, NFO text, file names, and the IMDb and TMDB ids (so the IMDb and TMDB buttons
point at made-up pages). What follows the maintainer's catalogue is only its shape: how many
releases a film has, sizes, dates, completion, resolution, source, media-info formats, chips,
genres, languages, ratings and scores, so the checks meet the same cases. The release details
route (`#/release/<id>`) is still a placeholder: that screen is designed next. No storage or
schema is proposed yet: that is decided after the remaining screen is designed.

Desktop only: phone layouts are out of scope by the maintainer's decision.
