# 08 · Watchlist

"Watchlist" is the merged My Shows + My Movies. Every existing capability stays: follow a title, choose which categories you want releases in, edit, remove, a view of releases from followed titles, and the RSS feed. What changes is that both roots share one page, one card, and one way to add.

## The Watch button (how a title gets added)

One control, identical for movies and shows, in three places:

1. **Entity overview page** (05): in the hero action row. `♡ Watch` (secondary) → `♥ Watching ▾` (primary) once followed.
2. **Covers view** (04): the heart on every Movies and TV tile (top-left, 24 px round). Hollow → filled.
3. **Release rows** (03): the fifth action button, only when the release is matched to a movie or show. Replaces today's purple "Add to My Movies" film button.

Clicking any of them opens the **watch picker** (10): a small dialog `Add “Dune: Part Two” to My Movies` · "Get releases in these categories:" · one checkbox per root category (Movies: UHD, HD, SD, Foreign, Other · TV: UHD, HD, SD, Anime, Foreign) · **Add** (primary) · **Cancel** · defaults to the user's last choice (first time: UHD + HD). At least one category is required. On Add: the title is stored with those categories, every heart/button for that title switches to the followed state, and a toast says `Added Dune: Part Two to My Movies · UHD, HD  Open` where *Open* goes to the Watchlist page.

When the title is already followed the same control opens the picker in edit mode (`Edit “…” on My Movies`, **Save**, and a **Remove** button). Removing shows a toast `Removed … from My Movies  Undo`; Undo restores it with its categories.

There is no full-page add form and no `?id=add` link. Today's `/mymovies?id=add&imdb=` and `/myshows?action=add&id=` become redirects into the picker on the entity page.

## The Watchlist page

Route `/watchlist` (aliases `/myshows`, `/mymovies` redirect here with the matching tab). Nav: the top bar's **Watchlist · n**.

```
Breadcrumb  Watchlist
H1 Watchlist                              [☰ View releases from these] [RSS]
[ card ]
  tabs:  My Movies · 7   |   My Shows · 12        [ ⌕ Find a movie to add… ]
  (when the find box has text) "Add" list: artwork · title · year/network · [♡ Add]
  one row per followed title:
     artwork 56 wide · title (link to overview) · year / network
                       Latest: <release name> · 2 h ago
                       category chips (UHD HD)                          [✎ Edit] [🗑 Remove]
  empty state: heart icon · "Nothing followed yet." · how to add · [Browse Movies]
```

- **Find a … to add** searches titles the app already knows (movies / shows) that are not yet followed and offers **Add** (opens the picker). This is new: today you cannot add a movie without first finding a release of it.
- **View releases from these** → `/browse/{root}?watching=1` (03): the release browser restricted to followed titles of that root, breadcrumb `Browse › Movies › Watching`, heading `Movies you follow`, with a **Clear** in the toolbar. The same filter is reachable from the Movies and TV browse headers as **Only titles I follow**.
- **RSS** copies/links the existing `rss/myshows` / `rss/mymovies` feed for the active tab.
- Edit opens the picker in edit mode; Remove removes with the Undo toast.

## Data

Existing `user_series` / `user_movies` (models `UserSerie`, `UserMovie`) with their category arrays. No schema change; the picker writes what the old forms wrote.

Rejected alternative, for the record: no Watchlist page, following only as a filter on the covers views. Randall chose the page (as prototyped).
