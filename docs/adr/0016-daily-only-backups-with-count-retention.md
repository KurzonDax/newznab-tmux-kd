# Database backups are daily-only, and retention counts single backups

## Status

Accepted. Partly supersedes ADR 0007: its table tiers, the files-on-disk-are-the-truth rule and the off-site rules still hold.

## Context

ADR 0007 made a Daily backup a complete standalone dump of the Important tables, and a weekly Full the same plus the Working tables. Retention kept whole Backup sets: one Full plus the six Dailies after it. On prod a Daily averaged 10.7 GB and a Full 12.9 GB, so a set was about 70 GB of near-duplicates. The local Backup location held 4 sets (230 GB) and the NAS 8 sets (880 GB). A restore took two imports, and a restore from late in the week got Working tables up to six days older than the Important tables. Restore points could only be added in whole-week steps.

## Decision

1. **One backup per day** at the **Backup time**. There is no Full or Daily kind. Each backup holds the Important tables, plus the Working tables while **Include Working tables** is on; Throwaway tables are never dumped. A scheduled backup is due when no attempt has finished since the most recent Backup-time slot. An attempt is a verified backup in the Backup location or a failed run recorded in `database_backups`. A failed scheduled run is not retried until the next slot: its failure email is the signal and **Run backup now** stays available. A tick skipped because another run holds the lock is not an attempt.
2. **Retention counts backups, with two numbers.** **Backups to keep** (`backup_keep`, 1 to 100, default 4) applies to the Backup location; **Off-site backups to keep** (`backup_offsite_keep`, 1 to 10, default 7) applies to the off-site destination. A blank, missing or non-positive stored value means the default, and an off-site value above 10 means 10. The same rule runs in both places: folders are ordered newest first (by their newest manifest's `finished_at`, or by the start time in a `YYYYMMDD-HHMMSS` name when no manifest is readable); the newest N folders whose files all verify are kept; every older folder is removed, verified or not; unverified folders newer than the oldest kept folder stay; nothing is removed while fewer than N verified folders exist; a folder with no manifest and another name shape is never touched. The off-site copy sends only the newest `backup_offsite_keep` verified local folders. After a successful backup the automatic off-site copy runs first and local retention second, whether or not the copy succeeded.
3. **One folder per backup**, named from its start time (`YYYYMMDD-HHMMSS`), holding `backup-YYYYMMDD-HHMM.sql.gz` and its manifest. Manifests no longer carry `kind`. Each backup restores with one import into a database migrated to the manifest's `app_version`.
4. **Folders written before this change age out on their own.** A folder holding one Full plus Dailies counts as one backup, ordered by its newest file, and is removed whole once it falls past the keep count. Their `kind` full/daily manifests still load everywhere.

## Consequences

- Every backup is a complete restore point, and Working tables are as fresh as the Important tables beside them.
- A backup with Working tables included is about 2.2 GB larger than a Daily was, every day; the keep counts are what bound disk use.
- Retention can be tuned one day at a time.
- Off-site zero ("keep everything") is gone. An upgrade maps an off-site keep of 0, empty or above 10 to 10.

Settled at triage on 2026-11-15 (#1247). See `GLOSSARY.md` for **Database backup**, **Working tables**, **Backup location** and **Off-site copy**.
