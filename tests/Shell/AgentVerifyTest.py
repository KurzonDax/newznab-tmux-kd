#!/usr/bin/env python3
"""Verify successful-check reuse and invalidation through the command boundary."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]


class VerifyTest(unittest.TestCase):
    def test_same_inputs_reuse_success_and_changed_inputs_run_again(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            subprocess.run(['git', 'init', '-q', directory], check=True)
            (root / 'input').write_text('first')
            command = ['python3', str(ROOT / 'scripts/agent-verify'), 'run', '--key', 'fixture',
                       '--inputs', 'input', '--seconds', '2', '--', 'python3', '-c',
                       'from pathlib import Path; p=Path("count"); p.write_text(p.read_text()+"x" if p.exists() else "x")']
            for unused in range(2):
                result = subprocess.run(command, cwd=root, capture_output=True, text=True)
                self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual('x', (root / 'count').read_text())
            (root / 'input').write_text('second')
            subprocess.run(command, cwd=root, check=True, capture_output=True)
            self.assertEqual('xx', (root / 'count').read_text())
            (root / 'input').chmod(0o755)
            subprocess.run(command, cwd=root, check=True, capture_output=True)
            self.assertEqual('xxx', (root / 'count').read_text())

    def test_failure_is_never_cached_and_timeout_ends_child_processes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            subprocess.run(['git', 'init', '-q', directory], check=True)
            child = "import time; from pathlib import Path; time.sleep(1); Path('child-finished').touch()"
            parent = f"import subprocess,time; subprocess.Popen({['python3', '-c', child]!r}); time.sleep(10)"
            command = ['python3', str(ROOT / 'scripts/agent-verify'), 'run', '--key', 'failure',
                       '--seconds', '0.2', '--', 'python3', '-c', parent]
            result = subprocess.run(command, cwd=root, capture_output=True, timeout=4)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b'time budget exceeded', result.stderr)
            time.sleep(1.2)
            self.assertFalse((root / 'child-finished').exists(), 'timeout must stop descendant processes too')
            self.assertEqual([], list((root / '.git/agent-verification').glob('*.json')))


    def test_focused_receipt_covers_changed_methods_and_rejects_midrun_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            def git(*args):
                subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', *args], cwd=root, check=True, capture_output=True)
            git('init', '-q'); git('config', 'user.name', 'CI'); git('config', 'user.email', 'ci@example.test')
            (root / 'tests').mkdir(); (root / 'storage').mkdir(); (root / 'scripts').mkdir()
            (root / 'fake-bin').mkdir()
            adapter = root / 'scripts/agent-sail'
            adapter.write_text("#!/usr/bin/env python3\nimport os,sys\na=sys.argv[sys.argv.index('laravel.test')+1:]\na=a[4:] if a[0]=='timeout' else a\nos.execvp(a[0],a)\n")
            adapter.chmod(0o755)
            test = root / 'tests/ExampleTest.php'
            test.write_text("<?php\nclass Example {\n public function test_old() {}\n}\n")
            fixture = root / 'scripts/run-tests-isolated.sh'
            fixture.write_text('#!/bin/sh\nexec "$@"\n'); fixture.chmod(0o755)
            (root / '.gitignore').write_text('fake-bin/\nstorage/\n')
            fake = root / 'fake-bin/php'
            fake.write_text("""#!/usr/bin/env python3
