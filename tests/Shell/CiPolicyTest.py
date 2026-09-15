#!/usr/bin/env python3
"""Exercise CI planning and admission through its CLI, without Docker or network."""
import json
from pathlib import Path
import subprocess
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PolicyTest(unittest.TestCase):
    def plan(self, *paths):
        result = subprocess.run(['python3', str(ROOT / 'scripts/ci-policy'), 'plan', '--paths', *paths],
                                cwd=ROOT, text=True, capture_output=True)
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_unknown_and_shared_changes_select_correctness_only(self):
        for path in ['unknown-input', 'app/Providers/AppServiceProvider.php', 'tests/Support/a.php']:
            plan = self.plan(path)
            self.assertTrue(plan['php'])
            self.assertIn('ingestion', plan['suites'])
            self.assertNotIn('tv-memory', plan['suites'])
            self.assertEqual(len(plan['suites']), len(set(sum(plan['lanes'], []))))

    def test_docs_skip_runtime_but_blade_keeps_php(self):
        self.assertEqual([], self.plan('docs/guide.md')['suites'])
        self.assertFalse(self.plan('docs/guide.md')['php'])
        self.assertTrue(self.plan('resources/views/browse.blade.php')['php'])

    def test_duplicate_and_scale_selection_are_rejected(self):
        original = json.loads((ROOT / '.github/ci-policy.json').read_text())
        for mutation in ['duplicate', 'scale']:
            policy = json.loads(json.dumps(original))
            if mutation == 'duplicate':
                policy['suites']['duplicate'] = policy['suites']['ingestion']
            else:
                policy['suites']['recovery-publication']['exclude_groups'] = []
            with tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / 'policy.json'
                path.write_text(json.dumps(policy))
                result = subprocess.run(['python3', str(ROOT / 'scripts/ci-policy'), 'validate', '--policy', str(path)],
                                        cwd=ROOT, text=True, capture_output=True)
                self.assertNotEqual(0, result.returncode)
                self.assertIn('CI policy:', result.stderr)

    def test_required_result_rejects_unexpected_skip_and_missing_plan(self):
        for selected, result, expected in [(True, 'success', 0), (True, 'skipped', 1),
                                           (False, 'skipped', 0), (False, 'success', 1)]:
            needs = {'scope': {'result': 'success', 'outputs': {'php': str(selected).lower(),
                     'specialist': 'false'}}, 'php': {'result': result}, 'specialist': {'result': 'skipped'}}
            command = ['python3', str(ROOT / 'scripts/ci-policy'), 'result', json.dumps(needs)]
            actual = subprocess.run(command, cwd=ROOT, capture_output=True)
            self.assertEqual(expected, actual.returncode)
        actual = subprocess.run(['python3', str(ROOT / 'scripts/ci-policy'), 'result', '{}'],
                                cwd=ROOT, capture_output=True)
        self.assertNotEqual(0, actual.returncode)

    def test_base_validator_rejects_candidate_changes_and_exact_amendment_expires(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for folder in ['.github', 'scripts', 'tests/Integration']:
                shutil.copytree(ROOT / folder, root / folder)
            def git(*args):
                subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', *args], cwd=root,
                               check=True, capture_output=True)
            git('init', '-q'); git('checkout', '-qb', 'issue/99')
            git('config', 'user.name', 'CI fixture'); git('config', 'user.email', 'ci@example.test')
            git('add', '.'); git('commit', '-qm', 'baseline')
            candidate = root / 'scripts/ci-policy'
            candidate.write_text('# candidate bypass must never execute\n')
            command = ['python3', 'scripts/ci-preflight', '--base', 'HEAD']
            rejected = subprocess.run(command, cwd=root, text=True, capture_output=True)
            self.assertNotEqual(0, rejected.returncode)
            self.assertIn('recurring CI inputs changed', rejected.stderr)
            subprocess.run(['python3', str(ROOT / 'scripts/ci-policy'), 'amendment', '--base', 'HEAD', '--issue', '99'],
                           cwd=root, check=True, capture_output=True)
            accepted = subprocess.run(command, cwd=root, capture_output=True)
            self.assertEqual(0, accepted.returncode, accepted.stderr)
            candidate.write_text('# another unreviewed change\n')
            rejected = subprocess.run(command, cwd=root, capture_output=True)
            self.assertNotEqual(0, rejected.returncode)

    def test_full_branch_diff_includes_earlier_commits_renames_and_deletions(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for folder in ['.github', 'scripts', 'tests/Integration']:
                shutil.copytree(ROOT / folder, root / folder)
            def git(*args):
                return subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null', *args], cwd=root).decode().strip()
            git('init', '-q'); git('config', 'user.name', 'CI'); git('config', 'user.email', 'ci@example.test')
            resource = root / 'resources/js/old.js'; resource.parent.mkdir(parents=True); resource.write_text('old')
            git('add', '.'); git('commit', '-qm', 'base'); base = git('rev-parse', 'HEAD')
            resource.rename(resource.with_name('new.js'))
            git('add', '.'); git('commit', '-qm', 'rename')
            (root / 'docs.md').write_text('later commit')
            git('add', '.'); git('commit', '-qm', 'docs')
            command = ['python3', str(ROOT / 'scripts/ci-policy'), 'plan', '--base', base]
            result = subprocess.run(command, cwd=root, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            plan = json.loads(result.stdout)
            self.assertEqual(['frontend'], plan['suites'])
            self.assertEqual(['resources/js/new.js', 'resources/js/old.js'], plan['reasons']['frontend'])
            (root / 'scripts/ci-run').unlink(); git('add', '-u')
            result = subprocess.run(['python3', 'scripts/ci-preflight', '--base', base], cwd=root, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b'recurring CI inputs changed', result.stderr)
            added = root / 'tests/Integration/NewTest.php'; added.write_text('<?php class NewTest {}')
            result = subprocess.run(command, cwd=root, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b'explicit bounded or acceptance inventory', result.stderr)

    def test_runtime_inventory_rejects_omitted_and_duplicate_discovered_cases(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); (root / '.github').mkdir()
            xml = root / 'discovery.xml'
            xml.write_text('<testSuite><tests><testClass file="' + str(root / 'ExampleTest.php') +
                           '" name="Example"><testMethod id="Example::test_one" name="test_one"/>' +
                           '<testMethod id="Example::test_two" name="test_two"/></testClass></tests><groups/></testSuite>')
            policy = {'suites': {'example': {'files': ['ExampleTest.php'], 'filter': 'test_one'}}}
            manifest = root / '.github/ci-policy.json'
            command = ['python3', str(ROOT / 'scripts/ci-inventory'), '--xml', str(xml)]
            manifest.write_text(json.dumps(policy))
            result = subprocess.run(command, cwd=root, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b'absent from inventory', result.stderr)
            policy['suites']['example'].pop('filter'); manifest.write_text(json.dumps(policy))
            self.assertEqual(0, subprocess.run(command, cwd=root, capture_output=True).returncode)
            policy['suites']['duplicate'] = policy['suites']['example']; manifest.write_text(json.dumps(policy))
            result = subprocess.run(command, cwd=root, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b'duplicate discovered test', result.stderr)

    def test_scheduling_uses_observed_cost_not_timeout_headroom(self):
        original = self.plan('.github/ci-policy.json')
        policy = json.loads((ROOT / '.github/ci-policy.json').read_text())
        policy['suites']['ingestion']['seconds'] = 99999
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'policy.json'; path.write_text(json.dumps(policy))
            result = subprocess.run(['python3', str(ROOT / 'scripts/ci-policy'), 'plan', '--policy', str(path),
                                     '--paths', '.github/ci-policy.json'], cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(original['lanes'], json.loads(result.stdout)['lanes'])

    def test_client_install_uses_root_and_client_tests_use_sail(self):
        import runpy
        import sys
        from unittest.mock import patch
        sys.path.insert(0, str(ROOT / 'scripts'))
        runner = runpy.run_path(str(ROOT / 'scripts/ci-run'))
        policy = json.loads((ROOT / '.github/ci-policy.json').read_text())
        commands = []
        with patch.dict(runner['execute'].__globals__, run=lambda command, *a: commands.append(command)):
            runner['execute'](['downloaders'], policy, False)
        installer = next(c for c in commands if 'tests/Support/ObfuscationRecovery/install-clients.sh' in c)
        tests = next(c for c in commands if 'scripts/run-tests-isolated.sh' in c)
        self.assertEqual('root', installer[installer.index('-u') + 1])
        self.assertEqual('sail', tests[tests.index('-u') + 1])

    def test_frontend_does_not_start_database_or_downloaders(self):
        plan = self.plan('resources/js/alpine/components/content-toggle.js')
        self.assertFalse(plan['php'])
        self.assertEqual(['frontend'], plan['suites'])


if __name__ == '__main__':
    unittest.main()
