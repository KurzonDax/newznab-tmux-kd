---
paths:
  - 'app/Services/AdditionalProcessing/**'
---

# Additional Processing

## Keep unknown-payload sniffing bounded and reusable
Unknown-payload sniffing is fallback-only: run it only when the planner found no normal archive/media/direct candidate. Select a configured, byte-budgeted set and fetch exactly the first segment of each candidate. Route those same bytes to the existing archive, PAR2, media, or NFO handler; never redownload the sniffed segment.
