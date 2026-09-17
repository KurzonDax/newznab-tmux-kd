# Issue 683 audit, rollback and retained evidence

## Disposition

**Keep #683 open as a performance defect; do not mark it wont-fix.** Its evidence
and failed design are useful. The user explicitly approved this disposition and
the rollback on 2026-09-17. The issue now has `needs-triage`, no `ready-for-agent`,
and a replacement body preserving the regression while withdrawing the cache
instructions. It is not ready for a new implementation.

All seven uncommitted paths were archived with SHA-256 digests and restored to
the issue branch's HEAD, `483136faba78aa0624999a37b4aa6d183ed6d4d7`. `git status
--porcelain` was empty afterward. There were no #683 commits to revert and no
#683 patch merged into master. Existing committed tests and the #681 schema fix
remain intact. The clean #683 worktree/branch is retained; this task did not
delete its runtime or roll it forward to the newer documentation baseline.

| Path | Uncommitted change | Disposition |
| --- | --- | --- |
| `app/Services/Releases/TvEpisodeBrowser.php` | Whole ordered group cache, page slicing, page-show hydration, unbuffered cursor collecting every group into an array | Reverted; rejected catalogue-sized cache/read pattern |
| `app/Services/Releases/TvBrowseMembershipTable.php` | Explicit `fullCatalogue` flag and PRIMARY hint on that walk only | Reverted with abandoned implementation; retain measured finding, not a shipped stopgap |
| `config/nntmux.php` | Group-cache TTL | Reverted |
| `.env.example` | `TV_COVER_GROUPS_TTL` | Reverted; binary-safe patch preserves original diff |
| `tests/Feature/ReleaseBrowserControllerTest.php` | Cache identity and integer-only payload test | Reverted; integer payloads can still grow without bound |
| `tests/Integration/TvBrowseMemoryMariaDbTest.php` | Larger fixture, plan/read assertions, delayed cache-cap assertion, fixture index fidelity | Reverted; preserve diagnostic design/evidence for adaptation |
| `tests/Support/tv-browse-memory-probe.php` | Handler/plan measurements and request peak sampled before diagnostic serialization | Reverted; preserve probe in rejected patch archive |

No interim PRIMARY-only patch was selected or authorized for shipping. It could
be considered separately if immediate mitigation is requested; it still leaves
linear catalogue reconstruction on each cold request and must not be described
as the lasting design. Table remains the existing interim browsing option.

## Archived evidence

The archive contains the [complete rejected patch](evidence/rejected-issue-683.patch),
[raw final candidate log](evidence/issue-683-all-handlers.txt),
[raw baseline log](evidence/issue-683-baseline-all-handlers.txt),
[failed memory/cache log](evidence/issue-683-memory-request.txt), and
[archive manifest](evidence/issue-683-manifest.json). These files are historical
evidence, not active tests or changes to apply to the current worktree. A second
local archive also preserves exact copies of the seven modified files and the
original issue body. The archived patch has no migration or production credentials;
test connection values in logs are disposable fixture values.

Baseline: MariaDB 11.4, code `483136fab`. The experiment was never committed.
For the baseline comparison, the same modified fixture/probe ran with the two
production classes restored to baseline; the candidate classes were restored
after measurement. The recorded command and assertion outcome are in each log.
An isolated reconstruction requires that base plus archived patch; it is not a
command to run against the current project or production. Do not register this
large test in recurring CI without the required separate policy agreement.

## What was measured

**Scope limitation:** the probe bootstraps Laravel and calls
`TvEpisodeBrowser::paginate()` directly. It measures that module's cold/warm
execution, including its queries/hydration. It does **not** measure a complete
authenticated HTTP route, middleware, Blade rendering, browser behavior, or
production cache serialization. Earlier descriptions calling this a “whole
request” were too broad. Replacement proof must include the actual HTTP request.

The performance fixture used 10,000 and 20,000 shows, 100 metadata episodes/show,
three eligible releases/show (single, two-episode range, full S01 pack), and an
equal number of unrelated non-TV releases. The fixture included the composite
release primary key, category/postdate index, category index, show index and real
category distribution needed to expose the planner problem. Thus:

| Dimension | N | 2N |
| --- | ---: | ---: |
| Shows | 10,000 | 20,000 |
| Eligible TV releases | 30,000 | 60,000 |
| Non-TV releases | 30,000 | 60,000 |
| Metadata episodes | 1,000,000 | 2,000,000 |
| Episode groups | 100,000 | 200,000 |
| Membership pairs | 130,000 | 260,000 |

Memberships are 10 pack + 1 single + 2 range per show. This distinction matters:
engine work can scale with memberships rather than only release count. An earlier
all-TV fixture chose PRIMARY even on old code and was not a reliable regression
demonstration; catalogue distribution and indexes matter as well as row count.

| Measured cold `paginate()` work | Baseline N | Baseline 2N | Rejected candidate N | Rejected candidate 2N |
| --- | ---: | ---: | ---: | ---: |
| Total handler reads, all counters | 4,952,641 | 15,437,094 | 1,473,122 | 2,913,195 |
| Release-walk handler reads | 3,160,059 | 11,884,534 | 70,174 | 140,269 |
| Query count | 208 | 388 | 213 | 393 |
| Seconds on the isolated fixture | 2.7755 | 8.1869 | 2.1477 | 4.2594 |

Total read growth was approximately 3.12× baseline versus 1.98× candidate. The
candidate's warm page 2 used 1,393 handler reads at both sizes; warm refresh and
separately warmed title sort used 1,273 at both sizes. Each used 29 queries. The
asserted fixed 48-tile bounds were fewer than 1,500 handler reads and 50 queries.
These figures do not include the cost of deserializing ever-larger production
cache entries, more users/filter/sort combinations, concurrency, or growth in
releases per selected show. Page-show scope is not automatically page-sized work.

EXPLAIN showed catalogue walks becoming PRIMARY range scans without filesort;
show-scoped walks retained `ix_releases_videos_id`. Forcing PRIMARY on scoped
queries had scanned unrelated releases and was rejected during the experiment.

## User-approved experimental refinements and their limits

- Warm each sort before measuring cache hits; changing sort creates a cold key.
- Apply the 12× eligible-release bound to release walks, while also requiring
  cold total handler reads at 2N to be at most 2.5× N.
- Force PRIMARY only for explicitly flagged full-catalogue reads; do not infer
  the flag by inspecting SQL. Leave show/expansion/dialog reads unforced and
  assert the show index in EXPLAIN.
- Compare identical warm page reads at N/2N against a stated fixed bound.

Those approvals refined the experiment; they did not approve a larger cache
budget or establish that the architecture would scale indefinitely. The 2.5×
ratio rejects this baseline's behavior on this fixture; two measurements do not
prove asymptotic linearity, and the probe was not the full HTTP request.

The smaller memory fixture used 3,000/6,000 shows. Peak allocated PHP bytes were
63,438,848 and 71,827,456: exactly 8 MiB growth, below 128 MiB total. Its serialized
array-cache diagnostic reached **2,078,103 bytes**, failing the existing **131,072
byte** cap. That cap was not relaxed. Sampling request peak before serialization
separated diagnostic allocations; it did not validate a real cache store's cost.

The final performance test passed 82 assertions; baseline run failed on unscoped
warm walks, with the cold metrics also exceeding the proposed ratios/bounds. The
memory/cache test still failed. Earlier focused behavior checks passed, but the
final publish verifier was not complete and the new large test's CI registration
was unresolved. This was never a merge-ready patch.

## Lessons retained for replacement acceptance

Keep schema-faithful fixtures, EXPLAIN plus actual read work, every handler counter,
separate warm sorts when applicable, cold N/2N measurements, realistic selectivity,
and independent fixed membership expectations. Extend them to end-to-end HTTP,
large per-show populations, text search, deep pages and data-change races. Do
not retain assertions about a cache representation the replacement does not use.
