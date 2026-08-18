# ADR 0009: Interpret naive datetimes in the application timezone

## Status

Accepted

## Context

NNTmux has many `datetime` columns without timezone information. PHP writers use the configured application timezone, while SQL functions previously used the database host's session timezone. A value written by `NOW()` on a UTC database host could therefore appear several hours in the future when the application interpreted it in a regional timezone.

## Decision

Naive database datetimes are stored and interpreted in `config('app.timezone')`. MySQL and MariaDB connections pin their session timezone to `DB_TIMEZONE`, or to the current numeric offset of the application timezone when that setting is empty. Application writers use bound PHP date values instead of database clock functions for persisted datetimes.

The database host or container `TZ` should match `APP_TIMEZONE`. `DB_TIMEZONE` remains available for deployments that need an explicit session setting.

When `DB_TIMEZONE` is empty, the numeric offset is resolved while configuration loads. Long-running processes must be restarted after a daylight-saving transition, and deployments using cached configuration must rebuild that cache, so the derived session offset tracks `APP_TIMEZONE`.

## Consequences

PHP and SQL writers share one clock assumption, and user-facing helpers can reliably parse stored values before converting them to a user's timezone. Existing mixed-clock rows are not migrated because their originating clock cannot be inferred safely; normal group processing corrects them on the next update. A deployment that changes `APP_TIMEZONE` must treat the existing naive data as part of that operational migration.
