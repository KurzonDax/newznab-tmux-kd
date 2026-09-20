# Development workflow: issue startup → isolated work → publish → merge

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

Master only moves by pull request. Agent changes use the issue loop below; human prose changes use the manual branch/PR path. The required `PHP 8.5 via Sail` check is strict: a pull request that falls behind the effective master tip must update its issue branch and pass the check again before merge.

Use [bounded verification and CI policy](ci-policy.md) to select checks for changed tests/CI or publication; reuse that context until the scope changes.
The manifest and planner select bounded correctness suites; the accepted-base
preflight runs before execution. Four PHP shards and two selected-suite workers
feed the existing strict aggregate. Prose-only changes skip runtime execution;
unknown/shared changes select all bounded correctness, never large acceptance.

**Definition of done:** a coding task is complete only when `scripts/agent-issue-finish` prints `MERGE_STATUS=merged`. Pushing the issue branch, opening the pull request, and enabling auto-merge are pre-authorized — run the finish helper without asking for confirmation. This overrides any skill or prompt instruction whose final step is committing.

## Human prose edits

Once per clone, run `bash scripts/install-git-hooks` (Git, Bash and Python 3 only).
It preserves existing custom hook configurations for manual reconciliation. Then
use an ordinary branch in Git or your Git GUI, edit prose, commit, push, and open a
PR. No issue, label, assignment, agent task, CodeGraph, PHP, Docker or dependency
installation is needed. Wait for any agent using primary to finish first.

```bash
git switch -c docs/clarify-guide
# Edit documentation, then:
git add docs/agents/development-workflow.md
git commit -m "Clarify the guide"
git push -u origin docs/clarify-guide
gh pr create --base master
```

The maintained hooks inspect staged paths, blobs and modes: ordinary `.md` and
`LICENSE` text qualify, while executable files, symlinks, shebangs, mixed code and
configuration do not. Agent changes retain the issue gate even when prose-only.
The command-line Git path is fixture-tested; no particular GUI has been tested.
GUIs that use normal Git hooks use these same entry points.

The installer selects clone-local hooks via `core.hooksPath`, leaving old hooks
intact. It installs every CaptainHook event, including inactive no-op events, so
the installed Composer plugin's default `--skip-existing` preserves them. Do not
force-reinstall CaptainHook over these hooks. No dependency manifests change.
Checkout/merge hooks perform no dependency installation; readiness is checked when
an application command is requested.

## Issue startup and ownership

Agents need an open, unblocked `ready-for-agent` issue and implementation approval.
From primary:

```bash
scripts/agent-issue-start NUMBER             # serial branch in primary
scripts/agent-issue-start --worktree NUMBER  # independent parallel checkout
```

Startup checks origin, issue state, effective Git identity and ownership, fetches current
`origin/master`, reserves the selected checkout and creates `issue/NUMBER`. It
initializes/synchronizes CodeGraph, but does not start Docker or install application
dependencies. Install maintained hooks once with `scripts/install-git-hooks` before
committing in this clone.

Use the returned absolute `WORKTREE_PATH` for every later repository command;
it names primary in the default mode. The output also supplies `ISSUE_NUMBER`,
`BRANCH`, `CHECKOUT_OWNER` and the path-derived `COMPOSE_PROJECT_NAME`. Primary must
start clean and on `master`; optional worktrees leave primary's files/branch alone.
The maintainer waits until primary's agent task finishes before editing it. There
is no routine suspend/switch/resume workflow.

An atomic directory in common Git metadata reserves each checkout until cleanup.
Its owner is `AGENT_SESSION_ID`, or the Codex/Claude task ID inherited by the process.
If the runner supplies none, export a unique `AGENT_SESSION_ID` before startup and
retain it for recovery. A clean checkout and a shared GitHub login do not grant a
second task ownership. Metadata stays outside tracked source.

For interrupted execution, resume the same task identity from primary:

```bash
scripts/agent-issue-start --recover NUMBER
```

The helper discovers the already checked-out mode. For a reservation interrupted
before optional-worktree creation, also pass `--worktree`. If continuing in a new
agent task, explicitly carry over the recorded `CHECKOUT_OWNER` as
`AGENT_SESSION_ID` only after confirming that the original task has ended. Never
copy an active task's identity. Recovery preserves uncommitted work on the issue
branch; unexpected branches, ownership mismatches and interrupted Git operations
stop with actionable state instead of stashing/resetting/discarding files.
Existing legacy issue worktrees without new ownership metadata retain their
assigned-owner recovery and finish path through this transition.

## CodeGraph

Agents must actually use a usable index for the selected checkout. Startup and
final verification run `scripts/agent-codegraph`; run it again after uncommitted
source changes or branch switches before more exploration. It attempts init,
sync and full repair, checks index identity/completeness/freshness, and exercises
exploration. Then use CodeGraph queries for the code under investigation.
Unsupported source types (such as these extensionless shell helpers) can be read
with ordinary tools after the CodeGraph query does not cover them.

