# TV membership and query redesign proposal

Status: **proposed direction; no replacement implementation or scale proof yet**.
Read the [accepted behavior](01-design-contract.md) before designing storage.

## Problem and recommendation

The episode-Covers redesign made request handling reconstruct many-to-many
release/episode membership from names across matching catalogue releases.
#643 bounded PHP batches but repeated that work for each request; its query
planner could also rescan/sort the catalogue for every batch. The rejected #683
experiment fixed that walk and cached every ordered group, introducing an
unbounded per-user/filter/sort payload. Larger cache allowance does not resolve it.

Recommended direction: establish canonical membership when release or episode
information changes and store/index it for reuse by all users and views. Request
eligibility remains current and user-specific. Store relationships once rather
than materializing a personalized catalogue. The cost is relationship storage,
write maintenance, an initial backfill and explicit consistency/recovery work.
This is a candidate architecture, not evidence that any particular SQL join or
search-index projection meets latency requirements.

## Verified schema and current ownership

Verified against committed `database/schema/mysql-schema.sql`, relevant code and
migration searches at the handoff baseline; this is not a live production audit.

| Data | Existing columns/identity | Consequence |
| --- | --- | --- |
| Release | `releases.id`, `videos_id`, `tv_episodes_id`, `searchname`, `display_name`, category/visibility and sort values | One direct episode link cannot represent a whole pack; link values can also carry processing status |
| Show | `videos.id`, `title`, `started`, `countries_id`, `imdb`, `tmdb`, `tvdb`, `tvmaze`, `trakt` | Basic TV Year is the series start year; provider filters refer to the show |
| Show details | `tv_info.videos_id` primary key, `publisher`, `summary` | Network and series Summary are not episode fields |
| Episode | `tv_episodes.id`, `videos_id`, `series`, `episode`, `firstaired`, `title`, `summary`, `se_complete` | `series` means season; aired bounds use `firstaired` |
| Existing episode uniqueness | `(videos_id, series, episode, firstaired)` | Does not guarantee one row per show/season/episode; canonicalization remains necessary |
| Current canonical identity | MIN episode ID per `(videos_id, series, episode)` | Same-show links to duplicate rows resolve to the canonical tuple |

Do not add fields to fixtures because a proposed query needs them. Schema,
indexes, nullability, composite keys and migrations must drive fixture shape.
The prior `video_data.id` failure is the concrete warning: that table's primary
key is `releases_id`; `audio_data` separately has `id`. #681 fixed the regression;
#682 tracks wider fixture fidelity and is not implementation-authorized here.

## Behavioral invariants

- Explicit accepted episode/range declarations take precedence over linked fallback.
  Reversed and cross-season ranges resolve to none. Do not substitute a guessed
  link when explicitly declared episodes are missing.
- Full-season declarations mean stored identified positive-numbered episodes of
  the declared show and season. Keep the declaration even when episode metadata
  has not arrived; otherwise later episodes cannot acquire the pack relationship.
- Fallback links must belong to the positively identified show. Resolve duplicate
  rows canonically; never bridge shows sharing the same title.
- Season 0 specials remain supported; episode 0 is not an identified episode tile.
- Visibility, exclusions, category/group/poster, completion/password, watching,
  basket and search criteria must be applied to the contributing releases before
  group totals and sort aggregates. A relationship is not permission to display it.
- Episode criteria must select matching membership, not merely a release's direct
  episode link. Proposed combined-predicate rule: Season, Episode and aired bounds
  must match the same episode. Settle null-date and independent-filter semantics.
- Counts use distinct underlying releases per episode. Deduplicate joins. Ordinary
  release lists use existence of qualifying membership and keep one row per release.
- Covers and expansions share normalized criteria; show-dialog pack separation
  and all-stored-show directory eligibility remain distinct existing contracts.

## Proposed logical storage, not a migration specification

There are two facts to preserve: the parsed release declaration (explicit episode
set/range, full season, or valid linked fallback), and its resolved canonical
episode memberships. A conceptual pair `(release identity, canonical episode
identity)` is unique. Enough declaration information must survive to resolve
future metadata, show repairs, duplicate changes and season packs correctly.

Consider a forward access path from release to memberships and a reverse path
from episode to releases, plus scoped show/season access for pack maintenance.
Actual table names, field widths, foreign keys, additional indexes and whether
declarations and memberships are separate persisted relations remain open. Do
not present conceptual names as existing columns. Confirm the real release key
and lifecycle before adding a foreign key, particularly the composite production
release primary key and legacy use of release ID alone.

