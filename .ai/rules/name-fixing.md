---
paths:
  - 'app/Services/NameFixing/**'
---

# Name Fixing

## Only evidence-backed names may propagate
Cross-copy UID, PAR2 hash, and CRC32 donors must have PreDB/AniDB identity or releases.is_trusted_name = 1. Set is_trusted_name only for proper-at-creation names or strong content/name sources; plausibility-gated and Descriptive Title renames must remain untrusted.

## Verify SRRDB Archive CRC matches before trusting names
SRRDB archive-crc search hits are candidates, not proof. Accept a name only when details repeat the queried CRC with the exact inner-file size, exactly one candidate survives, and a complete release is within the configured total-size tolerance. Transient API failures stay pending; only verified SRRDB matches may create/attach a PreDB row and set trusted-name provenance.
