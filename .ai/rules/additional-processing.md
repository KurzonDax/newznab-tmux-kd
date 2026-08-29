---
paths:
  - 'app/Services/AdditionalProcessing/**'
  - 'app/Services/AudioProcessing/**'
---

# Additional Processing

## Keep unknown-payload sniffing bounded and reusable
Unknown-payload sniffing is fallback-only: run it only when the planner found no normal archive/media/direct candidate. Select a configured, byte-budgeted set and fetch exactly the first segment of each candidate. Route those same bytes to the existing archive, PAR2, media, or NFO handler; never redownload the sniffed segment.

## The two candidate queries must stay exact complements
`AudioCandidateQuery` and `AdditionalCandidateQuery` split the pending set between the `aud` and `add` workers. Everything they agree about lives in `ReleaseClaimant` — what "pending" means (`applyPendingPredicates()`), the stale-claim window, claiming, and the bucket backlog — and `AudioRouting` decides which half each owns, one calling `applyAudioPath()` and the other `applyVideoPath()`. Neither query may restate any of that inline. A release claimable by neither sits pending forever; one claimable by both gets fetched twice.

The additional path already learned this once with a single query pair: a mismatch on size filters and `nzbstatus` had the bucket query advertising releases the per-worker fetch then rejected. Two paths make that twice as easy to reintroduce.

## Declining is a claim-token sentinel, not a column
When the audio worker's article-1 probe finds video, or no audio stream, it writes `AudioRouting::DECLINED_TOKEN` into `additional_pp_claim_token` and clears `additional_pp_claimed_at`. The audio query then excludes the release and the video query claims it. Do not add a `releases` column for this — the table was deliberately slimmed. Two consequences to keep in mind: `haspreview` stays `-1` across a decline (the video path still owes the release a run), and if the video path later fails without settling `haspreview`, the cleared token puts the release back on the audio path for one more probe.

## Audio settlement preserves archive password evidence
When the audio path already fetches an archive head and its listing reports encryption, settle `passwordstatus` as `ReleaseBrowseService::PASSWD_RAR`; direct audio and unencrypted archives settle as `PASSWD_NONE`. Do not add NNTP fetches, deep archive inspection, or general-path deferral solely to determine this verdict.

## Float WavPack previews use the reference decoder fallback
When ffmpeg cannot demux a .wv source, or a partial archive head yields less than the required preview window, decode it with wvunpack -b to a temporary WAV and feed that WAV through the existing length, clip, and spectrogram paths. The -b blind-decode flag is required for truncated archive extractions; if wvunpack is unavailable, surface the distinct dependency failure instead of the archive-volume or generic ffmpeg reason.
