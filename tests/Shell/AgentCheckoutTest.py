#!/usr/bin/env python3
"""Managed checkout reservation contracts with real Git and disposable remotes."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class CheckoutTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.primary = self.root / 'primary'
        self.primary.mkdir()
        shutil.copytree(ROOT / 'scripts', self.primary / 'scripts')
        self.git('init', '-q', '-b', 'master')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@users.noreply.github.com')
        self.git('config', 'core.hooksPath', '/dev/null')
        (self.primary / '.gitignore').write_text('.codegraph/\n.env\n')
        (self.primary / '.env').write_text('PRIVATE_DEVELOPMENT=preserved\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'base')
        self.git('remote', 'add', 'origin', 'https://github.com/KurzonDax/newznab-tmux-kd.git')
        self.git('update-ref', 'refs/remotes/origin/master', 'HEAD')
        fake = self.root / 'bin'
        fake.mkdir()
        self.env = dict(os.environ, PATH=str(fake) + os.pathsep + os.environ['PATH'],
                        REAL_GIT=shutil.which('git'), CODEX_THREAD_ID='owner-one')
        commands = {
            'git': '''#!/usr/bin/env python3
import os,sys
args=sys.argv[1:]
if 'fetch' in args: sys.exit(0)
if 'ls-remote' in args: sys.exit(2)
os.execv(os.environ['REAL_GIT'], [os.environ['REAL_GIT'], *args])
''',
            'gh': '''#!/usr/bin/env python3
import os,sys
args=sys.argv[1:]
if args[:2] == ['api','user']: print('fixture' if args[-1] == '.login' else 'fixture\\t1\\t')
elif args[:2] == ['issue','view']: print(os.environ.get('ISSUE_STATE', 'OPEN')+'\\t1\\t0\\t0')
elif args[:2] == ['issue','edit']: pass
elif args[:2] == ['pr','list'] or args[0] == 'api': print('0')
else: sys.exit('unexpected gh: '+repr(args))
''',
            'codegraph': '''#!/usr/bin/env python3
import json,os,pathlib,sys
pathlib.Path('.codegraph').mkdir(exist_ok=True)
if sys.argv[1] == 'status': print(json.dumps(dict(initialized=True, projectPath=os.getcwd(), fileCount=1, index=dict(state='complete'), pendingChanges={})))
else: print('usable fixture index')
''',
            'docker': '#!/bin/sh\necho "unexpected runtime startup" >&2\nexit 91\n',
        }
        for name, body in commands.items():
            path = fake / name
            path.write_text(body)
            path.chmod(0o755)

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.primary), *args], stderr=subprocess.STDOUT).decode().strip()

    def start(self, *args, env=None):
        return subprocess.run(['scripts/agent-issue-start', *args], cwd=self.primary,
                              env=env or self.env, capture_output=True, text=True, timeout=20)

    def test_default_reserves_primary_without_runtime_and_rejects_competing_session(self):
        result = self.start('123')
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual('issue/123', self.git('branch', '--show-current'))
        self.assertFalse((self.root / 'worktrees').exists())
        self.assertEqual('PRIVATE_DEVELOPMENT=preserved\n', (self.primary / '.env').read_text())
        result = self.start('124', env=dict(self.env, CODEX_THREAD_ID='owner-two'))
        self.assertNotEqual(0, result.returncode)
        self.assertIn('owned', result.stderr)
        self.assertEqual('issue/123', self.git('branch', '--show-current'))

    def test_recovery_requires_session_identity_and_keeps_interrupted_edits(self):
        self.assertEqual(0, self.start('123').returncode)
        (self.primary / 'interrupted.md').write_text('unfinished')
        result = self.start('--recover', '123', env=dict(self.env, CODEX_THREAD_ID='owner-two'))
        self.assertNotEqual(0, result.returncode)
        result = self.start('--recover', '123')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('unfinished', (self.primary / 'interrupted.md').read_text())

    def test_adapter_selects_testing_configuration_and_branch_invalidates_readiness(self):
        self.assertEqual(0, self.start('123').returncode)
        (self.primary / 'sail').write_text('#!/bin/sh\nprintf "%s\\n" "$APP_ENV" "$AGENT_WORKTREE_ROOT"\ncat "${SAIL_FILES#*:}"\n')
        (self.primary / 'sail').chmod(0o755)
        result = subprocess.run(['scripts/agent-sail', 'config'], cwd=self.primary,
                                env=self.env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue(result.stdout.startswith('testing\n' + str(self.primary.resolve())))
        self.assertIn('source: ${AGENT_WORKTREE_ROOT}/.env.testing', result.stdout)
        self.assertIn('agent-vendor:/var/www/html/vendor', result.stdout)
        self.assertEqual('PRIVATE_DEVELOPMENT=preserved\n', (self.primary / '.env').read_text())
        command = ['scripts/agent-runtime-state', 'runtime']
        first = subprocess.check_output(command, cwd=self.primary)
        (self.primary / 'package-lock.json').write_text('{"changed":true}')
        second = subprocess.check_output(command, cwd=self.primary)
        self.assertNotEqual(first, second)
        (self.primary / 'config').mkdir()
        (self.primary / 'config/app.php').write_text('<?php return [];')
        self.assertNotEqual(second, subprocess.check_output(command, cwd=self.primary))
        self.git('switch', '-qc', 'issue/124')
        self.assertNotEqual(second, subprocess.check_output(command, cwd=self.primary))
        result = subprocess.run(['scripts/agent-sail', 'config'], cwd=self.primary,
                                env=self.env, capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode, 'unexpected branch must not borrow another task runtime')

    def test_rejected_issue_does_not_reserve_primary(self):
        result = self.start('123', env=dict(self.env, ISSUE_STATE='CLOSED'))
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('master', self.git('branch', '--show-current'))
        self.assertEqual(0, self.start('124').returncode)

    def test_concurrent_sessions_cannot_both_reserve_primary(self):
        first = subprocess.Popen(['scripts/agent-issue-start', '123'], cwd=self.primary,
                                 env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        second = subprocess.Popen(['scripts/agent-issue-start', '124'], cwd=self.primary,
                                  env=dict(self.env, CODEX_THREAD_ID='owner-two'), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        first.communicate(timeout=20)
        second.communicate(timeout=20)
        self.assertEqual(1, sum(process.returncode == 0 for process in (first, second)))
        self.assertIn(self.git('branch', '--show-current'), ('issue/123', 'issue/124'))

    def test_dirty_primary_is_preserved(self):
        (self.primary / 'human-work').write_text('keep me')
        result = self.start('123')
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('master', self.git('branch', '--show-current'))
        self.assertEqual('keep me', (self.primary / 'human-work').read_text())

    def test_codegraph_failure_stops_until_task_specific_approval(self):
        (self.root / 'bin/codegraph').write_text('#!/bin/sh\nexit 1\n')
        result = self.start('123')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('CODEGRAPH_STATUS=approval-required', result.stderr)
        self.assertEqual('issue/123', self.git('branch', '--show-current'))
        result = subprocess.run(['scripts/agent-codegraph'], cwd=self.primary,
                                env=dict(self.env, AGENT_CODEGRAPH_APPROVED_ISSUE='issue/123'), capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)

    def test_explicit_worktree_is_independent(self):
        result = self.start('--worktree', '123')
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual('master', self.git('branch', '--show-current'))
        self.assertTrue((self.root / 'worktrees/issue-123/.git').is_file())


if __name__ == '__main__':
    unittest.main()
