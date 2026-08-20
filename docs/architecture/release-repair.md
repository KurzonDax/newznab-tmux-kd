# Repair before delete

The completion sweep (`ReleaseProcessingService::deleteIncompleteReleases()`) used to delete any
release measured below `completionpercent`. Many of those were recoverable: the missing articles
were still on the provider and only the *headers* had been missed.

Verified empirically on release `9db10f50-b300-458f-998e-96d7b4db5054` — its poster used
PowerPost-style message-IDs (`part{N}of{M}.{TOKEN}@powerpost2000AA.local`, token constant per
file), the missing segment IDs were derived from files with as few as 2 of 211 segments seen,
and NNTP `STAT` returned EXISTS for every probed "missing" article, on two different files.

## The invariant

**The sweep never deletes a release without a completed repair process.** Its predicate lives in
one place, `App\Services\Releases\IncompleteReleaseSweepQuery`:

```
completion > 0 AND completion < completionpercent AND repair_outcome IN ('failed', 'skipped-floor')
```

Only *final* outcomes are deletable. The sweep does no timestamp arithmetic — the repair state
machine owns time and hands the reaper only what it has given up on. Manual commands
(`nntmux:delete-releases --completion-max`) remain operator overrides and bypass the gate.

`completion = 0` stays exempt: it is the "never measured" sentinel, meaning nothing declared a
part total, not that the release is empty.

## The state machine

`releases.repair_attempted_at` and `releases.repair_outcome` are both load-bearing: the sweep
reads the outcome, the retry pass reads both.

| Outcome | Meaning | Deletable |
| --- | --- | --- |
| *(null)* | Never offered to the repair engine | no |
| `retry-pending` | First pass fell short; one more pass is owed after 72h | no |
| `repaired` | Reached the completion target | no |
| `failed` | Both passes spent, still short | **yes** |
| `skipped-floor` | Measured under 10%; no articles were ever probed | **yes** |

A pass that could not *run* — no NZB on disk, an unparseable NZB, an NZB that could not be
written back — records nothing at all. Those say something about our storage, not about whether
the release's articles are still on the provider, so they must not advance the state machine:
two unmounted volumes in a row would otherwise be enough to mark a release `failed`. Such a
release is simply picked up again next invocation, and the command reports it under
"Not attempted".

Every release gets at most **two network passes**. The 72-hour retry window exists because fresh
releases are stale-promoted at 8 hours and repaired within hours, while their articles may still
be propagating across the provider farm: a first attempt at hour 10 can fail where a recheck at
hour 82 succeeds. For legacy releases the second pass costs a few STATs to confirm nothing has
changed, and one uniform rule beats branching on age.

## Commands

```bash
# Measure releases stored before completion was recorded. No network. --dry-run writes nothing
# and prints a completion-band histogram.
php artisan releases:backfill-completion --dry-run
php artisan releases:backfill-completion

# Re-derive rows already measured below 50%: the old arithmetic summed each file's declared
# total, which understates the obfuscated single-segment style by two orders of magnitude.
# Rerunnable, and disjoint from the pass above -- `0` is "never measured", not a low percentage.
php artisan releases:backfill-completion --understated --dry-run
php artisan releases:backfill-completion --understated

# One repair pass over a bounded batch. Runs hourly from the scheduler.
php artisan releases:repair-completion --dry-run -v
php artisan releases:repair-completion --limit=250
```

`releases:repair-completion` is bounded per invocation on purpose. Repaired releases feed back
into additional processing, whose capacity is `postthreads x maxaddprocessed` per cycle, and AP
claims newest-first — so a flood here would starve fresh releases. The reaper needs no throttle:
it only ever sees final outcomes, which appear at this drip rate by construction.

## How repair works

For each candidate release:

1. Parse the stored NZB; per file, compute the missing segment numbers from the declared total.
2. Detect a numbered message-ID template from the segments present (PowerPost/camelsystem style:
   the segment number varies, the token is constant per file). Two segments minimum — one sample
   cannot say which digit run is the part counter. Random-ID posters (Nyuu, ngPost) yield no
   template and the file is left alone.
3. Synthesize the missing IDs and **spot-check** them with `STAT` through the provider pool —
   two per file, capped at 20 per release. A file is accepted only when every sampled ID exists.
   Nothing unverified goes into an NZB: a wrong template fills the file with IDs that fail at
   download time, which is worse than leaving the release short. Stragglers are what PAR2 is for.
   When the per-release budget runs out mid-way the remaining files are left alone rather than
   accepted on a thinner sample — one confirmation is a far weaker argument against a wrong
   template than two. The sample is deterministic, which is what makes the second pass cheap:
   it re-probes exactly the IDs the first pass could not confirm.
4. Rewrite the NZB atomically (temp file, then rename) with the accepted segments in numeric
   order. `bytes` for synthesized segments is estimated from siblings — it is advisory, and the
   true size is unknowable without fetching the article.
5. Recompute completion from the rewritten NZB with the same formula creation time uses.
6. Apply the state machine, and re-queue for additional processing **only** when repair added
   segments *and* the release has no artifacts (no media info, no preview). A release AP cannot
   improve is not re-queued.

Article existence and fetch both go through `NntpProviderPool`, so repair gets cross-backbone
reach for free: an article missing on one provider is often present on another. See
[nntp-providers.md](nntp-providers.md).

## Rollout

The sweep is disarmed (`completionpercent = 0`) until the gate has been proven in production.
Re-arming is the last step, not the first:

1. Deploy. The migration adds `repair_attempted_at` / `repair_outcome`, both null, so nothing is
   deletable until the repair engine says so.
2. `php artisan releases:backfill-completion --dry-run` — check the band histogram looks like the
   sample (roughly two thirds at or above 95%), then run it for real. Follow it with
   `--understated` to restate the single-segment posts the old arithmetic measured at a fraction
   of a percent; without that they read as the most incomplete releases in the index.
3. Let `releases:repair-completion` drip for a few cycles. Watch that `repair_outcome` fills in
   at the expected rate and that `retry-pending` rows are not being deleted:
   ```sql
   SELECT repair_outcome, COUNT(*) FROM releases GROUP BY repair_outcome;
   ```
4. Only then set `completionpercent` back to `95` in the admin settings.

## What this does not cover

Files the header scan missed **entirely**. With no seen segment there is no message-ID pattern to
derive, and with no `binaries` row the file never appears in the NZB at all — so it is invisible
to everything above. Recovering those needs a header re-scan rather than synthesis, and detecting
them first needs the declared file count to survive stale promotion, which today it does not:
`ReleaseProcessingService` overwrites `collections.totalfiles` with the number of files actually
seen when it promotes a stale collection.

Tracked separately in #153.