If repair fails, stop implementation and ask the maintainer whether to wait for
repair or continue without CodeGraph for this task. Silence and elapsed time never
approve fallback. Only after explicit approval, set
`AGENT_CODEGRAPH_APPROVED_ISSUE=issue/NUMBER` in that task's process environment;
the helper reports the exception and continues. This exception does not change
the default policy or apply to other issue branches. Human prose edits bypass
CodeGraph entirely. Comparing or replacing CodeGraph with Graft is separate work.

## On-demand isolated runtime

Use `scripts/agent-sail` for PHP, Artisan, Composer, Node/npm and application tools;
Git, gh, workflow helpers and Python verification run on the host. The adapter
prepares runtime readiness on the first application command. `agent-worktree-setup`
remains the explicit readiness entry point in both checkout modes.

The runtime mounts the selected source and tracked `.env.testing` over its container
`.env`; it never copies over or sources primary's development `.env`. Composer/npm,
framework caches and logs have checkout-specific Docker volumes. Tests use default
in-memory SQLite or registered disposable MariaDB fixtures. This is not a general
network-isolation guarantee. Runtime/dependency fingerprints include checkout,
branch and relevant manifests/runtime inputs; verification receipts also include
checkout, branch, source inputs and runtime identity. Failed setup leaves no ready
receipt. If a setup process is killed, inspect the Git metadata `agent-runtime/setup-lock`
and remove that empty lock directory only after confirming no setup process runs.

```bash
python3 scripts/agent-verify plan
python3 scripts/agent-verify focused --test tests/Feature/ExampleTest.php --filter test_example
python3 scripts/agent-verify final
scripts/agent-sail artisan COMMAND --no-interaction
```

### Boost MCP runtime selection

Codex (`.codex/config.toml`) and Claude Code (`.mcp.json`) both use
`scripts/agent-boost-mcp`. Before the first connection in every clone, including
existing clones, explicitly select one mode. Run from that clone's checkout.

For managed development:

```bash
git config --local nntmux.boostMode development
```

For canary diagnostics against the installed application:

```bash
git config --local nntmux.boostMode canary
```

The clone-local Git setting is shared by linked worktrees, survives ordinary
pulls, and is independent of other clones. Exactly one value is required;
missing, empty, invalid, or duplicate values stop startup before application
bootstrap. Resolve duplicate entries in local Git configuration before reconnecting.
Branch, environment, hostname, and client identity never select a mode.

Development uses the existing isolated adapter, tracked testing configuration,
and ordinary development tools. Its issue-branch and task-ownership requirements
still apply, and dependencies/runtime setup remain deferred until needed.

Canary uses local PHP, installed Boost dependencies, and deployed configuration,
including the effective configuration cache. It works on `master` without checkout
ownership. Its dedicated CLI bootstrap activates console diagnostics without
changing the application environment/debug flag or web provider registration.
Each tool child returns through that bootstrap, independently checks canary mode
and the allowed command/tool, and reapplies the policy after configuration loads.
Only the existing `mcp:start` and `boost:execute-tool` commands are used.

The canary allowlist is ApplicationInfo, DatabaseConnections, DatabaseSchema,
DatabaseQuery, LastError, ReadLogEntries, BrowserLogs, GetAbsoluteUrl, and SearchDocs.
Tinker, RecordRule, extra configured tools, and newly installed tools are excluded.
Use bounded queries and targeted schema requests. Boost's read-query validation
is retained; it is not a database permission boundary. A separately provisioned
read-only database account is optional. BrowserLogs reads existing logs; a missing
log is an ordinary result, and diagnostic startup adds no browser collection.
Documentation searches must use generic technical terms, never private log content,
credentials, hostnames, paths, or user data.

Both modes use local stdio. Canary startup installs nothing and does not prepare
testing containers, migrate/seed, rebuild assets/caches, or restart services.
Missing PHP or Boost dependencies require preparation through the existing release
process. Diagnostic access does not authorize source edits, repairs, or data/runtime
mutations on the deployment host.

Build and test fixtures in the development environment. Validate actual Codex and
Claude Code startup, tool discovery, and diagnostic calls separately from protocol
harness coverage. After the merged release reaches a deployment, use each client
for application-info, a targeted schema read, `SELECT 1 AS diagnostic_ok`, and a
bounded existing-log read. Do not create probe data, development checkouts, worktrees,
or containers on the deployment host for this check.

## Public commit identity

The managed workflow requires `KurzonDax` and
`5052775+KurzonDax@users.noreply.github.com` for both author and committer of
outgoing commits. Startup and workflow-generated branch updates check Git's
effective identity, including environment overrides. Publication checks every
commit in `origin/master..HEAD` before each push and before enabling auto-merge;
a safe tip does not excuse an earlier unsafe commit. Rejections identify the
commit and field without printing the rejected value. Existing commits are never
rewritten automatically.

