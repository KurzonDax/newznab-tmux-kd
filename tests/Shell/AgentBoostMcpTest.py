#!/usr/bin/env python3
"""Exercise the shared MCP launcher in disposable clones, without PHP or Docker."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AgentBoostMcpTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='boost fixtures ')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve() / 'clone with spaces'
        self.root.mkdir()
        (self.root / 'scripts').mkdir()
        shutil.copy2(ROOT / 'scripts/agent-boost-mcp', self.root / 'scripts')
        for name in ['agent-sail', 'php']:
            stub = self.root / 'scripts' / name
            stub.write_text('#!/bin/sh\nprintf "%s\\n" "' + name + '" "$PWD" "$@"\n')
            stub.chmod(0o755)
        self.git('init', '-q', '-b', 'master')

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, capture_output=True, text=True, check=True)

    def launch(self):
        return subprocess.run([str(self.root / 'scripts/agent-boost-mcp')], cwd=self.temp.name,
                              env={**os.environ, 'PATH': str(self.root / 'scripts') + ':' + os.environ['PATH']},
                              text=True, capture_output=True, timeout=10)

    def test_mode_must_be_one_explicit_valid_value(self):
        for values in [[], [''], ['invalid'], ['canary', 'canary'], ['development', 'canary'], ['canary\n']]:
            with self.subTest(values=values):
                subprocess.run(['git', 'config', '--local', '--unset-all', 'nntmux.boostMode'], cwd=self.root, capture_output=True)
                for value in values:
                    self.git('config', '--local', '--add', 'nntmux.boostMode', value)
                result = self.launch()
                self.assertNotEqual(0, result.returncode)
                self.assertEqual('', result.stdout)
                self.assertIn('git config --local nntmux.boostMode', result.stderr)

    def test_both_clients_share_dispatch_and_development_adapter(self):
        codex = (ROOT / '.codex/config.toml').read_text()
        command = json.loads((ROOT / '.mcp.json').read_text())['mcpServers']['laravel-boost']['command']
        self.assertIn('command = "' + command + '"', codex)
        self.assertEqual('./scripts/agent-boost-mcp', command)
        for mode, executable, tail in [
            ('development', 'agent-sail', ['laravel.test', 'php', 'artisan', 'mcp:start', 'laravel-boost']),
            ('canary', 'php', [str(self.root / 'scripts/agent-boost-canary.php'), 'mcp:start', 'laravel-boost']),
        ]:
            self.git('config', '--local', 'nntmux.boostMode', mode)
            result = self.launch()
            self.assertEqual(0, result.returncode, result.stderr)
            lines = result.stdout.splitlines()
            self.assertEqual([executable, str(self.root)], lines[:2])
            self.assertEqual(tail, lines[-len(tail):])

    def test_local_choice_is_shared_only_by_worktrees_and_survives_fast_forward(self):
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.test')
        self.git('add', '.')
        self.git('-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'fixture')
        self.git('config', '--local', 'nntmux.boostMode', 'canary')
        worktree = Path(self.temp.name) / 'linked worktree'
        self.git('-c', 'core.hooksPath=/dev/null', 'worktree', 'add', '-qb', 'issue/1', str(worktree))
        self.assertEqual('canary', subprocess.check_output(
            ['git', 'config', '--local', '--get-all', 'nntmux.boostMode'], cwd=worktree, text=True).strip())
        clone = Path(self.temp.name) / 'independent clone'
        self.git('clone', '-q', str(self.root), str(clone))
        subprocess.run(['git', 'config', '--local', 'nntmux.boostMode', 'development'], cwd=clone, check=True)
        (worktree / 'ordinary-file').write_text('next revision')
        for args in [('add', '.'), ('-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'next')]:
            subprocess.run(['git', *args], cwd=worktree, check=True, capture_output=True)
        self.git('-c', 'core.hooksPath=/dev/null', 'merge', '--ff-only', 'issue/1')
        self.assertEqual('canary', self.git('config', '--local', '--get-all', 'nntmux.boostMode').stdout.strip())
        self.assertEqual('development', subprocess.check_output(
            ['git', 'config', '--local', '--get-all', 'nntmux.boostMode'], cwd=clone, text=True).strip())

    def test_development_keeps_master_and_ownership_rejection(self):
        shutil.copy2(ROOT / 'scripts/agent-sail', self.root / 'scripts')
        shutil.copy2(ROOT / 'scripts/agent-workflow-lib', self.root / 'scripts')
        shutil.copy2(ROOT / 'scripts/agent-checkout', self.root / 'scripts')
        self.git('config', '--local', 'nntmux.boostMode', 'development')
        result = self.launch()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('expected an issue/<number> branch', result.stderr)
        self.git('symbolic-ref', 'HEAD', 'refs/heads/issue/123')
        result = self.launch()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('ownership', result.stderr)

    def test_missing_php_has_actionable_error_without_runtime_setup(self):
        self.git('config', '--local', 'nntmux.boostMode', 'canary')
        (self.root / 'scripts/php').unlink()
        for command in ['bash', 'git', 'dirname']:
            (self.root / 'scripts' / command).symlink_to(shutil.which(command))
        result = subprocess.run([str(self.root / 'scripts/agent-boost-mcp')], cwd=self.temp.name,
                                env={**os.environ, 'PATH': str(self.root / 'scripts')},
                                text=True, capture_output=True, timeout=10)
        self.assertEqual(2, result.returncode)
        self.assertEqual('', result.stdout)
        self.assertIn('installed PHP CLI', result.stderr)


if __name__ == '__main__':
    unittest.main()
