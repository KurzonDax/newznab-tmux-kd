# Development workflow: issue startup → isolated work → publish → merge

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

Master only moves by pull request, and every change merges through this loop — docs, one-line fixes, `/implement <issue-number>` sessions, and ad-hoc requests alike. The required `PHP 8.5 via Sail` check is strict: a pull request that falls behind the effective master tip must update its issue branch and pass the check again before merge.

Use [bounded verification and CI policy](ci-policy.md) to select checks for changed tests/CI or publication; reuse that context until the scope changes.
The manifest and planner select bounded correctness suites; the accepted-base
preflight runs before execution. Four PHP shards and two selected-suite workers
feed the existing strict aggregate. Prose-only changes skip runtime execution;
unknown/shared changes select all bounded correctness, never large acceptance.

**Definition of done:** a coding task is complete only when `scripts/agent-issue-finish` prints `MERGE_STATUS=merged`. Pushing the issue branch, opening the pull request, and enabling auto-merge are pre-authorized — run the finish helper without asking for confirmation. This overrides any skill or prompt instruction whose final step is committing.

## The loop

1. **Issue.** Work starts from an open GitHub issue labelled `ready-for-agent`. Agree scope and implementation authorization first; creating or triaging an issue is not implementation authorization.
2. **Start.** From the primary checkout, before changing source files, run `scripts/agent-issue-start <issue-number>`.
3. **Work and verify.** Implement, run affected bounded tests and `python3 scripts/agent-verify final` locally, review, and commit on `issue/<number>` inside the worktree. Required sharded CI owns complete main-suite validation, overriding generic skill instructions for a local full-suite pass.
4. **Publish.** Immediately after committing, run `scripts/agent-issue-finish --publish` from the issue worktree.
5. **Monitor to merge.** Run `scripts/agent-issue-finish --monitor` — rerun it until it prints `MERGE_STATUS=merged`.

## Startup contract

The user starts a normal Codex or Claude Code session and the agent owns all branch, worktree, runtime, pull request, merge monitoring, and cleanup operations, through the helpers.

```bash
scripts/agent-issue-start <issue-number>
```

The helper verifies the issue is open, unassigned, labelled `ready-for-agent`, and has no open blocker. It reads existing ownership and pull request state, fetches current `origin/master` without writing `FETCH_HEAD`, then atomically reserves `issue/<number>` at `../worktrees/issue-<number>`. Only the session that wins that branch lock assigns the issue and starts setup.

The host needs Git, GitHub CLI (`gh`), `jq`, Python 3, and Docker for startup and merge monitoring.

The successful command ends with stable output:

```text
ISSUE_NUMBER=<number>
BRANCH=issue/<number>
WORKTREE_PATH=<absolute-path>
COMPOSE_PROJECT_NAME=<path-derived-name>
```

Use `WORKTREE_PATH` as the working directory for every later repository command in the session. Leave the primary checkout alone while work is in flight — a stale local `master` is harmless because startup always branches from current `origin/master`, and the finish helper syncs it after merge.

If setup fails after the branch reservation, the helper deliberately preserves the branch, worktree, runtime, and issue assignment. A second ordinary start refuses to take it over. The same assigned GitHub user may inspect that state and explicitly resume it from the primary checkout:

```bash
scripts/agent-issue-start --recover <issue-number>
```

`--recover` is only for resuming your own interrupted work; when startup reports another owner's reserved state, report it to the user.

Setup also checks the worktree's git identity, because the master ruleset requires an extra review for commits whose author email is not attributed to a GitHub account — which strands the finish helper at `REVIEW_REQUIRED`. It fails fast on an unset identity or an email it can prove unattributed, and prints a warning when the `gh` token cannot read the account's verified emails (a `users.noreply.github.com` address always passes).

## Isolated worktree runtime

Startup copies tracked `.env.testing` to the ignored worktree `.env`; it never copies the primary checkout's `.env` or development credentials. It starts the worktree's path-derived Compose project with `.github/docker-compose.ci.yml`, installs Composer and npm dependencies in that worktree, and verifies the container identity, source mount, testing database, PHP, Composer, and Node.

Run Git, gh, Python verification, and the workflow helpers on the host. The verifier routes application checks through the worktree adapter and records reusable results. For other PHP, Artisan, Composer, Node/npm, and Sail operations, use `scripts/agent-sail`:

```bash
python3 scripts/agent-verify focused --test tests/Feature/ExampleTest.php --filter test_example
python3 scripts/agent-verify final
scripts/agent-sail artisan COMMAND --no-interaction
```

The tracked Claude (`.mcp.json`) and Codex (`.codex/config.toml`) configurations both launch Laravel Boost through `scripts/agent-boost-mcp`, which delegates to this same isolated runtime as the container's `sail` user. MCP availability does not participate in issue locking or worktree creation.

## Publish, monitor, and cleanup

After tests and code review, stage the intended project files and commit them on `issue/<number>` (leave temporary verification files out). Then, from the issue worktree:

```bash
scripts/agent-issue-finish --publish
```

The publish phase requires a clean worktree with commits ahead of `origin/master`, pushes only `issue/<number>`, opens or resumes exactly one pull request whose body contains `Fixes #<number>`, and arms squash auto-merge. It returns within seconds and prints `MERGE_STATUS=pending`.

```bash
scripts/agent-issue-finish --monitor
```

The monitor phase polls the pull request until it merges:

- When strict checks require a fresh base (`BEHIND`), it fetches current `origin/master`, merges it only into the issue branch, pushes, and waits for the new check run. A conflict is left for explicit resolution in the issue worktree.
- After merge, it stops only the worktree's Compose project, deletes only its remote issue branch if it remains, removes only its linked worktree, and deletes only its local issue branch, then prints `MERGE_STATUS=merged`.
- It then fast-forwards the primary checkout's `master` when that checkout is clean and on `master`, reporting `PRIMARY_MASTER=synced`. A dirty or off-`master` checkout is left untouched (`PRIMARY_MASTER=skipped`) and a failed pull reports `PRIMARY_MASTER=sync-failed`; neither ever fails the finish — the corrective `git pull --ff-only` is printed for the user.
- It reads actual required checks and requires the aggregate to appear; absence remains pending. It discards changing-head snapshots and follows the newest Actions run/attempt for same-head reruns. A current required failure or cancellation remains actionable, and GitHub API errors are reported separately. Reviews and strict base checks still apply.
- It is idempotent: rerun it as many times as needed, including after a previous monitor died mid-watch. Auto-merge never updates a `BEHIND` branch by itself, so a pull request whose monitoring session ended stays pending until some session reruns `--monitor`.
- `--timeout-seconds <n>` bounds one invocation: when the window elapses before merge, the helper exits successfully with `MERGE_STATUS=pending` and the instruction to rerun. Use it when the calling tool enforces a command timeout — cold builds, runner queueing, and strict base updates can make an unbounded monitor outlive the tool limit. A monitor cut off either way is interrupted, not failed; the loop is done only at `MERGE_STATUS=merged`.

Plain `scripts/agent-issue-finish` runs publish then an unbounded monitor. Every failure message names the next action; perform it and rerun the helper rather than ending the session.

Other issue worktrees, containers, networks, dependency directories, and untracked files are outside the finish helper's cleanup scope; the only primary-checkout operation it performs is the post-merge fast-forward of `master`.

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
