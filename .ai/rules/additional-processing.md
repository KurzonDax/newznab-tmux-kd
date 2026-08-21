---
paths:
  - 'app/Services/AdditionalProcessing/**'
  - 'app/Services/AudioProcessing/**'
---

# Additional Processing

## Keep unknown-payload sniffing bounded and reusable
Unknown-payload sniffing is fallback-only: run it only when the planner found no normal archive/media/direct candidate. Select a configured, byte-budgeted set and fetch exactly the first segment of each candidate. Route those same bytes to the existing archive, PAR2, media, or NFO handler; never redownload the sniffed segment.

## The two candidate queries must stay exact complements
`AudioCandidateQuery` and `AdditionalCandidateQuery` split the pending set (`haspreview = -1`) between the `aud` and `add` workers. Both apply `AudioRouting` — one with `applyAudioPath()`, the other with `applyVideoPath()` — and nothing else may decide routing. A release claimable by neither sits pending forever; one claimable by both gets fetched twice.

## Declining is a claim-token sentinel, not a column
When the audio worker's article-1 probe finds video, or no audio stream, it writes `AudioRouting::DECLINED_TOKEN` into `additional_pp_claim_token` and clears `additional_pp_claimed_at`. The audio query then excludes the release and the video query claims it. Do not add a `releases` column for this — the table was deliberately slimmed. Two consequences to keep in mind: `haspreview` stays `-1` across a decline (the video path still owes the release a run), and if the video path later fails without settling `haspreview`, the cleared token puts the release back on the audio path for one more probe.
