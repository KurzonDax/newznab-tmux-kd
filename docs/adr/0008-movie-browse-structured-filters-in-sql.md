# ADR 0008: Keep structured movie browse filters in SQL

## Status

Accepted

## Context

Movie browse results combine release availability with canonical movie metadata. The movie search table can be unavailable, empty, or briefly stale while it is rebuilt, so using it for exact genre, rating, or year constraints can incorrectly turn valid browse results into an empty page.

## Decision

Genre, minimum rating, and year filters are always evaluated against the relational movie metadata. Free-text title, actor, director, and plot constraints use the configured search index first, then fall back to partial per-word SQL matching when the index is unavailable or returns no movie IDs. Every text word remains required, while an unqualified word may match any searchable movie field. Movie metadata upserts explicitly synchronize the same search document used by the model observer.

Manticore's movie table enables prefix and infix matching. Changing those table settings requires a maintenance rebuild of only that logical table, followed by repopulation:

```bash
php artisan manticore:create-indexes --drop --index=movies
php artisan nntmux:populate --manticore --movies
```

## Consequences

Structured browsing remains correct during search-index outages and rebuilds, while text lookup keeps index-backed performance and partial matching. The SQL fallback is deliberately less scalable and may repeat a genuinely empty index search, but it preserves results instead of treating index state as canonical movie data.
