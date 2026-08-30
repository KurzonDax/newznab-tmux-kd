# NNTmux

A Usenet indexer: downloads NNTP headers, assembles them into releases, enriches them with metadata, and serves them for search. Background processing is orchestrated by a tmux monitor that drives per-task panes.

## Language

**Threads**:
The configured count of parallel forked worker processes for a tmux-driven task (e.g. `binarythreads`, `backfillthreads`). Kept from nZEDb's Python-threading days; each "thread" is actually a separate process holding its own NNTP connection. UI and DB deliberately both say "threads" so the terms never diverge.
_Avoid_: workers, processes (in UI copy and setting names)

**Pane**:
A tmux pane owned by the monitor, running exactly one background task (binaries, backfill, releases, postprocessing, fix-names).

**Backfill pass**:
One bounded sweep across every eligible group, with at most `backfillthreads` groups active at once and at most `backfill_qty` headers requested from each group. Eligibility ends at either the group's Backfill Days target or the site-wide Safe Backfill Date, according to the configured target type.
_Avoid_: Safe mode, All mode — backfill is either Disabled or Enabled.

**Amazon postprocessing**:
The metadata-lookup family for books, music, console and PC games (pane 2.2, `postthreadsamazon`). Named for the nZEDb-era Amazon Product API source, retained as the umbrella term for these categories.

**Edit Selected**:
An in-place update of several settings at once, applied to the groups an admin has checked on the group list. Distinct from **Bulk Add**, which creates groups from a name filter and edits nothing.
_Avoid_: "bulk edit" — "bulk" belongs to Bulk Add.

**Release floor**:
A group's `minsizetoformrelease` / `minfilestoformrelease`: the smallest collection that may become a release for that group. Combined with the site-wide setting by taking the larger of the two, so a group floor below the site setting has no effect. Zero and unset both mean "no group floor" and display as "n/a".
_Avoid_: calling a group floor an override of the site setting — it can only raise it, never lower it.

**Backfill Days**:
A group's `backfill_target`: how many days back the backfill runner aims for on that group. Always at least 1; a group never lacks a target.

**Web Login Session**:
An authenticated browsing session on the web frontend, expiring after 12 hours of inactivity. Applies only to browser logins by users and admins — API keys and RSS tokens are a separate concept and are never affected by session policy.
_Avoid_: plain "session" when the distinction from API/RSS access matters.

**Remembered Login**:
The long-lived "keep me signed in" state: a rolling 7-day window, reset by each new activity, with no absolute ceiling — a browser used at least weekly stays signed in indefinitely until revoked. Transparently re-authenticates a returning browser without a password. Explicitly opt-in for password logins (checkbox, unchecked by default); always granted on passkey logins, since possessing the authenticator already proves the device.
_Avoid_: "remember me cookie" in UI copy — name the behavior, not the mechanism.

**Single Active Session**:
A site-owner option (default off): each account may hold only one Web Login Session at a time — a new login anywhere ends the account's other sessions and their Remembered Logins. Enforced from the next login onward when enabled; existing concurrent sessions are not reaped. When off, concurrent logins from any number of devices are allowed.
_Avoid_: describing this as a security feature — it is an anti-account-sharing policy.

**Trusted Device**:
A browser the user chose to skip 2FA challenges on, for 30 days. Independent of Remembered Login: one answers "who are you", the other "should this device be re-challenged". The windows are deliberately not aligned.

**Expire All Logins**:
An admin breach-response action ending every Web Login Session, Remembered Login, and Trusted Device — site-wide or for a single user. Spares only the acting admin's current session; their other logins expire like everyone else's.
_Avoid_: "logout all users" — it also revokes remembered state and trusted devices, not just live sessions.

**Discard**:
The permanent, complete purge of a release: database row (with cascaded file rows), NZB file on disk, preview/cover images, and search-index documents. Irreversible. Triggered when a release is found to contain an executable file, controlled per root category (`root_categories.discard_executables`) with the extension list in the `discard_executable_extensions` setting. See ADR 0003.
_Avoid_: plain "delete" when contrasting with Hide — the distinction is the point.

**Hide**:
Marking a release passworded (via the `innerfileblacklist` regex) so browse, search, and API surfaces can exclude it while the release and all its artifacts are kept. The reversible counterpart to Discard; the two mechanisms are independent and both remain active.

