# 05 · Entity overview pages

One template, `/title/{root}/{id}`, for a movie, a show, an album, a console/PC game and a book. It replaces `movies/viewmoviefull`, `series/viewseries` (and the `movie.view` / `series` routes keep working as aliases). Reached from: the **entity chip** on every row (Table and Cards), the **Title page** button in an expanded covers row, and the details page breadcrumb. Adult and Other have no entity and no page.

## Layout

```
Breadcrumb   Movies › Dune: Part Two
[ hero card ]
  artwork 180 wide (root aspect; placeholder if none)
  H1 title  (year, or artist for albums, muted after it)
  action row: [Watch]  [IMDb ↗] [TMDB ↗] [Trakt ↗] [▶ Trailer]      (per root, below)
  "On your My Movies for: UHD HD · change · remove"  (only when followed)
  metadata as labelled values (11 px label over 13 px value), wrapping grid
  plot / overview paragraph (max 80ch)                                (movies, tv, books)
  track list, two columns                                             (albums)
  stats: Releases · Latest · Best / Season packs
H2 Releases
[quality / format chips]  All · 2160p · 1080p · 720p      (only when more than one)
[ releases ]                                              (table, or season tabs for TV)
[ bulk bar when anything is selected ]
```

## Per root

| Root | Metadata rows | Links | Extra |
|------|---------------|-------|-------|
| Movie | Year, Rating, Genre, Runtime, Director, Cast | IMDb, TMDB, Trakt, Trailer | plot; stats Releases / Latest / Best |
| Show | Network, Status, First aired, Rating, Genre, Seasons | TVDB, TVMaze, Trakt | plot; stats Releases / Latest / Season packs. **No Cast row until issue #556 lands.** |
| Album | Artist, Year, Label, Genre, Tracks | MusicBrainz, iTunes | track list; format chips instead of quality; stats Releases / Latest / Best (`24-bit FLAC` / `FLAC`) |
| Game | Platform, Publisher, Genre, Released, ESRB | IGDB | stats Releases / Latest |
| Book | Author, Publisher, Published, Pages, ISBN, Genre | Goodreads, ISBNdb | overview |

Rating, genre and plot for TV depend on what `tv_info` holds today (summary only); show what exists and omit empty rows. A row with no value is omitted, not shown blank.

**Watch** (movies and TV only): secondary "♡ Watch" when not followed; primary "♥ Watching ▾" when followed (click reopens the picker to change categories); the line under the actions lists the chosen categories with *change* and *remove* links. See 08.

## Releases

**Movies, albums, games, books**: one release table (03 Table view, no toolbar, no pager unless > 100 rows) with the quality/format chips above it filtering in place (`All` resets).

**TV — season tabs (decision):** seasons are tabs across the top of the releases card, **ascending left to right** (Season 1, 2, 3 …), each tab showing its release count, with the **newest season selected by default**. Only the selected season's table renders. Above the table a season header: `**Season 3** · 5 episodes · 1 season pack · 7 releases` · spacer · **Select season** (selects every row for the bulk bar). Rows are ordered by episode number then age; season packs (`S03.COMPLETE`) sort after the episodes. Quality chips filter within the selected season. Specials are a tab named *Specials* after the numbered seasons. If the show has more seasons than fit, the tab row scrolls horizontally. Stacked season sections were rejected ("a page ten miles long").

Row actions are the same five as everywhere; the Watch button on rows is redundant here and may be hidden on the entity's own page.

## Behaviour

- Switching season, quality or format never reloads the page (Alpine state); the tab and chip state resets when navigating to another title.
- The bulk bar (03) appears when rows are selected.
- The page works for a title with no releases (empty state in the releases card) so links from search and watchlist never dead-end.
