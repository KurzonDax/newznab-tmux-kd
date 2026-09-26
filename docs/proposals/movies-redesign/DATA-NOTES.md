# NNTmux Movies section: data notes

Facts measured on a restored copy of the production catalogue (2.36 million releases) for the
Movies design, 2026-09-26, and the query experiments run so far. Later storage decisions rest
on them.

**No schema is proposed here.** What is stored, in which tables, and which code writes it is
decided after the Films wall, the film page and the release details page are designed, the way
TV's `DATA-CONTRACT.md` was written after its screens were approved. `SPEC.md` section 7 lists
what the approved decisions already require.

Where a fact comes from TMDB rather than the catalogue, it comes from **a sample of 8,959 films
whose TMDB details were fetched once for the design**. That sample is used as it is; it is not
topped up.

---

## 1. The movies band

- **575,109 releases** are in the movies band.
- **519,173 of them are Movies > Other** (category 2999):
  - 464,407 have purely numeric names and 40,405 have random-token names;
  - they average 0.55 GB and were posted between 2025-08-15 and 2026-09-19;
  - only 450 carry an IMDb id;
  - 516,300 come from **one Usenet group**, which also files 1.2 million releases into
    Misc > Hashed.

  This looks like a categorisation fault. The rule that files these releases as Movies has not
  been traced. By the maintainer's rule "never judge a release", they are listed like any other
  release and the Category menu can leave them out (`SPEC.md` 6.1).
- The **named sub-categories hold 55,936 releases**, 46,216 of them with an IMDb id.
- The newest 50 movie releases by post date are all in named sub-categories; recent days are 95
  to 100% named.
- 9,720 releases in the named sub-categories have no matched film. They are listed without a
  poster or film line (`SPEC.md` 5.6).

## 2. Resolution coverage

- Resolution is known for **99%** of the 55,936 named-category releases and for **0%** of
  Movies > Other.
- The TV data contract's warning case, "only 6% of 575 thousand movies have a known resolution;
  1080p page 200 reads 508 thousand entries in 60 ms" (`../tv-redesign/DATA-CONTRACT.md`
  section 4), is caused entirely by Movies > Other.

## 3. Films and their metadata

### How releases link to films

- Releases link to films by `releases.imdbid = movieinfo.imdbid`. `videos_id` is 0 on every
  movie release. (`INVENTORY.md`, "How a release links to a movie", has the code paths.)

### How many releases a film has

17,569 films (distinct IMDb ids) have releases:

| Releases per film | Films |
|---|---|
| 1 | 9,363 |
| 2–3 | 4,752 |
| 4–10 | 2,899 |
| 11–30 | 536 |
| 31 or more | 19 (the largest has 152) |

### `movieinfo` coverage

For the films with releases (16,658 `movieinfo` rows):

| Field | Filled |
|---|---|
| cover | 99% |
| plot | 99% |
| director | 98% |
| genre | 98% |
| actors | 97% |
| rating | 92% |
| backdrop | 91% |
| tagline | 48% |
| trailer, language, Trakt id | 0% |

- `rtrating` holds `N/A` on all 17,320 rows looked at: it is useless.
- `rating` is a number from 0 to 10, mostly whole numbers, empty on 1,288 films.
- `genre` is one comma-joined string of at most 64 characters
  (`database/schema/mariadb-schema.sql:816`); longer lists are cut, which leaves fragments such
  as "Science F".
- `actors` is an unbounded comma-joined list (60 or more names on some films) and holds control
  characters on some rows.

### Films with releases, by decade

| 2020s | 2010s | 2000s | 1990s | 1980s | 1970s | older | no year |
|---|---|---|---|---|---|---|---|
| 6,119 | 5,397 | 2,384 | 1,178 | 694 | 389 | 524 | 136 |

### The score

- The stored `movieinfo.rating` is the first non-empty value of the IMDb scrape, TMDB, Trakt and
  OMDb (`app/Services/MovieService.php:478`). Which source supplied it is not recorded.
- In the sample it equals TMDB's `vote_average` for **97%** of the films that have a score:
  2,784 match to one decimal, and about 5,267 are whole numbers, written before the one-decimal
  rounding in `MovieService` arrived with #109 (`27939c945`, 2026-08-17). 179 differ.
- **IMDb supplies nothing today**: imdb.com answers the scraper with a block page (noted upstream
  on 2026-04-02), and the fallback service is cooling down or erroring. OMDb has no key on the
  maintainer's instance (`OMDB_APIKEY` is empty). The maintainer deals with the IMDb source
  separately.
