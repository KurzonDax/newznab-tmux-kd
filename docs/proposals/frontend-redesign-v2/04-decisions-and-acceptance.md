# Decisions, proof and delivery gates

This is a handoff of settled choices and remaining work, not a demand that the
user design database internals. Resolve engineering questions through inspection
and a separately scoped proof. Ask product questions with concrete examples,
a recommendation and tradeoffs. Do not ask again about settled visuals, the six
sorts, inner posted-descending order, pack eligibility or Music/Audiobooks split.

## Open decision register

| ID | Still unresolved | Recommendation and consequence |
| --- | --- | --- |
| D01 | Exact basic-search fields and token/phrase/negative/fuzzy semantics by root | Specify a shared contract across views, using existing parsing where it matches intent. Broader metadata matching can change counts and index/update cost; no engine switch has been approved. |
| D02 | SQL versus index execution for TV search plus membership grouping | Compare the concrete alternatives in the query proposal. Choose using exact-result tests and uncached plans/work; neither a stored relation nor one release-index page proves the grouped query. |
| D03 | Reachable pages, counts, outage and fallback behavior for browse | Preserve complete browse totals/later pages. Do not inherit `/search`'s window or stale-title behavior silently; any changed reachability/freshness is a product decision. |
| D04 | Combined Season/Episode/air-date predicates, blank/unknown dates and Episode without Season | Recommend one matching canonical episode satisfy all active criteria; keep a qualifying pack once in Table/Cards and only matching groups in Covers. Specify null-date handling and clarify whether an unqualified episode number spans seasons. |
| D05 | Advanced text fields versus exact label filters; Console option eligibility scope | Preserve existing exact-label semantics until an explicit partial-text contract is agreed. A free-text-looking demo does not establish substring behavior; use stored Console platforms, not sample choices. |
| D06 | Identifier normalization and validation | Recommend exact provider predicates with explicit accepted formatting and preserved IMDb leading zeros. Do not run IDs through fuzzy text matching. |
| D07 | Numeric rating semantics and Rotten Tomatoes data quality | Recommend the ranges described in the design contract, after verifying attribution/normalization. Missing/invalid stored values and data repair need explicit handling. |
| D08 | Search submission, clear/reset behavior, state persistence and legacy parameters | Recommend one coherent submit/Enter behavior without navigation mid-entry, reset affected pagination and preserve unrelated context. This is a proposal; accepted appearance alone did not settle interaction or old URL migration. |
| D09 | Precise shared compact tokens, basic-versus-Advanced sizing, responsive wrapping and Country control | Use accepted rendering as the visual reference; extract deliberate shared rules. The 420px Search value and a two-letter Country text input were recorded as proposals. Do not silently expand style scope. |
| D10 | Audio mode transitions and metadata gaps | Keep the accepted root/category mapping and book/music identity. Specify which Advanced values reset or persist when switching, and how non-album Music releases retain name-search eligibility. |
| D11 | Membership physical schema, freshness, mutation coverage and concurrent backfill | Prefer durable canonical relationships plus enough declaration information for future metadata. Prove all writers and choose consistency/recovery explicitly; added storage/write load is the tradeoff. |
| D12 | Absolute latency, concurrency and storage/write budgets | Establish before the large proof. Historical 12×/2.5×/<1500 criteria describe the abandoned experiment, not universal performance targets. A fast small fixture or a memory pass is insufficient. |
| D13 | Prototype show genres/status versus available stored metadata | Preserve accepted presentation where supported, but verify a real data source. Current core TV tables do not provide generic genre/status columns; do not fabricate them in code/tests or infer Ended from missing data. |

These questions are recorded rather than answered by assumption. Documentation
can be complete while implementation remains intentionally unready.

## Next-agent sequence

1. Read the handoff and current AGENTS/rules. Recheck base and related issues;
   do not develop from the older clean #683 branch without normal recovery/base
   handling. The user approved documentation and rollback, not replacement code.
2. Prepare one concrete behavioral/query/storage design against the actual schema.
   Include proposed columns/indexes explicitly labelled new, all known writers,
   backfill/cutover/recovery, and examples for open product semantics.
3. Agree the scope of a disposable query proof and its acceptance budgets. File
   or revise the scoped issue before changing project tests/prototype code. No
   new Artisan command, dependency, live API call or production experiment is
   authorized by this document.
4. Run the isolated proof. Report exact IDs/counts/order as well as resource data.
   Reject designs that need a full result-ID transfer or catalogue reconstruction
   on each page. Revisit the design when evidence fails; do not increase limits
   just to make the fixture green.
