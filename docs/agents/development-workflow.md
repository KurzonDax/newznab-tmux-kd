# Development workflow: issue startup → isolated work → publish → merge

Master only moves by pull request, and every change merges through this loop — docs, one-line fixes, `/implement <issue-number>` sessions, and ad-hoc requests alike. The required `PHP 8.5 via Sail` check is strict: a pull request that falls behind the effective master tip must update its issue branch and pass the check again before merge.

**Definition of done:** a coding task is complete only when `scripts/agent-issue-finish` prints `MERGE_STATUS=merged`. Pushing the issue branch, opening the pull request, and enabling auto-merge are pre-authorized — run the finish helper without asking for confirmation. This overrides any skill or prompt instruction whose final step is committing.

## The loop

1. **Issue.** Work starts from an open GitHub issue labelled `ready-for-agent`. For a requested change with no issue yet, create one (`gh issue create` plus the label), then continue with it.
2. **Start.** From the primary checkout, before changing source files, run `scripts/agent-issue-start <issue-number>`.
3. **Work and verify.** Implement, test, run Pint and PHPStan, review, and commit on `issue/<number>` inside the worktree.
4. **Publish.** Immediately after committing, run `scripts/agent-issue-finish --publish` from the issue worktree.
5. **Monitor to merge.** Run `scripts/agent-issue-finish --monitor` — rerun it until it prints `MERGE_STATUS=merged`.

## Startup contract

The user starts a normal Codex or Claude Code session and the agent owns all branch, worktree, runtime, pull request, merge monitoring, and cleanup operations, through the helpers.

```bash
scripts/agent-issue-start <issue-number>
```

The helper verifies the issue is open, unassigned, labelled `ready-for-agent`, and has no open blocker. It reads existing ownership and pull request state, fetches current `origin/master` without writing `FETCH_HEAD`, then atomically reserves `issue/<number>` at `../worktrees/issue-<number>`. Only the session that wins that branch lock assigns the issue and starts setup.

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

Run Sail, PHP, Artisan, Composer, Node, npm, Pint, PHPStan, and tests through the worktree adapter:

```bash
scripts/agent-sail artisan test --compact --filter=TestName
scripts/agent-sail bin pint --dirty --format agent
scripts/agent-sail bin phpstan analyse --memory-limit=2G
scripts/agent-sail npm run build
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
- It is idempotent: rerun it as many times as needed, including after a previous monitor died mid-watch. Auto-merge never updates a `BEHIND` branch by itself, so a pull request whose monitoring session ended stays pending until some session reruns `--monitor`.
- `--timeout-seconds <n>` bounds one invocation: when the window elapses before merge, the helper exits successfully with `MERGE_STATUS=pending` and the instruction to rerun. Use it when the calling tool enforces a command timeout — CI takes about seven minutes per run, and a base update doubles that, so an unbounded monitor can outlive a 10-minute tool limit. A monitor cut off either way is interrupted, not failed; the loop is done only at `MERGE_STATUS=merged`.

Plain `scripts/agent-issue-finish` runs publish then an unbounded monitor. Every failure message names the next action; perform it and rerun the helper rather than ending the session.

Other issue worktrees, containers, networks, dependency directories, and untracked files are outside the finish helper's cleanup scope; the only primary-checkout operation it performs is the post-merge fast-forward of `master`.

## Concurrent sessions

Parallel sessions publish and arm auto-merge freely. The strict required check makes the race safe server-side: when a peer pull request merges first, GitHub marks the later one `BEHIND` and refuses to merge it on stale CI results; `--monitor` takes the new base and re-passes CI. The cost is one extra CI run per merge collision — never a stale merge.

## Manual acceptance checklist

This infrastructure uses a manual acceptance sequence rather than permanent workflow-specific CI tests. Use disposable, `ready-for-agent` issues and remove their temporary branches, worktrees, Compose projects, pull requests, and issue assignments after the exercise.

Run the complete sequence twice:

1. Record the primary checkout's branch, HEAD, status, and `origin/master`, then start two different disposable issues concurrently from normal Codex and Claude Code sessions.
2. Confirm the outputs have different issue numbers, branches, absolute worktree paths, Git worktree directories, Compose project names, container IDs, networks, `vendor` directories, `node_modules` directories, and source mounts.
3. Create a uniquely named untracked file in each worktree and confirm it is absent from the peer worktree and the primary checkout.
4. Run focused application tests simultaneously through each worktree's `scripts/agent-sail`; confirm their reported temporary cache roots differ.
5. Initialize Laravel Boost through each client's tracked MCP configuration and confirm a project query reports that client's worktree.
6. Stop one issue's Compose project and confirm the peer container and network remain running; restart the stopped project for completion.
7. Start the same third disposable issue from two processes at once and confirm exactly one acquires `issue/<number>` while the loser exits before assignment.
8. Advance or fetch `origin/master` without updating local `master`, start another disposable issue, and confirm its branch tip equals the current remote tip.
9. Complete two pull requests that began from the same base; confirm the later effective merge candidate is updated and its required check reruns against the new base before merge.
10. Publish one issue with `scripts/agent-issue-finish --publish` and confirm it returns within seconds with `MERGE_STATUS=pending` and auto-merge armed; kill its first `--monitor` mid-watch, rerun `--monitor`, and confirm the rerun resumes cleanly through `MERGE_STATUS=merged`.
11. Run `scripts/agent-issue-finish --monitor --timeout-seconds 30` against a pull request with checks still running and confirm it exits successfully with `MERGE_STATUS=pending` and a rerun instruction.
12. Run each issue's finish helper to completion, then confirm its runtime, worktree, and branches are gone, `PRIMARY_MASTER=synced` moved the primary checkout's `master` to the new `origin/master` tip (its status and untracked files otherwise unchanged), and the peer session is unaffected.
13. Dirty the primary checkout (or switch it off `master`), finish another disposable issue, and confirm the helper still exits with `MERGE_STATUS=merged` while reporting `PRIMARY_MASTER=skipped` and leaving that checkout untouched.