Metadata fields such as summary, network and air date normally remain with their
owner. A chosen search projection may duplicate them intentionally, but then it
must specify freshness, update fan-out, rebuilds and source-of-truth ownership.
Do not duplicate broad text metadata in every hydrated release-row object.

## Search is part of page selection

The delivered #651 / ADR 0015 path answers `/search` through the release index,
then hydrates only that release page. It does not implement toolbar/Covers search.
It includes exact-then-fuzzy behavior, explicit fallback/outage behavior, entity
field lookup, a reachable-page window and intentionally stale linked titles
until resync. None of those product choices automatically applies to the planned
browse search. Its field registry is useful existing work, not a complete TV solution.

It is incorrect to fetch one release-index page and group it into an episode page:
other matching releases can create additional groups, change totals and reorder
groups, and one pack contributes to several episodes. It is also unacceptable
to enumerate every matching release ID into PHP/cache just to merge search and
membership in SQL. The engine that performs final group selection needs the
complete predicates and membership relation available within its execution plan.

Evaluate these concrete alternatives before finalizing the physical model:

| Candidate | Benefit | Work/risk to prove |
| --- | --- | --- |
| Indexed relational membership with database search/filter/group/count/page queries | Current metadata/visibility and canonical joins share one database model | Broad text predicates, exact counts, aggregate sorts and deep offsets can still scan/sort many matches; a new relation alone proves no latency bound |
| Stored membership with a search projection capable of membership-aware grouping | Could combine text retrieval and group page selection without exporting IDs | Must prove pack fan-out, per-member predicates, exact distinct counts/order, both supported drivers, reachable pages, update freshness and rebuild cost; one release page is insufficient |

A hybrid is viable only if it avoids an all-match transfer and proves complete
eligibility and counts. Do not choose a new search engine or dependencies here.
Avoid designing an abstract pluggable framework in advance of evidence.

Return bounded page DTOs and scalar totals through one consistent query interface;
all views use the same normalized criteria and canonical membership semantics.
Engine details and physical storage stay behind that interface. The proof must
include query plan and actual work, not merely a bounded PHP result array.

## Mutation and consistency audit required

Known entry points to inspect further:

| Mutation | Known entry point | Requirement for the design |
| --- | --- | --- |
| TV identification/linking | `TvProcessing/Providers/AbstractTvProvider` | Build/replace the release declaration and resolved memberships |
| Not-found/revisit status and relinking | Same provider; `TvEpisodeRevisitService` | Remove invalid prior membership; processing status is not an episode ID |
| Name correction/reidentification | `NameFixing/ReleaseUpdateService` | Reparse names; some paths reset both show/episode IDs |
| Show/episode metadata arrival/correction/deletion | Provider writes and all metadata/import/repair writers, not yet exhaustively enumerated | Reconcile affected declarations, canonical duplicates and pack fan-out |
| Release deletion and bulk updates | `ReleaseObserver` plus direct query-builder/SQL writers | No dangling or stale membership; observer-only maintenance is insufficient |
| Eligibility/order changes | Category/password/completion/grabs/downloads/watch/basket paths | Apply current eligibility; maintain any chosen denormalized projection correctly |

The inspected code includes query-builder and raw SQL updates that bypass model
events. An observer alone is not a full update plan. Audit all writers, races,
retries, imports and repair paths before promising correctness. Choose synchronous
updates versus asynchronous reconciliation explicitly; specify allowed staleness
and behavior during lag/failure. The existing search-title staleness exception
does not silently authorize stale episode filtering or leaked visibility.

## Backfill and rollout proposal

Use bounded, resumable work over existing releases, with idempotent writes,
progress/checkpoints, failure recovery and protection against overwriting newer
updates. Account separately for release rows, declarations and expanded membership
rows: full packs can multiply the last two substantially. Define how backfill and
ongoing mutations converge, verify coverage before switching reads, and establish
how incomplete coverage is reported rather than returned as an empty success.

Before cutover, compare semantic results against fixed independent fixtures and
sampled read-only comparisons where separately authorized. Define write/lock load,
disk growth and time budgets, rollback compatibility, and any index rebuild. A
page request must not compensate for missing backfill by rebuilding the catalogue.
Do not discard source release or metadata records. No specific backfill command
or production operation has been approved. See the [proof gates](04-decisions-and-acceptance.md).
