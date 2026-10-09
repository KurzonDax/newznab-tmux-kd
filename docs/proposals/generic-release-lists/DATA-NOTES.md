# Generic release lists: data notes

Facts measured on the restored production catalogue in the query lab (read-only, 2026-10-09; MariaDB 11.4, the
catalogue of 2026-09-20) that shaped the design in `SPEC.md`, and the timings of every list read at full catalogue
size.

## 1. The catalogue

| | |
|---|---:|
| Releases | 2,359,525 |
| Other band (categories 10 Misc, 20 Hashed) | 1,590,022 (Misc 285,557 · Hashed 1,304,465) |
| TV (5000) | 173,152 |
| Movies (2000) | 575,109 |
| Adult (6000) | 15,878 |
| Audio (3000) | 3,645 |
| PC (4000) | 1,362 |
| Books (7000) | 211 |
| Console (1000) | 17 |
| Groups with releases | 42 (the biggest holds 2,027,639) |
| Distinct poster identities | 482,337 (the biggest, 674,421 releases) |

- Two in three releases are in the Other band, and six in seven of those are hashed names: the All releases list is
  mostly junk on its first pages, which is why "Exclude Other" sits in its Category menu.
- 482,337 poster identities over 2.36M releases: a poster's list is usually short, and a few identities are huge.
- The longest poster identity is 51 characters, the longest group name 39.

## 2. The prototype's slice

The prototype shows the **newest 25,000 releases by posting date** across every category (three days of posting, 17 to
20 September 2026): Other 19,610 (Misc 5,367 · Hashed 14,243), TV 4,102, Movies 811, Adult 317, Audio 154, Books 3,
PC 3; 22 groups, 16,486 poster identities. Within the slice: 5,071 releases with media info, 38 NFOs, 2,058 with a
stored file list, 378 PreDB matches, 5,706 passworded, 4,861 partly complete, 692 matched to a film, 3,409 to a show,
12 with audio tags, 732 with a preview image, 46 with a sample image, 69 with a clip.

**The restored catalogue holds one release report and no comment, none in the slice**, so the Reported / Response
chips and the comment counts on nine releases are invented in the prototype (marked as such on the release page).

## 3. The list reads at full catalogue size

Today's generic list query (`ReleaseBrowserQuery`: `releases` filtered by `passwordstatus`, the user's excluded
categories, the group, the poster identity or the category, sorted, 50 a page, with a count) on the lab catalogue.
Best of three warm runs, the client's own timing, in one session.

| Read | Page read | Count |
|---|---:|---:|
| All releases, page 1 (Posted newest) | 0 ms | 140 ms |
| All releases, page 200 (offset 10,000) | 9 ms | |
| All releases, page 20,000 (offset 1,000,000) | 342 ms | |
| All releases, Added newest, page 1 | 1 ms | |
| A group's list, page 1 (a group of 5,529 releases) | 7 ms | |
| A group's list, page 200 (the biggest group, offset 10,000) | 9 ms | 139 ms |
| A group's list with Category TV, page 1 | 6 ms | |
| A poster's list, page 200 (the biggest identity, offset 10,000) | 70 ms | 279 ms |
| Other, page 200 (offset 10,000) | 10 ms | 145 ms |
| Category TV on All releases, page 1 | 0 ms | 159 ms |
| Exclude Other on All releases, page 1 | 0 ms | 162 ms |
| Completion 95% or more on All releases, page 1 | 0 ms | 267 ms |
| Name search "S01E" on All releases, page 1 | 0 ms | 444 ms |
| **Name A to Z** on All releases, page 1 | **2,098 ms** | |

- Every page read but Name A to Z is served from an index in order (`ix_releases_postdate_admin`,
  `ix_releases_adddate_id`, `ix_releases_groupsid`, `ix_releases_fromname_postdate`, `ix_releases_categories_postdate_admin`),
  so a page costs the same whether it is page 1 or page 200; a page a million rows deep walks the index to it (342 ms).
- The counts are the cost of every list (140 to 444 ms). Today's list pays them too.
- **Name A to Z** sorts the whole catalogue (2.1 s): there is no index on the display name expression. It is today's
  sort at today's cost; `SPEC.md` Appendix A 10 keeps it as such.
- The name search is a `LIKE '%…%'` on `searchname`, walking newest-first until 50 matches (0 ms for a common word);
  its count scans the matching rows (444 ms for "S01E", 199k matches).
