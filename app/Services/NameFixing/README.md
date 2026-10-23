# Name fixing

`NameFixingService` coordinates evidence sources and delegates candidate
selection to `NameFixingQueryService` and persistence to
`ReleaseUpdateService`. A source-specific `proc_*` column prevents repeated
work. Only strong evidence may set `releases.is_trusted_name`; see the
repository glossary for the Trusted donor definition.

## Sources, policy and the writer

- `NameSource` is the registry: one case per source and evidence kind. Each
  case declares its stored `name_source`, its status columns on acceptance, its
  `proc_*` column on a miss, whether it skips plausibility, its `trustedDonor`
  value (`Trusted`, `Untrusted` or `Keep`) and the guards that apply. Call
  sites pass their case; method labels are only CLI echo text. A candidate
  carrying a PreDB id is trusted whatever its case, except a descriptive
  container title, which stays untrusted (`NamePolicy::trust()`).
- `NamePolicy::decide()` judges a `NameCandidate` without queries: the
  caller passes the recovery verdict and a loader for the stored files.
- `ReleaseNameWriter` is the only writer of an existing release's name, trust,
  `name_source` and rename status: *rename* (through the policy and the
  registry; clears the links and dispatches `ReleaseNameFixed`), *reset name*
  (reconciliation and neutral recovery labels) and *restore prior values*
  (a reverted MusicBrainz rename). `ReleaseUpdateService::updateRelease()` is
  its adapter and returns a `NameUpdateResult`.
- `NameTrust` holds the trust predicates the code reads.
- A formed release changes category through `CategoryChange`. Every kind of
  move except a rename clears only the links the category being left owns
  (`CategoryLinkOwnership`).

## Standard sweep

`NameFixingQueryService::standardCandidateSources()` is the single definition
of what the unwindowed standard sweep admits. `standardCandidateBatch()` (the
worker's GUID-partitioned page), `standardCandidateCount()` (the tmux monitor's
Fix Names backlog figure) and `hasStandardCandidates()` (its Fix Names wake-up
gate) are all built from it. The count and the gate also include pending PAR2
sidecar work (`Par2Sidecar\SidecarWork`), which `processStandardBatch()` resumes
before it reads its page.

Admission is per-source readiness only: unrenamed, no PreDB identity, not held
by an obfuscation-recovery publication that is still initializing or awaiting
enrichment (`RecoveryReleaseGate`), and at least one evidence source both ready
and unconsumed. There is no category
restriction and no cross-source gate -- a pending or terminally failed NFO
lookup and an unsettled `passwordstatus` must not hide another source's
evidence. The NFO term requires `nfostatus = 1`, PAR2 requires `nzbstatus = 1`,
and the dedicated XXX and media-movie terms require their corresponding file or
media-info evidence. The filename, SRR and CRC32 terms require a stored `release_files` row (any row, an `.srr`/`.srs` name, a non-empty CRC), because additional processing stores that evidence after the release exists, and the worker runs those legs only for releases with a stored file row. The PAR2-hash term is still a bare `proc_hash16k = 0` check.

SRRDB also carries readiness in its term, because
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
zero for a later cycle. Requests go through the provider gateway's `srrdb`
provider and have a cycle cap (retries included), courtesy rate limit,
timeouts, doubling retry backoff, and a circuit breaker that lasts for the life
of the lookup service instance.

Enable with `SRRDB_ENABLED=true`. The remaining `SRRDB_*` settings in
`.env.example` control the endpoint, user agent, request budget, retry policy,
cache TTLs, and total-size tolerance. Keep the default rate at or below one
request per second and use this source only for exact candidate lookups, never
bulk scraping.
