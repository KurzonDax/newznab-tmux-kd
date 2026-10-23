# Manticore Search 28.4.4 maintenance upgrade

This procedure belongs to commit `9643d4dd0` (2026-07-15, "Update manticore indexes", committed directly to master without a pull request), which pinned the Compose image to `manticoresearch/manticore:28.4.4`. Keep it for installs still running an older Manticore. Every command, option and path below still exists on master. That commit also moved the Compose volume mount from `/var/lib/manticore` to `/var/lib/manticore/data`; on an install that predates it, back up the volume at its old mount point.

This upgrade rebuilds every configured Manticore table. Do not reuse data files written by 28.4.4 with an older daemon.

## Before maintenance

1. Set `SEARCH_INDEX_GENERATION` to a new value so cached results and cursors cannot cross the cutover.
2. Run `php artisan manticore:inspect --json` and save its output.
3. Stop the processing engine with `php artisan tmux:stop` and pause queue workers that update search documents.
4. Back up `/var/lib/manticore/data` with the container stopped, or use the supported Manticore backup tooling. Record the old image tag with the backup.

## Upgrade and repopulate

1. Pull and start `manticoresearch/manticore:28.4.4`.
2. Confirm HTTP connectivity and optional username/password or bearer-token authentication with `php artisan manticore:inspect`.
3. Run `php artisan manticore:create-indexes --drop`.
4. Run `php artisan nntmux:populate --manticore --all` (or explicitly populate every enabled releases, PreDB, movie, and secondary metadata table).
5. Run `php artisan manticore:inspect`, index reconciliation (`php artisan nntmux:check-index --manticore --releases` and `php artisan nntmux:search-reconcile --dry-run --all`), and representative searches from `tests/Fixtures/Search/manticore-relevance-v1.json`.
6. Resume queue workers and run `php artisan tmux:start` only after all tables report compatible.

## Rollback

Stop Manticore, restore the pre-upgrade data backup, and start the exact image version recorded with that backup. Do not point the older image at files modified by 28.4.4. Restore the previous `SEARCH_INDEX_GENERATION` only if application code was also rolled back.

Local Docker may remain anonymous. Secured deployments use `MANTICORESEARCH_USERNAME` and `MANTICORESEARCH_PASSWORD`, or `MANTICORESEARCH_TOKEN`; never store credentials in Compose files.
