# AGENTS.md

NNTmux is a Laravel Usenet indexer: it collects headers, forms releases, and enriches
searchable metadata. These instructions define repository policy and task-specific
entry points; source files and dependency metadata supply implementation details.

## Scope and authorization

Before changing project code, tests, configuration, or documentation, agree the
behavior, boundaries, and acceptance criteria, record them in a GitHub issue, and
confirm implementation is authorized. An explicit request to implement a scoped
issue satisfies this gate. Investigation, filing an issue, or a ready label alone
does not authorize implementation. State the issue number and scope before editing.

**New PHP Artisan commands require explicit approval of the specific command.**
This includes command classes, `Artisan::command()` closures, aliases, and one-off
backfill, repair, maintenance, or diagnostic commands. General issue implementation
approval is insufficient. Record command-specific approval in the agreed scope
before scaffolding or registering it; otherwise use an existing approved interface.

Dependency changes, new base folders, and removing tests/test files require approval.
Create documentation only when requested. Creating database records through Tinker
requires approval; ordinary tests use disposable fixtures.

## Issue-to-merge workflow

Every change, including documentation and one-line fixes, reaches master by PR.

1. Start with an open, scoped issue labelled `ready-for-agent` and implementation
   authorization. From the primary checkout run `scripts/agent-issue-start NUMBER`
   before project edits. The helper owns the branch, worktree, environment, and runtime.
2. Use the absolute `WORKTREE_PATH` it reports for every later repository command;
   work on `issue/NUMBER`. Leave the primary checkout alone while work is in flight.
   Report reserved state: only the assigned owner resumes their interrupted work
   using `--recover` from the primary checkout.
3. Implement, run affected bounded verification, review, and commit the intended
   files. Review and restage formatter changes; keep temporary artifacts out of Git.
4. Immediately run `scripts/agent-issue-finish --publish` in the issue worktree.
5. Run `scripts/agent-issue-finish --monitor` until it prints `MERGE_STATUS=merged`.
   A timeout interrupts monitoring; rerun it. The helper updates a branch behind
   master, follows required CI, and cleans up only this issue after merge. It also
   fast-forwards a clean primary checkout on master (`PRIMARY_MASTER` reports this).

Pushing the branch, opening the PR, enabling squash auto-merge, and monitoring through
merge are pre-authorized. Do not stop at a commit, open PR, or enabled auto-merge.
For startup/recovery, concurrent work, or publish failures, consult
[the workflow contract](docs/agents/development-workflow.md).

## Commands and testing

Run Git, gh, Python verification, and workflow helpers on the host. Run PHP, Artisan,
Composer, Node/npm, and other application commands through `scripts/agent-sail` in
the issue worktree. Direct Sail/Makefile calls do not supply the adapter's isolation.

```bash
python3 scripts/agent-verify plan
python3 scripts/agent-verify focused --test tests/Feature/ExampleTest.php --filter test_example
python3 scripts/agent-verify final
scripts/agent-sail artisan COMMAND --no-interaction
```

Within the helper-created worktree, run the accepted bounded checks, fix failures
caused by the requested change, and rerun invalidated checks without asking again.
The verifier owns applicable formatting, changed-file lint, full-project PHPStan,
frontend builds/checks, and reusable results; avoid separate duplicate passes.
Required sharded CI owns the complete main suite, including `/implement` work.
Generic skill instructions do not add a full local suite. Live API tests and large
acceptance runs need their own explicit scope; recurring CI expansion needs a
separately agreed policy issue.

Use [CI policy](docs/agents/ci-policy.md) when selecting new/changed tests, changing
CI, or resolving verification/publication requirements; retain that context while
its inputs remain unchanged. Documentation changes use document/policy checks.
For test fixtures, bootstrap, and isolation traps, see [testing rules](.ai/rules/testing.md).
The runtime starts from tracked `.env.testing`, not the primary `.env`; default
SQLite and registered MariaDB fixtures are disposable. This is not a blanket
network-isolation guarantee for every test in the repository.

## Repository invariants

- External API and RSS fields, attributes, and query parameters are frozen, including
  additive changes. Restore existing behavior only; new release data belongs in the
  web frontend. See [API/RSS rules](.ai/rules/api-frozen.md) when touching these surfaces.
- Admin settings are declared in `app/Support/Settings/` section providers; the
  registry is the write whitelist. See [settings hub rules](.ai/rules/settings-hub.md).
- Read environment variables in configuration and use `config()` in application
  code. Every added/renamed environment key needs a sensible default and short
  comment in `.env.example`. Configure credentials only for enabled integrations.
- Model casts use `casts()`. Preserve explicit relationship keys in the existing
  schema rather than inferring them from a universal naming pattern.
- Route groups, middleware groups, and aliases are wired in `bootstrap/app.php`.
  Inspect that registration to distinguish global from web-group middleware.

## Contextual guidance

Select the relevant entries from [the rules index](.ai/rules/index.md) for the
behavior and paths involved. Read the needed sections once; revisit when scope
changes. Small edits do not require a repository tour or unrelated rule searches.
Use skills available in the session when their workflows fit the task.

| When working on | Reference |
| --- | --- |
| Issue filing or triage | [Issue tracker](docs/agents/issue-tracker.md), [label mapping](docs/agents/triage-labels.md) |
| Domain terminology or design decisions | [Domain docs](docs/agents/domain.md) |
| Header ingestion and release formation | [Indexing pipeline](docs/architecture/indexing-pipeline.md) |
| NNTP providers or header-provider changes | [Provider architecture](docs/architecture/nntp-providers.md) |
| Missing segments and repair-before-delete | [Release repair](docs/architecture/release-repair.md) |
| Release naming | [Name-fixing rules](.ai/rules/name-fixing.md), [service README](app/Services/NameFixing/README.md) |
| Tmux scheduling or audio/additional processing | [Tmux rules](.ai/rules/tmux.md), [processing rules](.ai/rules/additional-processing.md) |
| Manticore query/schema work | [Search rules](.ai/rules/drivers.md) |
| Passkey chooser behavior | `app/Actions/Passkeys/GeneratePasskeyRegisterOptionsAction.php` and `config/passkeys.php`; the preferences/hints intentionally allow multiple authenticator types |

## Frontend

Application pages use Blade/Alpine. The active `resources/forum/blade-tailwind/`
preset includes Vue; retained Livewire forum templates are inactive. Laravel Pulse
uses Livewire. For the design system, Alpine CSP/lazy loading, active forum ownership,
and admin content interactions, use [frontend rules](.ai/rules/resources.md).

## Tools and instruction maintenance

Verify uncertain or version-sensitive APIs using installed package source/metadata
or official documentation. Manifests describe constraints; lockfiles and installed
metadata identify resolved versions. Use available Boost tools for documentation,
read-only data/schema inspection, URLs, and recent browser logs; use local source,
configuration, or existing read-only interfaces when those tools are unavailable.

Record settled, non-obvious rules within the authorized scope, using `record-rule`
when available. Keep each rule in one maintained source and update its contextual
pointer. When changing instructions or Boost generation, see
[instruction maintenance](docs/agents/instruction-maintenance.md).
