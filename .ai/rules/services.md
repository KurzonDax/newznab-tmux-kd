---
paths:
  - 'app/Services/**'
---

# Services

## Automated release deletion honors live work claims
All automated release deletion sweeps must apply ReleaseDeletionProtection during selection and use ReleaseManagementService's protected deletion boundary so claims acquired after selection are rechecked under a row lock. Fresh additional-processing claims and recovery leases are never deleted; stale claims use ReleaseClaimant's shared cutoff. Segment repair and whole-file rescan must hold RecoveryLease around their public service work and clear it in a finally path. Explicit operator deletion remains an override.

## TV processing admission has one shared predicate
Use App\Services\TvProcessing\TvProcessingCandidateQuery for TV provider selection, runner work gates, and tmux pending counts. Episode-revisit pacing and expiry live in that query; duplicating the SQL in a consumer causes worker/monitor drift.

## Claim heartbeats distinguish matched from changed rows
Database update() reports affected/changed rows, so a same-value heartbeat may return 0 while the worker still owns the row. For ownership-preserving updates that can be no-ops, accept one changed row or recheck the exact pending/token predicate with exists(). Treat zero as lost only when the update necessarily changes state, such as nzbstatus pending to added.

## Collection clocks follow ingestion frontiers
Collection promotion and stuck deletion share a quiet predicate comparing collection head/tail stamps to usenet_groups frontiers. NOW() belongs only in the legacy or disabled/settled-frontier fallback; frozen active frontiers keep collections waiting. Only ingestion writes last_seen_at and last_seen_*_postdate; dateadded stays at insertion time. Retention excludes in-flight collections. Forward pointers advance only through contiguous successfully ingested ranges.

## PAR2 membership does not establish one media title
Reconciled postings can contain independent videos: naming and movie/episode writers must honor CollectionReconciliation\BundleIdentity at the mutation boundary, because identifying one member does not identify the bundle. Collection promotion and cleanup must recheck CollectionOwnership under the same source-row locks used by ingestion. Publish replacements through PostingPublication so interrupted handoffs retain the prior NZB and inventory; a late rewrite must preserve files whose original CBP rows were already cleaned.

## Title-year names are not unique release identities
Releases named from a container title or any title-plus-year source can share one searchname across distinct files, including episodes of one series. Dedupe keyed on searchname must exclude these releases or also key on duration.

## Collection population locks require selective access and real commits
Keep exact collection-ID locks separate from state-by-state collections_admission_window ranges: LIMIT bounds returned rows, not examined or locked records. Ordinary sizing, completeness and cleanup commit at most eight source IDs per transaction; nested savepoints do not release locks. Establish raw window completeness before recovery exclusions: overflow skips speculative admission and defers artifact publication. Deploy the additive index before code requiring it (#539).