**Generated Preview**:
The single still image the indexer captures (via ffmpeg) from a video file found inside a release. Created by the indexer, not shipped in the release.
_Avoid_: "thumbnail" in UI copy — thumbnails are the small rendering of any image, not this artifact.

**Generated Sample Video**:
The short video clip the indexer stores from a video file found inside a release. Paired with the Generated Preview under Preview Generation. One per release, in one of two forms: a Clip (the only form still produced) or a legacy OGV transcode left behind by the retired pre-Clip path (still served, never newly created). A Clip takes over the slot — the two forms never coexist for one release.

**Clip**:
The browser-playable form of the Generated Sample Video, cut from the downloaded head window. Browser-safe streams are copied at full resolution with no duration cap; other decodable video is converted to H.264/AAC MP4 and capped at `preview_target_seconds` (0 leaves the fetched window uncapped). A safe H.264 video stream paired with unsafe audio keeps the video unchanged and converts only the audio. Produced only where Clip storage is enabled for the release's root category (a Movies/TV/XXX toggle, default XXX only). An encode shorter than the duration floor (`clip_minimum_seconds`, default 5, 0 disables) is discarded — a starved extraction stores no video artifact rather than a seconds-long tease; an unreadable duration is not "below the floor". Lives and dies with its release, and is never generated while the covers volume is under the Free-disk guard.
_Avoid_: "trailer" — a Clip is cut from the release's own footage; "sample video" in UI copy where the full-res/downscaled distinction matters.

**Free-disk guard**:
The gate that halts disk-hungry artifact production while the volume holding covers storage is below a threshold share of free space (default 10%). Each producer declares its own response: a guarded Clip is simply not produced (no video artifact is stored), while sample/preview imagery is skipped entirely and recorded as an Imagery disk skip. A guard that cannot read the disk refuses, on the principle that producing large artifacts is the wrong response to not knowing how full the disk is.
_Avoid_: treating a guard skip as an error — it is policy working as intended.

**Imagery disk skip**:
The recorded fact that a release's imagery work was suppressed by the Free-disk guard, kept until an operator requeues the release after reclaiming space. The record means "suppressed", not "imagery existed" — the requeued run determines what the release actually yields.
_Avoid_: reading a skip record as proof the release contained a sample.

**Dynamic segment budget**:
Sizing the fetched head of a release's main video file by target duration instead of a fixed segment count: a fixed-size initial window is probed for bitrate, then topped up to reach a target duration (default 30s) under a hard byte ceiling (default 300 MB, 0 = unlimited). Enabled per root category (Movies/TV/XXX toggles, default XXX only); every other root keeps the fixed count. For a bare non-faststart MP4 the budget runs only after a successful moov splice. For video inside a RAR set the budget extends the partially extracted fragment instead: it keeps fetching further archive segments — the rest of the fetched part, then subsequent parts in posted order, at most `preview_max_rar_parts` (default 6) parts — re-extracting after each fetch until the fragment covers the target or extraction stops progressing. Top-up fetches are skipped early, with a distinct outcome reason, when the needed segment ranges have gaps.
_Avoid_: treating the target duration as a guarantee — the ceiling, segment gaps, or undecodable data may leave less.

**Preview chip**:
The badge on release rows, cover cards, and the details page that opens the preview modal. The modal shows the Generated Preview image; when a playable Generated Sample Video exists, the chip also carries a movie-camera icon and the modal offers a media control that plays the video in the space the image occupied. One mechanism on every surface — listing pages are the primary one, details is not privileged.
_Avoid_: surfacing video playback only on the details page.

**Completion chip**:
The always-on badge on release rows showing `releases.completion` — the share of the release's articles the indexer has seen — floored to an integer so a release short of complete never reads "100%". Green at 95% and above, yellow from 80% to just under 95%, red below 80%. A release with completion `0` was never measured and shows no chip at all. Cover tiles carry it only when the release is below 100%, so complete tiles stay clean art.
_Avoid_: reading `0` as an empty release; rounding the percent up.

**Repair-state chip**:
The badge beside the Completion chip on a release that is measured but below 100%, saying whether recovery still has attempts left. "Repair Attempt(s) Pending" until *both* the segment-repair and header-rescan machines reach a final outcome — the same conjunction the deletion sweep's safe-to-delete invariant uses — and "Repair Attempts Complete" once they have. Yellow while pending, gray once complete; no other colors.
_Avoid_: calling a release unrecoverable because one machine finished; treating a successful repair as an exhausted attempt.

