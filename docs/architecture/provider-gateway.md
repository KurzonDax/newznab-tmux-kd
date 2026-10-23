# The provider gateway

Every request NNTmux sends to an external metadata service goes through one class,
`App\Services\ProviderGateway\ProviderGateway` (`app/Services/ProviderGateway/`). For each
service it owns request spacing, window budgets, retries (honouring `Retry-After`), circuit
breaking, the outcome of each call and per-day counters. Usenet servers do not go through it;
they have their own pool and breaker, described in [NNTP providers](nntp-providers.md).

The gateway has one entry per service, named in `config/provider-gateway.php`:

| Entry | Service |
| --- | --- |
| `tmdb`, `tvmaze`, `trakt`, `tvdb` | TV and film metadata |
| `imdb` | IMDb title pages and the `/find` HTML search |
| `imdb_search` | IMDb's suggestion search (`v2.sg.media-imdb.com`), with its own breaker so that blocked title pages do not stop it |
| `omdb`, `fanarttv` | Film details and artwork |
| `musicbrainz`, `acoustid`, `coverart` | Music identity: MusicBrainz, AcoustID, Cover Art Archive |
| `srrdb` | SRRDB archive-CRC name fixing |
| `decision_model` | The self-hosted [decision model](decision-model.md) |
| `igdb`, `anilist` | Games and anime |
| `isbndb`, `googlebooks`, `openlibrary`, `itunes` | Books and iTunes lookups |

## What one call comes to

Each call ends in one of four outcomes (`ProviderOutcome`):

| Outcome | When |
| --- | --- |
| Found | Any status below 400 (unless the caller reads the body and reports otherwise). |
| NotFound | 404, or a caller's report that an empty 200 means no match. |
| Rejected | Any other 4xx: the service refused the request. |
| Unavailable | 429, 5xx, a status in the entry's `unavailable_statuses`, a timeout or connection error, or the gateway declining to send. |

