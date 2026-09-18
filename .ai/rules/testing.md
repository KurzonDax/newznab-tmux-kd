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

The shared test case checks live tables on open Laravel SQLite and MariaDB
connections in `assertPostConditions()`, before isolated database teardown, and
before `tearDown()` when a test skips or is incomplete, so tables built in `setUp()`
are checked too. From the start of `setUp()`, every Laravel connection also inspects
the production tables a statement is about to drop, rename or wipe (`DROP TABLE`,
`RENAME`, `CREATE OR REPLACE`, `DROP DATABASE`, `DETACH`, SQLite catalog deletes): a
table created and dropped within one test is still checked. It checks raw SQL,
variable table names, attached and temporary tables, primary keys and separately
created unique indexes too; MariaDB is read through `information_schema`. Names are
compared without the connection's table prefix, and a prefixed MariaDB connection
owns only its prefixed tables; on a shared MariaDB schema an unknown table counts
only if the test created it. A schema loaded from the dump or built by running
migrations passes by construction, so a table a file in `database/migrations/` built
is not inspected when a migration drops or rebuilds it. A table the test built or
reshaped (`ALTER TABLE`, index DDL) is inspected whoever drops it, and so is a
migration-built table the test drops. Partial fixtures may omit unrelated columns.
Every included column must exist; every production primary/unique key whose columns
are present (and the auto-increment column alone) needs a fixture primary/unique
subset. Conversely, every fixture primary/unique key needs a production key subset.
Types, defaults, nullability, foreign keys and non-unique indexes are outside this
check. Partial unique indexes cannot satisfy an unconditional production key.

Tests that build tables must extend `Tests\TestCase`, and build them through Laravel
connections; `FixtureSchemaCoverageTest` fails for a table-building class that
extends PHPUnit directly. Tables on raw PDO handles outside Laravel are not seen.

Declare purpose-built tables with no production counterpart by overriding
`fixtureOnlyTables(): array` on that test class. Declarations are reported and cannot
exempt a production table.

A test that deliberately builds production tables in their shape from before one
migration, because the code under test runs ahead of it or reverses it, overrides
`historicalSchema()` with that migration's file name in `database/migrations/` and
the tables. For those tables in that class the guard skips R1-R3 and prints
`SCHEMA_HISTORICAL`; the test fails if the file is missing, a named table is never
built or the file never mentions it. Narrow it to the tests that need it (the
method can read `$this->name()`). No other path tolerates a violation.

When reviewing query changes, cite the dump's columns and keys independently of
the test fixture. A passing fixture cannot prove its own schema assumptions. For
regressions, show the old implementation failing against a production-faithful
fixture, then the fix passing. `video_data` is keyed by `releases_id` and has no
`id`; `audio_data` has an `id` primary key and a separate unique
`(releases_id, audioid)` stream key.