Local Git configuration does not select the author email of GitHub's new squash
commit. The finish helper explicitly supplies the approved noreply email and pins
the request to the checked local head. Resuming an armed request disables it and
re-enables squash auto-merge with that email; failures stop the workflow. After
GitHub confirms merge, the helper verifies the actual server-created author
before cleanup. GitHub's service committer is distinct from the maintainer author
and is not subject to the outgoing local-committer check.

## Requested visual approval

Identify which approval the maintainer requested and keep it as a gate:

- **Proposed design:** provide an accessible interactive prototype and wait for
  approval before implementing that design. Use the prototype skill or an inline
  interactive artifact; a written description is insufficient.
- **Implemented result:** run the actual changed application locally and wait for
  approval before publication. Tests and a prototype do not replace this review.

Require both only when requested. No preview starts for ordinary tasks. For an
implemented-result review:

```bash
scripts/agent-preview start
# Open the printed PREVIEW_URL in a browser; it binds to localhost only.
scripts/agent-preview stop
```

Preview startup prepares dependencies, migrates/seeds an independent temporary
MariaDB review database, builds assets and starts the changed app. It reports a URL
only after the HTTP health route responds. Review uses no production credentials
or data. The review database is destroyed on stop; the ordinary test runtime stays
available. Scenario-specific review fixtures (for example an authenticated account)
can be added through an existing approved interface within the agreed issue scope.
For a changed screen, navigate to that screen and verify its assets/interactions;
a healthy server alone does not establish visual approval.

## Verification, publication and cleanup

Implement and run affected bounded checks plus `agent-verify final`, review and
commit intended files. Required sharded CI owns the complete main suite, overriding
generic skill instructions for a local full-suite run. Then immediately run:

```bash
scripts/agent-issue-finish --publish
scripts/agent-issue-finish --monitor
```

Publication pushes only the issue branch, creates/resumes one PR containing
`Fixes #NUMBER`, and enables squash auto-merge. Keep monitoring until
`MERGE_STATUS=merged`; `--timeout-seconds N` bounds an invocation, not the task.
Current failures/reviews need action. API errors, absent aggregate checks and
changing-head snapshots never count as success. When behind master, the helper
merges current `origin/master`, pushes and follows the replacement required CI.
Conflicts remain available for explicit resolution. Existing strict-base rules
and superseded-run cancellation are unchanged.

Cleanup requires GitHub-confirmed merge and rechecks local state. It stops only
the selected runtime, removes that remote branch if present, and removes a linked
worktree only in worktree mode. Primary mode switches the preserved checkout to
`master`. Both remove the completed issue branch and release its checkout owner.
A clean primary on `master` is fast-forwarded and reported as `PRIMARY_MASTER=synced`;
otherwise its state is preserved with a corrective instruction. Other worktrees,
containers, networks and human files are outside this cleanup scope.

## PR cancellation and master maintenance

A new commit or base update cancels obsolete `Run tests` executions for that same PR. Different PRs keep independent concurrency groups. Keep the finish helper running or rerun `--monitor` so it follows the replacement gate through GitHub's confirmed merge.

Master pushes no longer repeat the full suite. `CI maintenance` warms BuildKit and Composer/npm download caches only when runtime, workflow, or dependency inputs change. PR jobs restore those caches and still build/install normally on a miss; trusted master maintenance is the cache writer. Installed dependencies and application caches stay local to each runner.

At 08:17 UTC daily, maintenance pulls base images, rebuilds every layer, and runs the full unsharded suite plus runtime/frontend validation on that exact fresh image. This retains serial order and state-leak coverage alongside PR sharding. Dependency audits are informational on dependency-changing PRs and every daily/manual full run.

Request the same fresh-runtime full validation on demand:

```bash
gh workflow run ci-maintenance.yml --ref master
```

Watch its Actions result under `CI maintenance`; it has a distinct check name and is not the PR merge gate. Maintenance runs serialize on master. A cache hit is an optimization, not evidence that a runtime passed validation.

## Concurrent sessions

Parallel sessions publish and arm auto-merge freely. The strict required check makes the race safe server-side: when a peer pull request merges first, GitHub marks the later one `BEHIND` and refuses to merge it on stale CI results; `--monitor` takes the new base and re-passes CI. The cost is one extra CI run per merge collision — never a stale merge.

## Infrastructure acceptance

Use the focused shell/Python contracts for policy selection, verification reuse,
aggregate states and merge monitoring. Validate the implementation through its
normal PR. Do not create repeated disposable full pipelines for conditions that
can be exercised by local fixture tests. Worktree startup and strict merge
behavior remain unchanged by the CI policy.