5. Reconcile and finalize #683's backend scope and #628's frontend scope using
   that evidence and the user's remaining decisions. Do not revive #627's obsolete
   implementations. Mark work ready only after scope and implementation authority
   are settled. Avoid unnecessary child-ticket proliferation.
6. Implement through issue worktrees, bounded checks, review, PR and confirmed
   merge. Production deployment/backfill and index rebuilds remain separate steps.

## Correctness matrix for the proposed proof

- TV-A membership: each show's S01 pack, E01 single and E01–E02 range produce
  ten groups, counts E01=3, E02=2, E03–E10=1. No metadata-only season 2–10 groups.
- TV-B edges: duplicate episode records, foreign-show links, identical show titles,
  missing identity, specials, accepted explicit range/list forms, reversed/cross-
  season rejection, explicit precedence, full-season forms and linked fallback.
  Expected answers must be specified independently of the new implementation.
- Each sort and trending: deliberately different postdate/adddate/grabs/name
  ordering, deterministic ties, and inner `postdate DESC, id DESC` in every card.
- Episode predicates: pack without an episode FK; ranges overlapping only part
  of the filter; combined criteria; unknown air dates; duplicate metadata dates;
  Table/Cards release deduplication; Covers group counts and expansion agreement.
- Search: name-only, metadata-only, overlapping matches, absent metadata, broad
  and selective terms; agreed phrases/exclusions/field filters; all supported
  views; exact IDs; first/middle/last pages and boundaries crossing packs.
- Visibility: different users/exclusions, password/completion, watch/basket,
  group/poster and category; no hidden releases in counts or sort aggregates.
- Mutation: rename, relink, episode arrival/correction/deletion, canonical-ID
  change, full pack before metadata, release deletion, direct SQL updates, retries,
  concurrent readers/backfill and update failure.
- Audio: colliding book/music IDs, book-only links in category 3030, every other
  Audio category retained in Music, no cross-root leakage, mode/filter persistence,
  complete expansion pages and correct result units.

## Resource proof requirements

Generate disposable fixtures outside the measured process using production-faithful
schema/index definitions. Include realistic non-TV rows/selectivity and vary shows,
releases/show, episodes/season and pack fan-out separately. Start at N and 2N, then
use a larger representative scale and agreed concurrency; equal per-show populations
alone can conceal a scan that grows with releases in the selected shows.

Measure actual authenticated cold HTTP requests through render, plus module/query
sub-measurements for diagnosis. Run first and deep pages, all sorts, representative
filter combinations, expansions and the show dialog. If caching exists, also test
warm, expired, invalidated and failed-cache behavior using the actual configured
store. Correctness and ordinary performance cannot depend on a warmed full catalogue.

Record elapsed and database time, query/index-call counts, EXPLAIN and actual
handler/engine work, fetched/hydrated rows, peak allocated PHP and process RSS,
cache bytes, storage size and write/backfill cost. Report every Handler_read
counter on MariaDB. Do not retain full query logs/candidate arrays inside the
measured process merely to instrument it.

Retain the established PHP guard: below 128 MiB and at most 8 MiB dataset-induced
growth at the comparable N/2N fixtures, without increasing production limits.
Measure full-request database growth, not just the release walk. Additional
absolute budgets and workload sizes are D12. Exact counts/aggregate sorts may
need database work across matches; measure and budget it rather than claiming
constant work from the presence of an index. Two sizes alone are not a proof of
asymptotic complexity.

## UI and integration acceptance

Use the accepted prototype and actual shared components at the reported 1869×1280
viewport, a wider viewport, tablet and phone sizes; confirm actual computed
geometry, wrapping and no horizontal overflow. Exercise all nine roots plus both
Audio modes, collapsed/expanded Advanced, inline custom ranges, complete labels,
selected Platform persistence, and supported views/sizes. Check all six themes,
keyboard/focus behavior, error states and ordinary release actions.

Verify page/expansion state is independent and stale async responses cannot
replace the current state. Preserve bounded display/DOM loading already delivered
for expansions and show dialogs. The prototype's in-memory arrays and simulated
actions prove none of this. Private screenshots stay private; synthetic fixtures
and written requirements suffice.

Follow current CI policy: focused bounded correctness locally, shared final
verification and required CI. Large performance acceptance needs explicit scope;
recurring CI expansion needs a separate agreed policy issue. This documentation
task runs documentation/policy and artifact-integrity checks only. None of the
proposed replacement acceptance gates is represented as already passed.
