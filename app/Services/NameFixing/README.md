# Name fixing

`NameFixingService` coordinates evidence sources and delegates candidate
selection to `NameFixingQueryService` and persistence to
`ReleaseUpdateService`. A source-specific `proc_*` column prevents repeated
work. Only strong evidence may set `releases.is_trusted_name`; see the
repository glossary for the Trusted donor definition.

## Standard sweep

`NameFixingQueryService::standardSweepPredicate()` is the single definition of
what the unwindowed standard sweep admits, and both
`standardCandidateBatch()` (the worker's GUID-partitioned page) and
`standardCandidateCount()` (tmux's Fix Names wake-up gate) are built from it.

Admission is per-source readiness only: unrenamed, no PreDB identity, and at
least one evidence source both ready and unconsumed. There is no category
restriction and no cross-source gate -- a pending or terminally failed NFO
lookup and an unsettled `passwordstatus` must not hide another source's
evidence. The NFO term keeps its own `nfostatus = 1 AND proc_nfo = 0`
readiness; the other terms are bare `proc_* = 0`.

SRRDB is the exception that carries readiness in its term, because
`processStandardBatch()` declines to settle `proc_srrdb` when the source is
disabled, the name is trusted, or there is no archive CRC to query with.
Admitting rows the worker will not settle would keep the pane awake forever,
so the predicate and the worker leg apply the same three gates.

Never restate any part of this predicate elsewhere: the tmux monitor's
`processrenames` count previously did, drifted, and let the pane sleep on real
UID/SRR/hash/CRC work.

## SRRDB Archive CRC source

The opt-in SRRDB source uses levels 21 (past six hours) and 22 (all eligible
releases), and also runs as a leg of the unwindowed standard sweep. It
considers releases without a PreDB identity that have an eight-hex-digit
Archive CRC and are unrenamed or still in Other/Hashed; the standard sweep
drops the category restriction and admits any unrenamed release. A trusted
renamed release is never reconsidered.

For each release, `FilePrioritizer` orders CRC-bearing files by primary archive
or media usefulness and then by descending size. `SrrdbLookupService` searches
`archive-crc` with the exact archive size and verifies the details response.
A match requires the same CRC and exact inner-file size, one surviving result,
and (for complete releases) a release total within the configured five-percent
default tolerance.

The `srrdb_lookups` table caches confirmed and negative outcomes by CRC with
timestamps and separate TTLs. `proc_srrdb=1` means processed; `2` means an
ambiguous or unverifiable search result. Network failures leave the value at
zero for a later cycle. Requests have a cycle cap, courtesy rate limit,
timeouts, exponential retry backoff, and a cycle-local circuit breaker.

Enable with `SRRDB_ENABLED=true`. The remaining `SRRDB_*` settings in
`.env.example` control the endpoint, user agent, request budget, retry policy,
cache TTLs, and total-size tolerance. Keep the default rate at or below one
request per second and use this source only for exact candidate lookups, never
bulk scraping.
