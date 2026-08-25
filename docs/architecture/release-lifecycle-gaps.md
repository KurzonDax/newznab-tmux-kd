# Release lifecycle gap analysis

**Snapshot:** master @ `761f43bcc`, 2026-08-23. This document describes the code as it exists at this snapshot. It is a source audit, not an observation of production data.

This document was split out of [release-lifecycle.md](release-lifecycle.md), which retains the stage graph, drivers and cadence, eligibility matrix, state-field inventory, and ordering/rerun semantics that this analysis builds on. Gaps G1a-G20 originate from the initial source audit (#202) and were independently re-verified against this snapshot; several sections carry amendments from that verification review (G4, G5, G6, the shared G9/G10 root cause, G12, G15, G16, G17, G19). Gaps G21-G39 are new findings from the verification review.

Severity describes the consequence of the reachable state, not how frequently it occurs. "Stranded" means the automatic engine has no future transition that can complete the named work. Focused proofs added during this audit construct only local factory/in-memory database state; no production measurements were used.

## Audited gaps (G1a-G20)

### G1a — unrenamed releases outside Other are permanently excluded from the standard sweep (high, stranded names)

**Reachable state and path.** `isrenamed=0, predb_id=0, nfostatus>=0, proc_*=0, categories_id` in Music/Movie/TV/etc. A raw multipart subject such as `[10/88] "Artist-Title-FLAC-1971-GRP.part09.rar"` is not accepted as a proper name, but #132 categorization can recognize Music at creation. Forced XXX (#138) or a forced group root (#140/#141, including bulk edits fixed by #183) create the same state even when the raw name itself is opaque.

**Exclusion (before #213).** The process that should use the later NFO/files/PAR2/UID/SRR/hash/CRC evidence is `standardCandidateBatch`, which then ended with:

```sql
AND r.isrenamed = 0
AND r.predb_id = 0
...
AND r.categories_id IN (<Category::OTHERS_GROUP>)
```

The ordinary pane still calls every windowed source with `--category=other` (`TmuxTaskRunner.php:438-449`). Audio-tag and RAR-file renames cover only releases whose AP routing, archive contents, settings, and tag/name evidence cooperate; they are not a general cross-category sweep. PreDB full text is the only category-independent name fixer, is not scheduled, and a PreDB row is attempted once.

This was a long-standing category assumption made substantially more reachable by #132/#138/#140/#141 and left intact when #176 introduced the "standard" safety sweep. It corrupted no row, but permanently stranded naming and could also block renamed-only metadata.

**Resolved (#213).** The category gate is gone: the sweep admits any unrenamed, PreDB-less release with an unconsumed source regardless of root. The windowed per-source methods keep their `--category=other` arguments. `ReleaseLifecycleEligibilityGapTest::the_standard_name_sweep_admits_an_unrenamed_release_outside_other` and `StandardNameSweepAdmissionTest` lock the new behavior in.

### G1b — all negative NFO states block every standard source, including terminal NFO failure (high, stranded names)

**Reachable state and path.** An Other release has file/PAR2/UID/SRR/hash/CRC evidence and the matching `proc_* = 0`, but `nfostatus=-1` because NFO processing is disabled/size-excluded, or `nfostatus=-9/-10` because NFO attempts are exhausted. A -9 row without a nonempty NFO-like `release_files` row is not eligible for archive fallback (`NfoService.php:884-909`), so -9 itself may be terminal.

**Exclusion (before #213).** Standard naming unconditionally required `AND r.nfostatus > -1` before its OR across all seven independent sources, and the tmux wake-up count repeated that requirement. Neither predicate distinguished "NFO not ready" from "NFO permanently unavailable," nor allowed already-ready non-NFO evidence to proceed.

This coupled gate was surfaced by #176's standard sweep; it was a design regression in that new safety net over older independent source methods. Result: permanent name strand, with secondary metadata starvation.

**Resolved (#213).** The sweep's predicate is per-source readiness only. The NFO term keeps its own `nfostatus = 1 AND proc_nfo = 0` readiness; every other source's term stands alone, so -1/-9/-10 rows with file/PAR2/UID/SRR/hash/CRC evidence are admitted. `ReleaseLifecycleEligibilityGapTest::the_standard_name_sweep_admits_every_other_source_while_nfo_is_pending` and `...after_nfo_retries_are_exhausted` now assert admission.

### G2 — the Fix Names pane can sleep while the standard query has UID/SRR/hash/CRC work (high, stranded queue)

**Reachable state and path.** An otherwise eligible Other release has only one of `proc_uid`, `proc_srr`, `proc_hash16k`, or `proc_crc32` at 0 while `proc_nfo=proc_files=proc_par2=1`.

**Contradiction (before #213).** The worker admitted:

```sql
... OR r.proc_uid = 0 OR r.proc_srr = 0
OR r.proc_hash16k = 0 OR r.proc_crc32 = 0
```

but `processrenames` counted only:

```sql
(nfostatus = 1 AND proc_nfo = 0) OR proc_files = 0 OR proc_par2 = 0
```

`runFixNamesTask()` disables the pane at a zero count (`TmuxTaskRunner.php:414-424`), so the broader worker query was never reached. This was a #176 regression caused by adding a standard worker without deriving the monitor from its predicate: queued work could remain forever, though another row could incidentally wake the pane.

**Resolved (#213).** `processrenames` is gone from the tmux stats query and is now collected in `TmuxMonitorService::getProcessCounts()` from `NameFixingQueryService::standardCandidateCount()`, which shares `standardSweepPredicate()` with `standardCandidateBatch()`. The pane wakes exactly when the sweep would return work. `runFixNamesTask()` is unchanged and correct by derivation.

### G3 — PreDB full-text's only cross-category rescue is operator-only and one-shot (high, stranded names)

**Reachable state and path.** Any G1 row may be discoverable by a future PreDB title. `predb.searched=0` makes that title eligible once it is a day old.

**Exclusion/absence.** `multiprocessing:fixrelnames predbft` exists (`ProcessFixRelNames.php:17-38`) and its release confirmation has no category constraint (`NameFixingQueryService.php:310-329`), but neither `TmuxTaskRunner` nor `routes/console.php` invokes it. After an operator runs it, `ReleasesFixNamesGroup` writes each row's searched result after one attempt (`ReleasesFixNamesGroup.php:154-175`), so releases created or renamed later are never considered against that PreDB row.

This is a long-standing automation/retry gap, made consequential by the newer cross-category strands. It does not corrupt state, but eliminates the only general rescue path unless an operator continuously intervenes.

### G4 — PAR2 naming can mark evidence consumed before an NZB exists (high, stranded names)

**Reachable state and path.** Immediately after release creation: `nzbstatus=0, predb_id=0, isrenamed=0, proc_par2=0, categories_id=Other`. Another eligible row wakes the #176 Fix Names pane before the Releases pane finishes NZB creation. PAR2 level 7 sees the new row.

**Contradiction.** `SOURCE_PAR2` declares source existence as `1 = 1`, and `candidateWhere()` has no `nzbstatus` clause (`NameFixingQueryService.php:60-70,388-418`). `checkPar2()` cannot read the not-yet-created NZB; the miss path then calls `markProcessed(..., 'proc_par2', ...)` (`NameFixingService.php:1228-1255`). Once the NZB or a later repair/rescan supplies PAR2 evidence, `proc_par2=1` permanently excludes the row.

The marking is broader than the fix-names loop: `NzbContentsService::checkPar2()` itself sets `proc_par2=1` whenever it runs with name-fixing status active, including when `loadNzb()` failed because the NZB file does not exist on disk (`NzbContentsService.php:265-297`). The race is also unnecessary: level 7 runs inside the six-hour `adddate` window, and a release whose NZB export failed permanently satisfies the same predicate with no timing at all.

This is an ordering regression exposed by #176 automatically running odd source levels. The broader long-standing flaw is that `proc_*` booleans record "attempted at one moment," not the version of their source evidence. `ReleaseLifecycleEligibilityGapTest::par2_name_fixing_can_mark_a_release_done_before_its_nzb_exists` proves the gate transition. It strands one naming method and can strand the whole name when other methods lack evidence.

### G5 — an audio worker crash at the timeout limit creates a row neither audio nor general AP will claim (critical, stranded AP)

**Reachable state and path.** `passwordstatus=current pending, haspreview=-1, nzbstatus=1`, audio-routed category/group, no `aud:declined` token, and `pp_timeout_count>=maxpptimeoutcount`. The audio worker increments the counter **before** fetching an archive (`AudioReleaseProcessor.php:65-67,127-140`). If the process exits or is killed after that increment and before settlement/decline, the row remains pending at the maximum.

**Contradiction.** Audio appends `WHERE r.pp_timeout_count < max` (`AudioCandidateQuery.php:74-101`). General AP requires `NOT routedToAudio OR token='aud:declined'` (`AudioRouting.php:69-75`), but the crashing worker did not write the decline token. Logging the exclusion does not transition it. `AudioCandidateQueryTest::test_an_audio_release_past_the_timeout_threshold_is_stranded_between_both_paths` proves neither query selects the state.

The state is reachable without any crash. `pp_timeout_count` is a lifetime one-way ratchet — the only writers are the two incrementers, and settlement never resets it (`AudioReleaseProcessor.php:221-246`; `ReleaseClaimant.php:259-269`) — and the audio path burns one unit before every archive fetch, successful ones included. Ordinary repeated requeues therefore walk an archive-backed audio release to the maximum in normal operation; see G24 for the tool-side half of this problem.

This is a regression from the #173 dedicated audio path's crash guard, sharpened by #181. It permanently strands password/preview/tag processing and leaves a warning-only dead row.

### G6 — the mutable password pending sentinel (and hard-coded resets) strands both AP paths (high, stranded AP)

**Reachable state and path.** A row is created/requeued with `passwordstatus=0` while inspection is inactive, then the setting/path becomes active so current pending is -1; or the reverse. Independently, `nntmux:reset-postprocessing` and `postprocess:guid --reset` write -1 even while inspection is inactive (`NntmuxResetPostProcessing.php:75-90,353-369`; `ProcessAdditionalGuid.php:72-80`). In either case `haspreview=-1,nzbstatus=1` still says work is pending.

**Exclusion.** Both candidate paths share strict equality:

```php
->where('r.passwordstatus', PasswordInspectionMode::pendingReleaseStatus())
->where('r.haspreview', -1)
->where('r.nzbstatus', 1)
```

(`ReleaseClaimant.php:63-66`), where the expected value changes with live config (`PasswordInspectionMode.php:11-20`). Unlike repair and preview requeue commands, the reset writers do not call that helper. `AudioCandidateQueryTest::test_changing_password_inspection_mode_strands_rows_created_with_the_old_pending_sentinel` proves both routing polarities reject an old sentinel.

Two scope notes from the verification review. `postprocess:guid --reset` usually self-heals in the same invocation because it processes the given GUID directly, bypassing candidate selection (`AdditionalProcessingOrchestrator.php:78-126`); it strands only when that processing run aborts. And the mismatch-requeue remedy is narrower than it looks: `RequeueMissingVideoPreviews`'s stranded arm repairs only Movies/TV rows with `rarinnerfilecount=0` (`RequeueMissingVideoPreviews.php:157-174`), and `RequeueAudioPreviews`'s only audio-routed rows (`RequeueAudioPreviews.php:123,135-137`) — a mismatched sentinel in Console/Games/Books/Other, or Movies/TV with recorded inner files, is fixed by no command.

This is a dedicated-AP state-model regression (#173 and follow-ups) plus a long-standing reset mismatch. It permanently strands AP until an operator runs the specialized mismatch requeue — where one applies.

### G7 — whole-file rescan is required by the deletion design but has no automatic caller (high, stranded recovery)

**Reachable state and path.** `nzbstatus=1, 0<completion<target, repair_outcome` becomes failed/skipped, and `declaredfiles>totalpart` indicates complete files are absent rather than segments missing.

**Absence.** `RescanCandidateQuery` admits that row (`RescanCandidateQuery.php:37-91`), and `releases:rescan-missing-files` owns recovery (`RescanMissingReleaseFiles.php:33-142`), but there is no tmux or scheduler caller. Only segment repair is scheduled hourly (`routes/console.php:36-40`). The sweep correctly waits when the shortfall is known, so the row is not deleted—but it is also never repaired.

This is an incomplete #161 rollout. It strands whole-file recovery indefinitely and accumulates below-target releases.

### G8 — `declaredfiles IS NULL` is simultaneously rescan-eligible and deletion-eligible (critical, destructive race)

**Reachable state and path.** A legacy/imported row has `nzbstatus=1, 0<completion<target, repair_outcome=failed/skipped, rescan_outcome=NULL, declaredfiles=NULL`.

**Contradiction.** Never-attempted rescan explicitly admits:

```php
->whereNull('rescan_outcome')
->where(fn ($q) => $q->whereNull('declaredfiles')
    ->orWhereColumn('declaredfiles', '>', 'totalpart'))
```

(`RescanCandidateQuery.php:53-58`). At the same time the sweep admits the same row with `->orWhereNull('declaredfiles')` once repair is final (`IncompleteReleaseSweepQuery.php:42-50`). Since the rescan command is not scheduled and the release cleanup runs frequently, the deletion side normally wins.

This is a #161 regression: null was designed as "derive locally on first rescan visit" but also treated as proof no rescan was necessary. `ReleaseRepairGateTest::an_unresolved_legacy_release_is_simultaneously_rescan_eligible_and_deletion_eligible` proves simultaneous membership. Severity is critical because it can delete a recoverable release.

### G9 — a partial whole-file recovery is stamped terminal `repaired` below target (high, stranded recovery)

**Reachable state and path.** A rescan finds at least one missing file, rewrites the NZB, but `completionAfter<target`. `MissingFileRescanService` unconditionally constructs `outcome: Repaired` for any nonempty recovery (`MissingFileRescanService.php:220-245`) and persists it (`:403-422`).

**Exclusion.** Rescan only revisits `retry-pending` or null (`RescanCandidateQuery.php:42-58`); the segment repair query only revisits repair's own retry/null state (`ReleaseRepairCandidateQuery.php:39-53`); the sweep requires deletion-final rescan state when `declaredfiles>totalpart` (`IncompleteReleaseSweepQuery.php:45-50`). Therefore `completion<target,rescan_outcome=repaired` is selected by none of them.

**Resolved (#216).** Whole-file recovery now compares its post-write completion with the live
target. A below-target first pass writes `retry-pending`; the owed retry writes `failed`. Recovered
files remain in the NZB in either case, so the row is always owned by recovery or the sweep.

### G10 — raising the completion target does not reopen rows repaired to the old target (medium, stranded policy work)

**Reachable state and path.** At target 95 a release reaches `completion=96, repair_outcome=repaired`. The operator later raises `completionpercent` to 99.

**Exclusion.** Although `completion<99` now holds, repair selects only `repair_outcome=retry-pending` or null (`ReleaseRepairCandidateQuery.php:39-53`), rescan selects only its retry/null states, and the sweep deletes only failure/skip outcomes. No transition invalidates a former `repaired` verdict when the target changes.

**Resolved (#216).** Each `repaired` verdict records both the target it achieved and the latest
target it evaluated. Candidate queries reopen it only when the current target is higher than the
latter; that pass can improve the release but cannot change the earlier achieved target or turn
the previously successful verdict into a deletable outcome when it falls short.

**Shared root cause for G9 and G10.** Before #216, `Repaired` was a universally invisible outcome:
`ReleaseRepairOutcome::Repaired->isFinal()` was false, keeping it out of the sweep, while both
candidate queries revisited only retry-pending/null. Target stamps now make old successes visible
only after a target increase, and a rescan that reaches the live target settles a dangling segment-
repair retry as `repaired` under that target.

### G11 — NZB imports can remain in protected `completion=0` without an eligible NFO pass (high, stranded completion)

**Reachable state and path.** Every normal NZB import writes release identity/category/NZB fields but omits `completion` (`NzbImportService.php:630-645`), leaving the database default 0 even though the importer parsed the files/segments. `NzbImportSegmentHashDedupeTest::test_import_persists_sorted_segment_message_id_hash` now asserts the resulting 0. `NzbContentsService::parseNzb()` can later fill that sentinel as a side effect of automatic NFO work (`app/Services/Nzb/NzbContentsService.php:141-206,310-327`), but only when NFO processing is enabled and the release passes its size/status schedule. NFO-disabled, NFO-size-excluded, and NFO-pane-disabled imports remain at 0.

**Exclusion.** Repair (`ReleaseRepairCandidateQuery.php:69-73`), rescan (`RescanCandidateQuery.php:83-91`), and deletion (`IncompleteReleaseSweepQuery.php:42-50`) all require `completion>0`. Outside the conditional NFO side effect, the only general repair is the operator-only `releases:backfill-completion`, whose default candidate predicate is exactly `completion=0` (`BackfillReleaseCompletion.php:38-44,145-153`). `ReleaseRepairGateTest::the_never_measured_nzb_import_state_is_selected_by_no_recovery_or_sweep` proves the row is owned by no automatic recovery process once NFO is not an eligible rescue.

This is an incomplete #156 completion-at-release-creation change: the alternative release producer was not given an unconditional equivalent writer. It protects affected imports from deletion but disables completion policy and recovery until an eligible NFO pass or operator backfill measures them.

### G12 — #139 normalized dedupe can miss mixed-era duplicates before PHP sees them (high, duplicate releases)

**Reachable states and paths.** Two independent failures exist within the configured size band:

1. Twenty-five false prefix candidates (`ReleaseName.Extras.*`) precede the true legacy form (`"ReleaseName.part009.rar"`).
2. The stored legacy value begins with raw multipart decoration, e.g. `[10/88] "ReleaseName.part009.rar" yEnc`, while the incoming normalized value is `ReleaseName`.

**Exclusion.** The fallback prefilter is only:

```php
searchname = $normalized
OR searchname LIKE $normalized.'.%'
OR searchname LIKE '"'.$normalized.'%'
LIMIT 25
```

with no deterministic order (`ReleaseDuplicateFinder.php:102-110`). Case 1 crowds the match out of the limited candidate set. Case 2 matches none of those leading-prefix expressions, so `ReleaseNameNormalizer::normalize()` in PHP (`:112-115`) never sees the row. `CbpCleanupServiceTest::test_normalized_duplicate_fallback_can_omit_the_true_match_after_twenty_five_prefixes` and `...cannot_see_a_legacy_counter_prefix` prove both misses.

Case 2 is not only a prefilter gap: `ReleaseNameNormalizer::normalize()` strips only whole-string wrapping quotes (`app/Support/ReleaseNameNormalizer.php:26-38`), so `[10/88] "Name..." yEnc` normalizes to itself and would fail the PHP-side equality even with a perfect prefilter — fixing it requires normalizer work as well. The exact-match arm is also unordered (`ReleaseDuplicateFinder.php:66`), so which of several existing duplicates wins is nondeterministic (minor).

This is a #139 regression/incomplete mixed-era compatibility design. It creates duplicate releases; it does not merge or corrupt the existing one.

### G13 — whole-file rescan changes the NZB but does not requeue consumers of file evidence (high, stale derived state)

**Reachable state and path.** A release previously settles AP (`haspreview=0,passwordstatus=0`) and name sources (`proc_files/proc_par2/...=1`). A #161 rescan later adds an entire missing file and rewrites the NZB.

**Exclusion.** Rescan's final writer changes only `rescan_attempted_at`, `rescan_outcome`, and optionally `completion` (`MissingFileRescanService.php:403-422`). It does not update `totalpart` after adding whole files, restore the mode-aware AP pending fields, or reset any `proc_*` evidence-version flags. AP still requires pending password plus `haspreview=-1` (`ReleaseClaimant.php:63-66`), and name methods require their `proc_*=0` (`NameFixingQueryService.php:397-403`). By contrast segment repair deliberately invokes a bounded AP requeue when it adds segments and the row has no artifacts (`ReleaseRepairService.php:115-128,183-203`). `MissingFileRescanServiceTest::a_successful_rescan_does_not_requeue_settled_additional_processing` proves the missing AP transition.

This is an incomplete #161 integration. It leaves preview/password/file-name/metadata derived state stale after the underlying NZB gains qualitatively new evidence.

### G14 — late CBP can make the stored creation-time completion stale, then repair/sweep can delete a complete NZB (critical, destructive stale state)

**Reachable state and path.** Release creation measures (for example) 91.67% and writes `filecheck=4`. Before NZB creation claims/writes the release, later header ingest resolves the existing collection hash and inserts a missing binary/part under the linked collection. The collection fast-path intentionally does not change filecheck 4 (`CollectionHandler.php:541,594,604-682`), while `BinaryHandler.php:102-165` and `PartHandler.php:68-143` have no collection-status gate. NZB creation then streams the now-complete CBP.

**Contradiction.** Successful NZB creation deliberately updates only `nzbstatus=1` and claim fields, leaving the older completion untouched (`NzbService.php:802-816`). `NzbCreationReliabilityTest::test_writer_leaves_the_creation_time_completion_untouched` proves a stored 91.67 remains 91.67 even when the written CBP is 3/3. Repair later loads the complete NZB, sees `! $plan->hasWork()`, and writes retry-pending then failed without remeasuring (`ReleaseRepairService.php:73-80,205-264`). If `declaredfiles<=totalpart`, the completion sweep then admits it (`IncompleteReleaseSweepQuery.php:42-50`) despite the NZB on disk being complete.

This is a #156 regression relative to #147's NZB-time completion writer: moving the authoritative measurement earlier did not freeze CBP or reconcile late data. It can delete an actually complete release and is therefore critical.

### G15 — forced-root finalization overrides ADR 0006's audio-only cross-root correction (medium, categorization conflict)

**Reachable state and path.** A group is forced to XXX; categorization therefore writes `XXX_OTHER`. MediaInfo later proves audio-only FLAC/MP3.

**Decision context.** Before its ADR 0010 amendment, ADR 0006 stated that audio-only content was the one deliberate cross-root exception (`docs/adr/0006-mediainfo-refinement-out-of-other-only.md:1-13`). The refinement decision still proposes Music (`MediaInfoRefinementService.php:41-51`), but #140/#141 added a forced-root guard that returns null unless the target's root equals the group force (`:65-67,100-121`). A subsequent central rename cannot escape either because the categorization pipeline reapplies forced-root finalization (`CategorizationPipeline.php:143-171`).

Same-root legitimate refinement is **not** blocked, and a forced root correctly cannot be accidentally undone. ADR 0010 resolves the precedence conflict in favor of the operator-selected force: a Forced root is a lock, while ADR 0006's audio-only exception applies only to ADR 0005's Routed floor. The override is deliberate and test-locked (`tests/Feature/Services/Categorization/MediaInfoRefinementPersistenceTest.php:114,129`), so the current guard already matches the recorded decision and no code change is required for G15.

### G16 — direct book/audio renames bypass the central rename state transition (medium, inconsistent/stale state)

**Reachable state and path.** Book matching normalizes a wrapper/ISBN title, or audio tags produce performer/album. Both paths directly write `searchname,isrenamed` (and in audio's case category/proc_pp) (`BookService.php:430-473,553-564`; `AudioTagRenamer.php:68-79`).

**Missing interaction.** The canonical updater additionally resets stale TV/movie/music/book/game identities (imperfectly — see G25), sets trust and source status, emits `ReleaseNameFixed`, and recategorizes/refines through the listener (`ReleaseUpdateService.php:343-406`; `RecategorizeReleaseAfterNameFix.php:22-70`). Both direct paths **do** sync the search index (`Search::updateRelease()` at `BookService.php:472`; the sync coordinator at `AudioTagRenamer.php:79`), so index staleness is not among the missing side effects. Direct book rename does not recategorize at all (its category writes are junk/magazine detection, not name-driven recategorization); audio recategorizes inline from the file extension but never emits the event and never writes `is_trusted_name`. Consequently metadata/provider state from the old name can survive, and future naming/donor gates observe a different state depending on which writer found the same name.

Audio direct behavior was introduced with #173; the book path is older. This is state inconsistency rather than an unconditional strand, but it can permanently retain stale metadata because positive IDs are terminal to their workers.

### G17 — metadata monitor predicates disagree with worker predicates (medium, stranded or wasted metadata work)

Current mismatches are source-proven:

- Book scheduling admits `categories_id=3030` (Music/Audiobook) and, outside renamed-only mode, legacy `N:/NZB`/`N_NZB_` wrapper names even when `bookinfo_id` is non-null (`PostProcessRunner.php:619-670`). Tmux `processbooks` counts only Book-root rows with `bookinfo_id IS NULL` (`Tmux.php:341,362-363`). An audiobook-only backlog, or wrapper-only reprocessing backlog, never wakes the metadata pane. The books monitor also omits the `isrenamed=1` term `bookWorkCondition()` adds under `lookupbooks=2`, and the wrapper-name OR-branch under `lookupbooks=1` admits rows regardless of `bookinfo_id` (`PostProcessRunner.php:660-671`), so wrapper-named backlog with `bookinfo_id` set is undercounted.
- Music scheduling counts every null `musicinfo_id` in its three Music leaves (`PostProcessRunner.php:175-185,673-696`), but the worker adds `isrenamed=1` when `lookupmusic=2` (`MusicService.php:479-504`). Unrenamed rows wake repeated no-op cycles; if they also hit G1, they never become eligible. The same omission exists at the runner level: `processMusic()`/`getMusicBuckets()` (`PostProcessRunner.php:176-186,681-686`) also lack the renamed filter, so under `lookupmusic=2` up to 16 GUID-bucket children fork and no-op — unlike console/games/books, which apply it (`PostProcessRunner.php:662-663,706,733`).
- The same renamed-only drift affects tmux's TV, Console, and PC-game counts: their monitor SQL omits `isrenamed=1`, while the runner/worker adds it for `lookuptv=2` or `lookupgames=2` (`Tmux.php:335-342`; `PostProcessRunner.php:455-499,698-750`). These are repeated no-op wakeups rather than additional strands. Movies gets this right (`Tmux.php:327-331` appends `AND isrenamed = 1` when `lookupimdb=2`), which proves the pattern was known and simply not applied to the other types.
- Monitor counts are also not zeroed when a lookup toggle is off. Movies is (`Tmux.php:325-328` writes `0 = 1` when `lookupimdb<=0`), but NFO (`Tmux.php:343` vs `PostProcessRunner.php:389` requiring `lookupnfo === 1`) and music/books/console/games (`Tmux.php:337-341` vs the lookup checks at `PostProcessRunner.php:621,675,700,727`) are not. With a toggle off and any permanent backlog (with `lookupnfo=0` every new release stays `nfostatus=-1`, so that count grows without bound), the pane respawns a guaranteed no-op every cycle indefinitely.

This is long-standing duplicated-predicate drift, exposed by the #166–#201 reorganization of the shared metadata/audio pane. The under-counted Book cases strand metadata until unrelated work wakes the pane; the over-counted renamed-only and disabled-toggle cases waste cycles.

### G18 — exact PreDB attachment can close naming without marking the name renamed/trusted (medium, gate contradiction)

**Reachable state and path.** An `isrenamed=0,predb_id=0` release's existing `searchname` later exactly equals a PreDB title. `Predb::checkPre()` or `ReleaseUpdateService::attachPredbId()` writes only `predb_id` (`Predb.php:115-131`; `ReleaseUpdateService.php:445-455`).

**Exclusion.** Every automatic/source/standard/full-text name query requires `predb_id=0` (`NameFixingQueryService.php:129-143,323-324,388-418`), while renamed-only metadata modes require `isrenamed=1`. The resulting `predb_id>0,isrenamed=0,is_trusted_name=0` is therefore treated as naming-terminal without receiving the state normally implied by a PreDB match.

The state is automatically reachable: `attachPredbId` fires whenever a donor/PreDB title matches the current searchname case-insensitively while renaming is skipped (`NameFixingService.php:649-655,775-781,1404,1519`) — including from tmux-scheduled UID/hash/CRC levels and the standard sweep. `checkPre` itself is operator-only (`predb:check`; the tmux script that used to run it, `app/Services/Tmux/Scripts/postprocess_pre.php`, is orphaned — nothing invokes it). Contrast `attachSrrdbMatch()` (`ReleaseUpdateService.php:457-474`), which does set `isrenamed=1` and `is_trusted_name=1`.

This is a long-standing split-writer design gap. It can strand renamed-only metadata and prevents trusted donor propagation.

### G19 — `recategorize --all` leaves unchanged non-Other rows permanently `iscategorized=0` (medium, state corruption)

**Reachable state and path.** An operator runs `nntmux:recategorize-releases --all`. The command first changes every `iscategorized=1` row to 0 (`RecategorizeReleases.php:45-50`). In its loop it restores 1 only inside `if (old category !== new category)` (`:78-96`). Any correctly categorized, unchanged row remains 0.

**Exclusion.** The automatic legacy categorizer admits only `categories_id=OTHER_MISC AND iscategorized=0` (`ReleaseProcessingService.php:315-322`), so unchanged Movie/Music/TV/etc. rows are never repaired. Cleanup rules that require `iscategorized=1` (`ReleaseRemoverService.php:337-383`) can also be bypassed.

Two sibling defects live in the same command. First, `--all --test` is destructive: the option branches are an `elseif` chain, so the global 1→0 reset at `:45-54` runs before the per-row `--test` check at `:82`, and a supposed dry run leaves every release `iscategorized=0`, changed and unchanged alike. Second, the changed-category path writes `'bookinfo_id' => 0` (`:93`) where the schema default is NULL and every book predicate tests `bookinfo_id IS NULL` (`Tmux.php:341`; `PostProcessRunner.php:663-666`), so a release recategorized into Books never receives book metadata and never counts toward the monitor. The command also loads its full candidate set unchunked (`:75`), a memory hazard at production scale.

This is a long-standing manual-path state-corruption bug, not caused by the recent bulk forced-root edit fix (#183). It produces persistent invalid state and can weaken cleanup.

### G20 — cross-posted releases apply group policy from ingestion-order primary only (high, order-dependent routing and stranded AP)

**Reachable state and path.** The collection identity is `sha1(cleaned name + declared file count)` and excludes group; its unique key keeps the first inserted `collections.groups_id`, while every Xref group is separately accumulated in `collection_groups` and later `releases_groups` (`CollectionHandler.php:80-93,161-208,388-459,521-594`; `database/schema/mysql-schema.sql:187-221`; `ReleaseCreationService.php:64,137-179,265-297`). Consider a small opaque release cross-posted to plain group A and group B whose forced root is Music. If A is scanned first, the release reaches `groups_id=A, categories_id=Other, size<minsizetopostprocess`, while `releases_groups` truthfully contains B.

**Contradiction.** Forced-root categorization loads policy only through the single primary id passed to `categorize()` (`CategorizationPipeline.php:78-110,143-171`). Audio routing likewise checks only `r.groups_id`:

```sql
r.categories_id BETWEEN 3000 AND 3999
OR EXISTS (... usenet_groups.id = r.groups_id
           AND forced_root_categories_id = 3000)
```

(`AudioRouting.php:112-121`). It never consults `releases_groups`. The state above is too small for general AP (`AdditionalCandidateQuery.php:55-78`) and is not audio-routed, so neither path selects it; if B happened to be primary, the no-minimum audio query would select the same upload. `AudioCandidateQueryTest::test_a_small_crosspost_ignores_a_secondary_forced_music_group_and_is_selected_by_neither_path` proves the query-level strand.

This is a policy-integration regression from combining #140/#141's per-group forced roots with #166's no-minimum audio route and the existing cross-group collection identity; #139 further commits the lifecycle to one release for cross-group reposts. It makes categorization/AP depend on scan order and can permanently strand small audio work. ADR 0010 resolves the policy: any associated group's Forced root wins, conflicting forces use the documented deterministic tie-break, and categorization and audio routing must share the result. Issue #227 tracks the cross-post-aware implementation.

## Additional gaps from the verification review (G21-G39)

These were found while re-verifying G1a-G20 against this snapshot. Unlike the audited gaps, none of them has a dedicated regression test yet; each is proven by the quoted source citations and should receive a focused test as part of its fix.

### G21 — NZB-creation claim expiry lets a second worker truncate or delete a just-completed release (critical, destructive race)

**Reachable state and path.** `claimBatch()` stamps `nzb_creation_claimed_at` for the whole batch up front (`NzbCreationCandidateQuery.php:77-84`), then the worker processes releases sequentially without refreshing leases (`ReleaseProcessingService.php:700-735`). The TTL is `max(300, releaseprocessingtimeout*2)` — 300 seconds at defaults (`NzbCreationCandidateQuery.php:164-168`). Any batch slower than the TTL makes its tail reclaimable while worker A is still coming to it; the next cycle over the same group reclaims those rows.

**Contradiction.** Neither the success nor the failure path re-verifies ownership. The success update is unconditional — no claim-token check, no `nzbstatus` guard — and even nulls the *other* worker's claim columns (`NzbService.php:266-275,802-817`). Two destructive outcomes when A finishes while B holds the reclaimed row: B loads collections after A's CBP cleanup (`NzbService.php:288-299`), sees them empty, classifies it as deterministic (`:113-114`), and `handleFailedNzbCreation` deletes the release **and A's freshly written NZB** without rechecking that `nzbstatus` is now 1 (`ReleaseProcessingService.php:746-772,828-868`); or B is mid-stream when A deletes CBP — the keyset pager silently ends on an empty page (`NzbService.php:186-237,340-366`) and B renames a **truncated** NZB over A's complete file (`:257-259`), with CBP already gone.

The claim machinery is described in the lifecycle document as a benign lease; the double-processing interaction is distinct from G8/G14 and can destroy a complete release.

### G22 — an unreadable NZB store mass-deletes the general-AP backlog and orphans the NZB files (critical, destructive misclassification)

**Reachable state and path.** `NzbService::nzbPath()` returns `false` whenever `is_file()` fails (`NzbService.php:441-455`) — a missing file and an unmounted/failed NZB volume are indistinguishable. `NzbContentParser::parseNzb()` turns that into an error (`NzbContentParser.php:34-45`).

**Wrong transition.** General AP responds to that parse failure by **deleting the release** (`ReleaseProcessor.php:114-127` → `ReleaseFileManager::deleteRelease`). During a storage outage every claimed release (up to `maxaddprocessed` × 16 buckets per cycle) is deleted; the delete's own file cleanup also cannot resolve the path (`ReleaseFileManager.php:423-431`), so when storage returns, the NZB files are permanently orphaned on disk with no DB row. Contrast: the NZB *writer* carefully classifies transient vs deterministic failures (`NzbService.php:100-143`), and the *audio* path merely settles on the same parse error (`AudioReleaseProcessor.php:53-56`). Storage-level read failures need a transient classification.

### G23 — a declined audio release below the general minimum is claimable by neither path (high, stranded AP)

**Reachable state and path.** The audio probe declines a sub-`minsizetopostprocess` music-routed release (e.g. a 5 MB music-video or misrouted single): `declineToVideoPath()` writes `additional_pp_claim_token='aud:declined'` (`AudioCandidateQuery.php:186-198`).

**Contradiction.** The audio query now excludes the token (`AudioRouting.php:55-59`), and the video query would admit it via the token (`AudioRouting.php:71-75`) — but `AdditionalCandidateQuery::applyPredicates()` applies `minSizeBytes` before routing (`AdditionalCandidateQuery.php:98-104`; `ReleaseClaimant.php:68-70`). The audio path's entire purpose is no-minimum selection (#166), so it regularly claims sub-minimum releases, and every one it declines lands permanently pending. Only manual `releases:requeue-audio-previews --include-declined` touches the state — and it hands the row *back to audio* for another futile probe loop. This falsifies the earlier boundaries-section claim that declined rows are always admitted by general AP.

### G24 — `pp_timeout_count` is a lifetime ratchet that requeue/reset tools ignore, and the rescue tool cannot see counter strands (high, tool-induced strand)

**Reachable state and path.** No code path ever resets `pp_timeout_count` (repo-wide, the only writers are the incrementers at `AudioReleaseProcessor.php:127-140` and `ReleaseFileManager.php:396-400`; settlement writes neither — `AudioReleaseProcessor.php:221-246`, `ReleaseClaimant.php:259-269`). The audio path burns one unit before every archive fetch, successful ones included.

**Contradiction.** All four requeue writers set `haspreview=-1` without resetting the counter (`RequeueAudioPreviews.php:188-206`; `RequeueMissingVideoPreviews.php:50-57`; `ReleaseRepairService.php:197-200`; `PreviewGenerationPolicy.php:122-127`), as do `nntmux:reset-postprocessing` and `releases:additional --reset` (`NntmuxResetPostProcessing.php:75-91,353-369`; `ProcessAdditionalGuid.php:72-80`). With the default `maxpptimeoutcount=3`, the third requeue of any archive-backed audio release writes a pending state admitted by neither path — exactly the G5 strand, manufactured by the documented operator tools with no crash. Worse, `RequeueAudioPreviews`'s "stranded" arm matches only the wrong-sentinel (G6) strand (`RequeueAudioPreviews.php:132-137`); a counter strand (`haspreview=-1`, correct sentinel) is invisible to the one tool nominally responsible for rescuing it. General AP is unaffected on this axis — it has no counter gate.

### G25 — the central rename writes `''` into nullable metadata FKs; `strict=false` coerces it to 0, defeating the `IS NULL` re-lookup predicates (high, stranded metadata)

**Reachable state and path.** `ReleaseUpdateService::performDatabaseUpdate()`'s accepted-name branch (the mainline sweep path) writes `'musicinfo_id' => ''`, `'consoleinfo_id' => ''`, `'bookinfo_id' => ''`, `'anidbid' => ''` (`ReleaseUpdateService.php:356-367`), while the other branch correctly writes `null` (`:379-393`). The database connections run `'strict' => false` (`config/database.php:48,81`) and these are nullable integer columns (`database/schema/mysql-schema.sql:913-917`), so MySQL silently coerces `''` → `0`.

**Exclusion.** Every pending predicate for those types requires `IS NULL`: music (`MusicService.php:489`; `PostProcessRunner.php:183,685`), console (`PostProcessRunner.php:199,711`), book (`PostProcessRunner.php:663-666`), anime (`AnimeProcessor.php:88`) — and the tmux monitor counts agree (`Tmux.php:339-341`). A renamed music/console/book/anime release is therefore never looked up under its new name and never appears in any backlog count, silently contradicting the reset's whole purpose.

### G26 — NFO processing has no `nzbstatus` gate; a missing or unreadable NZB is stamped terminal `nfostatus=0` (high, stranded NFO/naming/completion)

**Reachable state and path.** Creation writes `nfostatus=-1, nzbstatus=0` (`Release.php:262-266`; `ReleaseCreationService.php:153`); the NZB is written later by the Releases pane — the same pre-NZB window G4 proves for PAR2. The NFO worker selects on the retry band and size only (`NfoService.php:819-838,976-990`), and the fan-out is `WHERE 1=1` plus that fragment (`PostProcessRunner.php:395-411`), so a pre-NZB release is selectable by the parallel NFO pane.

**Wrong transition.** `getNfoFromNzb()` treats any `parseNzb() === false` as "no NFO in NZB" and writes `nfostatus = NFO_NONFO (0)` (`NzbContentsService.php:84-94`) — but `parseNzb` also returns false when `loadNzb()` cannot find or decompress the NZB file (`:231-249`). NZB-not-yet-created and transient read/permission failures produce the same terminal 0 as a genuine no-NFO verdict; 0 is outside the retry band and never revisited, permanently losing the NFO naming source and G11's completion side effect. A related edge: the walk-down parks exhausted rows at `-(maxnforetries+1)`, but the archive fallback selects exactly `-9` (`NfoService.php:884-886`), so any non-default `maxnforetries < 8` produces exhausted rows (e.g. `-4`) the fallback never sees.

### G27 — TV "show matched, episode missing" is a dead state no worker revisits, and Trakt's failure sentinel points at a provider that does not exist (high, stranded metadata)

**Reachable state and path.** Providers write `videos_id > 0` with `tv_episodes_id = 0` when the show matches but the episode lookup fails (`setVideoIdFound(..., 0)`: `TraktProvider.php:138,183`; `TmdbProvider.php:132,196`; `TvMazeProvider.php:150`; `LocalDbProvider.php:86`; writer at `AbstractTvProvider.php:166-177`).

**Exclusion.** Every claim predicate requires `videos_id = 0`: worker selection (`AbstractTvProvider.php:147`; `TvProcessor.php:218`; `TvProcessingPipeline.php:164`), the runner (`PostProcessRunner.php:473,490,579`), and the monitor (`Tmux.php:336`). Nothing revisits the row — even after the metadata source later adds the episode, the routine case for episodes indexed on air date. `nntmux:reset-postprocessing tv` misses the variant because it requires **both** columns non-zero (`NntmuxResetPostProcessing.php:330`). Separately, Trakt — the last pipe; no IMDB pipe exists — writes `PROCESS_IMDB = -4` on failure (`TraktProvider.php:86,184,194,205`; constant at `AbstractTvProvider.php:51`). `-4` is outside every `BETWEEN -3 AND 0` predicate and no provider claims it: a semantically pending value that is actually terminal, distinct from the designed terminals (`NO_MATCH_FOUND = -6` is never written anywhere).

### G28 — an exception exit in general AP retries forever without counting (high, unbounded retry)

**Reachable state and path.** `AdditionalProcessingOrchestrator::processReleases()` catches any per-release throwable, records a `Failed` result, and the `finally` immediately clears the claim with no state change (`AdditionalProcessingOrchestrator.php:227-250`).

**Contradiction.** The comment at `:243-245` claims "the pp_timeout_count / maxpptimeoutcount machinery will eventually drop it" — but the counter increments only in `handleReleaseTimeout()` (`ReleaseFileManager.php:383-407`), reached only via the wall-clock check `isTimedOut()` (`ReleaseProcessor.php:324-338`). An exception exit — including `TemporaryWorkspaceUnavailable` (`ReleaseProcessor.php:101-109`) — increments nothing and settles nothing. The release stays fully pending, is re-claimed every cycle forever, and occupies one of the bucket's `maxaddprocessed` slots each time.

### G29 — deleters that bypass the canonical cleanup path leave artifacts and stale search documents (high, orphaned artifacts)

**Reachable state and path.** The canonical path — `ReleaseManagementService::deleteSingle/deleteBatch` (`ReleaseManagementService.php:50-146`) — removes the NZB, image/audio artifacts, and the search document, and every pipeline deleter uses it. Several writers do not:

- `AdminReleaseReportController` deletes via `Release::where('id', ...)->delete()` in both the single and bulk paths (`AdminReleaseReportController.php:113,243`). Mass deletes skip `ReleaseObserver` (registered at `AppServiceProvider.php:83`), so the NZB stays on disk, artifacts survive, and the Manticore/ES document keeps the release findable — for a *reported*, often abusive or passworded, release.
- The NZB-import failure path deletes with `Release::query()->where('guid', ...)->delete()` (`NzbImportService.php:235`) after `insertRelease` already synced the index (`Release.php:276`, and imports run outside a wrapping transaction) — leaving an orphan search document nothing reconciles (see G30).
- `nntmux:reset-truncate` bulk-deletes every `nzbstatus=0` release (`NntmuxResetTruncate.php:53`) — all indexed at insert — with no search cleanup; `NntmuxRemoveBadReleases.php:57` has a raw bulk delete that in a race window deletes without cleanup.

### G30 — repair/rescan writes never reach the search index (resolved)

**Resolved (#224).** The recovery evidence transition explicitly schedules a release search sync
after changing indexed `totalpart`, `passwordstatus`, `nfostatus`, or `haspreview` state; the
repair/rescan outcome and `completion` writes are stored-only and do not request a redundant sync.
The sync uses the same after-commit path as other raw release writers, so Manticore failures enter
`search_index_failures` for `nntmux:search-repair`.

`nntmux:search-maintain` now walks a bounded, persistent id cursor across the database and active
Manticore or Elasticsearch index. It repairs missing or mismatched documents and removes orphan
documents, then wraps after reaching the end. This scheduled safety net covers crash windows and
historic bypass writes without requiring a one-off repopulation.

### G31 — `passwordstatus >= 0` is a second silent standard-sweep gate (medium, stranded names)

**Reachable state and path.** With inspection active, creation writes `passwordstatus=-1` (`Release.php:242-263`; `PasswordInspectionMode.php:17-20`), and only AP settles it. Before #213 the standard sweep required `passwordstatus >= 0`, and the monitor repeated it.

**Exclusion.** A release AP never reaches — size-excluded above the maximum, or stranded per G5/G23/G24 — was excluded from the standard sweep indefinitely, the same shape as G1b's `nfostatus` gate. The windowed per-source methods do not check `passwordstatus`, so only the sweep (the safety net that matters after the six-hour windows expire) was affected.

**Resolved (#213).** The `passwordstatus` gate was removed with the rest of the cross-source gates; AP still owns password settlement. `ReleaseLifecycleEligibilityGapTest::the_standard_name_sweep_admits_a_release_whose_password_inspection_never_settled` covers it.

### G32 — SRRDB is missing from the standard sweep (medium, stranded names)

**Exclusion.** `standardCandidateBatch`'s OR list had no `proc_srrdb = 0` term, and tmux ran SRRDB only as level 21 inside the six-hour window (`FixReleaseNames.php:121-122`; `TmuxTaskRunner.php:438-447`). The comment near the fix-names task runner — that anything a stalled method let age out is still picked up by the sweep — was false for SRRDB: releases older than six hours that never got an SRRDB pass (e.g. during an SRRDB API outage) were never retried automatically.

**Resolved (#213).** SRRDB joined the sweep's OR list and the sweep now performs the lookup, settling `proc_srrdb` exactly as the windowed method does. The term carries the worker's own gates — `nntmux_srrdb.enabled`, `is_trusted_name = 0`, and an eight-character archive CRC in `release_files` — because the SRRDB worker declines to settle rows that fail them, and admitting those rows would keep the pane awake on work that can never drain. The task-runner comment is now true.

### G33 — cross-source status-column sharing consumes unrelated evidence (medium, stranded names)

**Contradiction.** `NameFixingQueryService::STATUS_COLUMNS` (`:44-55`) maps `SOURCE_XXX → proc_files` and `SOURCE_MEDIA_MOVIE → proc_uid`. A miss in `fixNamesWithMediaMovieName` marks `proc_uid = 1` (`NameFixingService.php:1342`), permanently blocking the UID-donor source (level 9 and the sweep's `proc_uid = 0` term) even if a UID donor appears later; likewise an XXX miss marks `proc_files = 1` (`:1297`), consuming the general filename source's flag.

### G34 — no claim or lock separates recovery passes and AP claims from the destructive sweeps (medium, orphaned artifacts)

**Reachable state and path.** Neither repair/rescan candidate query claims rows, so the G8 overlap population can be mid-rescan while a concurrent Releases-pane sweep deletes the row; `MissingFileRescanService` then calls `replaceNzbContents()` by guid (`MissingFileRescanService.php:224`), writing an NZB for a release that no longer exists (an orphan file on disk), and its final UPDATE silently matches zero rows. Similarly, none of the `deleteReleases()` sub-sweeps (`ReleaseProcessingService.php:953-971,1236-1304`) or hourly `nntmux:remove-bad` (`ReleaseRemoverService.php:810,991`) exclude rows with a live `additional_pp_claimed_at`; an in-flight AP worker keeps writing guid-keyed preview/sample files after the delete, and nothing ever cleans them. DB-side damage is contained — `release_files` cascades and the search driver deletes the document when updating a missing row (`ManticoreSearchDriver.php:958-975`).

### G35 — rescan budget exhaustion mid-window is misread as a verdict (medium, incorrect outcome)

**Wrong transition.** `MissingFileRescanService::fetch()` breaks out on budget exhaustion mid-window returning `fetchFailed=false` (`:268-271`); if nothing matched in the portion actually read, the caller treats it as a genuine "no header in the window belongs to this release" verdict (`:209-217`) and stamps RetryPending/Failed — even though the unread tail of the window may hold the missing headers. Only exhaustion *before the first batch* is correctly treated as not-attempted (`:187-192`).

**Resolved (#216).** Fetch now reports whether the complete window was read. An incomplete window
never writes a negative verdict; it may persist matches and record `repaired` only if they reach
the target. A later invocation starts the whole window again with a fresh run budget.

### G36 — repair's AP requeue resets only preview/password, not NFO or `proc_*` evidence (low, stale derived state)

`requeueForAdditionalProcessing` (`ReleaseRepairService.php:197-200`) restores `haspreview=-1` and the pending password sentinel but does not reset `nfostatus` or any `proc_*` flag, so consumers that already recorded "nothing found" against the incomplete NZB — e.g. an NFO scan that concluded no-NFO — never re-examine the repaired one. This is G13's disease on the repair side, at smaller scale.

### G37 — audio settlement always writes `passwordstatus=0`, even under deep inspection (low, semantics)

`AudioReleaseProcessor::settle()` unconditionally writes `PASSWD_NONE` (`AudioReleaseProcessor.php:233`), so audio-routed releases can never be flagged passworded, unlike the general path, which records an archive verdict. Terminal state rather than a strand, but a policy asymmetry: password cleanup can never act on audio-routed content.

### G38 — the broken-release delete path misses audio artifacts (low, orphaned files)

`ReleaseFileManager::deleteRelease` hand-builds its artifact list — `.ogv` plus thumbnails only (`ReleaseFileManager.php:432-447`) — instead of calling `ReleaseImageService::delete`, which additionally glob-deletes the audio preview clip and spectrogram (`ReleaseImageService.php:200-232`). A release that acquired audio artifacts and later re-enters general AP and hits the timeout cap leaves those files on disk forever.

### G39 — group purge and deactivation leave pipeline edges uncovered (low, stale policy)

`UsenetGroup::purge($id)` → `reset($id)` deletes only `missed_parts` and resets stats (`UsenetGroup.php:432-449,492-521`); only the global `resetall()` truncates CBP. A purged group's collections survive with `releases_id` pointing at deleted releases, and ingest has no status gate against attaching new binaries to them (the G14 handlers), so a re-activated group can silently lose re-fetched binaries into dead collections until `partretentionhours` cleanup. Also, the min/max-size and min-files filters and per-group release cleanup iterate **active** groups only (`ReleaseProcessingService.php:56-57,595-596,935-936`) while release creation has no `active` gate (`ReleaseCreationService.php:55-61`) — a deactivated group's in-flight collections still become releases but skip that group's size/file policy.

## Configured boundaries that are not predicate contradictions

- `AudioRouting` itself partitions the shared pending set correctly: `applyAudioPath` (audio ∧ not declined) and `applyVideoPath` (not audio ∨ declined) are exact boolean complements with NULL-token SQL handled explicitly (`AudioRouting.php:51-76`), and `AdditionalCandidateQuery` applies the inverted rule (`AdditionalCandidateQuery.php:112`). The decline leaves `proc_*` flags untouched intentionally because general AP still owns the row — **provided the release also passes general AP's size window; a declined row below `minsizetopostprocess` is G23, and the crash/ratchet guard is G5/G24.** The polarity itself does not break coverage.
- Audio intentionally has no `minsizetopostprocess`; general AP does. Thus a row recognized as audio below the general minimum is covered *by the audio path* until it declines, fixing the motivating #166 gap. A **non-audio** row below the configured minimum, or any row above the configured maximum, remains pending-looking but intentionally outside processing. These are operator policy boundaries, not contradictory predicates (`AdditionalCandidateQuery.php:55-78`; `AudioCandidateQuery.php:45-68`). G20 is different: the release has a forced-Music cross-post association, but the routing predicate ignores it because it is not primary. G23 is also different: the decline transition moves a sub-minimum row from the covered audio side to the uncovered general side.
- A central name fix cannot recategorize out of a Forced root, but same-root MediaInfo refinement still executes even when the category did not change (`RecategorizeReleaseAfterNameFix.php:43-59`). ADR 0010 resolves G15's former policy conflict by limiting ADR 0006's audio cross-root exception to Routed floors.
- Per-root preview and executable toggles are consistently evaluated from the **current** category. Preview skip uses -2 and can be restored after a category change; executable sweep and inline discard share `ExecutableReleaseDiscardService` (`PreviewGenerationPolicy.php:50-127`; `ExecutableReleaseDiscardService.php:65-106,161-203`). No dead state was found in those toggle interactions.
- Poster blacklist creation launches a full detached sweep and ordinary header ingest uses the enabled rule afterward (`PosterIdentityBlacklistService.php:65-105`; `BlacklistSweepService.php:129-145`). No lifecycle gap was found in #127/#128 independent of an external process failure.

## Proposed GitHub issues

These are drafts only. Labels are the repository's canonical triage labels from `docs/agents/triage-labels.md`. Every acceptance criterion is deterministically testable without production measurements.

| Finding / proposed title | Severity | Label | Body draft, including test-only acceptance criteria |
|---|---:|---|---|
| G1a — Let standard name fixing revisit unrenamed releases outside Other | High | `ready-for-agent` (#213, resolved) | Releases can be created or forced directly into a typed root with `isrenamed=0`, but both the standard sweep and automatic source passes require Other. Expand/replace that gate while preserving trusted-name and per-source safety. **Acceptance:** a PHPUnit feature test constructs unrenamed Music, Movie, forced-root, and Other rows with usable source flags and proves each intended row is selected exactly once; already-renamed and PreDB-matched controls remain excluded. |
| G1b — Decouple standard non-NFO name sources from negative NFO status | High | `ready-for-agent` (#213, resolved) | `nfostatus>-1` blocks files/PAR2/UID/SRR/hash/CRC even when NFO is pending or terminally failed. Make each source depend only on its own readiness, while NFO itself still respects NFO status. **Acceptance:** query tests prove -1, -9, and -10 rows with non-NFO evidence are selected, NFO lookup is not attempted without `nfostatus=1`, and a row with no unprocessed evidence remains excluded. |
| G2 — Derive Fix Names pane wake-up from the standard candidate query | High | `ready-for-agent` (#213, resolved) | The monitor omits four source flags admitted by the worker. Remove the duplicate predicate or make it exactly equivalent. **Acceptance:** orchestration/query tests cover UID-only, SRR-only, hash-only, and CRC-only backlog and prove `runFixNamesTask` launches; a fully processed control keeps the pane disabled. |
| G3 — Give PreDB full-text matching an automatic, repeat-safe lifecycle | High | `ready-for-agent` | The only cross-category PreDB matcher is operator-only and consumes each PreDB row once. Add bounded automatic scheduling and a retry/reconciliation model that can match releases arriving after a title's first scan. **Acceptance:** tests prove the orchestrator invokes a bounded full-text batch, a title with no initial release can match a later release, and successful/flood results do not loop indefinitely. |
| G4 — Do not consume PAR2 name-fix state before NZB availability | High | `ready-for-agent` | PAR2's source predicate is `1=1`, so a pre-NZB release can be attempted and marked done; `NzbContentsService::checkPar2()` also marks `proc_par2=1` when the NZB file is simply unreadable. Require `nzbstatus=1` (and retain/reopen state when evidence changes). **Acceptance:** a pre-NZB row is not selected or marked, becomes selected after NZB success, and a miss is retryable after a repair/rescan adds PAR2 evidence. |
| G5 — Settle or hand off audio rows that reach the archive crash limit | Critical | `ready-for-agent` | An audio-routed row at `pp_timeout_count>=max` without `aud:declined` is admitted by neither path. Make the maximum an atomic state transition (settle, decline, or deletion according to policy), including crash recovery. **Acceptance:** tests construct limit and over-limit states before/after stale claims and prove every pending row is claimable by exactly one path or explicitly terminal, never neither. |
| G6 — Make AP pending state stable across password-inspection mode changes | High | `ready-for-agent` | Pending eligibility is encoded as a mutable password verdict, and reset commands hard-code -1. Introduce a stable pending marker or reconcile both sentinels safely; make every reset use one API and give the mismatch requeue full category coverage. **Acceptance:** tests toggle inspection in both directions, exercise all reset/requeue commands, and prove each pending row is selected by exactly one AP path while settled 0/1 rows remain settled. |
| G7 — Schedule bounded whole-file rescan before incomplete cleanup | High | `ready-for-agent` | #161 supplies a candidate query and command but no automatic caller. Integrate a bounded rescan stage with repair-before-sweep ordering and overlap protection. **Acceptance:** scheduler/tmux tests prove due rescan work invokes the command before the deletion decision, observes limits/without-overlap, and no-work cycles do not launch workers. |
| G8 — Make unresolved declared-file counts deletion-safe | Critical | `ready-for-agent` | `declaredfiles=NULL` currently means both "derive on rescan" and "safe to delete." Ensure the sweep cannot admit a row until rescan has produced a final verdict. **Acceptance:** a gate test proves null+rescan-null/retry is never swept, becomes sweepable only after a deletion-final rescan result, and remains recoverable when derivation finds missing files. |
| G9 — Keep partial whole-file rescans retryable below target | High | `ready-for-agent` | Any nonempty recovery is stamped `repaired` even below target, which no query revisits. Make outcome target-aware like segment repair. **Acceptance:** service tests prove below-target partial recovery writes retry-pending then final failure/retry per policy, at-target recovery writes repaired, and every below-target outcome belongs to recovery or deletion—never neither. |
| G10 — Reevaluate repaired outcomes when completion target increases | Medium | `ready-for-agent` | `repaired` is treated as timeless even though its meaning depends on a mutable target. Store the achieved target or derive eligibility from current completion/target. **Acceptance:** tests repair at 95, raise to 99, and prove the row re-enters the appropriate recovery path; unchanged/lower targets do not duplicate work. |
| G11 — Measure completion during NZB import | High | `ready-for-agent` | Imported releases start `nzbstatus=1,completion=0`; only an eligible NFO pass incidentally measures them, while automatic recovery intentionally ignores 0. Compute the same segment-based completion during import or automatically enqueue a local measurement. **Acceptance:** import tests cover full, partial, NFO-disabled/size-excluded, and unmeasurable NZBs; measurable imports persist the correct nonzero percentage and immediately obey recovery/sweep gates, while truly unmeasurable imports retain the protected sentinel. |
| G12 — Make normalized dedupe complete for mixed-era search names | High | `ready-for-agent` | The unordered 25-row prefix cap can omit a true normalized match, and raw counter prefixes are never candidates (nor normalized by `ReleaseNameNormalizer`). Replace the incomplete prefilter with an indexed normalized identity/backfill or a complete deterministic strategy, including normalizer coverage of counter/yEnc decoration. **Acceptance:** tests with more than 25 false prefixes and legacy counter/quote/yEnc forms find the true duplicate; distinct reposts and out-of-tolerance sizes remain distinct. |
| G13 — Requeue derived processing after whole-file NZB recovery | High | `ready-for-agent` | Rescan can add qualitatively new files but only updates completion/outcome, leaving `totalpart` and consumer verdicts stale. Centralize an evidence-changed transition that refreshes counts and selectively resets AP/relevant naming flags. **Acceptance:** tests start from settled AP/name state, add a file through rescan, assert `totalpart`/completion agree with the NZB, and prove the row becomes eligible for intended AP/name stages exactly once; releases with no newly useful evidence are not requeued. |
| G14 — Reconcile completion with late CBP before destructive cleanup | Critical | `ready-for-agent` | CBP can change after release creation but before NZB write, while #156 freezes the earlier completion. Record/freeze the measured CBP version or remeasure authoritatively at write time without reintroducing #147's lossy arithmetic. **Acceptance:** a feature test creates a partial release, attaches late parts before NZB write, proves stored completion matches the final NZB, and proves repair/sweep cannot delete the now-complete release. |
| G15 — Decide forced-root precedence for audio-only MediaInfo evidence | Medium | `ready-for-agent` (#212) | ADR 0010 makes a Forced root an absolute operator lock and scopes ADR 0006's audio-only exception to ADR 0005's Routed floor. The existing forced-root guard matches the decision, so no application change is required for this gap. |
| G16 — Route book and audio renames through one canonical transition | Medium | `ready-for-agent` | Direct renamers bypass metadata resets, trust semantics, and the `ReleaseNameFixed` listener (search sync is already covered by both). Extract a canonical rename operation whose side effects are explicit and shared. **Acceptance:** tests feed equivalent accepted names through standard, book, and audio paths and assert identical category/trust/reset/event/search-sync state, with path-specific source flags preserved. |
| G17 — Derive metadata pane monitoring from worker candidate queries | Medium | `ready-for-agent` | Audiobooks and legacy wrapper-name Books can be worker-eligible but monitor-invisible, renamed-only TV/Music/Console/Games can be monitor-visible but worker-ineligible (Music also at the runner level), and disabled lookup toggles are not reflected in most counts (movies is the working example). Reuse candidate counts rather than handwritten SQL. **Acceptance:** tmux tests prove audiobook/wrapper-only work wakes the pane, unrenamed rows under every renamed-only mode do not, disabled-toggle types report zero, and each metadata type's monitor count equals its candidate-query count. |
| G18 — Make exact PreDB attachment an atomic naming state transition | Medium | `ready-for-agent` | `Predb::checkPre` and `attachPredbId` write only the id, yielding `predb_id>0,isrenamed=0,is_trusted_name=0` (contrast `attachSrrdbMatch`). Define whether exact equality confirms the current name and update all implied fields atomically. **Acceptance:** tests exercise creation-time and late exact matches, assert consistent renamed/trust/category/event state, and prove renamed-only metadata is not stranded. |
| G19 — Restore `iscategorized` for unchanged rows in `recategorize --all` | Medium | `ready-for-agent` | `--all` clears the flag globally but restores it only when category changes; `--all --test` performs the destructive reset despite being a dry run; the book path writes `bookinfo_id=0` where predicates expect NULL. Always finalize each non-test row, make `--test` fully read-only, write NULL metadata ids, and chunk the candidate set. **Acceptance:** a command test covers changed and unchanged Other/non-Other rows and asserts every processed row ends `iscategorized=1`, `--test` writes nothing, recategorized book rows remain book-lookup-eligible, and cleanup eligibility is preserved. |
| G20 — Define group-policy precedence for cross-posted releases | High | `needs-triage` (#227) | ADR 0010 requires any associated Forced root to win, uses the primary group's force when forced and otherwise the lowest forced-root category ID to break conflicts, and requires categorization and audio routing to share the result. **Acceptance:** integration tests create the same plain-vs-forced upload with opposite group ingestion orders and assert identical category and AP routing; conflicting forced roots follow the primary-force/lowest-ID tie-break; and a below-minimum forced-Music cross-post is owned by exactly one path. |
| G21 — Verify claim ownership before NZB write/delete decisions | Critical | `ready-for-agent` | Expired NZB-creation claims allow a second worker to double-process a release: it can delete the release and the first worker's NZB after CBP cleanup, or rename a truncated NZB over the complete one. Refresh leases per release and make the success/failure writers conditional on claim ownership and current `nzbstatus`. **Acceptance:** tests simulate an expired claim with a concurrent completion and prove the losing worker neither deletes the release/NZB nor overwrites the file; the winning worker's state is unchanged. |
| G22 — Classify NZB read failures as transient in general AP | Critical | `ready-for-agent` | General AP deletes a release whenever its NZB cannot be read, which turns a storage outage into a mass delete plus orphaned NZB files. Distinguish missing-release-data from storage-level failure (as the NZB writer does) and retry/skip instead of deleting. **Acceptance:** tests prove an unreadable NZB store leaves claimed releases pending and undeleted, a genuinely absent NZB still follows deletion policy, and no NZB file is orphaned. |
| G23 — Give declined sub-minimum audio releases an owner | High | `ready-for-agent` | `aud:declined` hands a release to general AP, but rows below `minsizetopostprocess` are excluded there, so every declined sub-minimum audio claim is stranded. Either admit declined rows regardless of the general minimum or settle them at decline time. **Acceptance:** tests prove a declined below-minimum row is claimable by exactly one path or explicitly settled, and above-minimum declines continue to reach general AP. |
| G24 — Reset `pp_timeout_count` on settlement and requeue | High | `ready-for-agent` | The counter is a lifetime ratchet: audio increments it on every archive pass, nothing resets it, and no requeue/reset tool clears it, so routine requeues manufacture the G5 strand and the audio requeue tool cannot see counter-stranded rows. Reset on successful settlement and in every requeue/reset writer; teach the requeue tool to select counter strands. **Acceptance:** tests prove a settled release returns to 0, three successive requeues remain claimable, and the requeue command selects a correct-sentinel counter strand. |
| G25 — Write NULL, not `''`, when clearing metadata ids on rename | High | `ready-for-agent` | The accepted-name branch of `ReleaseUpdateService::performDatabaseUpdate()` writes `''` into nullable metadata FK columns; with `strict=false` MySQL stores 0, defeating every `IS NULL` re-lookup predicate. Write NULL in both branches. **Acceptance:** a test renames a music/book/console/anime release and proves each cleared id is NULL and the release is selected by its metadata candidate query and counted by the monitor. |
| G26 — Gate NFO work on NZB availability and classify read failures | High | `ready-for-agent` | NFO selection has no `nzbstatus` gate and stamps terminal `nfostatus=0` when the NZB is missing or unreadable; the archive fallback also hardcodes -9 while exhaustion parks at `-(maxnforetries+1)`. Require `nzbstatus=1`, treat read failures as retryable, and derive the fallback band from the configured retries. **Acceptance:** tests prove a pre-NZB row is not selected, an unreadable NZB leaves the retry band intact, a genuine no-NFO still writes 0, and a non-default retry setting still reaches the archive fallback. |
| G27 — Revisit episode-missing TV rows and remove the -4 dead sentinel | High | `ready-for-agent` | `videos_id>0, tv_episodes_id=0` is claimed by no worker even after the provider adds the episode, and Trakt writes `-4` (process-IMDB) though no IMDB pipe exists. Add a bounded revisit for episode-missing rows and map the final pipe's failure to a designed terminal. **Acceptance:** tests prove an episode-missing row is re-attempted per policy and linked when the episode appears, and no path can write a `tv_episodes_id` outside the claimable or designed-terminal set. |
| G28 — Count and bound exception failures in general AP | High | `ready-for-agent` | A per-release exception clears the claim without settling or counting, so a poison release retries every cycle forever; the code comment claiming the timeout cap handles it is wrong. Route exception exits through the same counter/settlement machinery as timeouts. **Acceptance:** tests prove a repeatedly throwing release reaches the configured cap and is settled or deleted per policy, and a once-throwing release retries normally. |
| G29 — Route every release deletion through the canonical cleanup path | High | `ready-for-agent` | Admin report deletes, the import-failure delete, and reset/truncate commands bypass `ReleaseManagementService`, leaving NZBs, artifacts, and search documents behind. Use the canonical delete (or replicate its cleanup) in every deleter. **Acceptance:** tests prove each path removes the NZB, artifacts, and search document, and bulk paths remain bounded. |
| G30 — Sync repair/rescan state changes to the search index | High | Resolved (#224) | Recovery evidence writes sync indexed state after commit; scheduled bounded maintenance repairs drift and orphan documents for both search drivers. |
| G31 — Decouple the standard name sweep from the password pending sentinel | Medium | `ready-for-agent` (#213, resolved) | `passwordstatus>=0` silently excludes inspection-pending rows from the sweep, the same shape as the NFO gate. Let non-password evidence proceed while AP still owns password settlement. **Acceptance:** query tests prove a `-1` row with unprocessed evidence is selected and password inspection state is unchanged by naming. |
| G32 — Add SRRDB to the standard sweep | Medium | `ready-for-agent` (#213, resolved) | The sweep's OR list omits `proc_srrdb`, so SRRDB work that ages past the six-hour window is never retried. Include it under the same trusted-name/config gates as level 21. **Acceptance:** query tests prove an aged eligible row is selected only when SRRDB is configured, and processed/ambiguous rows remain excluded. |
| G33 — Give every name source its own status column | Medium | `ready-for-agent` | XXX misses consume `proc_files` and media-movie misses consume `proc_uid`, blocking unrelated sources. Add dedicated columns (or a per-source bitmap) and migrate the shared writers. **Acceptance:** tests prove an XXX miss leaves the filename source eligible and a media-movie miss leaves the UID donor source eligible. |
| G34 — Exclude in-flight claims from destructive sweeps | Medium | `ready-for-agent` | Deletion sweeps ignore live AP claims and running recovery passes, producing orphan artifact writes after deletion. Skip rows with a live claim/recovery lease, or re-verify existence before artifact writes. **Acceptance:** tests prove a claimed/in-recovery row is not swept during the lease and an expired lease restores sweep eligibility. |
| G35 — Treat mid-window budget exhaustion as not-attempted in rescan | Medium | `ready-for-agent` | Budget exhaustion mid-window is misread as "headers absent" and burns a rescan outcome. Propagate the exhaustion so the pass is not counted as a verdict. **Acceptance:** tests prove exhaustion mid-window leaves rescan state unchanged and a completed window still records its true verdict. |
| G36 — Reset NFO/name evidence when repair adds segments | Low | `ready-for-agent` | Repair's requeue restores only preview/password, so terminal NFO/name verdicts recorded against the incomplete NZB survive. Extend the requeue to reset the evidence-derived fields that the added segments can change. **Acceptance:** tests prove a repaired release re-enters NFO/name selection exactly once and unaffected verdicts are preserved. |
| G37 — Decide password semantics for the audio path | Low | `needs-triage` | Audio settlement always writes `passwordstatus=0`, so audio-routed releases can never be flagged passworded. Decide whether audio should inspect (or defer to general AP for) passworded archives, then align settlement. **Acceptance once chosen:** tests prove the chosen verdict is recorded for passworded audio archives and cleanup policy observes it. |
| G38 — Use the shared artifact deleter in the broken-release path | Low | `ready-for-agent` | `ReleaseFileManager::deleteRelease` hand-builds its artifact list and misses audio previews/spectrograms. Delegate to `ReleaseImageService::delete`. **Acceptance:** a test proves audio artifacts are removed when a broken release is deleted. |
| G39 — Cover purge/deactivation edges in collection and policy handling | Low | `needs-triage` | Single-group purge leaves that group's CBP with dangling `releases_id`, and deactivated groups' in-flight collections skip per-group size policy. Decide the intended semantics (purge CBP with the group; apply or explicitly waive policy for inactive groups) and implement. **Acceptance once chosen:** tests prove a purged group leaves no orphaned CBP claims and an inactive group's collections follow the chosen policy. |

## Verification index

The focused regression demonstrations for the audited gaps are:

- `tests/Feature/ReleaseLifecycleEligibilityGapTest.php`: G1a, G1b, G4, G31 (G1a/G1b/G31 now assert the resolved behavior).
- `tests/Feature/StandardNameSweepAdmissionTest.php`: G1a, G1b, G2, G31, G32 — the rebuilt sweep predicate and its derived monitor count.
- `tests/Feature/AudioCandidateQueryTest.php`: G5, G6, G20.
- `tests/Feature/ReleaseRepairGateTest.php`: G8, G9, G10, G11 gate ownership.
- `tests/Feature/NzbImportSegmentHashDedupeTest.php`: G11 producer state.
- `tests/Feature/CbpCleanupServiceTest.php`: G12's two mixed-era misses.
- `tests/Feature/MissingFileRescanServiceTest.php`: G13.
- Existing `tests/Feature/NzbCreationReliabilityTest.php::test_writer_leaves_the_creation_time_completion_untouched`: the writer half of G14.

The remaining audited findings, and all of G21-G39, are proven directly by command wiring or the mismatched predicates quoted above; adjacent suites cover nearby states but none of the new findings has a dedicated regression test yet. They should receive focused orchestration/service tests as part of their fixes. This audit did not query or depend on the production database.