**Extracted Sample Image**:
An image found inside a release's archives (or posted as its own article) and saved out by the indexer as a display thumb plus a Full-size copy. Never produced by ffmpeg and never affected by Preview Generation controls.
_Avoid_: plain "sample" when the generated/extracted distinction matters; "saved as-is" — the stored copies are always re-encoded, never the poster's bytes.

**Full-size copy**:
The larger stored rendering of a release's Extracted Sample Image or Generated Preview: capped to a desktop-viewport box, aspect-preserved, never upscaled, stored beside the small display thumb. Exists only for releases processed after the feature shipped — the back catalog is deliberately never regenerated.
_Avoid_: "original" — it is re-encoded and possibly downscaled, never the poster's verbatim bytes.

**Fullscreen view**:
The second stage of the image modal: the Full-size copy presented to fit entirely inside the viewport, entered from a small corner control on the modal image and exited back to the modal it came from. Offered only where a Full-size copy exists, so the control never promises more pixels than it can deliver.
_Avoid_: "open in new tab" — that affordance was considered and scrapped.

**Obfuscated-name routing**:
An opt-in per-group setting (a toggle plus a default *root* category) that sends releases whose names look obfuscated or gibberish — but are **not** true MD5/SHA hashes — to the default root's *Other* subcategory (e.g. XXX/Other) instead of Other/Hashed. Off and unset for every group by default; never pre-populated. The routed root is a floor, not a lock: content pipes and Mediainfo refinement may still refine the subcategory, and audio-only content is refiled under Audio. Editable per group and via Edit Selected.
_Avoid_: "strong group" — the concept lives in the setting, not in a hardcoded group-name list.

**Routed floor**:
The root selected by Obfuscated-name routing. Content evidence may refine within it, and audio-only Mediainfo evidence may cross it into Audio; unlike a Forced root, it is not an operator lock.

**Forced root**:
An operator-selected root-category lock attached to a Usenet group. Any Forced root among a release's associated groups governs both categorization and audio routing, and derived content evidence never crosses it.
_Avoid_: floor, default root — a force is an explicit lock.

**Cross-posted release**:
One release associated with more than one Usenet group. Its primary group is whichever association ingestion encountered first, but group policy considers every association so scan order does not hide a Forced root.
_Avoid_: duplicate release — the cross-post associations belong to one release identity.

**Anchor**:
The already-existing release an incoming duplicate is matched against. The anchor keeps its identity and history no matter how the duplicate is handled; at most its evidence is upgraded in place.
_Avoid_: "original" — the anchor is merely whichever copy arrived first, not a better one.

**Duplicate absorption**:
Upgrading an anchor in place from a more complete incoming copy of the same upload: the incoming copy's article evidence replaces the anchor's, and the incoming copy is then discarded as a duplicate. Only a strictly more complete copy absorbs; an equal or worse one is an ordinary duplicate.

**Deferred absorb**:
A duplicate absorption postponed because the anchor's NZB has not been written yet — absorption rewrites that stored NZB, so until it exists there is nothing to rewrite. The incoming copy is preserved untouched and absorbs on a later cycle once the NZB lands. Expected state while NZB creation catches up, and never counted against the Absorb attempt cap.
_Avoid_: treating a deferral as a failure — nothing was attempted.

**Absorb attempt cap**:
The bound on absorbs that were actually attempted and failed for one preserved incoming copy. At the cap the copy settles as an ordinary duplicate instead of retrying forever — a backstop for genuine storage failures, not the expected path.

**Mediainfo refinement**:
Moving a release out of a root's *Other* subcategory once post-processing has recorded what its media actually is. Video evidence (resolution, container, codec) refines within the same root; audio-only evidence (an audio track and no video track) refiles a Routed floor under Audio but never crosses a Forced root. It only ever acts on a `<root>/Other` release — a specific subcategory chosen from the name is never overridden — and only for the Movies, TV, XXX and Audio roots.
_Avoid_: "recategorize from mediainfo" — the mediainfo never re-runs name categorization; it refines or refiles the current result.

**moov splice**:
A temporary inspection copy of a bare non-faststart MP4/MOV, combining its downloaded beginning with its ending metadata so Preview Generation and Mediainfo refinement can inspect it without retrieving the complete media file. It does not apply to media inside archives.
_Avoid_: "repaired MP4", "full download" — the stored release is unchanged and most media bytes remain unfetched.

