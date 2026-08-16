# NNTmux

A Usenet indexer: downloads NNTP headers, assembles them into releases, enriches them with metadata, and serves them for search. Background processing is orchestrated by a tmux monitor that drives per-task panes.

## Language

**Threads**:
The configured count of parallel forked worker processes for a tmux-driven task (e.g. `binarythreads`, `backfillthreads`). Kept from nZEDb's Python-threading days; each "thread" is actually a separate process holding its own NNTP connection. UI and DB deliberately both say "threads" so the terms never diverge.
_Avoid_: workers, processes (in UI copy and setting names)

**Pane**:
A tmux pane owned by the monitor, running exactly one background task (binaries, backfill, releases, postprocessing, fix-names).

**Safe backfill**:
The incremental backfill mode: one group per cycle, a bounded number of headers (`backfill_qty` × `backfillthreads`), stopping at the Safe Backfill Date.
_Avoid_: calling the "All" mode "full backfill" in code — the setting values are Disabled/Safe/All.

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
The short video clip the indexer cuts (via ffmpeg) from a video file found inside a release. Paired with the Generated Preview under Preview Generation.

**Extracted Sample Image**:
An image that already exists inside a release's archives (or as its own article) and is saved out as-is. Never produced by ffmpeg and never affected by Preview Generation controls.
_Avoid_: plain "sample" when the generated/extracted distinction matters.

**Obfuscated-name routing**:
An opt-in per-group setting (a toggle plus a default *root* category) that sends releases whose names look obfuscated or gibberish — but are **not** true MD5/SHA hashes — to the default root's *Other* subcategory (e.g. XXX/Other) instead of Other/Hashed. Off and unset for every group by default; never pre-populated. The routed root is a floor, not a lock: content pipes and Mediainfo refinement may still refine the subcategory, and audio-only content is refiled under Audio. Editable per group and via Edit Selected.
_Avoid_: "strong group" — the concept lives in the setting, not in a hardcoded group-name list.

**Mediainfo refinement**:
Moving a release out of a root's *Other* subcategory once post-processing has recorded what its media actually is. Video evidence (resolution, container, codec) refines within the same root; audio-only evidence (an audio track and no video track) refiles the release under Audio. It only ever acts on a `<root>/Other` release — a specific subcategory chosen from the name is never overridden — and only for the Movies, TV, XXX and Audio roots.
_Avoid_: "recategorize from mediainfo" — the mediainfo never re-runs name categorization; it refines or refiles the current result.

**moov splice**:
A temporary inspection copy of a bare non-faststart MP4/MOV, combining its downloaded beginning with its ending metadata so Preview Generation and Mediainfo refinement can inspect it without retrieving the complete media file. It does not apply to media inside archives.
_Avoid_: "repaired MP4", "full download" — the stored release is unchanged and most media bytes remain unfetched.

**Year-only movie fallback**:
A low-confidence Movies/Other guess (`0.5`) for multi-token `Title.Year[.N]` names with no media markers. Movie post-processing may confirm the title and year; otherwise the release remains in Movies/Other.

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
