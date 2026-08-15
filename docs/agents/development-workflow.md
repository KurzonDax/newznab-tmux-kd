# Development workflow: branch → PR → auto-merge

Master only moves by pull request. A ruleset enforces this server-side — direct pushes to master are rejected for everyone, including docs-only changes and repo admins. The required check is the **PHP 8.5 via Sail** job in `.github/workflows/laravel.yml`; PRs squash-merge automatically once it passes.

## The loop

1. **Branch, in its own worktree — always.** Every change, however small (docs, `.gitignore`, one-line fixes), gets its own worktree; never commit on a branch in the main checkout. Pick a short kebab-case branch name with a type prefix (`feat/`, `fix/`, `chore/`, `docs/`), then install dependencies inside the new worktree:

   ```bash
   git worktree add ~/worktrees/nntmux/<branch> -b <branch>
   cd ~/worktrees/nntmux/<branch>
   composer install --no-interaction
   npm ci        # only if you will touch resources/ or run npm run build
   ```

   `composer install` is not optional. `vendor/` is git-ignored, so a fresh worktree has none, and the CaptainHook git hooks (shared from the main checkout's `.git/hooks`) invoke `vendor/bin/captainhook`, `vendor/bin/pint`, and PHP lint **relative to the worktree** — without it every commit fails with `vendor/bin/captainhook: No such file or directory`. Tests and Pint need it for the same reason. Composer's trailing `php artisan package:discover` step boots the app and touches the database; if the dev database is not running it errors after `vendor/` is already complete, and that error can be ignored (Laravel rebuilds the package manifest lazily). Run PHPUnit as `vendor/bin/phpunit --filter=...` when `php artisan test` cannot boot for the same reason.

   Work and commit inside the worktree.

   > **macOS hosts:** the pre-commit action `scripts/runtime-permissions.sh check-source` models a Linux deployment host (`getent`, GNU `stat`/`find`/`realpath`, the `www-data` group) and skips itself on non-Linux platforms; the other hook actions (PHP lint, Composer lock check, Pint, design-system check) run normally. `make fix-permissions` and the other `runtime-permissions.sh` actions are Linux-only by design.

2. **Open the PR and arm auto-merge** in the same breath:

   ```bash
   git push -u origin <branch>
   gh pr create --fill        # heredoc body for anything non-trivial
   gh pr merge --auto --squash
   ```

3. **Monitor until the PR resolves.** Watch checks, then confirm the merge actually happened:

   ```bash
   gh pr checks <number> --watch
   gh pr view <number> --json state,mergedAt
   ```

   - **Merged** → go to step 4.
   - **CI failed** → fix on the branch, push, and watch again. The PR stays open; auto-merge fires once checks go green.

4. **Clean up and sync.** GitHub deletes the remote branch on merge; mirror that locally:

   ```bash
   git -C /mnt/data/nntmux-dev switch master
   git -C /mnt/data/nntmux-dev pull --ff-only
   git -C /mnt/data/nntmux-dev worktree remove ~/worktrees/nntmux/<branch>
   git -C /mnt/data/nntmux-dev branch -d <branch>
   ```

The loop is done when master contains the squashed commit and `git worktree list` + `git branch` show no leftovers from the branch.

## Notes

- One PR per unit of work; keep unrelated changes on separate branches.
- Auto-merge is armed at PR creation, not after review — green CI is the merge gate.
- Squash is the only enabled merge method; intermediate commit messages on the branch can stay rough.
- CI runs on every PR and on pushes to master (post-merge). A feature-branch push alone does not trigger CI until its PR exists.
