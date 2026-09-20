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
    def exercise(self, scenario, expected, primary_mode=False, managed_worktree=False, unsafe_field=None, publish=False, override=None, no_merge=False):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            primary = root / 'primary'
            primary.mkdir()
            scripts = primary / 'scripts'
            scripts.mkdir()
            for name in ('agent-issue-finish', 'agent-check-snapshot', 'agent-workflow-lib', 'agent-checkout', 'agent-identity'):
                shutil.copy2(ROOT / 'scripts' / name, scripts / name)
            (scripts / 'agent-sail').write_text('#!/usr/bin/env bash\nprintf "sail %s\\n" "$*" >> "$OPERATIONS"\n')
            (scripts / 'agent-sail').chmod(0o755)
            (scripts / 'agent-verify').write_text('import sys\nsys.exit(0)\n')
            real_git = shutil.which('git')
            def git(*args):
                return subprocess.run([real_git, '-C', str(primary), *args], check=True, capture_output=True)
            git('init', '-q', '-b', 'master')
            git('config', 'user.name', 'KurzonDax')
            git('config', 'user.email', '5052775+KurzonDax@users.noreply.github.com')
            git('config', 'core.hooksPath', '/dev/null')
            git('add', '.')
            git('commit', '-qm', 'fixture')
            git('remote', 'add', 'origin', 'https://github.com/KurzonDax/newznab-tmux-kd.git')
            git('update-ref', 'refs/remotes/origin/master', 'HEAD')
            worktree = root / 'worktrees' / 'issue-123'
            if primary_mode:
                worktree = primary
                git('switch', '-qc', 'issue/123')
                subprocess.run(['python3', str(scripts / 'agent-checkout'), 'reserve', str(primary), str(primary / '.git'), '123'],
                               check=True, env=dict(os.environ, AGENT_SESSION_ID='fixture'), capture_output=True)
            else:
                git('worktree', 'add', '-qb', 'issue/123', str(worktree))
                if managed_worktree:
                    subprocess.run(['python3', str(scripts / 'agent-checkout'), 'reserve', str(worktree), str(primary / '.git'), '123'],
                                   check=True, env=dict(os.environ, AGENT_SESSION_ID='fixture'), capture_output=True)
            if unsafe_field:
                commit_env = dict(os.environ, **{unsafe_field: 'rejected@example.test'})
                subprocess.run([real_git, '-C', str(worktree), 'commit', '--allow-empty', '-qm', 'unsafe fixture'],
                               env=commit_env, check=True, capture_output=True)
                subprocess.run([real_git, '-C', str(worktree), 'commit', '--allow-empty', '-qm', 'safe tip'],
                               check=True, capture_output=True)
            if (publish or scenario == 'base-update') and not unsafe_field:
                subprocess.run([real_git, '-C', str(worktree), 'commit', '--allow-empty', '-qm', 'safe change'],
                               check=True, capture_output=True)
            if scenario == 'base-update':
                base = git('rev-parse', 'origin/master').stdout.decode().strip()
                tree = git('rev-parse', 'origin/master^{tree}').stdout.decode().strip()
                updated = git('commit-tree', tree, '-p', base, '-m', 'upstream change').stdout.decode().strip()
                git('update-ref', 'refs/remotes/origin/master', updated)
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
    print(('0' if args[-1] == 'length' else '') if scenario == 'initial' else ('1' if args[-1] == 'length' else '123'))
elif args[:2] == ['issue', 'view']:
    print('Fixture issue')
elif args[:2] == ['pr', 'create']:
    print('https://github.com/KurzonDax/newznab-tmux-kd/pull/123')
elif args[:2] == ['pr', 'merge']:
    with open(os.environ['OPERATIONS'], 'a') as out: out.write('merge ' + ' '.join(args) + '\\n')
    if '--disable-auto' not in args:
        assert '--auto' not in args
        assert '--squash' in args
        assert args[args.index('--author-email') + 1] == '5052775+KurzonDax@users.noreply.github.com'
        assert len(args[args.index('--match-head-commit') + 1]) == 40
        merges = pathlib.Path(os.environ['MERGES'])
        attempt = int(merges.read_text()) if merges.exists() else 0
        merges.write_text(str(attempt + 1))
        if scenario == 'merge-blocked' or (scenario in ('merge-race', 'merge-retry') and attempt == 0):
            sys.exit('failed to merge: Pull request is not mergeable')
        with open(os.environ['OPERATIONS'], 'a') as out: out.write('merge requested\\n')
