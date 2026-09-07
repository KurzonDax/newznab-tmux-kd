#!/usr/bin/env python3
"""Exercise finish orchestration in disposable linked worktrees with a fake remote."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class FinishTest(unittest.TestCase):
    def exercise(self, scenario, expected):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            primary = root / 'primary'
            primary.mkdir()
            scripts = primary / 'scripts'
            scripts.mkdir()
            for name in ('agent-issue-finish', 'agent-check-snapshot', 'agent-workflow-lib'):
                shutil.copy2(ROOT / 'scripts' / name, scripts / name)
            (scripts / 'agent-sail').write_text('#!/usr/bin/env bash\nprintf "sail %s\\n" "$*" >> "$OPERATIONS"\n')
            (scripts / 'agent-sail').chmod(0o755)
            real_git = shutil.which('git')
            def git(*args):
                return subprocess.run([real_git, '-C', str(primary), *args], check=True, capture_output=True)
            git('init', '-q', '-b', 'master')
            git('config', 'user.name', 'CI fixture')
            git('config', 'user.email', 'ci@example.test')
            git('config', 'core.hooksPath', '/dev/null')
            git('add', '.')
            git('commit', '-qm', 'fixture')
            git('remote', 'add', 'origin', 'https://github.com/KurzonDax/newznab-tmux-kd.git')
            git('update-ref', 'refs/remotes/origin/master', 'HEAD')
            worktree = root / 'worktrees' / 'issue-123'
            git('worktree', 'add', '-qb', 'issue/123', str(worktree))
            fake = root / 'bin'
            fake.mkdir()
            (fake / 'git').write_text('''#!/usr/bin/env python3
import os, sys
args = sys.argv[1:]
if 'fetch' in args or 'push' in args or 'pull' in args:
    with open(os.environ['OPERATIONS'], 'a') as out: out.write('git ' + ' '.join(args) + '\\n')
    sys.exit(0)
if 'ls-remote' in args: sys.exit(2)
os.execv(os.environ['REAL_GIT'], [os.environ['REAL_GIT'], *args])
''')
            (fake / 'gh').write_text('''#!/usr/bin/env python3
import json, os, pathlib, sys
args = sys.argv[1:]
scenario = os.environ['SCENARIO']
if args[:2] == ['pr', 'list']:
    print('1' if args[-1] == 'length' else '123')
elif args[:2] == ['pr', 'merge']:
    with open(os.environ['OPERATIONS'], 'a') as out: out.write('auto-merge armed\\n')
elif args[:2] == ['pr', 'checks']:
    assert '--required' in args
    print(json.dumps([dict(name='PHP 8.5 via Sail', state='CANCELLED' if scenario in ('failure', 'head-change') else 'SUCCESS', bucket='pass', link='', workflow='Run tests')]))
elif args[:2] == ['pr', 'view']:
    fields = args[args.index('--json') + 1]
    if fields == 'url': print('https://github.com/KurzonDax/newznab-tmux-kd/pull/123')
    elif fields == 'body': print('Fixes #123')
    elif fields == 'state': print('OPEN')
    elif fields == 'autoMergeRequest': print('false')
    else:
        assert fields == 'state,mergeStateStatus,reviewDecision,headRefOid', fields
        counter = pathlib.Path(os.environ['COUNTER'])
        count = int(counter.read_text()) if counter.exists() else 0
        counter.write_text(str(count + 1))
        merged = (scenario == 'merged' or (scenario == 'success' and count >= 3) or
                  (scenario == 'base-update' and count >= 1) or (scenario == 'head-change' and count >= 2))
        print(json.dumps(dict(state='MERGED' if merged else 'OPEN',
            headRefOid='b' if scenario == 'head-change' and count >= 1 else 'a',
            mergeStateStatus='BEHIND' if scenario == 'base-update' and count == 0 else 'BLOCKED',
            reviewDecision='REVIEW_REQUIRED' if scenario == 'review' else '')))
else: sys.exit('Unexpected gh: ' + repr(args))
''')
            (fake / 'sleep').write_text('#!/usr/bin/env bash\nexit 0\n')
            for path in fake.iterdir():
                path.chmod(0o755)
            operations = root / 'operations'
            env = dict(os.environ, PATH=str(fake) + os.pathsep + os.environ['PATH'],
                        OPERATIONS=str(operations), REAL_GIT=real_git, SCENARIO=scenario, COUNTER=str(root / 'counter'))
            result = subprocess.run(['scripts/agent-issue-finish', '--monitor', '--timeout-seconds', '5'],
                                    cwd=worktree, env=env, capture_output=True, text=True)
            self.assertEqual(expected, result.returncode, result.stderr + result.stdout)
            calls = operations.read_text()
            self.assertIn('auto-merge armed', calls)
            if expected == 0:
                self.assertIn('MERGE_STATUS=merged', result.stdout)
                self.assertIn('sail down --remove-orphans', calls)
                self.assertFalse(worktree.exists())
                self.assertTrue(primary.exists())
            else:
                self.assertNotIn('sail down', calls)
                self.assertTrue(worktree.exists())
            if scenario == 'base-update':
                self.assertIn('push origin issue/123', calls)
            return result

    def test_only_confirmed_merge_cleans_up(self):
        for scenario in ('merged', 'success', 'base-update', 'head-change'):
            with self.subTest(scenario=scenario):
                self.exercise(scenario, 0)

    def test_current_failure_and_review_preserve_the_worktree(self):
        for scenario in ('failure', 'review'):
            with self.subTest(scenario=scenario):
                self.exercise(scenario, 2)


if __name__ == '__main__':
    unittest.main()
