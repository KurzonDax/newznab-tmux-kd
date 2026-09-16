# ADR 0015: Web search is answered by the release index

The release index answers `/search` matching, filtering, sorting, counting and paging. Release documents contain linked titles as well as release names; SQL hydrates only the returned page. Collecting or caching all matching release IDs, or using SQL as the merge point, makes the work grow with the matching catalogue and is prohibited.

Entity-field prefixes resolve through the entity index to keys on release-link attributes (`imdbid` for movies, including releases without `movieinfo_id`). A prefix registration specifies its index, field, relational fallback and link attribute, so new fields and roots need no release-query changes. Empty or unavailable entity indexes retain ADR 0008's SQL metadata fallback. SQL release matching is used only when the release index is unavailable and `nntmux.mysql_search_fallback` is explicitly enabled.

Exact matching precedes fuzzy retrieval; fuzzy runs only when the exact total is zero and there are no exclusions. Displayed totals remain exact even beyond the reachable result window. Pagination stops at the configured `search.drivers.manticore.max_matches` window (10,000 for Elasticsearch) and tells users to narrow their search. An unavailable index shows no result count.

Linked-title changes after linking deliberately leave release documents stale until a release re-sync, `nntmux:search-repair`, or populate. Link changes trigger re-sync. Schema changes require a release-index rebuild and repopulation; deployment commands belong in the PR's operations notes. API, RSS, movie browse, toolbar and covers-page searches are unchanged.

The production projection stores the database collation weight of Display Name as `sort_name`, preserving browse ordering. Poster identity filters use a digest of the exact From header bytes, so text cleanup and search-engine string collation cannot merge distinct identities.