- **TMDB vote counts** in the sample:

  | Votes | 0 | 1–9 | 10–99 | 100–999 | 1,000 or more |
  |---|---|---|---|---|---|
  | Films | 714 | 2,072 | 2,520 | 1,528 | 2,125 |

- **Why "Too few votes" exists**: in the sample, 106 of the 107 films scored 9 or more have
  fewer than 10 votes; so do 123 of the 312 films at 8–8.9 and 633 of the 1,005 films under 5.
  High and low scores are mostly films almost nobody rated.
- **Stored score bands** (films with releases):

  | 9+ | 8–8.9 | 7–7.9 | 6–6.9 | 5–5.9 | Under 5 | none |
  |---|---|---|---|---|---|---|
  | 243 | 656 | 3,724 | 5,837 | 3,157 | 1,931 | 1,273 |

### US certificate

- 3,701 of the 8,959 films in the sample (**41%**) have a US certificate on TMDB. Like TV's US
  rating (57%), the Certificate filter always leaves a large remainder without one.

---

## 4. Cost of Movies > Other on the list

Movies > Other is 90% of the band, so its cost has to be solved in the data contract:

| Query | Time |
|---|---|
| Count of the movie releases list | 40–52 ms |
| Page 200 with Other left out | 63–74 ms |
| For comparison, TV list page 200 | about 2 ms |

---

## 5. Experiment: the release list filtered by genre

Question: the maintainer put a Genre menu on the Movie releases list (newest first, numbered
pages). Can "releases of genre X, newest first, page N" cost the same on page 1 and page 200?

Setup, in the lab only: a genres table of the 19 real genres split from `movieinfo.genre` (plus
4 junk rows from strings cut at 64 characters); a genre-to-film link table with the genre
first in its primary key; a release-to-film table for all 575,109 movie-band releases (44,454
of them with a film). Date order comes from a full-size copy of the releases table carrying
the stored resolution and source columns and the band indexes of #773.

| Plan | Query | ms (2 runs) |
|---|---|---|
| Band index first, genre checked with EXISTS | Horror page 1 | 1.4 |
| | Horror page 41 (offset 2,000) | 258 |
| | Western page 1 | 37 |
| | Drama page 200 | 254 |
| **Genre first** (genre → films → releases, then sort) | Horror page 41 | 5.6 / 6.6 |
| | Western page 1 | 0.4 / 0.9 |
| | Drama page 1 | 14 / 16 |
| | Drama page 200 | 17 / 28 |
| | Drama release count | 3.7 / 4.2 |

Conclusion: drive a genre-filtered list from the genre side. Its cost is bounded by the genre's
release count (Drama, the largest, about 20 thousand rows) and is the same on every page. The
band-index plan is rejected: it depends on the page and reaches 250 ms deep in the list.

---

## 6. Experiment: "Similar films" from stored data

Question: the maintainer weighed today's "Similar releases" (name matching) against a list of
similar films already in the database. Can stored data pick sensible films, fast?

Setup, in the lab only: the genre tables of section 5; a people table and a film-to-people link
table (87,655 people, 187,711 links: the director at position 0, the first 12 cast at 1 to
12), split from `movieinfo.director` and `movieinfo.actors` with a case-insensitive name match;
the release-to-film table.

Rule tried: candidates share a genre or a person with the film and have a release;
score = 2 × shared genres + 3 × shared people − |year difference| / 10; the top 6.

- Each query took **11–34 ms**.
- The picks were checked by eye on six well-known films across genres and languages.
- Genre alone would not do: 7,379 films are Drama. Shared people carry most of the signal.
- The viewer's excluded categories are not applied yet. They are added, and the query
  re-measured, before it is specified.

The maintainer then decided: Similar releases stay on the details page, and a "Similar films"
row of 6 posters goes on the film page (`SPEC.md` 6.6).

---

## 7. The prototype's data

The maintainer reviewed the Movie releases screen on real data from the lab. The sanitized
copy with an invented dataset is added when the Movies design is complete. The review data:

- the newest 6,000 movie releases, behind 2,668 films, with real posters;
- vote counts and certificates from the TMDB sample, which covers 1,838 of the 2,668 films, so
  some films show no certificate;
- 224 of the 2,725 releases without a film have a name that states `Title.Year.quality` and get
  a name card (`SPEC.md` 5.6);
- one-line chip rates, measured on this data: 99.9% of rows at 1600 and 1440 px, 98% at 1366 px.