**Year-only movie fallback**:
A low-confidence Movies/Other guess (`0.5`) for multi-token `Title.Year[.N]` names with no media markers. Movie post-processing may confirm the title and year; otherwise the release remains in Movies/Other.

**Year picker**:
A browse filter that selects one year, a named decade, or an optional-start/optional-end custom range. An open custom endpoint means no lower or upper year limit.
_Avoid_: separate "from year" and "to year" filters presented as unrelated choices.

**Series banner**:
The wide fanart.tv banner image stored per show alongside its poster; the series list prefers it and falls back to the poster.
_Avoid_: "cover" for TV artwork when the banner/poster distinction matters.

**Premiere year**:
The year of `videos.started`, when a series first aired; the Year picker filters this on the series list. Distinct from an episode's **air year** (`tv_episodes.firstaired`), which the picker filters on a show page.

**Movie search prefixes**:
Field qualifiers in a Movies search—`title:`, `actor:`/`actors:`, `director:`, and `plot:`—that restrict the following word or quoted phrase to that movie detail. Words without a prefix may match any of those details, but every search word must match somewhere.
_Avoid_: "advanced syntax" for the separate Advanced form fields; both inputs produce the same movie search constraints.

**Display Name**:
The derived, regenerable human-readable rendering of a release's Search Name (`releases.display_name`), shown only on user-facing web pages. Dots and underscores become spaces except inside recognised spans — dates, dotted version numbers, and A/V tokens such as `H.264` or `DD5.1` — and a trailing container extension is kept but uppercased. It is never used for matching, dedupe, API or RSS output, or search indexing: `searchname` stays canonical, and a null Display Name falls back to it.
_Avoid_: "pretty name" in code; prettifying `searchname` itself.

**Descriptive Title**:
A human-written, non-scene inner *video* file name (e.g. `My Wife Is In Heat`, `SupergirlPerv`) accepted as a release name only when the release's current name looks obfuscated or hashed and no scene pattern or predb match was found. Anything but known junk (`video1`, `movie`, DVD structure names, hashes) qualifies; a current name that already reads as a real title is never overwritten. Site-wide on/off setting, on by default.
_Avoid_: "plausible title" — that is the existing scene-shape check a candidate must pass, which Descriptive Titles deliberately do not.

**Trusted donor**:
A release whose current name is backed by strong evidence and may therefore name another release that shares the same content fingerprint. A merely plausible or descriptive name is not a Trusted Donor.
_Avoid_: "renamed release" — renaming alone does not establish trust.

**Archive CRC**:
The CRC32 recorded for a file inside a RAR archive, as distinct from a CRC of the Usenet article or the complete release. An SRRDB lookup may use it to identify a scene release only after SRRDB details confirm the same CRC and exact inner-file size.
_Avoid_: plain "CRC" where article, PAR2, and archive checks could be confused.

