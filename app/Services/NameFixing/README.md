# Name fixing

`NameFixingService` coordinates evidence sources and delegates candidate
selection to `NameFixingQueryService` and persistence to
`ReleaseUpdateService`. A source-specific `proc_*` column prevents repeated
work. Only strong evidence may set `releases.is_trusted_name`; see the
repository glossary for the Trusted donor definition.

## SRRDB Archive CRC source

The opt-in SRRDB source uses levels 21 (past six hours) and 22 (all eligible
releases). It considers releases without a PreDB identity that have an
eight-hex-digit Archive CRC and are unrenamed or still in Other/Hashed. A
trusted renamed release is never reconsidered.

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
