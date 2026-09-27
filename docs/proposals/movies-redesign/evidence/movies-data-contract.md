# Movies data contract: lab measurements

The measurements behind `../DATA-CONTRACT.md`, made in the maintainer's query lab on a restored copy of the production catalogue (outside this repository).

Restored production catalogue, full size: 2,359,525 releases (575,109 in the Movies band, 519,173 of them Movies > Other;
173,152 TV), 17,320 films (16,832 with a release). A build script (kept in the lab) builds a scratch schema beside the untouched production copy:
`releases` (the list columns + the #773 `resolution`/`source` from `labwork.releases_sim`, the generated `category_band`,
production's band and video indexes), `movieinfo` (+ proposed `vote_count`, `content_rating_us`, `original_language`, filled
from the 8,959-film TMDB sample on disk), `genres` (type 2000) + `movie_genres`, `people` + `movie_people` (role 0 director /
1 cast, position), `languages` + `release_audio_languages` (369,752 rows, 141 names, every category; InnoDB's estimate printed by the build was 359,417). Method: best of 3
warm runs in one session (SHOW PROFILES), rows read = Handler_read* after FLUSH STATUS. Visibility V = `passwordstatus <= 0 AND
categories_id NOT IN (one excluded sub-category)`. Every statement is kept in the lab's query files, named per table below.

## Movie releases list — release filters

| Query | Today's band index | ms / rows read | Filter-led index | ms / rows read |
|---|---|---:|---|---:|
| page 1, no filter | band_posted | 0.1 / 50 | | |
| page 200, no filter | band_posted | 1.5 / 10,151 | | |
| exact middle page (worst, mirrored past it) | band_posted | 37.6 / 289,826 | | |
| count, no filter | band_count | 46.8 / 575,110 | | |
| Category HD only, page 200 | band_posted | 61.5 / 507,298 | band_cat_posted | 1.7 / 10,016 |
| Category all but Other, page 200 | band_posted | 68.9 / 499,980 | band_cat_posted | 16.1 / 65,915 |
| Category all but Other, middle page | band_posted | 74.3 / 531,052 | band_cat_posted | 11.1 / 55,915 |
| Category all but Other, count | band_count | 51.5 / 575,110 | band_cat_posted | 6.2 / 55,915 |
| Resolution 1080p, page 200 | band_posted | 65.6 / 508,642 | band_res_posted | 1.5 / 10,043 |
| Resolution 4K or 1080p, page 200 | | | band_res_posted | 14.2 / 50,979 |
| Resolution SD + Source DVD, page 1 | band_posted | 29.5 / 236,241 | band_res_posted | 0.1 / 259 |
| Source WEB, page 200 | band_posted | 63.0 / 515,845 | band_src_posted | 1.9 / 10,045 |
| Added order, all but Other, page 200 | cat_posted + filesort | 43.1 | band_cat_added | 16.2 / 65,915 |
| Added order, 1080p, page 200 | band_added | 58.8 / 496,641 | band_res_added | 1.5 / 10,037 |
| Completion 100% only / 95%+, page 200 | band_posted | 6.2 / 7.4 | | |
| Completion 100% only, count | band_count (row lookups) | 291.7 | band_count + completion | 52.8 / 575,110 |

Cause: in date order a selective filter walks Movies > Other's 519,173 entries. Each filter-led index is 66.6 MB on the
full table (index size does not depend on the table's other columns). TV reference: Source WEB page 200 on today's index 2.2 ms.

## Film filters on the list, driven from the films (`STRAIGHT_JOIN movieinfo → releases`)

| Query | ms / rows read |
|---|---:|
| worst case (a film filter matching every film), page 200, posted / added (covering per-film index) | 16.7 / 16.6 |
| worst case, count | 13.0 |
| Genre Drama or Comedy, page 200 / count | 15.6 / 12.8 |
| Year 1990s + 2000s, page 1 | 4.3 |
| Score 7–8.9, page 200 | 5.5 |
| Too few votes, page 1 | 4.5 |
| MPAA R or PG-13, page 1 | 4.1 |
| Language English, page 200 | 5.3 |
| all five film filters + 1080p, page 1 | 3.8 |
| without the forced order (optimizer's own plan) | 350–406 (reads all 2.36 M) — rejected |

Per-film index: today's `ix_releases_movieinfo_cat (movieinfo_id, categories_id, passwordstatus, postdate)`, 100.8 MB, extended with
`adddate, resolution, source, completion` → 94.8 MB; the Added order then costs the same as Posted (16.6 vs 45.7 ms without).

## Audio

| Query | ms / rows read |
|---|---:|
| Movies, English (138,755 rows in every category, 19,610 movies), language side, any page / count | 88.5 / 84.3 |
| Movies, English, list index + EXISTS: page 1 / page 200 | 0.1 / 221.8 |
| Movies, Hindi, page 200 (language side) | 41.0 |
| Movies, Korean (rare), page 1 | 10.6 |
| TV, English, list index + EXISTS, page 200 / count | 7.4 / 74.6 |
| TV, Hindi, language side, page 200 | 40.7 |

## Films wall

| Query | ms |
|---|---:|
| newest release per film (index-only group-by, 44,501 entries) | 7.6 |
| visibility probe on all 16,832 films (count, and any page past the first) | 27.9 |
| newest releases first, page 1 (sort, then probe in order) | 7.0 |
| newest releases first / newest to the site, last page | 47.3 / 47.1 |
| A to Z, page 1 / last page (title index) | 0.1 / 27.7 |
| newest films first, page 1 (year index) | 0.2 |
| Drama + 2010s + score 7–8.9, page 1 | 29.1 |
| films with one person, page 1 | 15.1 |
| release counts for a page of 42 tiles | 11.0 |

## Film page, details, Similar

| Query | ms |
|---|---:|
| film page, largest film (152 releases), a page of 50 / header count + latest + best | 0.1 / 0.1 |
| details: the page holding this release (its rank) | 0.1 |
| Similar films with the viewer's exclusions (Heat, Inception, The Conjuring, Sully, Mass Jathara, Fist of the North Star) | 33.7, 17.2, 14.5, 19.9, 25.5, 15.3 |
| TV Similar shows with visibility (most genres, Breaking Bad, Doctor Who, The West Wing) | 36.8, 29.1, 32.4, 20.7 |
| Similar releases with the same film left out, on production's Manticore index (`AND movieinfo_id <> ?`) | 2 |

## TV releases list

| Query | ms |
|---|---:|
| all six show filters, page 1 (show side) | 17.2 |
| one broad show filter (Language English), page 200: show side / list index + IN | 77.4 / 11.7 |
| Language English, count | 61.8 |
| six show filters + Audio English + 95%+, page 1 | 25.8 |
| count, 95% or more (completion in the count index) | 15.8 |

## Data facts

- 44,454 movie releases carry `movieinfo_id` (44,435 agree with `imdbid`); 46,666 carry an `imdbid`. `MovieService` writes both.
- Today's strings are nearly whole: genres on 16,921 films (6 cut at 64 characters), directors on 16,961 (none cut), cast on
  16,748 (85 at the 2,000-character limit).
- Audio languages: 359,417 release–language rows from `media_info_tracks` and `audio_data`; 49,480 movie releases, 153,567 TV.

## TV list show filters, both forms

| Query | ms |
|---|---:|
| six show filters, list index + IN: page 1 / last page | 4.9 / 49.4 |
| six show filters, count (from the shows) | 15.4 |
| Network HBO page 1: list index + IN / from the shows | 5.4 / 2.9 |
| Language English exact middle, list index + IN (row lookups for videos_id) | 73.6 |
| Language Korean: list index + IN page 1 / last page; from the shows any page; count | 0.1 / 61.3; 11.2; 9.9 |
| band index extended with videos_id (76.7 MB): English middle / Korean last page / six filters last page | 34.9 / 26.3 / 4.3 |

## Review follow-up

| Query | ms |
|---|---:|
| Completion 100%, exact middle: band index without / with completion | 159.9 / 39.5 |
| everything but Other + 100%, middle page, category index with completion | 10.5 |
| per-value counts for the index choice (all users) | 79–88 |
| Other + 1080p page 1 on the resolution index | 5.0 |
| HD + Other middle page on the band index | 37.9 |
| Audio menu order, Movies / TV (all users) | 220.2 / 217.7 |
| Language menu order | 2.4 |
| Audio Unknown: count / exact middle | 134.9 / 99.6 |
| search people 'smi' / 'an' (111 thousand people): one query / two-step | 14.7 / 173.7; 14.0 / 41.3 |
| people name scan alone, 'an' | 12.4 |
| search films 'the' | 11.6 |
| TV Rating TV-14: count / page 200 from the shows / middle from the index | 33.3 / 43.2 / 33.4 |
| fresh-built sizes: category index with completion / band_posted with videos_id + completion | 85.7 / 94.8 MB |

Exact counts: movie_genres 38,291, movie_people 187,711 (first 12 cast), release_audio_languages 369,752.
Films with a new release in the last 30 days of the copy: 3,039; of them with a record older than 30 days: 34.
