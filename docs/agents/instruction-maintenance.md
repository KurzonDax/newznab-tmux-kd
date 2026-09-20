# Maintaining agent instructions

Use this document when editing agent guidance or updating Boost/dependencies in a
way that changes generated instructions. It is not a prerequisite for ordinary
application work.

## Sources and placement

`AGENTS.md` owns repository-wide authorization, workflow, verification, and a few
invariants. Keep implementation inventories in source and narrow traps in scoped
rules. A contextual pointer states when the reference matters; adding a pointer
does not make every linked document mandatory reading for every edit.

When relocating guidance, preserve the useful rule, correct its current facts,
and update `.ai/rules/index.md` plus path frontmatter. Shared response-shaping
services and routes must still encounter the frozen API/RSS policy. Reconcile
linked instructions so a removed universal prerequisite is not imposed again by
its target. Review root approval boundaries before and after the edit.

## Boost generation

`boost.json` disables guideline generation during normal updates. MCP and skill
settings are independent and remain enabled. `config/boost.php` also excludes the
installed generic guideline identifiers, so explicit generation composes an empty
result and `GuidelineWriter` leaves the hand-maintained file untouched.

Exclusions use exact identifiers, not wildcards. When Boost or dependencies change,
check the installed `GuidelineComposer` output against the configured package list
and Sail/test/MCP/skill options; new nonempty identifiers need an editorial decision.
Use supported configuration or overrides, never vendor edits. Do not use a global
editor/MCP reinstall to refresh prose.

Validate changes in the issue runtime. Boost registers its commands in local mode;
use the same per-process `APP_ENV=local` / `APP_DEBUG=true` overrides as
development mode in `scripts/agent-boost-mcp` via `scripts/agent-sail exec -T -u sail`. Keep the tracked
testing database configuration; do not replace the worktree environment file:

- Check ordinary `boost:update --no-discover --ignore-skills --no-interaction`
  through `scripts/agent-sail`; the root file and MCP/editor settings should be unchanged.
- For explicit generation, call the installed composer and writer against a temporary
  copy of `AGENTS.md` with the configured agents' guideline destinations redirected
  to that temporary location. Check the composed output and preservation of the
  entire file; no global MCP or editor configuration needs to be written.
- Check Markdown links, scoped paths, and contradictions against the changed guidance,
  then run the applicable shared final verifier. Prose edits do not need invented
  application tests or new recurring CI work.

## Diagnostic MCP configuration

Both clients retain the shared repository launcher. Follow
[Boost MCP runtime selection](development-workflow.md#boost-mcp-runtime-selection)
for the required initial clone-local choice and the development/canary boundaries.
Canary applies its tool policy in memory after deployed configuration loads and
revalidates every child through the diagnostic bootstrap; cached permissive Boost
settings cannot enable extra tools. It neither enables browser ingestion nor
changes the served application's environment/debug configuration. Guideline
updates and explicit generation remain development-runtime operations under the
policy above; canary provides no rule-writing or generation tools.
