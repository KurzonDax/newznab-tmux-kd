# Sources, supersession and current-code map

Review date: 2026-09-17. Code baseline `f2df2eee4a1843911e355e0603b60f579a34ade2`.
Historical #683 experiment baseline `483136faba78aa0624999a37b4aa6d183ed6d4d7`.

## Primary decision sources

- Design task: [Fixing Astra's Shitty UI Design](thread://01a09c80-8201-78f1-8b1d-4d29a7e1fc0b?hostId=remote-ssh-codex-managed%3AClawCode).
  Read through the app's `read_thread`, including final visual acceptance, revision
  17 Console Platform, revision 16 Music/Audiobooks, revision 14 TV fields, revision
  12 Movies, earlier TV/episode/dialog decisions, and the instruction to reconcile
  against delivered fixes. A task title alone was not treated as evidence.
- [Original working decision log](sources/toolbar-design-decisions.md), retained
  as historical source. Later entries supersede earlier proposals; the consolidated
  design contract is the handoff's current reading of those explicit decisions.
- [Original compatibility review](sources/issue-627-compatibility-review.md), retained
  as historical source **with obsolete dependencies**, not implementation guidance.
- Current task: rejection of catalogue-group caching, proposed stored membership,
  review of the toolbar plan's impact, and explicit approval to archive/revert the
  seven #683 files while keeping #683 open. The handoff request authorized this
  documentation folder and its normal repository publication workflow.

No private screenshots, screenshot crops, raw chat transcript, live user records,
or source-task tool payloads are published. The written decisions and included
sample-data artifacts make this handoff usable without the chats.

## Issue reconciliation

Statuses were checked through GitHub on the review date. Closed status alone
does not establish a proposed design was implemented; closure reasons and source
matter. Recheck if resuming later.

| Issue | State / handoff interpretation |
| --- | --- |
| [#621](https://github.com/KurzonDax/newznab-tmux-kd/issues/621) | Closed; prior accepted layout, episode-Covers and directory/dialog contract, implemented through #625. Later toolbar decisions supersede its old toolbar arrangement/search width. |
| [#624](https://github.com/KurzonDax/newznab-tmux-kd/issues/624) | Closed; restored full year choices, decades, custom/open ranges. Preserve those semantics where year remains meaningful. |
| [#627](https://github.com/KurzonDax/newznab-tmux-kd/issues/627) | Closed, explicitly superseded; old W01–W14 text is an audit record, not an implementation order. |
| [#628](https://github.com/KurzonDax/newznab-tmux-kd/issues/628) | Open, needs-info; later design decisions stayed local. This handoff does not mark its production implementation ready. |
| [#629](https://github.com/KurzonDax/newznab-tmux-kd/issues/629) / [#643](https://github.com/KurzonDax/newznab-tmux-kd/pull/643) | Memory fix delivered; bounded temporary membership retains costly per-request reconstruction. Preserve semantic rules; the new persistent-storage direction would require a newly agreed scope. |
| [#631](https://github.com/KurzonDax/newznab-tmux-kd/issues/631) / [#681](https://github.com/KurzonDax/newznab-tmux-kd/pull/681) | Narrow hydration work plus correction of fabricated `video_data.id`. Keep source-schema fidelity; do not copy W11's erroneous minimum-video-ID wording. |
| [#634](https://github.com/KurzonDax/newznab-tmux-kd/issues/634) | Closed; non-TV expansion pagination is part of the current baseline, also relevant to future audiobook identity. |
| [#638](https://github.com/KurzonDax/newznab-tmux-kd/issues/638) | Closed; preserve scalar show-dialog state rather than retained DOM. |
| [#640](https://github.com/KurzonDax/newznab-tmux-kd/issues/640) → [#651](https://github.com/KurzonDax/newznab-tmux-kd/issues/651) | Temporary candidate transfer proposal superseded by delivered index-answered `/search`. Do not resurrect W03's candidate-table/lease/budget machinery as a dependency. |
| [#641](https://github.com/KurzonDax/newznab-tmux-kd/issues/641) → [#656](https://github.com/KurzonDax/newznab-tmux-kd/issues/656), [#657](https://github.com/KurzonDax/newznab-tmux-kd/issues/657) | Snapshot/token design not adopted; Select season removed and archive defects handled separately. Do not restore removed bulk selection from old W04. |
| [#642](https://github.com/KurzonDax/newznab-tmux-kd/issues/642) | Closed wontfix; compulsory searchable-popup design was not pursued at observed deployment scale. No mandatory `/web/browse-options`/25-option contract from W14. |
| [#682](https://github.com/KurzonDax/newznab-tmux-kd/issues/682) | Open needs-triage; schema-faithful test-fixture prevention work, separate from this documentation. |
| [#683](https://github.com/KurzonDax/newznab-tmux-kd/issues/683) | Open needs-triage after approved cleanup; performance defect retained, catalogue cache withdrawn. |
| [#693](https://github.com/KurzonDax/newznab-tmux-kd/issues/693) | This documentation effort; does not close or implement #628/#683. |

The September 15 compatibility review said #627 was open and required W03/W14
interfaces. That is outdated. Its membership-aware filtering, distinct audiobook
identity and narrow hydration observations remain useful, but its old popup,
candidate storage and season-selection dependencies must not be carried forward.

## Current code navigation

Paths below are relative to the repository root. The new documentation worktree
has no CodeGraph index; source was inspected directly. An indexed checkout should
use CodeGraph according to current AGENTS.md.

| Area | Entry points and verified implications |
| --- | --- |
| Browse state, scopes and view eligibility | `app/Data/ReleaseBrowserState.php`, `app/Enums/BrowseRoot.php`, `app/Services/Releases/ReleaseBrowserQuery.php` — Table/Cards are release lists; Cards has additional processing eligibility. |
| Metadata joins/options/search | `app/Services/Releases/ReleaseBrowserMetadata.php` — current root maps do not contain the new Advanced contract; Audio joins musicinfo; PC Platform is literal PC; Adult Year uses postdate. Most browse text predicates use SQL; movie Covers can use entity-index lookup with SQL fallback. It is inaccurate to call every browse path purely SQL or already index-paged. |
| TV grouping | `TvEpisodeBrowser.php`, `TvBrowseMembershipTable.php`, `TvReleaseMembership.php`, `TvEpisodeCatalog.php` under `app/Services/Releases/` — temporary request membership, explicit parser, canonical same-show semantics, episode grouping and page hydration. |
| Directory/dialog | `TvShowDirectory.php`, `app/Http/Controllers/SeriesController.php`, `resources/js/alpine/components/tv-show-directory-component.js` — all-stored-show directory and scoped fragments; confirm file names/callers at the resumed base. |
| Search execution | `WebReleaseSearch.php`, `WebSearchIndex.php`, `app/Support/WebSearchFields.php`, `app/Services/Search/Support/ReleaseIndexProjection.php`, [ADR 0015](../../adr/0015-web-search-answered-by-the-release-index.md) — `/search` index page plus bounded SQL hydration; browse/Covers explicitly excluded. |
| Row presentation | `ReleaseRowDataLoader.php`, `ReleaseEntityDataLoader.php`, `ReleaseMediaInfoAvailabilityLoader.php` under `app/Services/Releases/` — apply new predicates without loading every summary/plot into row DTOs. |
| Movie rating source | `app/Services/MovieService.php` — `Ratings[1]->Value` source-position issue remains at review; verify before filtering rtrating. |
| Shared frontend | `resources/views/components/release-browser/toolbar.blade.php`, `year-picker.blade.php`, shared input/select/buttons, `resources/css/app.css`, `csp-safe.css`, `.ai/rules/resources.md` — implement shared compact rules, CSP-safe Alpine and semantic theme tokens. |

The source schema has no generic `videos.genre` or show-status column and current
`TvShowDirectory` does not project those values. Fictional genres/Continuing/Ended
chips in the accepted review are visual requirements subject to real metadata
availability, not proof that those columns exist. Do not invent a schema or infer
status. Resolve the data source explicitly if extending that presentation.

Current state is not evidence that every requested future field is populated in
production or indexed for efficient search. Provider identifiers, missing metadata,
field formats and index capabilities need the proposed schema/query proof.
