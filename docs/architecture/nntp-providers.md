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

## Provider 1 owns the headers

Article *numbers* are per-server. Every piece of header-scan state — a group's
`first_record`/`last_record`, backfill ranges, part-repair ranges — is a set of article numbers,
and those numbers mean nothing on another backbone. So header work is pinned to provider 1.

This is enforced structurally rather than by convention: `NntpProviderPool` and the
`ProviderClient` interface expose **no header operation at all**. There is no XOVER, no group
selection, no backfill on the pool's API, so no future caller can accidentally scan headers
against a fallback backbone.

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
state to keep consistent. Header work has no alternative provider to fall back to, so it is
unaffected — a broken primary surfaces there as it always has.

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
