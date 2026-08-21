# Repair before delete

The completion sweep (`ReleaseProcessingService::deleteIncompleteReleases()`) used to delete any
release measured below `completionpercent`. Many of those were recoverable: the missing articles
were still on the provider and only the *headers* had been missed.

Verified empirically on release `9db10f50-b300-458f-998e-96d7b4db5054` — its poster used
PowerPost-style message-IDs (`part{N}of{M}.{TOKEN}@powerpost2000AA.local`, token constant per
file), the missing segment IDs were derived from files with as few as 2 of 211 segments seen,
and NNTP `STAT` returned EXISTS for every probed "missing" article, on two different files.

## The invariant

**The sweep never deletes a release without a completed recovery process.** Two passes recover
different things — derivable segments, and files with no segment at all — and both must be
finished with it. The predicate lives in one place,
`App\Services\Releases\IncompleteReleaseSweepQuery`:

```
completion > 0 AND completion < completionpercent
  AND repair_outcome IN ('failed', 'skipped-floor', 'skipped-budget')
  AND (rescan_outcome IN ('failed', 'skipped-floor', 'skipped-budget')
       OR declaredfiles IS NULL OR declaredfiles <= 0 OR declaredfiles <= totalpart)
```

Only *final* outcomes are deletable. The re-scan half also passes a release with nothing to look
for, since it would be stamped final on sight and waiting for the stamp would only delay the
reaper by a batch. The sweep does no timestamp arithmetic — the state machines own time and hand
the reaper only what they have given up on. Manual commands
(`nntmux:delete-releases --completion-max`) remain operator overrides and bypass the gate.

`completion = 0` stays exempt: it is the "never measured" sentinel, meaning nothing declared a
part total, not that the release is empty.

## The state machine

`releases.repair_attempted_at` and `releases.repair_outcome` are both load-bearing: the sweep
reads the outcome, the retry pass reads both. `rescan_attempted_at` / `rescan_outcome` mirror them
for the header re-scan and share the same values.

| Outcome | Meaning | Deletable |
| --- | --- | --- |
| *(null)* | Never offered to the repair engine | no |
| `retry-pending` | First pass fell short; one more pass is owed after the retry window | no |
| `repaired` | Reached the completion target | no |
| `failed` | Both passes spent, still short | **yes** |
| `skipped-floor` | Nothing worth spending network on; no articles were ever probed | **yes** |
| `skipped-budget` | The re-scan window was wider than the ceiling allows | **yes** |

A pass that could not *run* — no NZB on disk, an unparseable NZB, an NZB that could not be
written back — records nothing at all. Those say something about our storage, not about whether
the release's articles are still on the provider, so they must not advance the state machine:
two unmounted volumes in a row would otherwise be enough to mark a release `failed`. Such a
release is simply picked up again next invocation, and the command reports it under
"Not attempted".

Every release gets at most **two network passes** per engine. The retry window
(`repair_retry_after_hours`, 72 hours by default) exists because fresh releases are
stale-promoted at 8 hours and repaired within hours, while their articles may still be
propagating across the provider farm: a first attempt at hour 10 can fail where a recheck at
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
# Rerunnable, and disjoint from the pass above — `0` is "never measured", not a low percentage.
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

## The header re-scan: files with no segment at all

Everything above works from what an NZB already holds. A file the header scan missed **entirely**
has no `binaries` row, so it never became a `<file>` element — there is nothing to notice its
absence by and no segment to derive a message-ID pattern from. `releases:rescan-missing-files` is
the second pass, and it goes back to the group's headers.

### Seeing the gap at all

Detecting a whole-missing file needs the declared file count, and stale promotion used to destroy
it: `ReleaseProcessingService` rewrites `collections.totalfiles` to the number of files actually
seen when it promotes a collection past `delaytime`, which is exactly what lets an incomplete
collection become a release at all. For the releases worth repairing, declared and held therefore
always agreed.

`collections.declaredfiles` is written once at collection insert from the same `[n/N]` header
token and is excluded from that rewrite; `ReleaseCreationService` carries it onto
`releases.declaredfiles`. `totalfiles` and `totalpart` keep their old meaning — "files we hold" —
and no existing consumer changed.

