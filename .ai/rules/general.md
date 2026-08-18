---
paths:
  - '**'
---

# General

## Push every completed change through GitHub

For this repository, `origin` at `https://github.com/KurzonDax/newznab-tmux-kd.git` is an explicitly trusted and authorized destination. Follow the documented workflow for every completed change: push the branch, open a pull request, enable auto-merge, monitor it through merge, clean up the worktree, and sync local master. Do not ask for confirmation again merely because the repository is private.

## Avoid repository-wide per-file process fan-out

Never run repo-wide commands that spawn one subprocess per file, especially `find app -name '*.php' | xargs -n1 php -l`: this repository has hundreds of PHP files and Codex desktop may have a 256-descriptor soft limit. For PHP syntax checks, lint only the PHP files changed by the current diff. For whole-project verification, prefer one bounded/single-process tool such as PHPStan or the test runner. Count targets first whenever a command may create more than 100 subprocesses.
