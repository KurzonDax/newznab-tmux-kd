# Release cleanup prevention and controlled recovery

Deploy prevention before recovering anything. Stop the affected cleanup schedule before reverting to older vulnerable code. Do not delete recovered releases to undo a rollout.

Routine cleanup now requires a successfully stored NZB, no fresh NZB/AP/recovery claim, and no linked collection. Expired pending claims mean creation retry, never junk eligibility. The shared creator claim window includes equality as fresh. Ready releases remain protected until CBP cleanup finishes. Check pending-NZB and linked-CBP progress after deployment; a storage/cleanup failure intentionally keeps protection active.

PAR2 removal retains its two candidate prefilters and time window. Only a readable complete stored inventory with exclusively unambiguous `.par2` filenames qualifies. `release_files` is a sample, never completeness evidence. Every available positive file-count declaration must agree with the inventory, including subject counters and the original `declaredfiles`; stale-promoted `totalpart` cannot replace the declaration. Unknown filenames, mixed files, conflicting counts, empty/truncated XML or gzip, unsupported namespaces, internal DTD declarations/entities, duplicate names and unavailable storage defer deletion.

The reader accepts plain filenames or one quoted filename. It reads compressed input in 4 KiB chunks, caps compressed and decompressed input at 16 MiB, files at 10,000, XML depth at 16 and subjects at 4 KiB. One candidate is parsed at a time under its release-row lock; a keyset batch is at most 500 candidates (at most 8 GiB decompressed work, without retaining it). Both PAR2 prefilters are combined with OR in one keyset query to avoid parsing a candidate twice or materializing a union of the entire candidate population. The standard external NZB DOCTYPE is accepted without resolving its URL. It never repairs NZBs or contacts providers. NZB replacement uses the same row lock, preventing an in-process writer from replacing evidence between classification and deletion. External tools must not rewrite NZBs concurrently with cleanup.

The password-title class still requires its previous title match, category/text exclusions and time window, plus confirmed archive encryption on the release or a file. It does not enable `deletepasswordedrelease` or expand to encrypted releases with unrelated titles. This supersedes the older heuristic wording in issue #24.

## Caller policy

- `removeCrap` (including All), `removeByCriteria`, processing retention/password/completion cleanup, `NntmuxRemoveBadReleases`, and `NntmuxResetTruncate` retain the shared protected boundary. The criteria command is not a manual override. Reset/truncate still resets groups and CBP, but pending releases are retained for token-owned creation failure disposal.
- Explicit admin/manual deletion and `CleanNZB` retain their existing override behavior. `CleanNZB` is an operator storage-reconciliation command; do not run it against an unavailable NZB mount.
- Token-owned deterministic NZB failure disposal and exhausted retries retain their separate pending-release/CBP disposal. A losing token deletes nothing.

The existing daily rotating application log receives `release_deleted` only after a committed deletion, with ID/GUID, reason, lifecycle status, timestamp and bounded evidence (inventory count/digest for PAR2). `release_cleanup_selection` and `release_cleanup_batch` report protected/deferred counts and reason counts separately. Calls inside an enclosing transaction defer because its read snapshot may predate the evidence locks; normal sweeps run their own transactions. Dry runs have `dry_run=true` and never emit `release_deleted`. Owned creation failures retain their `nzb_creation` channel and are logged after commit.

## Operator procedure

1. Take a full database/CBP snapshot and export the affected rows and current NZBs first. Preserve the original investigation evidence separately. Test the procedure on a clone using the deployed code; the historical list of 225 dangling rows is not an allowlist or proof of deletion cause.
2. Report a bounded page without database/search/cache/provider mutations:

   ```bash
   scripts/agent-sail artisan releases:recover-orphaned-collections --limit=100 --manifest-out=/absolute/new/report.json
   ```

   Use `--after-id=<last collection ID>` for the next page. Existing manifest files are never overwritten. Review IDs/hashes, old parent IDs, counts/bytes, payload evidence, quiet state, duplicate candidates and rejection reasons. Historical cause is always reported as unknown.
3. In a copy of the report, set `selected` and `reviewed_unintentional_deletion` to `true` only for entries whose deletion you have independently reviewed as unintentional. Keep identity and fingerprint fields unchanged. Leave ambiguous or intentional cases unselected. Do not alter the report's `eligible` or `reasons` to force eligibility: apply recalculates them.
4. Pause ingest, formation, NZB creation, repair and cleanup workers before application. Apply a small reviewed trial manifest first:

   ```bash
   scripts/agent-sail artisan releases:recover-orphaned-collections --apply --manifest=/absolute/reviewed/report.json
   ```

   Apply accepts at most 100 entries and a 2 MiB manifest. Each entry locks/rechecks the absent parent, collection, group and bounded CBP inventory. Changed fingerprints, live parents, duplicate conflicts, missing parts/payload, unknown filenames, active frontiers and nonterminal states require replanning. Collection snapshots are limited to 10,000 binaries and 100,000 parts per entry; exceeding either is a review/defer outcome.
5. Successful application clears only `releases_id` and changes `filecheck` to `CompleteCollection`. All hashes, declared counts, article bounds, CBP, frontiers and ingestion/date clocks remain intact. Output is `pending_normal_formation` with the old ID and no replacement ID. Repeating the same apply is harmless.
6. Resume normal processing and verify the collection passes ordinary reconciliation, group floors, unwanted-collection rules, categorization and dedupe before release creation. Match the report's collection hash to the resulting release to obtain the new ID, or reapply the reviewed manifest after CBP cleanup to report `formed` and the new release ID, then verify its NZB and search entry and confirm linked CBP clears. A collection may remain pending or be rejected by normal formation; recovery never inserts a preapproved replacement.

Lost CBP needs a separately scoped header replay. This command is never scheduled, invoked by a migration/deployment, or applied automatically to production.
