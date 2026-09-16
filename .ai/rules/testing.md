---
paths:
  - 'tests/**'
  - 'phpunit.xml'
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
