#!/usr/bin/env python3
"""Behavioral contracts for the CI command boundaries; no network or Docker."""
import itertools
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ClassifierTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'CI fixture')
        self.git('config', 'user.email', 'ci@example.test')
        self.write('app.php')
        self.commit()

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, check=True, capture_output=True)

    def write(self, path):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text('fixture\n')

    def commit(self):
        self.git('add', '.')
        self.git('-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'fixture', '--allow-empty')

    def classify(self):
        result = subprocess.run([str(ROOT / 'scripts/ci-classify-changes')], cwd=self.root,
                                check=True, capture_output=True, text=True)
        return dict(line.split('=', 1) for line in result.stdout.splitlines())

    def test_documentation_allowlist_handles_whitespace(self):
        for path in ('docs/a file', '.ai/rule', 'nested/a.md', 'LICENSE', 'docs/new\nline'):
            self.write(path)
        self.commit()
        self.assertEqual({'code': 'false', 'dependencies': 'false'}, self.classify())

    def test_mixed_workflow_and_deleted_code_run_full_ci(self):
        for path in ('.github/workflows/test.yml', 'docs/guide.md'):
            self.write(path)
        (self.root / 'app.php').unlink()
        self.commit()
        self.assertEqual({'code': 'true', 'dependencies': 'false'}, self.classify())

    def test_rename_from_code_to_documentation_still_runs_ci(self):
        self.git('mv', 'app.php', 'app.md')
        self.commit()
        self.assertEqual('true', self.classify()['code'])

    def test_deleted_documentation_stays_documentation(self):
        self.write('notes.md')
        self.commit()
        (self.root / 'notes.md').unlink()
        self.commit()
        self.assertEqual('false', self.classify()['code'])

    def test_each_dependency_manifest_requests_audit(self):
        for path in ('composer.json', 'composer.lock', 'package.json', 'package-lock.json'):
            with self.subTest(path=path):
                self.write(path)
                self.commit()
                self.assertEqual({'code': 'true', 'dependencies': 'true'}, self.classify())

    def test_missing_parent_and_empty_diff_are_conservative(self):
        self.assertEqual({'code': 'true', 'dependencies': 'true'}, self.classify())
        self.commit()
        self.assertEqual({'code': 'true', 'dependencies': 'true'}, self.classify())

    def test_failed_git_diff_is_conservative(self):
        self.git('config', 'core.repositoryformatversion', '999')
        self.assertEqual({'code': 'true', 'dependencies': 'true'}, self.classify())


class AggregateTest(unittest.TestCase):
    def test_only_the_two_explicit_success_states_pass(self):
        states = ('success', 'failure', 'skipped', 'cancelled')
        for code, scope, php, runtime in itertools.product(('true', 'false', '', 'unknown'), states, states, states):
            with self.subTest(code=code, scope=scope, php=php, runtime=runtime):
                result = subprocess.run([str(ROOT / 'scripts/ci-required-result'), code, scope, php, runtime], capture_output=True)
                expected = (scope == 'success' and
                            ((code == 'true' and php == runtime == 'success') or
                             (code == 'false' and php == runtime == 'skipped')))
                self.assertEqual(expected, result.returncode == 0)


class ShardTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.files = ['tests/ZTest.php', 'tests/a space Test.php', 'tests/ATest.php',
                      'tests/quote\"Test.php', 'tests/newTest.php', 'tests/sub/BTest.php']
        for path in self.files:
            target = self.root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.touch()
        self.discovery = self.root / 'discovery'
        self.discovery.write_text('PHPUnit 12.5.33 by Sebastian Bergmann and contributors.\n\nAvailable test files:\n' +
                                  ''.join(' - ' + str(self.root / path) + '\n' for path in self.files))
        self.capture = self.root / 'capture'
        binary = self.root / 'php'
        binary.write_text("""#!/usr/bin/env python3
import os, sys, pathlib, json
if '--list-test-files' in sys.argv:
    print(pathlib.Path(os.environ['DISCOVERY']).read_text(), end='')
    sys.exit(int(os.environ.get('DISCOVERY_EXIT', '0')))
pathlib.Path(os.environ['CAPTURE']).write_text(json.dumps(sys.argv[1:]))
report = pathlib.Path(sys.argv[sys.argv.index('--log-junit') + 1])
report.write_text(os.environ.get('JUNIT', '<testsuites><testsuite><testcase file="' + str(pathlib.Path.cwd() / 'tests/ZTest.php') + '" time="2.5"/></testsuite></testsuites>'))
sys.exit(int(os.environ.get('TEST_EXIT', '0')))
""")
        binary.chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.root) + os.pathsep + os.environ['PATH'],
                        DISCOVERY=str(self.discovery), CAPTURE=str(self.capture),
                        PHPUNIT_CACHE_DIRECTORY=str(self.root / 'isolated cache'))

    def shard(self, index=0, count=4):
        return subprocess.run([str(ROOT / 'scripts/ci-phpunit-shard'), '--index', str(index), '--count', str(count)],
                              cwd=self.root, env=self.env, capture_output=True, text=True)

    def test_partitions_equal_discovery_once_and_quote_arguments(self):
        selected = []
        for index in range(4):
            result = self.shard(index)
            self.assertEqual(0, result.returncode, result.stderr)
            args = json.loads(self.capture.read_text())
            self.assertEqual(['artisan', 'test', '--compact'], args[:3])
            self.assertIn('--fail-on-empty-test-suite', args)
            self.assertNotIn('--configuration', args, 'Artisan supplies the main XML configuration itself')
            self.assertEqual(str(self.root / 'isolated cache'), args[args.index('--cache-directory') + 1])
            selected.extend(arg for arg in args if arg.startswith('tests/'))
        self.assertCountEqual(self.files, selected)
        self.assertEqual(len(selected), len(set(selected)))
        self.assertIn('tests/newTest.php', selected)

    def test_measured_costs_balance_deterministically_and_ignore_stale_files(self):
        costs = self.root / '.github/phpunit-costs.json'
        costs.parent.mkdir()
        costs.write_text(json.dumps({'files': {
            self.files[0]: 80, self.files[1]: 40, self.files[2]: 20,
            self.files[3]: 10, 'tests/deleted.php': 9999,
        }}))
        assignments = []
        for index in range(4):
            result = self.shard(index)
            self.assertEqual(0, result.returncode, result.stderr)
            assignments.append([arg for arg in json.loads(self.capture.read_text()) if arg.startswith('tests/')])
        # Unknown/new and renamed files use the median of current measurements: 30.
        self.assertEqual([[self.files[0]], [self.files[1]],
                          ['tests/newTest.php', self.files[2]],
                          ['tests/sub/BTest.php', self.files[3]]], assignments)
        self.discovery.write_text('Available test files:\n' + ''.join(
            ' - ' + str(self.root / path) + '\n' for path in reversed(self.files)))
        self.shard(2)
        self.assertEqual(assignments[2], [arg for arg in json.loads(self.capture.read_text()) if arg.startswith('tests/')])

    def test_junit_costs_include_data_sets_and_attribute_traits_to_the_discovered_file(self):
        self.env['JUNIT'] = ('<testsuites><testsuite file="' + str(self.root / self.files[0]) + '" time="99">'
                             '<testsuite time="77"><testcase file="tests/Support/Trait.php" time="0.125"/>'
                             '<testcase file="tests/Support/Trait.php" time="0.375"/></testsuite>'
                             '</testsuite></testsuites>')
        result = self.shard()
        self.assertEqual(0, result.returncode, result.stderr)
        report = next(line.split('=', 1)[1] for line in result.stdout.splitlines() if line.startswith('PHPUNIT_FILE_SECONDS='))
        self.assertEqual({self.files[0]: 0.5}, json.loads(report))

    def test_daily_serial_execution_keeps_configured_suite_order(self):
        result = self.shard(0, 1)
        self.assertEqual(0, result.returncode, result.stderr)
        args = json.loads(self.capture.read_text())
        self.assertFalse(any(arg.startswith('tests/') for arg in args),
                         'Daily execution must let PHPUnit retain its configured suite order')
        self.assertIn('--log-junit', args)

    def test_test_failure_propagates_and_isolation_is_required(self):
        self.env['TEST_EXIT'] = '7'
        self.assertEqual(7, self.shard().returncode)
        self.capture.unlink()
        del self.env['PHPUNIT_CACHE_DIRECTORY']
        self.assertNotEqual(0, self.shard().returncode)
        self.assertFalse(self.capture.exists())

    def test_invalid_and_empty_partitions_fail(self):
        for index, count in ((-1, 4), (4, 4), (0, 0), ('bad', 4), (6, 7)):
            with self.subTest(index=index, count=count):
                self.assertNotEqual(0, self.shard(index, count).returncode)
                self.assertFalse(self.capture.exists())

    def test_duplicate_unexpected_empty_and_failed_discovery_fail(self):
        original = self.discovery.read_text()
        for content in (original + ' - ' + str(self.root / self.files[0]) + '\n',
                        original + 'unexpected warning\n',
                        'Available test files:\n',
                        original + ' - /outside/Test.php\n'):
            with self.subTest(content=content):
                self.discovery.write_text(content)
                self.assertNotEqual(0, self.shard().returncode)
                self.assertFalse(self.capture.exists())
        self.discovery.write_text(original)
        self.env['DISCOVERY_EXIT'] = '2'
        self.assertNotEqual(0, self.shard().returncode)
        self.assertFalse(self.capture.exists())


