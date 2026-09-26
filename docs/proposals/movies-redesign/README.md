# Movies section redesign

The design of the redesigned Movies section, recorded while it is being designed. It follows
the same process as the TV section ([`../tv-redesign/`](../tv-redesign/)): each screen is
prototyped, reviewed by the maintainer in a clickable prototype and approved one at a time,
and every query is measured on a restored production catalogue before it is written down.
Nothing here is implemented yet.

| Screen | Status |
|---|---|
| Movie releases (list) | **Approved** on the maintainer's prototype, 2026-09-26 |
| Films (discovery wall) | Design not started; a placeholder route in the prototype |
| Film page | Design not started; a placeholder route in the prototype |
| Release details (Movies) | Design not started; a placeholder route in the prototype |

Decisions already taken for the three screens still to design are recorded in `SPEC.md`
section 6, so they are not asked again.

| Read | For |
|---|---|
| [`SPEC.md`](SPEC.md) | what the section is for, the approved Movie releases screen, every decision so far with the reasons, what was rejected |
| [`DATA-NOTES.md`](DATA-NOTES.md) | facts measured on the restored catalogue and the query experiments run so far; later storage decisions rest on them |
| [`INVENTORY.md`](INVENTORY.md) | every feature of today's movie screens, with `path:line`; the input for the screens still to design |
| [`../tv-redesign/`](../tv-redesign/) | the TV specification, contracts and prototype; the Movies design inherits its rules |

There is no sanitized copy of the Movies prototype in this folder yet. It is added when the
Movies design is complete, the same way `../tv-redesign/prototype/` was: an entirely invented
dataset with placeholder art, its behaviour checks and reference screenshots. No storage or
schema is proposed yet either: that is decided after the remaining screens are designed.

Desktop only: phone layouts are out of scope by the maintainer's decision.
