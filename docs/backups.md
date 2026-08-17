# Database backups

NNTmux can create verified, compressed logical backups of MySQL and MariaDB. The feature is disabled by default. It produces one weekly Full backup and Daily backups on the other days, groups them into Backup sets for retention, and can copy completed sets to separately mounted storage.

These backups run online with `--single-transaction`, so the site remains available. When configured, the processing tmux session is paused during the dump to reduce write activity and restored to its exact prior running state afterward.

## Configure backups

Open **Admin → Database Backups**, then configure:

- an absolute, writable Backup location outside the public web root;
- the Full day and Full/Daily times;
- how many Full-backed sets to retain;
- whether Full backups include Working tables;
- whether to pause tmux while dumping;
- an optional absolute `mariadb-dump` or `mysqldump` path;
- an optional off-site destination and retention count.

Enable backups only after the destination is mounted and writable. Laravel's scheduler must already be invoked every minute in the normal way (`php artisan schedule:run`); NNTmux registers `backup:tick` with overlap protection.

For Sail deployments, paths configured in the admin page are container paths. Mount local or network storage into the application container and enter that mounted path, not the host-only path.

The Backup location must be local storage that remains available to the application. A removable disk or network share is an off-site destination, never the primary location. Install `mariadb-dump` (or `mysqldump`) and `gzip`; `pigz` is used automatically when installed. Off-site copies additionally require `rsync`.

## Backup tiers

Tables are selected by tier:

- **Important**: application state that cannot be recreated easily. Unknown tables default to this safest tier. Included in Full and Daily backups.
- **Working**: large Usenet ingestion tables such as binaries, collections, parts, and missed parts. Included only in Full backups when **Working tables in Full** is enabled.
- **Throwaway**: cache, queues, sessions, metrics, logs, and derived statistics. Never included.

The exact names and patterns live in `config/nntmux-backup.php`. Review that configuration after adding application tables with unusual lifecycle requirements.

## Commands

Run or inspect backups directly with:

```bash
php artisan backup:run full
php artisan backup:run daily
php artisan backup:list
php artisan backup:tick
php artisan backup:offsite
```

`backup:list` reads manifests from disk, verifies every SHA-256 checksum, and reconciles the database catalog. Only one local backup and one off-site copy can run at a time. A second scheduled tick exits successfully and logs that it was skipped.

The admin **Run now** buttons record a request that `backup:tick` consumes within one minute. A Full request takes priority over a Daily request.

## Files and retention

A Full backup creates a timestamped set directory. Daily files are added to the newest Full set. Each `.sql.gz` has an adjacent `.manifest.json` containing its kind, timestamps, included tables and tiers, compressed size, SHA-256 checksum, application version, database version, and set ID.

If no successful Full exists yet, a Daily is stored in a `no-full-yet-<timestamp>` set. The next successful Full starts a fresh timestamped set; it does not adopt that pre-Full Daily.

Files are finalized atomically: partial dumps and manifests are removed on failure and never appear as complete backups. After a successful Full backup, retention removes the oldest Full-backed directories as whole sets, including their Daily files. Failed run records remain visible for diagnosis even when no backup file exists.

Before a run, NNTmux checks that free space is at least twice the size of the most recent backup of that kind. Restore errors, dump failures, invalid paths, unsupported database drivers, and verification failures stop the run and alert the configured admin email.

## Off-site copies

`backup:offsite` uses `rsync`, verifies the copied checksum, and publishes the manifest last. Existing valid files are skipped, while incomplete or corrupt destinations are recopied. Automatic copies run only after tmux has been restored and a local backup has succeeded.

By default the destination must be on a different mounted filesystem. For a genuine second local disk that reports the same device ID, use the explicit bypass:

```bash
php artisan backup:offsite --destination=/mnt/backup-disk/nntmux --allow-local
```

Use `--keep=N` to override off-site retention for a run. Zero retains every copied set. The admin page shows `Copied`, `Partial`, `Failed`, or `Not copied` per local set.

To copy independently of the application scheduler, add a cron entry for the application user. Adjust paths and the time to suit the installation:

```cron
17 4 * * * cd /var/www/nntmux && php artisan backup:offsite >> storage/logs/backup-offsite.log 2>&1
```

## Restore a backup

Test restores regularly on a non-production database. Stop processing before restoration, verify the checksum, create an empty target database, and import the selected Full file followed by the desired Daily file from the same set if one exists:

```bash
sha256sum full-20260816-0200.sql.gz
gzip -dc full-20260816-0200.sql.gz | mariadb --host=127.0.0.1 --user=nntmux --password target_database
gzip -dc daily-20260817-0200.sql.gz | mariadb --host=127.0.0.1 --user=nntmux --password target_database
```

The manifest contains the SHA-256 value; compare it with the `sha256sum` output before importing.

Because every dump is a complete logical dump of its selected tier, a Daily file replaces the Important-table state from the Full; it is not an incremental SQL delta. Working tables are available only from the Full file.

## Crash recovery

If the process dies after pausing tmux, `backup_pause_marker` records whether processing had been running plus the backup process identity and cache-lock owner token. For markers older than two hours, `tmux:health-check` verifies that exact process is no longer alive, releases only that process's orphaned lock, restores the recorded state, and clears the marker. A genuinely long-running dump keeps its pause and lock. When tmux pausing is disabled, or for an independent off-site copy, an orphaned lock expires after 25 hours (the 24-hour operation deadline plus a one-hour margin). Inspect the backup destination and application logs before manually restarting processing sooner.
