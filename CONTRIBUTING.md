# Contributing

This repository is the `KurzonDax/newznab-tmux-kd` fork of NNTmux. Every change
reaches `master` through a pull request. Pull requests are squash-merged once the
required checks pass, including the strict `PHP 8.5 via Sail` check; a pull
request that falls behind `master` must be updated and pass again before merge
(see the [development workflow](docs/agents/development-workflow.md)).

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

## Before you change anything

- **Application, configuration and test changes** start from a scoped GitHub
  issue that records the agreed behaviour and acceptance criteria. Open an issue
  first and wait for it to be agreed before you start.
- **Prose-only edits** (ordinary Markdown and `LICENSE` text) need no issue. Run
  `bash scripts/install-git-hooks` once per clone, then use an ordinary branch,
  commit, push and open a pull request. See
  [Human prose edits](docs/agents/development-workflow.md#human-prose-edits).
- **Agents** follow [AGENTS.md](AGENTS.md) and the
  [development workflow](docs/agents/development-workflow.md), which includes the
  issue startup, isolated runtime, verification and publish steps.
- Dependency changes, new top-level folders and removing tests need approval in
  the issue.

## Checks

The Docker development environment ([DOCKER.md](DOCKER.md)) provides the tools:

```bash
make test filter=SomeTest   # PHPUnit (omit filter= for the whole suite)
make pint                   # Laravel Pint on dirty files
make phpstan                # PHPStan
make npm-build              # production frontend build
```

Which checks a change needs, and what CI runs, is described in the
[CI policy](docs/agents/ci-policy.md).

## Reporting bugs and requesting features

Use the issue templates at
[github.com/KurzonDax/newznab-tmux-kd/issues](https://github.com/KurzonDax/newznab-tmux-kd/issues).
