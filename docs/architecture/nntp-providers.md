# NNTP providers

NNTmux talks to one or more Usenet backbones. Providers are declared as numbered env groups
and assembled into `config('nntmux_nntp.providers')`:

```
NNTP_PROVIDER_{n}_NAME         short label, REQUIRED and unique
NNTP_PROVIDER_{n}_HOST         a provider exists only when HOST is set
NNTP_PROVIDER_{n}_PORT
NNTP_PROVIDER_{n}_SSL
NNTP_PROVIDER_{n}_USERNAME
NNTP_PROVIDER_{n}_PASSWORD
NNTP_PROVIDER_{n}_CONNECTIONS  advisory only -- nothing enforces it
NNTP_PROVIDER_{n}_TIMEOUT      socket timeout, seconds
NNTP_PROVIDER_{n}_ENABLED      excluded from every operation when false
```

Roles come from **position**, not from flags.

## Every enabled provider scans headers

A provider's header listing (XOVER) can leave posts out while the provider keeps accepting
connections. So header scanning reads every enabled provider and merges the results: a post
missing from one provider's listing is filled in from another's, with no switchover step.
Headers for the same post land in the same `collections`, `binaries` and `parts` rows whichever
provider they came from, and a repeated part is ignored rather than counted twice.

Article *numbers* are per-server, so each provider keeps its own positions:

- **Provider 1** owns `usenet_groups.first_record`/`last_record`, backfill, part repair
  (`missed_parts`) and obfuscation-recovery capture, exactly as before. `groups:update` writes
  its server positions to `short_groups`.
- **Each secondary provider** (enabled, position 2 and after) scans forward only, with its own
  position per group in `usenet_group_provider_cursors`. `groups:update --provider=NAME` records
  its server positions there and finds a starting article for any group it has no position for,
  `secondary_header_start_hours` back (Usenet Ingest → Header download). The binaries pane
  queues its `articles:get-range --provider=NAME` ranges after all of provider 1's. Each pass
  reads a group's newest unread articles first, then works down into its backlog with what is
  left of `max_headers_iteration`, so a provider that starts far behind covers new posts at once
  and fills the backlog behind them. A completed range above the position is parked in
  `usenet_group_provider_ingested_ranges`; the position moves when the backlog below it is
  complete. Parts first stored from a secondary provider keep `parts.number = 0`, because their
  article number is another server's. A cursor's `last_record_postdate` follows the group
  frontier's rule: newest published posting date, capped at now, never backwards (see
  "Collection clocks" in [the indexing pipeline](indexing-pipeline.md)).

Release formation waits for a secondary provider that is live and caught up: an incomplete
collection is not formed until every such provider has scanned past its newest header plus the
release delay. A provider that has not completed a range for an hour, or whose position is more
than the delay behind provider 1, holds nothing back.

The pool itself still has no header API: `NntpProviderPool` and the `ProviderClient` interface
expose no XOVER, group selection or backfill. A header scan picks its provider explicitly with
`NNTPService::useProvider()`.

### Runbook: changing a secondary provider

Repointing a secondary provider at a different backbone needs no manual reset: its cursors
record the host they were found on, and a host change re-initialises them on the next pass.

A secondary server that renumbers its articles on the same host leaves its cursors beyond the
server's newest article, so it queues nothing and the `nntp-headers` status probe reports its
scanning stopped after an hour. Re-initialise it with tmux stopped:
`DELETE FROM usenet_group_provider_cursors WHERE provider = '<name>';`

### Runbook: changing the primary provider

Because group positions are provider-1 article numbers, **repointing provider 1 at a different
backbone invalidates every stored group position.** The numbers will still be valid-looking
integers, so nothing errors — the indexer just scans the wrong part of the spool, silently.

If you change `NNTP_PROVIDER_1_HOST` to a different backbone (not a rename, not a new hostname
for the same spool):

1. `php artisan tmux:stop` — stop header scanning first.
2. Reset the group positions so they are re-derived against the new numbering:
   `UPDATE usenet_groups SET first_record = 0, last_record = 0, first_record_postdate = NULL, last_record_postdate = NULL;`
3. `php artisan nntp:pool-status` — confirm the new provider authenticates.
4. `php artisan tmux:start`.

Swapping the *order* of two configured providers is the same operation: whichever provider ends
up at position 1 is the one the group positions must match.

This is a runbook step, not a schema constraint — there is no stored marker of which backbone a
group position came from.

## Article operations walk the pool

Body and STAT lookups by message-ID go through `NntpProviderPool`, in strict configuration
order: provider 1 first, then each further enabled provider. Failover is **per article** — an
article fails only once every enabled provider has failed it. STAT stops at the first provider
that reports the article exists, because the caller only needs to know it is retrievable
somewhere and a subsequent fetch walks the same pool anyway.

A provider that answers "I do not carry that article" (430/423) is healthy; only transport,
auth and protocol failures count against it.

### Circuit breaker

Per process, not shared: five consecutive failures skip a provider for article operations for
60 seconds. A worker that trips a provider does not punish its siblings, and there is no shared
state to keep consistent. Header scans do not fail over: each reads the one provider it was
pointed at, so a broken provider surfaces as its own failed ranges, and the `nntp-headers`
status probe reports a provider whose scanning stops.

## Connections are advisory

`NNTP_PROVIDER_{n}_CONNECTIONS` is observe-only metadata: a monitoring display and a sizing
hint. Nothing enforces it, deliberately — the numbers are an operator-chosen split of what may
be a *shared account budget* across backbones (two backbones under one provider can share a
single 100-connection allowance), which no per-process counter can police. A provider refuses
excess connections server-side and the breaker treats that refusal as an ordinary failure.

## Checking a deployment

```bash
php artisan nntp:pool-status
```

Connects to each enabled provider and reports transport, auth, latency and greeting, exiting
non-zero if any enabled provider fails. `/status` runs the same probe continuously: a primary
failure is Critical, any other provider failing is Major.
