---
paths:
  - 'tests/**'
  - 'phpunit.xml'
  - 'database/migrations/**'
  - 'database/schema/mariadb-schema.sql'
---

# Test fixtures and isolation

Use these details when adding/changing tests or diagnosing shared test state.
The [root verification workflow](../../AGENTS.md#commands-and-testing) and
[CI policy](../../docs/agents/ci-policy.md) own check selection and permissions.

- Tests are PHPUnit classes with `#[Test]` or a `test` prefix. Use existing factories
  and their relevant states. Create a test through
  `scripts/agent-sail artisan make:test --phpunit NAME --no-interaction` when needed.
- `phpunit.xml` defines the default Install/Unit/Feature suites with in-memory
  SQLite (`DB_CONNECTION=testing`) and forces stderr logging. Selected Integration
  suites also run in CI with isolated services; `.github/ci-policy.json` owns that
  inventory. Other integration tests deliberately contact live APIs. Mock HTTP in
  ordinary regressions; neither the directory name nor shared bootstrap guarantees
  that an arbitrary test makes no external requests.
- Reuse `tests/Fixtures/` and `tests/Support/`. App boot can read settings through
  `CategorizationPipeline` before test setup runs. `Tests\Support\IsolatedSqliteDatabase`
  provides the file-backed bootstrap workaround, including the minimal
  `categorizeforeign` and `catwebdl` settings. Follow its setup/teardown contract
  rather than duplicating it; `AdminContentControllerTest` demonstrates its use.
- Public layouts/search use `GlobalDataComposer`; admin views use
  `AdminDataComposer`, as registered in `AppServiceProvider`. Tests exercising public
  data can need to reset `GlobalDataComposer::$resolvedData`; existing
  `resetGlobalComposerState()` helpers demonstrate that reset. Do not assume every
  admin response consumes the public composer.
- In `Tests\TestCase`, allocate unique temporary files/directories with
  `makeTempPath()` / `makeTempDirectory()`; teardown removes them. Static providers
  and plain PHPUnit classes must allocate unique paths under `sys_get_temp_dir()`
  and clean them themselves. Never hardcode a shared temporary path.
- Restore any changed `DB_CONNECTION`/`DB_DATABASE` environment in teardown. The
  base test detects leakage and identifies the previous test; its explicit
  `$allowsConnectionSwap` exception is for intentional connection swaps, not a
  replacement for cleanup.

Choose the smallest regression proving the changed behavior and relevant failure
cases. Existing coverage can be sufficient. Removal of tests or test files remains
subject to the [root approval policy](../../AGENTS.md#scope-and-authorization).

## Schema-faithful fixtures and query review

`database/schema/mariadb-schema.sql` is the fixture schema authority. Migration
PRs refresh it from a fully migrated disposable MariaDB using the existing
`schema:dump` command through `scripts/agent-sail`. Keep migration files: the fast
freshness test checks that each file is recorded in the dump (older recorded
migrations may legitimately have been pruned). The MySQL dump is a separate,
legacy installation artifact and is not this guard's authority.

Prefer actual migrations or fixtures derived from the authority. New tests should
create production tables with
`Tests\Support\ProductionTables::fromAuthority()->create($table, $columns, $connection)`
rather than hand-written `Schema::create` or SQL; `createStatement()` returns the
SQL for a raw PDO handle. Name only the production columns the test needs, or pass
null for all. The table keeps every production primary/unique key whose columns are
present, the auto-increment identity, SQLite type affinity, literal defaults and
generated expressions (include their source columns). Every column is nullable.
Unknown tables, columns and types, and generated expressions SQLite cannot evaluate,
fail by name. A refreshed dump changes the table without editing the builder.

The shared test case checks live tables on already-open Laravel SQLite connections in
`assertPostConditions()`, before isolated database teardown. It checks raw SQL,
variable table names, attached databases, primary keys and separately created
unique indexes too. Partial fixtures may omit unrelated columns. Every included column must exist;
every production primary/unique key whose columns are present (and the
auto-increment column alone) needs a fixture primary/unique subset. Conversely,
every fixture primary/unique key needs a production key subset. Types, defaults,
nullability, foreign keys and non-unique indexes are outside this check. Partial
unique indexes cannot satisfy an unconditional production key.

Declare purpose-built tables with no production counterpart by overriding
`fixtureOnlyTables(): array` on that test class. Declarations are reported and cannot
exempt a production table. Tests extending PHPUnit directly and non-SQLite
connections are outside the shared guard.

Existing mismatches are temporarily recorded in `tests/schema-fixture-baseline.json`.
Each entry identifies a class, table, rule, column/key and the test cases where it
was observed. Listed violations are reported as tolerated; new violations and
resolved entries fail. Remove repaired cases/entries. #697 owns fixture repairs
and deletion of the baseline mechanism. Initial collection uses the existing test
runner with `NNTMUX_SCHEMA_COLLECT` pointing to a new temporary output file; this
is a rollout tool, never passing verification evidence or permission to enlarge
the baseline. Ordinary verification runs with this variable unset.

When reviewing query changes, cite the dump's columns and keys independently of
the test fixture. A passing fixture cannot prove its own schema assumptions. For
regressions, show the old implementation failing against a production-faithful
fixture, then the fix passing. `video_data` is keyed by `releases_id` and has no
`id`; `audio_data` has an `id` primary key and a separate unique
`(releases_id, audioid)` stream key.