elif args[:2] == ['api', 'repos/KurzonDax/newznab-tmux-kd/pulls/123']:
    print('a' * 40)
elif args[:2] == ['api', 'repos/KurzonDax/newznab-tmux-kd/commits/' + 'a' * 40]:
    print('false' if scenario == 'bad-server-author-' + ('email' if '.email' in args[-1] else 'name') else 'true')
elif args[:2] == ['pr', 'checks']:
    assert '--required' in args
    print(json.dumps([dict(name='PHP 8.5 via Sail', state='CANCELLED' if scenario in ('failure', 'head-change') else 'SUCCESS', bucket='pass', link='', workflow='Run tests')]))
elif args[:2] == ['pr', 'view']:
    fields = args[args.index('--json') + 1]
    if fields == 'url': print('https://github.com/KurzonDax/newznab-tmux-kd/pull/123')
    elif fields == 'body': print('Fixes #123')
    elif fields == 'state': print('OPEN')
    elif fields == 'autoMergeRequest': print('true' if scenario == 'resumed' else 'false')
    elif fields == 'state,mergeStateStatus,headRefOid':
        print('\\t'.join(['OPEN', 'BEHIND' if scenario == 'merge-race' else 'BLOCKED', 'a']))
    else:
        assert fields == 'state,mergeStateStatus,reviewDecision,headRefOid', fields
        counter = pathlib.Path(os.environ['COUNTER'])
        count = int(counter.read_text()) if counter.exists() else 0
        counter.write_text(str(count + 1))
        merged = (scenario in ('merged', 'bad-server-author-name', 'bad-server-author-email') or (scenario == 'success' and count >= 3) or
                  (scenario == 'base-update' and count >= 1) or (scenario == 'head-change' and count >= 2) or
                  (scenario == 'merge-race' and count >= 7) or (scenario == 'merge-retry' and count >= 6))
        print(json.dumps(dict(state='MERGED' if merged else 'OPEN',
            headRefOid='b' if scenario == 'head-change' and count >= 1 else 'a',
            mergeStateStatus='BEHIND' if (scenario == 'base-update' and count == 0) or (scenario == 'merge-race' and count == 3) else 'BLOCKED',
            reviewDecision='REVIEW_REQUIRED' if scenario == 'review' else '')))
