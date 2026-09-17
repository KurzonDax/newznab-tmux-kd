# Frontend redesign v2 — handoff

Prepared 2026-09-17 under [#693](https://github.com/KurzonDax/newznab-tmux-kd/issues/693).
Reviewed code baseline: `f2df2eee4a1843911e355e0603b60f579a34ade2`.

This is the consolidated handoff for the accepted browse design and the proposed
TV query redesign. It is **not authorization to implement the frontend or a new
membership schema**. The user requested this documentation, preservation of the
final prototypes, and the separately approved #683 cleanup. No replacement
query prototype, migration, backfill, new Artisan command, or production change
has been implemented by this effort.

## Read in this order

1. [Accepted design and behavior](01-design-contract.md): final toolbar/category
   requirements and the earlier accepted TV directory/dialog/episode presentation.
2. [TV query proposal](02-tv-query-proposal.md): why membership needs redesign,
   actual schema, search integration, lifecycle, alternatives, and limits.
3. [#683 audit and disposition](03-issue-683-audit.md): exact rollback, retained
   evidence, failed checks, and what the experiment did and did not establish.
4. [Decisions and delivery gates](04-decisions-and-acceptance.md): unresolved
   contracts, recommendations with tradeoffs, and the next-agent sequence.
5. [Sources and current-code reconciliation](05-sources-and-current-state.md):
   provenance, issue statuses, superseded instructions, and navigation into code.
6. [Prototype guide](prototypes/README.md): entry links, supported examples,
   dependencies, and known demo shortcuts.

## Current disposition

- **#683 stays open, not wont-fix.** The slow episode-Covers behavior is a real,
  unresolved defect. Its rejected cache instructions have been removed, its title
  updated, and `ready-for-agent` replaced with `needs-triage`.
- The seven uncommitted #683 files were archived and restored to their branch
  HEAD, `483136faba78aa0624999a37b4aa6d183ed6d4d7`. The worktree is clean. No
  #683 commit or PR shipped. No already-merged fix was reverted.
- The PRIMARY-index experiment and measurements remain useful diagnostic evidence;
  the whole-result cache is rejected. [Detailed audit](03-issue-683-audit.md).
- #628 still tracks the toolbar work. Its old issue body is not the final local
  design. This handoff consolidates the later accepted decisions without
  prematurely replacing #628 with an implementation specification.
- The candidate backend direction is **stored, indexed release-to-episode
  membership maintained when relevant data changes**. Its physical schema,
  execution engine, consistency strategy, and performance have not been approved
  or proven. Search and grouping must be evaluated together.
- TV Table is the existing interim option. Changing the default view, redirecting
  Covers, or rolling back the frontend has not been authorized.

## Decision authority

Use these labels throughout the handoff:

| Label | Meaning |
| --- | --- |
| Accepted | Explicit user decision in the design task or this task |
| Existing | Verified code/contract at the baseline; preserve unless explicitly superseded |
| Proposed | Recommended direction, not implementation approval |
| Open | A product/engineering contract still needs a decision or evidence |
| Historical | Evidence or an earlier proposal; not an instruction to implement |

The latest explicit user decisions take precedence over earlier issue text and
prototype shortcuts. Accepted visual design does not approve invented database
fields, search semantics, demo counts, cache limits, or operational changes.
The superseded #627 specification is an audit record, not a dependency contract.

The next deliverable is a concrete design and isolated query proof against this
behavioral contract, followed by an agreed implementation scope. Do not restart
the rejected #683 patch while preparing that design. Do not reopen settled visual
choices as a general design questionnaire. For a remaining decision, present the
specific behavior, a concrete example, the recommendation, and its downside.

## Privacy and boundaries

The source task contains private screenshots. None are included, needed, or
authorized for upload. Reference HTML uses fictional/sample data; its controls
and original CSS are archival demonstrations, not application code. External API
v1/v2 and RSS remain frozen. Implementation and deployment require their normal,
separate authorization. A proposal for a backfill does not approve a new command.
