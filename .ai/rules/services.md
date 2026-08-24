---
paths:
  - 'app/Services/**'
---

# Services

## Automated release deletion honors live work claims
All automated release deletion sweeps must apply ReleaseDeletionProtection during selection and use ReleaseManagementService's protected deletion boundary so claims acquired after selection are rechecked under a row lock. Fresh additional-processing claims and recovery leases are never deleted; stale claims use ReleaseClaimant's shared cutoff. Segment repair and whole-file rescan must hold RecoveryLease around their public service work and clear it in a finally path. Explicit operator deletion remains an override.