import sys, pathlib
if '-v' in sys.argv: print('PHP fixture'); sys.exit(0)
report = pathlib.Path(sys.argv[sys.argv.index('--log-junit') + 1])
name = sys.argv[sys.argv.index('--filter') + 1] if '--filter' in sys.argv else 'test_new'
report.write_text('<testsuite><testcase name="' + name + '" time="0.01"/></testsuite>')
if pathlib.Path('mutate').exists(): pathlib.Path('tests/ExampleTest.php').write_text('changed during run')
"""); fake.chmod(0o755)
            node = root / 'fake-bin/node'; node.write_text('#!/bin/sh\necho node-fixture\n'); node.chmod(0o755)
            git('add', '.'); git('commit', '-qm', 'base')
            test.write_text("<?php\nclass Example {\n public function test_old() {}\n public function test_new() {}\n}\n")
            env = dict(os.environ, PATH=str(root / 'fake-bin') + os.pathsep + os.environ['PATH'])
            prefix = ['python3', str(ROOT / 'scripts/agent-verify')]
            def invoke(mode, *extra):
                return subprocess.run(prefix + [mode, '--base', 'HEAD', '--test', 'tests/ExampleTest.php', *extra],
                                      cwd=root, env=env, text=True, capture_output=True)
            result = invoke('focused', '--filter', 'test_old')
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertNotEqual(0, invoke('evidence').returncode)
            result = invoke('focused', '--filter', 'test_new')
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(0, invoke('evidence').returncode)
            for filename in ['tests/Support.php', '.github/ci-policy.json']:
                changed = root / filename; changed.parent.mkdir(exist_ok=True)
                changed.write_text('changed input')
                self.assertNotEqual(0, invoke('evidence').returncode)
                self.assertEqual(0, invoke('focused', '--filter', 'test_new').returncode)
            node.write_text('#!/bin/sh\necho changed-runtime\n')
            self.assertNotEqual(0, invoke('evidence').returncode)
            self.assertEqual(0, invoke('focused', '--filter', 'test_new').returncode)
            (root / 'mutate').touch()
            result = invoke('focused', '--filter', 'test_new')
            self.assertNotEqual(0, result.returncode)
            self.assertIn('inputs changed during execution', result.stderr)
            self.assertNotEqual(0, invoke('evidence').returncode)


    def test_shared_bootstrap_requires_measurement_and_vite_selects_frontend(self):
        import argparse
        import runpy
        import sys
        from unittest.mock import patch
        sys.path.insert(0, str(ROOT / 'scripts'))
        verifier = runpy.run_path(str(ROOT / 'scripts/agent-verify'))
        globals = verifier['final_checks'].__globals__
        for path in ['tests/TestCase.php', 'tests/CreatesApplication.php']:
            with patch.dict(globals, changed=lambda base: [path], files=lambda: [path],
                            runtime_identity=lambda: 'fixture', git=lambda *a: '', checked=lambda *a: None,
                            measurements=lambda *a: iter([])), patch('subprocess.run'):
                with self.assertRaisesRegex(ValueError, 'focused behavioral measurement'):
                    verifier['final_checks'](argparse.Namespace(mode='final', base='HEAD'))
        calls = []
        with patch.dict(globals, changed=lambda base: ['vite.config.js'], files=lambda: ['vite.config.js'],
                        runtime_identity=lambda: 'fixture', git=lambda *a: '', checked=lambda key, *a: calls.append(key)), patch('subprocess.run'):
            verifier['final_checks'](argparse.Namespace(mode='final', base='HEAD'))
        self.assertTrue({'build', 'js', 'design'} <= set(calls))

    def test_shared_helper_below_test_requires_unfiltered_measurement(self):
        import runpy
        import sys
        from unittest.mock import patch
        sys.path.insert(0, str(ROOT / 'scripts'))
        verifier = runpy.run_path(str(ROOT / 'scripts/agent-verify'))
        with tempfile.TemporaryDirectory() as directory:
            test = Path(directory) / 'ExampleTest.php'
            test.write_text("<?php\nclass Example {\n    public function test_one()\n    {\n        $this->helper();\n    }\n    protected function helper()\n    {\n        sleep(90);\n    }\n}\n")
            with patch.dict(verifier['changed_methods'].__globals__, git=lambda *args: '@@ -9 +9 @@'):
                self.assertIsNone(verifier['changed_methods'](str(test), 'HEAD'))
            with patch.dict(verifier['changed_methods'].__globals__, git=lambda *args: '@@ -5 +5 @@'):
                self.assertEqual({'test_one'}, verifier['changed_methods'](str(test), 'HEAD'))

    def test_mutating_check_cannot_cache_success_and_staged_mismatch_fails_hook(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            subprocess.run(['git', 'init', '-q', directory], check=True)
            (root / 'input').write_text('before')
            command = ['python3', str(ROOT / 'scripts/agent-verify'), 'run', '--key', 'formatter',
                       '--inputs', 'input', '--', 'python3', '-c', 'from pathlib import Path; Path("input").write_text("after")']
            result = subprocess.run(command, cwd=root, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b'changed its inputs', result.stderr)
            (root / 'scripts').mkdir()
            (root / 'scripts/ci-preflight').write_text('')
            subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', 'add', '.'], cwd=root, check=True)
            (root / 'input').write_text('unstaged')
            # No commit/runtime required: mismatch must fail before expensive verification.
            subprocess.run(['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.test',
                            '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'base'], cwd=root, check=True, capture_output=True)
            subprocess.run(['git', 'add', 'input'], cwd=root, check=True)
            (root / 'input').write_text('different again')
            result = subprocess.run(['python3', str(ROOT / 'scripts/agent-verify'), 'hook', '--base', 'HEAD'],
                                    cwd=root, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b'staged/unstaged versions differ', result.stderr)



if __name__ == '__main__':
    unittest.main()
