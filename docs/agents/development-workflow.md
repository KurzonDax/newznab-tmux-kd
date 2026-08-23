# Development workflow: issue startup → isolated work → peer completion

Master only moves by pull request. The required `PHP 8.5 via Sail` check is strict: a pull request that falls behind the effective master tip must update its issue branch and pass the check again before merge.

## `/implement <issue-number>` contract

The user starts a normal Codex or Claude Code session in the primary checkout and enters only `/implement <issue-number>`. The agent owns all branch, worktree, runtime, pull request, merge monitoring, and cleanup operations.

Before changing a source file, run the tracked startup helper from the primary checkout:

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

Use `WORKTREE_PATH` as the working directory for every later repository command in the session. Do not switch or pull the primary checkout. A stale local `master` is harmless because startup always branches from current `origin/master`.

If setup fails after the branch reservation, the helper deliberately preserves the branch, worktree, runtime, and issue assignment. A second ordinary start refuses to take it over. The same assigned GitHub user may inspect that state and explicitly resume it from the primary checkout:

```bash
scripts/agent-issue-start --recover <issue-number>
```

Never use `--recover` to take over another assignee's issue.

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

## Finish, merge, and cleanup

After tests and code review, stage the intended project files and commit them on `issue/<number>`. Do not stage temporary verification files. Then run:

```bash
scripts/agent-issue-finish
```

The finish helper:

1. Requires a clean issue worktree with commits ahead of `origin/master`.
2. Pushes only `issue/<number>`.
3. Opens or resumes exactly one pull request whose body contains `Fixes #<number>`.
4. Enables squash auto-merge immediately and monitors the required check and merge state.
5. When strict checks require a fresh base, fetches current `origin/master`, merges it only into the issue branch, pushes, and waits for the new check run. A conflict is left for explicit intervention.
6. After merge, stops only the worktree's Compose project, deletes only its remote issue branch if it remains, removes only its linked worktree, and deletes only its local issue branch.

The helper never switches or pulls the primary checkout. Other issue worktrees, containers, networks, dependency directories, and untracked files are outside its cleanup scope.

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
10. Run each issue's finish helper, then confirm its runtime, worktree, and branches are gone while the peer session and the primary checkout's recorded branch, HEAD, status, and untracked files are unchanged.