MAINTENANCE = ROOT / '.github/workflows/ci-maintenance.yml'
SERIAL_COMMAND = ('./sail exec -T -u sail laravel.test scripts/run-tests-isolated.sh '
                  'scripts/ci-phpunit-shard --index 0 --count 1')
BOUNDED_COMMAND = 'python3 scripts/ci-run --all-correctness'
REPORT_TITLE = 'Nightly CI maintenance is red'


def maintenance():
    return json.loads(MAINTENANCE.read_text())


def named_step(job, name):
    matches = [step for step in job['steps'] if step.get('name') == name]
    if len(matches) != 1:
        raise AssertionError(f'expected one step named {name!r}, found {len(matches)}')
    return matches[0]


class MaintenanceWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.workflow = maintenance()
        self.jobs = self.workflow['jobs']

    def test_serial_and_bounded_jobs_run_side_by_side_within_thirty_minutes(self):
        self.assertEqual({'serial', 'bounded', 'report'}, set(self.jobs))
        for key, other in (('serial', 'bounded'), ('bounded', 'serial')):
            needs = self.jobs[key].get('needs', [])
            self.assertNotIn(other, [needs] if isinstance(needs, str) else needs)
            self.assertEqual(30, self.jobs[key]['timeout-minutes'])

    def test_each_test_command_runs_once_in_its_own_job(self):
        commands = [(key, step['run']) for key, job in self.jobs.items() for step in job['steps'] if 'run' in step]
        self.assertEqual([('serial', SERIAL_COMMAND)], [c for c in commands if c[1] == SERIAL_COMMAND])
        self.assertEqual([('bounded', BOUNDED_COMMAND)], [c for c in commands if c[1] == BOUNDED_COMMAND])
        self.assertEqual(SERIAL_COMMAND, named_step(self.jobs['serial'], 'Run tests')['run'])
        self.assertEqual(BOUNDED_COMMAND, named_step(self.jobs['bounded'], 'Run bounded correctness from the shared inventory')['run'])

    def test_audit_follows_serial_tests_and_counts_whenever_setup_succeeded(self):
        steps = self.jobs['serial']['steps']
        audit = named_step(self.jobs['serial'], 'Audit dependencies')
        self.assertGreater(steps.index(audit), steps.index(named_step(self.jobs['serial'], 'Run tests')))
        self.assertEqual('make deps-audit', audit['run'])
        self.assertEqual("always() && steps.setup.outcome == 'success'", audit['if'])
        self.assertNotIn('continue-on-error', audit)
        for key in ('bounded', 'report'):
            self.assertFalse(any(step.get('run') == 'make deps-audit' for step in self.jobs[key]['steps']))

    def test_only_the_reporting_job_widens_permissions(self):
        self.assertEqual({'contents': 'read'}, self.workflow['permissions'])
        self.assertEqual({'issues': 'write', 'actions': 'read'}, self.jobs['report']['permissions'])
        for key in ('serial', 'bounded'):
            self.assertNotIn('permissions', self.jobs[key])

    def test_push_runs_warm_caches_once_and_no_tests(self):
        self.assertEqual("github.ref == 'refs/heads/master' && github.event_name != 'push'", self.jobs['serial']['if'])
        self.assertEqual("github.ref == 'refs/heads/master'", self.jobs['bounded']['if'])
        self.assertEqual("github.event_name != 'push'",
                         named_step(self.jobs['bounded'], 'Run bounded correctness from the shared inventory')['if'])
        self.assertEqual("github.event_name == 'push'",
                         named_step(self.jobs['bounded'], 'Warm npm downloads on input changes')['if'])
        self.assertIn("github.event_name == 'schedule'", self.jobs['report']['if'])

    def test_both_setups_are_fresh_outside_push(self):
        self.assertEqual('true', named_step(self.jobs['serial'], 'Set up runtime')['with']['fresh'])
        self.assertEqual("${{ github.event_name != 'push' }}",
                         named_step(self.jobs['bounded'], 'Set up runtime')['with']['fresh'])

    def test_only_the_bounded_job_writes_caches(self):
        setup = named_step(self.jobs['bounded'], 'Set up runtime')
        self.assertEqual('setup', setup['id'])
        self.assertEqual({'npm-cache': 'true', 'export-build-cache': 'true'},
                         {k: v for k, v in setup['with'].items() if k != 'fresh'})
        self.assertEqual('setup', named_step(self.jobs['serial'], 'Set up runtime')['id'])
        for key, job in self.jobs.items():
            saves = [step for step in job['steps'] if step.get('uses', '').startswith('actions/cache/save@')]
            warms = [step for step in job['steps'] if step.get('name') == 'Warm npm downloads on input changes']
            exports = [step for step in job['steps'] if step.get('with', {}).get('export-build-cache') == 'true']
            expected = (2, 1, 1) if key == 'bounded' else (0, 0, 0)
            self.assertEqual(expected, (len(saves), len(warms), len(exports)), key)

    def test_reporting_job_awaits_both_test_jobs_on_scheduled_runs_only(self):
        report = self.jobs['report']
        self.assertEqual(['serial', 'bounded'], report['needs'])
        self.assertIn('always()', report['if'])
        self.assertIn("github.event_name == 'schedule'", report['if'])
        self.assertFalse(any('uses' in step for step in report['steps']), 'reporting adds no action')