Only Found and NotFound answers are cached (`ProviderGateway::remember()`, under each
service's existing cache key and TTLs). An outage is never cached as "not found". A TV release
that a TV service could not answer stays pending for a later pass instead of being settled as
unmatched (`app/Services/TvProcessing/TvProcessingPipeline.php`).

## The path of one attempt

Every attempt, including each retry, is asked in this order:

1. **Breaker**: is the entry's circuit breaker open?
2. **Window budget**: has the entry used up `budget.max` requests in the current window?
3. **Caller budget**: a caller's own cap, such as SRRDB's requests per cycle.
4. **Pacer**: wait for the entry's next request slot.

If any of them declines, nothing is sent: the request fails as a connection error
(`ShortCircuitConnectionException` for Laravel's HTTP client, `ShortCircuitConnectException`
for Guzzle clients) and the call is Unavailable. A request the gateway declined to send never
counts toward a breaker, but it is counted (`budget_refusals`, `pacer_skips`).

## Configuration

`config/provider-gateway.php` holds a `defaults` block and one `providers` entry per service
that overrides what it needs. It has no environment keys. Services that already had
configuration keep it as the source of truth, and `ProviderPolicies` reads both on every call,
so a change applies to the next request (after `php artisan config:cache` where the
configuration is cached).

| Key | Default | Meaning |
| --- | --- | --- |
| `pace.min_interval_ms` | 0 | Real requests start at least this far apart. |
| `pace.scope` | `shared` | `shared`: one sequence for every worker, through the cache store. `process`: this process only. |
| `pace.when_busy` | `wait` | `wait`: sleep until the slot. `skip`: refuse instead of waiting. |
| `pace.hosts` | `null` | Pace only requests to these hosts; `null` paces every request. |
| `pace.serial` | `false` | One lookup at a time across workers, starting `min_interval_ms` after the previous one ended. |
| `pace.lock_wait_seconds` | 10 | How long to wait for the shared pacing lock before refusing. |
| `pace.hold_on_429_seconds` | 0 | A 429 that is not retried holds the pacer at least this long. |
| `budget.max` | `null` | At most this many requests per window, shared; further requests are refused. `null` means no budget. |
| `budget.window_seconds` | 60 | The budget window. |
| `budget.key` | `null` | Rate-limiter key; `null` uses the entry's own key. |
| `retry.attempts` | 1 | Attempts in total, counting the first; 1 means no retry. |
| `retry.backoff_ms` | 0 | Wait before the first retry. |
| `retry.multiplier` | 1 | Each later retry waits this many times longer. |
| `retry.transport` | `true` | Retry timeouts and connection errors. |
| `retry.server_errors` | `true` | Retry 5xx (and bodies a caller classified as unusable). |
| `retry.statuses` | `[429]` | Other statuses that are retried. |
| `retry.retry_after_max_seconds` | 30 | A longer `Retry-After` is never waited in the worker: the call is Unavailable and the pacer is held instead. Any pacer wait longer than this is refused. |
| `retry.default_retry_after_seconds` | `null` | `Retry-After` assumed for a 429 that carries none. |
| `breaker.failure_threshold` | 3 | Consecutive attempts without an answer that open the breaker. Any answer, even a 404 or 4xx, resets the count. |
| `breaker.open_seconds` | 60 | How long it stays open. 0 turns the breaker off; `null` keeps an `instance` breaker open until its owner is discarded. |
| `breaker.scope` | `shared` | `shared` (every worker), `process` or `instance` (per owning object). |
| `breaker.escalate` | `true` | Shared breakers only: see below. |
| `unavailable_statuses` | `[]` | Statuses besides 429 and 5xx that mean the service is unavailable, such as a spent quota. |
| `ledger_host` | `null` | The recovery ledger's name for calls made through `call()`. |

A `Retry-After` on an unavailable answer holds the entry's pacer for that long, in every worker
when pacing is shared, so the retry and any other caller wait it out. If the shared lock
backend fails, the request is paced in this process and goes ahead, with one logged warning.

### Breakers

A **shared** breaker lives in the cache store, so every worker sees it. When its open period
ends, one trial request is let through (claimed for up to 120 seconds); every other caller is
refused until the trial reports. A failed trial re-opens the breaker for twice the previous
time, never more than an hour; a success closes it and resets the doubling. A **process**
breaker is open for its open time in that process only. An **instance** breaker belongs to one
object, such as the SRRDB lookup service or the decision model client.

### What each entry sets

| Entry | Settings (from `config/provider-gateway.php` and the service's own configuration) |
| --- | --- |
| `tmdb` | 1 s spacing, shared. Attempts `tmdb.retry_times` (`TMDB_RETRY_TIMES`, default 3), backoff `tmdb.retry_delay` (`TMDB_RETRY_DELAY`, default 100 ms). Neither key is in `.env.example` yet. |
| `tvmaze` | 1 s spacing. It is called through `call()`, so it is paced, counted and breaker-guarded but never retried. Ledger host `api.tvmaze.com`. |
| `trakt` | 1 s spacing. A 429 without `Retry-After` is treated as 60 s. Attempts `TRAKTTV_RETRY_TIMES` (3), backoff `TRAKTTV_RETRY_DELAY` (100 ms). |
| `tvdb` | 1 s spacing. |
| `imdb`, `imdb_search` | Defaults; each has its own breaker. |
| `omdb` | A 401 (the daily quota is spent) counts as unavailable. |
| `fanarttv` | Attempts `FANARTTV_RETRY_TIMES` (3), backoff `FANARTTV_RETRY_DELAY` (100 ms). |
| `musicbrainz` | Only requests to `musicbrainz.org` are paced, at least 1 s apart; lock wait 5 s. Attempts 3, backoff 250 ms; breaker 3 failures, 60 s (`config/music-identity.php`). |
| `acoustid` | Spacing from `music-identity.acoustid.requests_per_second` (3 a second). Attempts 3, backoff 1,000 ms doubling, `Retry-After` up to 30 s. |
| `coverart` | Serial: one lookup at a time across workers, 1 s after the previous one ended. |
| `srrdb` | Process-scope spacing from `SRRDB_REQUESTS_PER_SECOND` (never faster than one a second; 0 is unpaced). Attempts `SRRDB_RETRY_ATTEMPTS`, backoff `SRRDB_BACKOFF_MILLISECONDS` doubling; 408, 425 and 429 are retried and unavailable. Instance breaker after `SRRDB_CIRCUIT_BREAKER_FAILURES`, open until the lookup service is discarded. |
| `decision_model` | Attempts `DECISION_MODEL_RETRY_ATTEMPTS`, backoff 250 ms doubling. Instance breaker after `DECISION_MODEL_CIRCUIT_BREAKER_FAILURES`; the client is shared per process, so once open it stays open until the process ends. |
| `igdb` | Budget of 4 requests per 60 s, shared, under the existing `igdb_api_rate_limit` key. |
| `anilist` | 4 s spacing in each process (15 a minute). A 429 with a `Retry-After` of 30 s or less is waited and retried once; any other 429 pauses AniList in that process for 15 minutes. Transport errors and 5xx are not retried. Process breaker without escalation. |
| `isbndb` | The shared breaker opens on the first failure, for 300 s, doubling after failed trials. |
| `googlebooks`, `openlibrary`, `itunes` | Defaults. |

## Counters

The gateway keeps per-entry day counters in the cache store, each kept for eight days:
`requests`, `found`, `not_found`, `rejected`, `status_4xx`, `status_5xx`, `status_429`,
`transport_failures`, `retries`, `waited_ms`, `breaker_opens`, `budget_refusals`,
`pacer_skips` and `cache_hits` (`ProviderCounters`). A counter that cannot be written is
dropped; telemetry never fails a lookup. There is no admin screen for them; they are read with
`ProviderGateway::counters('<entry>')`, for today or a given day.

While an obfuscated-recovery publication is running, every attempt is also recorded in the
recovery ledger, `obfuscation_recovery_catalog` (see
[Obfuscated recovery](indexing-pipeline.md#obfuscated-recovery)).

## Key files

| Concern | File |
| --- | --- |
| Entry points (`http()`, `through()`, `guzzle()`, `call()`, `remember()`) | `app/Services/ProviderGateway/ProviderGateway.php` |
| Resolving an entry's policy | `app/Services/ProviderGateway/ProviderPolicies.php` |
| Spacing and holds | `app/Services/ProviderGateway/ProviderPacer.php` |
| Breakers | `app/Services/ProviderGateway/ProviderBreaker.php` |
| Counters | `app/Services/ProviderGateway/ProviderCounters.php` |
| Settings | `config/provider-gateway.php` |
