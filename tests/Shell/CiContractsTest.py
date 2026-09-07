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


if __name__ == '__main__':
    unittest.main()
