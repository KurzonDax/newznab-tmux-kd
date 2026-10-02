# Books, Console and PC: data notes

Facts measured on production (read-only, 2026-10-01) and on IGDB's live API (three reference requests with the
maintainer's own client credentials, 2026-10-01) that shaped the design in `SPEC.md`. Query timings at full catalogue
size are in `DATA-CONTRACT.md` and `evidence/`.

## Production, 2026-10-01

| Band | Releases | Sub-categories with releases | NFO | File list stored | PreDB match | Media info | Passworded |
|---|---:|---|---:|---:|---:|---:|---:|
| Books (7000) | 108 | Magazines 44, Ebook 62, Comics 2 | 2 | 14 | 1 | 4 | 3 |
| Console (1000) | 18 | PS Vita 6, PS3 3, Wii 2, Xbox 360 2, Xbox One 2, Other 2, PSP 1 | 1 | 7 | 0 | 1 | 2 |
| PC (4000) | 1,408 | 0day 1,177, ISO 165, Games 30, Phone-Android 29, Mac 6, Phone-IOS 1 | 135 | 538 | 21 | 16 | 39 |

- `bookinfo`, `consoleinfo` and `gamesinfo` hold **0 rows** on production (the restored lab catalogue agrees): the
  book and game lookups have never stored anything there. No release in these bands has a title, author, platform
  (beyond its sub-category), publisher, genre or cover. Today's metadata filters for these bands have no options.
- So every game value in the Console prototype (name, year, cover, genres, summary, storyline, credits, scores, modes,
  perspective, links) is **invented**, by the maintainer's request ("make up some data so that we can actually see what
  it looks like. That's the whole purpose of a mockup").
- Media info exists for 4 Books, 1 Console and 16 PC releases, which is why the Media info tab shows only when a release
  has some (`SPEC.md` 5A).
- Much of the data is filed in the wrong sub-category by today's categoriser: concert videos in Console, adult disc
  images under PC › ISO, newspapers and magazines under Books › Ebook. The screens show the rows as they are; the design
  does not depend on the categoriser.
- The seeded category 4999 is titled "Phone-Other" though its constant is `PC_OTHER`; 4040 `PC_PHONE_OTHER` is not
  seeded. No release is in either on production. The prototype names 4999 "Other".
- NFO text in a `mariadb --batch` dump keeps raw carriage returns: a loader that splits rows on CR cuts NFOs to one line.
  The prototype's loader splits on LF only and decodes CP437 (all 135 PC NFOs show in full).

## IGDB, 2026-10-01

Three requests to `api.igdb.com/v4` (`games` for ids 1942, 1020, 7346, 11198, 19560; `website_types`):

- The fields the design keeps all come back under these names with `fields *` plus expansions:
  `aggregated_rating` (critic score, 0-100, a float such as 91.73), `rating` (IGDB user score, 0-100),
  `storyline` (free text, absent for many games), `summary`, `game_modes.name` ("Single player", ...),
  `player_perspectives.name` ("Third person", ...), `involved_companies` with `developer` and `publisher` booleans
  and `company.name`, `websites.type` and `websites.url`.
- `website_types` id **1 is "Official Website"** (others: Community Wiki, Wikipedia, Facebook, Twitter, Twitch,
  Instagram, YouTube, the stores, Discord, ...). `websites.type` is the supported field (the old `category` is
  deprecated, as #914 found for age ratings).
- Each of the five games checked has exactly **one** official website, so one stored URL per game is enough.
- A company can be listed as neither developer nor publisher (porting studios): only the flagged ones are credits.
- The game's IGDB page is `url` (for example `https://www.igdb.com/games/the-witcher-3-wild-hunt`), already stored as
  `consoleinfo.url`.
