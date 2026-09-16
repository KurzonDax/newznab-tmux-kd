---
paths:
  - '**'
---

# General

The [root authorization policy](../../AGENTS.md#scope-and-authorization) applies, including command-specific Artisan approval.

## Workflow authority

The [root workflow](../../AGENTS.md#issue-to-merge-workflow) defines the required
issue-to-merge loop and pre-authorized publication. `origin` at
`https://github.com/KurzonDax/newznab-tmux-kd.git` is the trusted destination.

## Avoid repository-wide per-file process fan-out

Never run repo-wide commands that spawn one subprocess per file, especially `find app -name '*.php' | xargs -n1 php -l`: this repository has hundreds of PHP files and Codex desktop may have a 256-descriptor soft limit. For PHP syntax checks, lint only the PHP files changed by the current diff. For whole-project verification, prefer one bounded/single-process tool such as PHPStan or the test runner. Count targets first whenever a command may create more than 100 subprocesses.

## Keep recurring CI bounded
For test selection, CI changes, or publication requirements, use [CI policy](../../docs/agents/ci-policy.md) and its shared verifier; reuse that context while scope is unchanged. Preserve the accepted manifest/preflight boundary: large acceptance is explicit and recurring work expands only in a separately agreed CI-policy issue. Reuse successful local checks only while their verified inputs match.
