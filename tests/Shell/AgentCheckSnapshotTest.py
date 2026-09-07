#!/usr/bin/env python3
"""Mock GitHub at the CLI boundary; exercise actual required-check snapshots."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
REPO = 'KurzonDax/newznab-tmux-kd'
NAME = 'PHP 8.5 via Sail'


def pr(head='a', state='OPEN', merge='BLOCKED', review=''):
    return dict(state=state, headRefOid=head, mergeStateStatus=merge, reviewDecision=review)


def check(state='SUCCESS', name=NAME, link=''):
    return dict(name=name, state=state, bucket='pass', link=link, workflow='Run tests')


def response(match, value, rc=0, stderr=''):
    return dict(match=match, value=value, rc=rc, stderr=stderr)


class SnapshotTest(unittest.TestCase):
    def run_snapshot(self, responses, expected=0):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            fixture = root / 'responses.json'
            fixture.write_text(json.dumps(responses))
            gh = root / 'gh'
            gh.write_text('''#!/usr/bin/env python3
import json, os, pathlib, sys
path = pathlib.Path(os.environ['GH_RESPONSES'])
responses = json.loads(path.read_text())
if not responses:
    sys.exit('Unexpected gh call: ' + repr(sys.argv))
reply = responses.pop(0)
if reply['match'] not in ' '.join(sys.argv[1:]):
    sys.exit('Unexpected gh call: ' + repr(sys.argv) + ' expected ' + reply['match'])
path.write_text(json.dumps(responses))
value = reply['value']
print(value if isinstance(value, str) else json.dumps(value))
print(reply.get('stderr', ''), file=sys.stderr)
sys.exit(reply['rc'])
''')
            gh.chmod(0o755)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ['PATH'], GH_RESPONSES=str(fixture))
            result = subprocess.run([str(ROOT / 'scripts/agent-check-snapshot'), REPO, '499'],
                                    env=env, capture_output=True, text=True)
            self.assertEqual(expected, result.returncode, result.stderr)
            self.assertEqual([], json.loads(fixture.read_text()))
            return json.loads(result.stdout) if expected == 0 else result.stderr

    def basic(self, checks):
        return [response('pr view', pr()), response('pr checks 499 --repo ' + REPO + ' --required', checks),
                response('pr view', pr()), response('pr view', pr())]

    def test_missing_expected_context_is_pending(self):
        for checks in ([], [check(name='Some other required check')]):
            self.assertEqual('pending', self.run_snapshot(self.basic(checks))['checksStatus'])

    def test_github_cli_reports_no_required_checks_before_aggregate_starts(self):
        for message in ("no required checks reported on the 'issue/499' branch",
                        "no checks reported on the 'issue/499' branch"):
            replies = self.basic([])
            replies[1] = response('--required', '', 1, message)
            self.assertEqual('pending', self.run_snapshot(replies)['checksStatus'])

    def test_current_success_failure_cancellation_skip_and_unknown(self):
        for state, expected in [('SUCCESS', 'success'), ('FAILURE', 'failure'), ('CANCELLED', 'failure'),
                                ('SKIPPED', 'failure'), ('IN_PROGRESS', 'pending'), ('UNKNOWN', 'failure')]:
            with self.subTest(state=state):
                self.assertEqual(expected, self.run_snapshot(self.basic([check(state)]))['checksStatus'])

    def test_other_actual_required_failures_are_not_ignored(self):
        self.assertEqual('failure', self.run_snapshot(self.basic([check(), check('FAILURE', 'Other required')]))['checksStatus'])

    def test_head_change_discards_old_cancellation_before_resolving_runs(self):
        replies = [response('pr view', pr()), response('--required', [check('CANCELLED')]),
                   response('pr view', pr('b'))]
        result = self.run_snapshot(replies)
        self.assertEqual('b', result['headRefOid'])
        self.assertEqual('pending', result['checksStatus'])

    def test_review_base_conflict_and_merge_state_return_without_check_reads(self):
        for snapshot in (pr(review='REVIEW_REQUIRED'), pr(review='CHANGES_REQUESTED'),
                         pr(merge='BEHIND'), pr(merge='DIRTY'), pr(state='MERGED'), pr(state='CLOSED')):
            with self.subTest(snapshot=snapshot):
                result = self.run_snapshot([response('pr view', snapshot)])
                self.assertEqual(snapshot['state'], result['state'])
                self.assertEqual(snapshot['reviewDecision'] or 'NONE', result['reviewDecision'])
                self.assertEqual(snapshot['mergeStateStatus'], result['mergeStateStatus'])

    def test_json_and_api_errors_are_distinct_from_required_failure(self):
        for value, rc in [('invalid JSON', 0), ({'message': 'API unavailable'}, 1), ({}, 0)]:
            with self.subTest(value=value):
                error = self.run_snapshot([response('pr view', pr()), response('--required', value, rc)], expected=2)
                self.assertIn('GitHub snapshot error', error)

    def action_replies(self, jobs, latest_status='completed', latest_id=10, attempt=2):
        run = dict(id=10, workflow_id=50, head_sha='a', event='pull_request', run_attempt=attempt,
                   status=latest_status, created_at='2026-09-07T00:00:00Z')
        newest = dict(run, id=latest_id, created_at='2026-09-07T00:01:00Z')
        return [response('pr view', pr()), response('--required', [check('CANCELLED', link=f'https://github.com/{REPO}/actions/runs/10/job/100')]),
                response('pr view', pr()), response('/actions/runs/10', run),
                response('/actions/workflows/50/runs?', {'workflow_runs': [newest]}),
                response(f'/actions/runs/{latest_id}/jobs?', {'jobs': jobs, 'total_count': len(jobs)}),
                response('pr view', pr())]

    def test_same_head_rerun_uses_newest_attempt(self):
        jobs = [dict(id=200, name=NAME, run_attempt=2, status='in_progress', conclusion=None)]
        self.assertEqual('pending', self.run_snapshot(self.action_replies(jobs, 'in_progress'))['checksStatus'])
        jobs[0].update(status='completed', conclusion='success')
        self.assertEqual('success', self.run_snapshot(self.action_replies(jobs))['checksStatus'])
        jobs[0]['conclusion'] = 'failure'
        self.assertEqual('failure', self.run_snapshot(self.action_replies(jobs))['checksStatus'])

    def test_same_head_new_run_replaces_cancelled_old_run(self):
        jobs = [dict(id=300, name=NAME, run_attempt=1, status='in_progress', conclusion=None)]
        self.assertEqual('pending', self.run_snapshot(self.action_replies(jobs, 'in_progress', 11, 1))['checksStatus'])

    def test_current_actions_cancellation_remains_failure(self):
        jobs = [dict(id=200, name=NAME, run_attempt=2, status='completed', conclusion='cancelled')]
        self.assertEqual('failure', self.run_snapshot(self.action_replies(jobs))['checksStatus'])

    def test_replacement_missing_job_is_pending(self):
        self.assertEqual('pending', self.run_snapshot(self.action_replies([], 'queued'))['checksStatus'])


if __name__ == '__main__':
    unittest.main()
