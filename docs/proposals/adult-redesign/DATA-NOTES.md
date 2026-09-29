# Adult section: measured facts

Measured 2026-09-29 on the maintainer's query lab: a restored copy of the production catalogue
(2,359,525 releases, posted 2024-06-23 to 2026-09-20). Resolution and source come from the #773 rule
run over the whole catalogue in the lab. "Production" figures are read-only queries on the live
instance the same day. These are facts about one install's data, used to shape the design; they
size nothing.

## 1. Releases per sub-category

| id | Sub-category | Lab | With a picture (preview or sample) | With a clip | Production |
|---|---|---|---|---|---|
| 6010 | DVD | 8 | 6 | 0 | 8 |
| 6020 | WMV | 80 | 68 | 10 | 82 |
| 6030 | XviD | 2,043 | 2,026 | 151 | 2,072 |
| 6040 | x264 | 2,097 | 1,997 | 964 | 2,266 |
| 6041 | HD Clips | 785 | 372 | 98 | 864 |
| 6042 | SD Clips | 607 | 441 | 2 | 607 |
| 6045 | UHD | 977 | 879 | 381 | 1,105 |
| 6046 | VR | 101 | (no category row) | | 104 |
| 6047 | OnlyFans | 28 | (no category row) | | 30 |
| 6050 | Packs | 19 | 4 | 1 | 19 |
| 6060 | Imageset | 11 | 2 | 0 | 11 |
| 6080 | SD | 414 | 313 | 196 | 428 |
| 6090 | WEBDL | 0 | | | 0 |
| 6999 | Other | 8,837 | 178 | 0 | 9,499 |
| | **Total** | **16,007** | | | **17,195** |

## 2. Findings

- **Other is 55% of the section** (8,837 of 16,007) and almost none of it has a picture (178). It is the
  sorter's fallback for adult names that fit no sub-category, spread over eight groups (the largest holds
  1,997), not one group's fault. 3,381 of those releases are still waiting for additional processing and
  2,757 are passworded.
- **Outside Other**: 7,170 releases; 5,956 (83%) have a preview or sample picture and 1,803 have a clip.
  Resolution is known for 6,743 (94%); source for 286 (4%). This is why the list has no Source column,
  filter or chip.
- **The first page, newest posted first**: 19 of 50 releases have a picture with Other included, 47 of
  50 without it. This is why the Category menu has "Exclude Other".
- **Pictures**: a preview is a 16:9 frame from the video (800 × 450 thumbnail, some 1920 × 1080 full
  size); a sample is about 2.1:1 (650 × 308 thumbnail). This is why the list's picture is a 176 × 99
  frame, not a 2:3 poster. On production, 392 of the 2,589 adult samples have a full-size copy.
- **Clips**: 1,803 adult releases have one, all `video/mp4`, 26 MB and 31 seconds on average.
- **Audio**: 5,994 of 16,007 adult releases have an audio track in their media info; 2,233 (14%) name
  its language: 2,131 English and 166 in 16 other languages.
- **No title data**: adult releases have no title, performer or studio stored anywhere. The only
  title-like value is the media info's embedded file title.
- **VR and OnlyFans have no category row** on production or in the lab, though both migrations are
  recorded as run: on MariaDB installs the schema dump marks them as run so their inserts never execute,
  and the categories seeder deletes every category and does not re-add them. Effects today: those
  releases never appear on the Adult page, and users who hide Adult are not shielded from them. Today's
  sorter without its OnlyFans rule files production's 30 OnlyFans releases as 15 HD Clips and 15 Other.
  Filed as #879.
- **Similar releases**: today's query on production's search index finds matches for 9,185 of the 16,007
  lab releases.
