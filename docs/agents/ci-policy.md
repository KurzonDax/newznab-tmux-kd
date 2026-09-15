# Bounded verification and CI policy

Read this before adding tests, changing CI selection, or publishing a change.
The accepted suite inventory is `.github/ci-policy.json`; commands and budgets
live there, not in feature PRs or copied instruction lists.

## During development

Local verification owns affected bounded regressions and `agent-verify final`.
Required sharded CI owns the complete main suite, including `/implement` work;
its generic full-suite instruction does not add a routine local serial pass.
Daily fresh-runtime serial coverage and explicitly requested exceptional runs
remain unchanged. The retired `php_seconds` value was a CI shard cutoff, never
a valid timeout for the local serial suite.

Run `python3 scripts/agent-verify plan` to see applicable local checks. Use
`python3 scripts/ci-policy plan` for the remote suite selection and its reasons.
PHP/Blade changes keep the complete fast PHP suite. Specialist suites follow
their subsystem paths; shared or unknown inputs select all bounded correctness.
Prose-only changes skip execution. Acceptance is always an explicit request.

Add the smallest regression that proves the behavior. Before publishing new or
changed PHP tests, measure the focused test with its setup:

```bash
python3 scripts/agent-verify focused --test tests/Feature/ExampleTest.php --filter test_example
python3 scripts/agent-verify final
```

Focused commands have a 180-second wall budget; new fast cases have a 30-second
budget and specialist cases 120 seconds. Timeouts fail, terminate child work, and
require diagnosis. A large fixture belongs in explicit acceptance with a small
recurring correctness proof. Moving an unchanged test between groups is checked
through selection contracts; it does not require rerunning a huge fixture.

Final verification owns changed-PHP formatting/lint, applicable full-project
PHPStan, Composer lock validation, source permissions, relevant frontend checks
and the small CI command contracts. Format
repairs stop verification so the agent can review the changed diff, restage and
rerun the verifier; this is expected repair, not an application regression.
Focused admission failures report a case exceeding its budget even when all
assertions pass: reduce redundant setup/work at the agreed seam, then remeasure.
A stale amendment requires review and regeneration against the current base;
retrying tests cannot fix admission. Hooks and
publication use the same verifier. Successful local records live in the issue's
Git metadata directory and are reused only while the command, inputs, base and
runtime identity match, including input file permissions. Uncertain or missing records rerun; failed results never
cache. Stage the same content that was verified. Local records never replace
GitHub's required merge validation.

Changes to a specialist file's shared helper require all of that file's registered
recurring cases. A focused run with the inventory's acceptance group excluded
can satisfy this check; omitting any recurring method fails admission. Manual
acceptance does not become routine because it shares the file.

Application fixes also require affected behavioral tests even when no test file
changes. Run the focused verifier for those tests; full-suite execution is not a
routine replacement for choosing the relevant regression. Documentation changes
use policy/document checks rather than inventing application tests.

## PR admission and policy amendments

`ci-preflight` loads the accepted validator from the base revision into a temporary
directory and inspects candidate files as data. It validates suite overlap,
acceptance exclusions, workflow commands and job prerequisites before any Sail
setup. Installed PHPUnit discovery then checks actual test identities, including
data-provider cases, against the complete registered specialist and acceptance
inventory. New integration files require an explicit inventory entry. Candidate
validator edits are not authoritative for their own PR.

Ordinary feature PRs cannot change recurring CI control files. If a change needs
new recurring work, first agree a separate CI-policy issue, including its cost.
For that authorized issue only, record the exact proposed amendment:

```bash
python3 scripts/ci-policy amendment --issue ISSUE_NUMBER --base origin/master
python3 scripts/ci-preflight --base origin/master
```

The amendment records the base SHA and every control-file digest, and must match
the issue branch. Review its delta and commit it with the policy change. Any
further control edit or base advance invalidates it: re-evaluate the change before
regenerating. A leftover amendment cannot authorize a different branch or future
base. #644 supplies the initial bootstrap; subsequent work uses its merged
validator. Cheap contract tests exercise candidate policy changes while the base
validator remains authoritative.
Because the base validator also pins the registered workflow commands, a change
to a registered command must land in the validator one merge before the workflow
itself changes.

This is an engineering guard against accidental expansion, not independent human
authentication: an agent with the owner's credentials could deliberately rewrite
workflow controls. An amendment command/label does not establish user agreement.
Keep agreement in the issue/session; never use amendment mode for incidental
feature work. No PR code executes in a privileged event.

## Execution and manual acceptance

### Measured scheduling and timeout headroom

PHPUnit discovery remains authoritative. `scripts/ci-phpunit-shard` sorts files
by descending measured cost then path, assigning each to the lightest shard
(ties use the lowest index). `.github/phpunit-costs.json` contains timing data,
not test selection or CI policy controls. Deleted entries are ignored; unknown
or renamed files use the median of measurements for currently discovered files
(1 second only when none exist). Every discovered file is assigned once.

Shard jobs and the daily serial run print `PHPUNIT_FILE_SECONDS` from temporary
JUnit reports, including each case's setup/teardown. When costs drift, refresh
the data from the daily run's printed totals and record the run/revision in its
`source`; no extra scheduled benchmark is needed. Bootstrap/discovery overhead
is reported separately by comparing job steps and `SHARD_TEST_SECONDS`.
Specialist estimates include material setup (downloaders include client install).

The PHP job's 20-minute outer ceiling and always-run Sail cleanup protect hangs;
there is no inner 480-second PHP cutoff. Performance targets below are separate
from kill thresholds. Validate headroom using the observed slow-run multipliers:
`1200 >= slowest balanced shard seconds * 4.4 + setup seconds * 1.5`.
A tighter ceiling requires measured workload and variance evidence.

The PR gate uses four PHP shards and two workers for selected suites. Each suite
prints its name, budget, elapsed time and result. Scheduling estimates are
separate from timeout ceilings: initial estimates use #644 local/PR measurements
so a generous timeout does not place several slow suites behind downloader work. The aggregate remains
`PHP 8.5 via Sail`; failures, empty selections and unexpected skips fail it.
The workflow files using JSON are valid YAML and permit strict standard-library
parsing in preflight without installing a YAML parser.

Run an individual bounded suite with `python3 scripts/ci-run SUITE_ID`. For large
acceptance use `python3 scripts/ci-run --acceptance SUITE_ID`, or dispatch the
Performance acceptance workflow at the intended revision. Its dropdown comes
from the explicit supported acceptance suites. Start MariaDB through the isolated
adapter if using the focused verifier directly for a database test.

Full admission comparison, catalogue-memory, million-row ingestion, retained-index,
naming-scale and frontier-scale tests remain
manual; there is no automatic performance schedule. Daily maintenance retains
fresh-runtime serial PHP plus the bounded suite inventory. Master pushes warm
runtime caches only; they do not repeat the full test pipeline.

## Completion and timing

Use ordinary run logs to compare execution, queue delay, setup and total runner
minutes. Targets for warm CI are five minutes for ordinary small PRs and eight
minutes for broad correctness; a target is not permission to omit coverage or
raise a timeout. Diagnose overruns using the named slow suite. Do not create a
benchmark campaign to measure the workflow itself.

Publish after local final verification. Keep existing strict-base protection,
superseded-run cancellation and merge monitoring. Avoid cosmetic mid-run pushes
and blind functional retries. Necessary base updates can still require another
run; this policy reduces its cost without changing merge scheduling or container
startup. CI changes are complete only after the normal merge helper confirms
`MERGE_STATUS=merged`.
