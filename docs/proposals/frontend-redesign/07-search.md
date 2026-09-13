# 07 · Search

Decision: **one scoped search (option 3A)**. The header search is the only free-text search entry point. `x-inline-search`, the per-page forms on movies / music / games / books / series, the `?search_type=adv` mode and the dead `x-search-autocomplete` component are removed.

## Header search

`GET /search?q={text}&t={scope}`. Scope select: All, then each visible root. Suggestions (existing `api/search/suggest`) appear under the input as you type; `/` focuses the box from anywhere.

Inside a listing, the toolbar's **Search in {root}** field is not this search: it narrows the current listing (03).

## Results page

The release browser (03) in **Table** view, with a **query-chip bar** above the pager:

```
“dune” ✕   Scope: Movies ✕   Age ≤ 30 d ✕   actor: Chalamet ✕   [+ Add filter]     Did you mean dune part two?
```

- Each chip is one constraint; ✕ removes it and re-runs.
- **+ Add filter** offers: category, age, size range, completion, and for movies the structured fields actor / director / title / plot (the same fields `MovieSearchQuery` parses today). This replaces the collapsed "Advanced" panel.
- Prefix syntax typed into the box (`actor:"Emily Blunt"`, `director:villeneuve`, `title:…`, `plot:…`) is parsed into chips.
- "Did you mean" comes from the existing suggest endpoint.
- Results are unfiltered by the Cards rule (Cards is not offered on search results); the query applies to release name and matched entity title/artist.
- Header actions: **RSS for this search**.

Sort options: Newest, Title A–Z. Per page and pager as in 03. Selecting rows enables the bulk bar.

## Not in scope

The API's search endpoints and RSS feeds are frozen; the web search changes only the web layer.
