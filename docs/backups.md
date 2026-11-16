# Database backups

NNTmux can create verified, compressed logical backups of MySQL and MariaDB. The feature is disabled by default. It takes one standalone backup per day at the Backup time, keeps a chosen number of the newest backups, and can copy them to separately mounted storage.

These backups run online with `--single-transaction`, so the site remains available. When configured, the processing tmux session is paused during the dump to reduce write activity and restored to its exact prior running state afterward.

## Configure backups

Open **Admin → Database Backups**, then configure:

- an absolute, writable Backup location outside the public web root;
- the **Backup time**;
- **Backups to keep** in the Backup location (1 to 100);
- whether backups **Include Working tables**;
- whether to pause tmux while dumping;
- an optional absolute `mariadb-dump` or `mysqldump` path;
- an optional off-site destination and **Off-site backups to keep** (1 to 10, required);
- whether to copy to the off-site destination after each successful backup (**Copy after each backup**).

Enable backups only after the destination is mounted and writable. Laravel's scheduler must already be invoked every minute in the normal way (`php artisan schedule:run`); NNTmux registers `backup:tick` with overlap protection.

For Sail deployments, paths configured in the admin page are container paths. Mount local or network storage into the application container and enter that mounted path, not the host-only path.

The Backup location must be local storage that remains available to the application. A removable disk or network share is an off-site destination, never the primary location. Install `mariadb-dump` (or `mysqldump`) and `gzip`; `pigz` is used automatically when installed. Off-site copies additionally require `rsync`.

## Backup tiers

Tables are selected by tier:

- **Important**: application state that cannot be recreated easily. Unknown tables default to this safest tier. Included in every backup.
- **Working**: large Usenet ingestion tables such as binaries, collections, parts, and missed parts. Included in every backup while **Include Working tables** is on.
- **Throwaway**: cache, queues, sessions, metrics, logs, and derived statistics. Never included.

The exact names and patterns live in `config/nntmux-backup.php`. Review that configuration after adding application tables with unusual lifecycle requirements.

## Commands

Run or inspect backups directly with:

```bash
php artisan backup:run
php artisan backup:list
php artisan backup:tick
php artisan backup:offsite
```

`backup:list` reads manifests from disk, checks that each dump is present at the size its manifest records, and reconciles the database catalog. Only one local backup and one off-site copy can run at a time. A second scheduled tick exits successfully and logs that it was skipped.

A scheduled backup is due when no backup attempt has finished since the most recent Backup-time slot. An attempt is a verified backup in the Backup location or a failed run. A failed scheduled run is not retried until the next slot: its failure email is the signal, and **Run backup now** stays available. A tick skipped because another run holds the lock is not an attempt, so the next tick tries again.

The admin **Run backup now** button records a request that `backup:tick` consumes within one minute.

## Files and retention

Each backup is written to its own folder in the Backup location, named from its start time (`YYYYMMDD-HHMMSS`), as `backup-YYYYMMDD-HHMM.sql.gz`. Beside it, `.manifest.json` records its timestamps, included tables and tiers, compressed size, SHA-256 checksum, application version, database version, and folder ID (`set_id`).

Files are finalized atomically: partial dumps and manifests are removed on failure and never appear as complete backups. Failed run records remain visible for diagnosis even when no backup file exists.

Retention counts single backups. After every successful backup, the automatic off-site copy runs first (when it is on), then local retention; local retention runs whether or not the copy succeeded, so a folder whose copy failed one night is still on local disk for the next night's copy. The same rule applies locally with **Backups to keep** and at the off-site destination with **Off-site backups to keep**:

- Folders are ordered newest first: by their newest manifest's `finished_at`, or, for a folder with no readable manifest, by the start time in its `YYYYMMDD-HHMMSS` name.
- The newest N folders whose files all verify are kept.
- Once N verified folders are kept, every older folder is removed, including folders that do not verify and folders with no manifest (a run killed mid-dump leaves only a `.partial` file; an rsync that fails part-way leaves a `.tmp-` file at the destination).
- Unverified folders newer than the oldest kept folder stay. While fewer than N verified folders exist, nothing is removed.
- A folder with no backup manifest whose name does not have the `YYYYMMDD-HHMMSS` shape is never touched.

A folder written before daily-only backups (one Full plus its Dailies, or a `no-full-yet-<timestamp>` folder) counts as one backup, ordered by its newest file, and is removed whole once it falls past the keep count. No manual cleanup is needed.

Before a run, NNTmux checks that free space is at least twice the size of the most recent verified backup in the Backup location. Restore errors, dump failures, invalid paths, unsupported database drivers, and verification failures stop the run and alert the configured admin email.

## Off-site copies

`backup:offsite` copies only the newest **Off-site backups to keep** verified local folders; older or unverified local folders are not copied, so a local folder with a bad file never aborts the copy. It uses `rsync`, verifies the copied checksum, and publishes the manifest last. A destination file is skipped when its size matches the source manifest and its manifest is identical to the source's (it is not re-hashed); a missing, wrong-sized or unmanifested file is recopied. Automatic copies run only when **Copy after each backup** is enabled and an off-site destination is set, after tmux has been restored and a local backup has succeeded.

By default the destination must be on a different mounted filesystem. For a genuine second local disk that reports the same device ID, use the explicit bypass:

```bash
php artisan backup:offsite --destination=/mnt/backup-disk/nntmux --allow-local
```

Use `--keep=N` (1 to 10) to override the off-site count for a run; it replaces the count both for which local folders are copied and for destination retention. Retention runs at the end of every successful run, including one where every file was already copied; a run that fails part-way does not prune. The admin page shows `Copied`, `Partial`, `Failed`, or `Not copied` per local folder.

An off-site keep of 0 (which used to keep every copied set) or above 10 becomes 10 on upgrade. The first copy after the upgrade then removes off-site folders past that count.

To copy independently of the application scheduler, add a cron entry for the application user. Adjust paths and the time to suit the installation:

```cron
17 4 * * * cd /var/www/nntmux && php artisan backup:offsite >> storage/logs/backup-offsite.log 2>&1
```

## Restore a backup

Test restores regularly on a non-production database. Stop processing before restoration and verify the checksum; the manifest contains the SHA-256 value, so compare it with the `sha256sum` output before importing. Then:

1. Create the empty target database.
2. Run `php artisan migrate --force` against it, with the application at the version in the manifest's `app_version`.
3. Import the single file.

```bash
sha256sum backup-20260817-0200.sql.gz
php artisan migrate --force   # with the application's database pointed at target_database
gzip -dc backup-20260817-0200.sql.gz | mariadb --host=127.0.0.1 --user=nntmux --password target_database
```

A dump holds only its selected tables, so the migration supplies the ones it leaves out: Throwaway tables, and Working tables when they were excluded. The dump's `DROP TABLE IF EXISTS` / `CREATE TABLE` statements replace every dumped table. It is a complete logical dump, not an incremental SQL delta.

A folder from before daily-only backups still restores as its Full file followed by the desired Daily file from the same folder, after the same migrate step.

## Crash recovery

If the process dies after pausing tmux, `backup_pause_marker` records whether processing had been running plus the backup process identity and cache-lock owner token. For markers older than two hours, `tmux:health-check` verifies that exact process is no longer alive, releases only that process's orphaned lock, restores the recorded state, and clears the marker. A genuinely long-running dump keeps its pause and lock. When tmux pausing is disabled, or for an independent off-site copy, an orphaned lock expires after 25 hours (the 24-hour operation deadline plus a one-hour margin). Inspect the backup destination and application logs before manually restarting processing sooner.
