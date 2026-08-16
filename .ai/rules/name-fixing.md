---
paths:
  - 'app/Services/NameFixing/**'
---

# Name Fixing

## Only evidence-backed names may propagate
Cross-copy UID, PAR2 hash, and CRC32 donors must have PreDB/AniDB identity or releases.is_trusted_name = 1. Set is_trusted_name only for proper-at-creation names or strong content/name sources; plausibility-gated and Descriptive Title renames must remain untrusted.