else: sys.exit('Unexpected gh: ' + repr(args))
''')
            (fake / 'sleep').write_text('#!/usr/bin/env bash\nexit 0\n')
            for path in fake.iterdir():
                path.chmod(0o755)
            operations = root / 'operations'
            env = dict(os.environ, PATH=str(fake) + os.pathsep + os.environ['PATH'],
                        AGENT_SESSION_ID='fixture', OPERATIONS=str(operations), REAL_GIT=real_git, SCENARIO=scenario,
                        COUNTER=str(root / 'counter'), MERGES=str(root / 'merges'))
            if override:
                env[override] = 'rejected@example.test'
            arguments = ['--publish'] if publish else ['--monitor', '--timeout-seconds', '60']
            if no_merge:
                arguments.append('--no-merge')
            result = subprocess.run(['scripts/agent-issue-finish', *arguments],
                                    cwd=worktree, env=env, capture_output=True, text=True)
            self.assertEqual(expected, result.returncode, result.stderr + result.stdout)
            calls = operations.read_text()
            self.assertNotIn(' --auto', calls)
            if unsafe_field:
                self.assertNotIn('merge requested', calls)
                self.assertNotIn('push', calls)
                self.assertNotIn('rejected@example.test', result.stderr + result.stdout)
                self.assertIn('commit', result.stderr)
                return result
            if scenario == 'resumed':
                self.assertLess(calls.index('--disable-auto'), calls.index('push -u'))
            if override:
                self.assertNotIn('push', calls)
                self.assertNotIn('rejected@example.test', result.stderr + result.stdout)
                return result
            if no_merge:
                self.assertNotIn('merge requested', calls)
                self.assertIn('MERGE_STATUS=pending', result.stdout)
                self.assertTrue(worktree.exists())
                if publish:
                    self.assertIn('push -u origin issue/123', calls)
                return result
            if publish:
                self.assertIn('push -u origin issue/123', calls)
                self.assertNotIn('merge requested', calls)
                return result
            if scenario in ('success', 'merge-race', 'merge-retry'):
                self.assertIn('merge requested', calls)
                for flag in ('--squash', '--author-email', '--match-head-commit'):
                    self.assertIn(flag, calls)
            if scenario in ('merge-race', 'merge-retry'):
                self.assertEqual(2, calls.count('merge pr merge 123'))
                self.assertEqual(1, calls.count('merge requested'))
            if scenario == 'merge-blocked':
                # Bounded, so an unexplained refusal neither spins nor fails first try.
                self.assertEqual(3, calls.count('merge pr merge 123'))
                self.assertNotIn('merge requested', calls)
            if expected == 0:
                self.assertIn('MERGE_STATUS=merged', result.stdout)
                if not primary_mode and not managed_worktree:
                    self.assertIn('sail down --remove-orphans', calls)
                if primary_mode:
                    self.assertTrue(worktree.exists())
                    self.assertEqual(b'master\n', git('branch', '--show-current').stdout)
                    self.assertEqual([], list((primary / '.git/agent-checkouts').iterdir()))
                else:
                    self.assertFalse(worktree.exists())
                self.assertTrue(primary.exists())
                if primary_mode or managed_worktree:
                    self.assertNotIn('sail down', calls)
            else:
                self.assertNotIn('sail down', calls)
                self.assertTrue(worktree.exists())
            if scenario in ('base-update', 'merge-race'):
                self.assertIn('push origin issue/123', calls)
            return result

    def test_unsafe_earlier_commit_blocks_merge_enablement(self):
        for field in ('GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'):
            with self.subTest(field=field):
                self.exercise('merged', 2, unsafe_field=field)

    def test_publication_checks_all_commits_and_explicit_merge_email(self):
        self.exercise('initial', 0, publish=True)
        self.exercise('resumed', 0, publish=True)
        for field in ('GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'):
            self.exercise('merged', 2, publish=True, unsafe_field=field)

    def test_branch_update_rejects_effective_environment_overrides(self):
        for field in ('GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'):
            self.exercise('base-update', 2, override=field)

    def test_server_author_mismatch_preserves_checkout(self):
        for field in ('name', 'email'):
            result = self.exercise('bad-server-author-' + field, 2)
            self.assertIn('author.' + field, result.stderr)

    def test_monitor_merges_the_green_pull_request(self):
        self.exercise('success', 0)

    def test_merge_refused_by_a_peer_merge_updates_the_branch_and_retries(self):
        self.exercise('merge-race', 0)

    def test_merge_refused_while_mergeability_recomputes_is_retried(self):
        self.exercise('merge-retry', 0)

    def test_merge_refused_past_the_retry_limit_preserves_the_worktree(self):
        result = self.exercise('merge-blocked', 2)
        self.assertIn('BLOCKED', result.stderr)

    def test_opt_out_stops_at_the_open_pull_request(self):
        self.exercise('initial', 0, publish=True, no_merge=True)
        self.exercise('success', 0, no_merge=True)

    def test_only_confirmed_merge_cleans_up(self):
        for scenario in ('merged', 'success', 'base-update', 'head-change'):
            with self.subTest(scenario=scenario):
                self.exercise(scenario, 0)

    def test_unused_optional_runtime_does_not_require_docker_for_cleanup(self):
        self.exercise('merged', 0, managed_worktree=True)

    def test_primary_cleanup_preserves_checkout_and_returns_to_master(self):
        self.exercise('merged', 0, primary_mode=True)

    def test_current_failure_and_review_preserve_the_worktree(self):
        for scenario in ('failure', 'review'):
            with self.subTest(scenario=scenario):
                self.exercise(scenario, 2)


if __name__ == '__main__':
    unittest.main()
