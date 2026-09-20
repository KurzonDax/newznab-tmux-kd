#!/usr/bin/env python3
"""Normal Git commits classify staged prose before any application tooling."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ProseHooksTest(unittest.TestCase):
    def test_human_prose_commit_and_checkout_need_no_application_or_agent_setup(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            for name in ('install-git-hooks', 'git-hook'):
                if (ROOT / 'scripts' / name).exists():
                    shutil.copy2(ROOT / 'scripts' / name, root / 'scripts' / name)
            def git(*args, check=True):
                return subprocess.run(['git', *args], cwd=root, check=check, capture_output=True, text=True)
            git('init', '-q', '-b', 'master')
            git('config', 'user.name', 'Human')
            git('config', 'user.email', 'human@example.test')
            git('-c', 'core.hooksPath=/dev/null', 'add', '.')
            git('-c', 'core.hooksPath=/dev/null', 'commit', '--allow-empty', '-qm', 'Base')
            install = subprocess.run(['bash', 'scripts/install-git-hooks'], cwd=root, capture_output=True, text=True)
            self.assertEqual(0, install.returncode, install.stderr)
            git('switch', '-qc', 'docs/human')
            (root / 'README.md').write_text('Human prose\n')
            git('add', 'README.md')
            result = git('commit', '-qm', 'Clarify the manual workflow', check=False)
            self.assertEqual(0, result.returncode, result.stderr)
            (root / 'README.md').chmod(0o755)
            git('add', 'README.md')
            result = git('commit', '-qm', 'Make documentation executable', check=False)
            self.assertNotEqual(0, result.returncode, 'executable markdown must not receive the prose exemption')
            git('restore', '--staged', 'README.md')
            git('restore', 'README.md')
            (root / 'settings.json').write_text('{}')
            git('add', 'settings.json')
            self.assertNotEqual(0, git('commit', '-qm', 'Change configuration', check=False).returncode)


if __name__ == '__main__':
    unittest.main()
