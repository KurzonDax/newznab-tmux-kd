# 06 · Release details

Decision: **header-first with tabs (option 4A)**. Route stays `/details/{guid}`.

## Layout

```
Breadcrumb   Movies › Dune: Part Two › UHD          (entity link when matched; category otherwise)
[ header card ]
  artwork (entity artwork, 120 wide; placeholder if none)
  H1 release name (20 px, break-all)
  facts chips: completion · password · media info · NFO · preview · group
  line: category pill · size · files · added (relative) · posted (absolute) · grabs · comments
  action row (full-size x-button): [⬇ Download NZB  success] [Add to basket / Remove from basket  secondary]
                                   [NFO] [Media info] [Files (n)]  secondary → switch to that tab
                                   [♡ Watch / ♥ Watching  when matched]  [⚑ Report  ghost]
[ tabs ]  Overview · Files (n) · Media info · NFO · Comments (n)        [ rail ]
[ tab body ]                                                             Other releases of this title
                                                                          Similar releases
```

The facts sidebar of the current page (category, size, files, completion, group, posted) no longer exists as a separate block; those values are in the header, so on a phone they are above the tabs, never below the comments.

## Tabs

- **Overview** — the type partial (movie / series / album / game / book fields) as labelled values, then PreDB match, password status, group, poster. Keep the existing partials' content; only the wrapper changes.
- **Files (n)** — the file list (existing endpoint), name and size per file.
- **Media info** — the existing media-info rendering; "No media info for this release." when none.
- **NFO** — the NFO text in monospace on the dark NFO pane (same rendering as the NFO modal); "No NFO for this release." when none.
- **Comments (n)** — paginated (25), **only visible comments**, count matches the row chip; timestamps via `userDateDiffForHumans()`; post box with a submit button; success via toast. Edit/delete for the author and moderators may follow later.

Tabs are server-rendered anchors (`#files` etc.) so deep links work, switched client-side once loaded.

## Rail (right column, 300 px; below the tabs on phones)

- **Other releases of this title** — the entity's other releases: quality/source, size, completion chip, each linking to its details.
- **Similar releases** — the `searchSimilar()` result the controller already computes and currently discards; release names linking to details. Hidden when empty.

## Actions and consistency

Download is `variant="success"`, basket / NFO / media info / files are `secondary`, Watch is `secondary` (primary when watching), Report is `ghost`. Icons: `fa-download`, `fa-shopping-basket`, `fa-file-lines`, `fa-circle-info`, `fa-folder-open`, `fa-heart`, `fa-flag`. These are the same semantics as the row buttons in 01; NFO is not blue and the cart is not primary.

Size uses the shared formatter (`size_formatted`), so a 40 MB release reads "40 MB" here and in the list.
