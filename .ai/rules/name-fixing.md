---
paths:
  - 'app/Services/NameFixing/**'
---

# Name Fixing

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

## Only evidence-backed names may propagate
Cross-copy UID, PAR2 hash, and CRC32 donors must have PreDB/AniDB identity or releases.is_trusted_name = 1. Set is_trusted_name only for proper-at-creation names or strong content/name sources; plausibility-gated and Descriptive Title renames must remain untrusted.

## Verify SRRDB Archive CRC matches before trusting names
SRRDB archive-crc search hits are candidates, not proof. Accept a name only when details repeat the queried CRC with the exact inner-file size, exactly one candidate survives, and a complete release is within the configured total-size tolerance. Transient API failures stay pending; only verified SRRDB matches may create/attach a PreDB row and set trusted-name provenance.
