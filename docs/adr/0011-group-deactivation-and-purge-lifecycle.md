# Group deactivation stops fetching while purge clears staging

## Context

Release creation can finish collections after a group is deactivated, so limiting quality filters to active groups lets that in-flight work bypass the group's release floor. Single-group purge also left collections pointing at deleted releases and discarded cross-posted releases solely because the purged group happened to be their primary group.

## Decision

Deactivation is a fetch stop only: collection and release quality policy continues to apply to pending work regardless of the group's `active` flag. A single-group purge is a clean slate for that group's collections, binaries, parts, and missed parts, while the group row remains with reset statistics. It discards a release through the canonical cleanup path only when no `releases_groups` association points to a surviving group; shared releases and their NZBs remain intact. All-groups purge behavior is unchanged, and an upgrade migration removes collections left pointing at releases deleted by past purges.

## Deliberate choices a future reader may be tempted to "fix"

- **Inactive groups still participate in quality cleanup.** Deactivation stops new header fetching; it does not waive the release floor for work already in flight.
- **A shared release survives even when its primary group is purged.** Primary-group attribution depends on scan order and is not ownership of the release or its NZB.
- **The group row survives purge.** Resetting its statistics keeps configuration and associations valid while clearing ingest staging so later reactivation starts fresh.
- **All-groups purge remains destructive.** The surviving-association exception is meaningful only when another group is being kept.

Settled in issue #226 on 2026-08-25.