**Preview Generation**:
The umbrella for creating Generated Previews and Generated Sample Videos. Toggleable per root category (covering the root's entire subtree, no child overrides) and AND-ed with the site-wide switches — the per-root toggle can only disable, never enable. Disabling never deletes existing artifacts. A release skipped by the toggle is owed generation if later recategorized into a root where generation is enabled; re-enabling a root's toggle owes nothing (explicit requeue is the backfill tool).

**Full backup**:
The weekly logical dump of the database, always including Important tables and by default also Working tables. Anchors a Backup set.
_Avoid_: "weekly backup" in code and settings (the cadence is configurable, the kind is what matters).

**Daily backup**:
A complete dump of Important tables only, taken on non-Full days. Not an incremental — it stands alone and restores in one step.
_Avoid_: incremental, differential (nothing in this feature captures "changes since").

**Backup set**:
One successful Full backup plus every Daily backup taken until the next successful Full backup. The unit of retention: a set is kept or purged whole.

**Important tables**:
Tables whose contents cannot be rebuilt from Usenet or external APIs (releases, predb, users, settings, categories, groups, metadata, forum, …). Always backed up.
_Avoid_: durable, core.

**Working tables**:
The collections/binaries/parts/missed_parts family (including per-group and multigroup variants) — in-progress header state that backfill can regenerate. Backed up only in a Full backup, and only if the admin keeps that toggle on.
_Avoid_: CBP (in UI copy), transient.

**Throwaway tables**:
Telemetry, logs, sessions, cache and queue tables (`pulse_*`, `telescope_*`, metrics, request logs, `sessions`, `cache*`, `jobs`, `failed_jobs`, …). Never backed up.
_Avoid_: junk, ephemeral.

**Backup location**:
The admin-configured absolute directory that holds Backup sets, one subdirectory per set. The files on disk are the source of truth for what backups exist; any catalog in the database is a rebuildable cache.

**Backup pause**:
The soft tmux pause taken around a backup when the admin toggle is on: `running` is cleared so panes drain and are not respawned, then the previous `running` value is restored afterwards. Never kills panes and never starts tmux that was already stopped.
_Avoid_: stopping tmux, maintenance mode.

**Off-site copy**:
A verified copy of Backup sets from the Backup location to an admin-configured **Off-site destination** on separate (external or network) storage, made by `backup:offsite` from cron or right after a backup once the Backup pause has been lifted. Repeatable and resumable; a set counts as copied only when its checksum matches at the destination. The destination keeps its own retention count, independent of the local one.
_Avoid_: sync, mirror, remote backup, "linking" the Backup location to network storage.

**Poster identity**:
The complete, unmodified From header a release was posted under (e.g. `yEncBin@Poster.com`, `user <user@x.localdomain>`). Two releases share a Poster identity only when the strings are byte-for-byte equal; `user@x.localdomain` and `user@2.localdomain` are different Poster identities even if the same tool produced them. Shown to users under the label **Posted By**. Grouping look-alike identities is the job of a blacklist regex, never of the view.
_Avoid_: plain "poster" in code and docs (collides with Poster art); "uploader"; matching on email or display name alone.

**Poster art**:
The portrait artwork image for a movie or series (TMDB/TVDB/fanart.tv poster), as opposed to the Series banner. Never refers to the person who posted a release.
_Avoid_: plain "poster" when a reader could take it to mean the Poster identity.

**Music identity**:
The accepted MusicBrainz-backed identity of an audio release, always at an explicit scope: a **Recording** (one performance/mix that may appear on many albums), a **Release group** (the album concept across all its editions), or a **Release edition** (one exact country/date/label/catalog configuration). Identification may honestly stop at a broader scope — a known album with an unresolved edition is a complete answer. See ADR 0014.
_Avoid_: "album match" with no scope; treating MusicBrainz IDs of different entity types as interchangeable.

**Track evidence**:
One observation about one audio artifact in a posting — filename, position, tags, duration, identifier, fingerprint — before any MusicBrainz meaning is attached to it.
_Avoid_: bare "track" — qualify as track evidence, MusicBrainz release track, or recording; most matching mistakes come from collapsing those layers.

**Evidence revision**:
The immutable snapshot of everything observed about one release at one collection time, with completeness flags so absent data is never scored as a contradiction. Re-observation — or lazy synthesis from already-stored rows for the back catalog — creates a new revision; nothing ever edits an old one.
_Avoid_: "updating" evidence.

**Identification decision**:
The explained outcome of resolving one evidence revision under one algorithm version: an accepted Music identity at some scope, needs review, unresolved, conflicted, or a retryable provider error. Carries a deterministic ranking score, its reasons, and the runner-up margin. A new algorithm version produces a new decision; it never rewrites the previous one.
_Avoid_: reading the score as a probability; recording a provider failure as a no-match.

**Shadow mode**:
Running music resolution and persisting Identification decisions while applying no projection — no rename, no search text, no compatibility row. The default state until thresholds are calibrated against the labeled corpus.

**Music identity projection**:
A reversible, recorded application of an accepted Identification decision to the release: the canonical rename, internal search-index text, or the `musicinfo` compatibility row. Each stores a before/after diff, and a reversal restores a field only while it still holds the projected value, so a later human correction is never overwritten.
_Avoid_: letting the resolver touch a release directly — resolution decides, projections apply.

**Rename gate**:
The strictest projection gate: a verified-band decision with sufficient runner-up margin, no hard contradiction, no PreDB/Trusted/manual name protection, and the MusicBrainz release artist credit (Various Artists for compilations — never the sampled track's performer). When it passes, the canonical rename is the required projection, not an operator preference; when it fails, keeping the current name is the correct outcome, not a shortfall.
_Avoid_: treating renaming as a deployment option; renaming from one track's performer.