class MaintenanceReportTest(unittest.TestCase):
    """Runs the reporting step's script against a fake gh that records every call."""

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        workflow = maintenance()
        steps = workflow['jobs']['report']['steps']
        self.assertEqual(1, len(steps))
        self.assertEqual('python3 {0}', steps[0]['shell'])
        self.script = self.root / 'report.py'
        self.script.write_text(steps[0]['run'])
        self.calls = self.root / 'calls'
        gh = self.root / 'gh'
        gh.write_text("""#!/usr/bin/env python3
import json, os, pathlib, sys
args = sys.argv[1:]
with open(os.environ['CALLS'], 'a') as handle:
    handle.write(json.dumps({'args': args, 'stdin': '' if sys.stdin.isatty() else sys.stdin.read()}) + '\\n')
if args[:2] == ['run', 'view']:
    print(os.environ['FAKE_JOBS'])
elif args[:2] == ['issue', 'list']:
    print(os.environ['FAKE_ISSUES'])
""")
        gh.chmod(0o755)
        self.serial_name = workflow['jobs']['serial']['name']
        self.bounded_name = workflow['jobs']['bounded']['name']

    def job(self, name, conclusion, steps, minutes=12):
        return {'name': name, 'status': 'completed', 'conclusion': conclusion,
                'startedAt': '2026-11-12T08:20:00Z', 'completedAt': f'2026-11-12T08:{20 + minutes}:30Z',
                'steps': [{'name': step, 'status': 'completed', 'conclusion': result} for step, result in steps.items()]}

    def jobs(self, serial='success', tests='success', audit='success', bounded='success',
             correctness='success', setup='success', serial_minutes=12):
        return [self.job(self.serial_name, serial,
                         {'Set up runtime': setup, 'Run tests': tests, 'Audit dependencies': audit},
                         serial_minutes),
                self.job(self.bounded_name, bounded,
                         {'Set up runtime': 'success',
                          'Run bounded correctness from the shared inventory': correctness})]

    def report(self, jobs, issues=()):
        env = dict(os.environ, PATH=str(self.root) + os.pathsep + os.environ['PATH'], CALLS=str(self.calls),
                   FAKE_JOBS=json.dumps({'jobs': jobs}), FAKE_ISSUES=json.dumps(list(issues)),
                   GITHUB_REPOSITORY='owner/repo', GITHUB_RUN_ID='4242', GITHUB_SERVER_URL='https://github.com')
        result = subprocess.run(['python3', str(self.script)], env=env, capture_output=True, text=True,
                                stdin=subprocess.DEVNULL)
        self.assertEqual(0, result.returncode, result.stderr)
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        for call in calls:
            self.assertEqual(['--repo', 'owner/repo'], call['args'][-2:])
        return [call for call in calls if call['args'][:2] not in (['run', 'view'], ['issue', 'list'])]

    def issue(self, number, title=REPORT_TITLE, login='app/github-actions'):
        return {'number': number, 'title': title, 'author': {'login': login}}

    def test_red_night_without_an_open_issue_creates_one(self):
        writes = self.report(self.jobs(bounded='cancelled', correctness='cancelled'),
                             [self.issue(1241, title=REPORT_TITLE + ' (spec)', login='KurzonDax'),
                              self.issue(1300, login='KurzonDax')])
        self.assertEqual(1, len(writes))
        args = writes[0]['args']
        self.assertEqual(['issue', 'create'], args[:2])
        self.assertEqual(REPORT_TITLE, args[args.index('--title') + 1])
        self.assertEqual('needs-triage', args[args.index('--label') + 1])
        self.assertEqual('-', args[args.index('--body-file') + 1])
        body = writes[0]['stdin']
        self.assertIn('https://github.com/owner/repo/actions/runs/4242', body)
        self.assertIn('Bounded suites job | cancelled after 12.5 min', body)
        self.assertIn('Audit | passed', body)

    def test_red_night_replaces_the_lowest_matching_bot_issue_body(self):
        writes = self.report(self.jobs(serial='failure', audit='failure'),
                             [self.issue(1400), self.issue(1350, login='github-actions[bot]'),
                              self.issue(1300, login='KurzonDax')])
        self.assertEqual([['issue', 'edit', '1350', '--body-file', '-', '--repo', 'owner/repo']],
                         [call['args'] for call in writes])
        self.assertIn('Audit | failed', writes[0]['stdin'])
        self.assertIn('Serial PHP job | failure', writes[0]['stdin'])

    def test_failed_setup_reports_the_audit_as_not_run(self):
        writes = self.report(self.jobs(serial='failure', setup='failure', tests='skipped', audit='skipped'))
        self.assertIn('Audit | not run (runtime setup failed)', writes[0]['stdin'])
        self.assertIn('`Run tests` step | skipped', writes[0]['stdin'])

    def test_limit_night_reads_as_thirty_minutes(self):
        writes = self.report(self.jobs(serial='cancelled', tests='cancelled', serial_minutes=30))
        self.assertIn('Serial PHP job | cancelled after 30.5 min', writes[0]['stdin'])

    def test_missing_job_is_red(self):
        writes = self.report(self.jobs()[:1])
        self.assertEqual(['issue', 'create'], writes[0]['args'][:2])
        self.assertIn('Bounded suites job | not reported', writes[0]['stdin'])

    def test_green_night_closes_the_open_issue_with_one_comment(self):
        writes = self.report(self.jobs(), [self.issue(1400), self.issue(1300, login='KurzonDax')])
        self.assertEqual(1, len(writes))
        args = writes[0]['args']
        self.assertEqual(['issue', 'close', '1400'], args[:3])
        self.assertIn('https://github.com/owner/repo/actions/runs/4242', args[args.index('--comment') + 1])

    def test_green_night_without_an_open_issue_writes_nothing(self):
        self.assertEqual([], self.report(self.jobs(), [self.issue(1241, login='KurzonDax')]))


if __name__ == '__main__':
    unittest.main()