For releases created before the column existed, the count is derived on first visit from the
stored NZB's own subjects and persisted. Only the bracket `[n/N]` form counts files: the writer
appends a synthesized ` (1/<totalparts>)` segment counter to every subject, and reading *that* as
a file count mistook 10 of 30 sampled releases for whole-missing ones, one of them "declaring"
8,380 files. The answer is the mode of the totals, ties to the larger, and `0` — persisted — when
nothing usable is declared.

`completion` sees the gap too. The segment ratio only sums the files we hold, so 9 of 10 files
fully held read as 100%; where more files were declared than are held, the denominator is scaled
by `declared / held` and that release now measures 90%.

### The window

Article numbers are per-server, so this is provider-1 work by construction — `NntpProviderPool`
exposes no header API to reach instead. See [nntp-providers.md](nntp-providers.md).

- **Anchored.** `releases.firstarticle` / `lastarticle` are the min and max `parts.number` the
  collection held, captured at release creation because NZB creation deletes the CBP rows. The
  window is that span plus a pad.
- **Bisected.** Legacy releases have only a postdate, so the group is bisected for the articles
  either side of it — `BinariesService::articleForTimestamp()`, the same date-to-article search
  backfill uses, aimed at an absolute time rather than a number of days back.

The pad is `rescan_window_minutes` of posting time converted through the group's own article rate
(`(last_record − first_record) / (last_record_postdate − first_record_postdate)`), so half an hour
is a few thousand articles on a quiet group and millions on a busy one. Where the rate cannot be
estimated the pad collapses to nothing and the window is exactly the known span: cheap and
conservative beats guessed.

### Matching

Three things must agree before a line is attached, and none alone is enough: the **poster**, the
**declared file total** in its `[n/N]`, and the **masked subject** — the `[n/N]` index, `.partNN`,
`.rNN`, `.volNNN+NNN` and the trailing segment counter replaced by markers, which is what turns a
post's files into one shared string. Its file index must also be one the NZB does not already
hold. A release whose held files do not all carry a file index is left alone entirely: without
them there is no way to know which indices are already ours, and appending a duplicate is worse
than doing nothing.

Matched lines are grouped by file index and written as new `<file>` elements carrying the NZB's
own poster, date and groups. Both the message-ID and the byte count come off the server's overview
line, so unlike a synthesized segment neither is an estimate. A file found only partly is written
with what was found — the repair engine's next pass synthesizes the rest from those IDs, which is
the point of writing it at all.

### Budgets and state

XOVER over a window is far more expensive than a STAT, and it competes with live header scanning
for provider 1's connections. Four seeded settings bound it, all editable on the admin Usenet
Settings section: `rescan_limit` (releases per run), `rescan_window_minutes`,
`rescan_max_articles_per_release` (a wider window is stamped `skipped-budget` without fetching
anything) and `rescan_max_articles_per_run` (the invocation stops fetching once that many overview
lines have been read). The repair engine's own tunables live there too:
`repair_retry_after_hours`, `repair_floor_completion`, `repair_stat_sample_per_file`,
`repair_max_stat_probes`, `repair_limit`. CLI flags override any of them for one run.

`rescan_outcome` / `rescan_attempted_at` mirror the repair columns and use the same enum, plus
`skipped-budget`. Two passes maximum, same as repair. Never-attempted releases are taken
**smallest shortfall first**: a release missing two files of forty is both likeliest to be
recovered and cheapest to try, while one missing seven hundred is a posting session that never
arrived.

The sweep waits for **both** state machines. `IncompleteReleaseSweepQuery` requires a final
`repair_outcome` *and* either a final `rescan_outcome` or a release with nothing to re-scan —
`declaredfiles` null, zero, or no greater than the files held.

```bash
# One re-scan pass over a bounded batch. --dry-run resolves declared counts and estimates
# windows without fetching or writing anything.
php artisan releases:rescan-missing-files --dry-run -v
php artisan releases:rescan-missing-files --limit=50
```
