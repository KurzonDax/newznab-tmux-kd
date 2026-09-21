# Is the approved Shows wall cheap at production size, on real show details? — measured

> Superseded in part: this run used a stored per-show summary row. The final design stores none
> (`DATA-CONTRACT.md` 2.3); the normalized wall queries are measured in `release-quality-columns.md`.
> The TMDB coverage numbers and the filter, count and search timings here still stand.

Experiment, not a proposal. Run 2026-09-21 against the query lab (restored production
catalogue). Read-only on `nntmux`; tables live in `labwork`. Show details are **real**: fetched
from TMDB for every show with a TMDB id (`fetch_tmdb.py`, one read-only call per show; the raw
file stays out of git in `data/`). 
## Verdict in plain language

Yes. **5,648 shows** have at least one TV release. With one stored summary row per show (when
its newest release was posted, when it first arrived) plus genre and cast link tables, every
sort, every multi-select filter combination, the person filter, the counts, the search box and
the filter menus' option lists return in **0.3 to 4.2 ms**, reading at most about 16,000 rows.
Working out "newest releases first" from the releases table on every view, instead of storing
it per show, costs **150 ms and 165,771 rows** for the same 42 tiles.

| What | Rows read | Time |
|---|---:|---:|
| Any sort, no filter, page 1 of 135 | 85 | 0.3 ms |
| Any sort, no filter, last page | 5,670 | 0.6–2.3 ms |
| Count for "Showing X–Y of N", no filter / one plain filter | 5,649 | 0.5–0.7 ms |
| Genre: Drama or Comedy (3,739 shows): page 1 / last page / count | 4,675 / 15,823 / 15,678 | 1.0 / 3.7 / 3.7 ms |
| Language, Network, Rating + Status, Premiered: page 1 / last page | 90–490 / ~5,680 | 0.3–0.5 / 2.3–2.5 ms |
| All six filters at once (1,820 shows), any sort: page 1 / last page / count | ~5,800 / 13,463 / 14,629 | 1.4 / 4.2 / 4.1 ms |
| "Starring" the most prolific actor (22 shows) | 115 | 0.4 ms |
| Search box: titles containing "star" / people containing "john" | 1,808 / 4,305 | 0.5 / 0.7 ms |
| Filter menus: all option lists | 23,960 | 3.8 ms |

The wall is small enough (thousands of shows, not hundreds of thousands) that deep pages are
cheap without the mirrored-order trick the releases list needed.

## What TMDB actually returns for this catalogue (facts for the spec)

Of 5,357 shows with a TMDB id: 5,353 found, 4 gone from TMDB, 0 failures. Of the 5,648 shows
on the wall:

| Field | Shows that have it |
|---|---:|
| Original language | 5,243 (93%) |
| Running / Ended status | 5,243 (93%) |
| Genres (17 distinct) | 5,150 (91%) |
| Cast (23,418 distinct people) | 4,800 (85%) |
| Network (existing `tv_info.publisher`, else TMDB; 591 distinct spellings) | 5,459 (97%) |
| Premiere year (TMDB, else the site's start date) | 5,645 (99.9%) |
| **US content rating** | **3,242 (57%)** |

So the Rating filter will always have a large "no rating" remainder (many shows are not
American), and Network needs its spellings merged (591 distinct values).

## What this means for the build

1. One summary row per show, kept in step when a release is added or removed for that show:
   newest `postdate`, earliest `adddate`, plus the show's stored details.
2. Genre and cast as link tables (`(genre, videos_id)`, `(person, videos_id)`), each also
   indexed by `videos_id`.
3. Four two-column indexes on the summary, one per sort, each ending in `videos_id`.
4. Counts can simply be counted: at this size a count is under 5 ms.
