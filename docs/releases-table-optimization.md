# Releases table optimization runbook

The `2026_08_13_001652_normalize_and_optimize_releases_table` migration rebuilds the `releases` table with `ALGORITHM=COPY`. It permanently removes legacy columns after moving sparse NZB password and creation-failure state into dedicated tables. Its `down()` method restores the old columns empty, so a verified database backup is the rollback plan.

Production currently has about 167,508 releases and a 336 MB table. The rebuild is expected to take under one minute there, but schedule a maintenance window and do not extrapolate that estimate to larger installations.

## Procedure

1. Take and verify a database backup or snapshot.
2. Run the read-only checks:

   ```bash
   php artisan releases:optimize-preflight
   php artisan releases:normalize-guids --dry-run
   ```

3. If the GUID command reports only `leftguid` mismatches, correct them before migrating:

   ```bash
   php artisan releases:normalize-guids
   ```

   The command reports oversized, non-ASCII, and duplicate GUIDs but deliberately refuses to rewrite them because changing a GUID would orphan its NZB and break existing download links and statistics.

4. Confirm the database volume can hold at least twice the current `releases` data plus indexes. The migration checks the database data directory when PHP can see it. That check is a no-op for a remote database or a database in another Docker container, so verify capacity from the database host in those environments.
5. Stop release processing and pause every queue worker that can read or write releases:

   ```bash
   php artisan tmux:stop
   ```

6. Apply the migration:

   ```bash
   php artisan migrate
   ```

7. Optionally regenerate the local schema dump from the migrated MariaDB instance:

   ```bash
   php artisan schema:dump
   ```

8. Resume queue workers, then restart processing:

   ```bash
   php artisan tmux:start
   ```

## Overrides and known caveats

- `RELEASES_OPTIMIZE_SKIP_PREFLIGHT=true` skips the migration's repeated full-table scans. Set it only after `releases:optimize-preflight` succeeds against the same database.
- `RELEASES_OPTIMIZE_SKIP_FREE_SPACE_CHECK=true` bypasses the storage guard. Use it only after checking free space independently.
- `RELEASES_OPTIMIZE_CHUNK_SIZE` controls comment recount and rollback backfill batches. It defaults to 5000 and is clamped to 100–10000.
- The preflight command's UUID check reports legacy 40-character SHA1 GUIDs as blockers even though the final migration preserves and accepts them. Investigate those rows; do not replace their GUIDs automatically.
- If the rebuild fails or post-migration verification finds lost data, keep processing stopped and restore the verified backup. Rolling the migration down cannot recover values from the removed legacy columns.
