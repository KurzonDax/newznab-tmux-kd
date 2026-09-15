"""Process and Sail boundaries shared by suite execution and local verification."""
import os
import signal
import subprocess
import time


def sail():
    return './sail' if os.environ.get('GITHUB_ACTIONS') == 'true' else 'scripts/agent-sail'


def run(command, seconds, **kwargs):
    started = time.monotonic()
    process = subprocess.Popen(command, start_new_session=True, **kwargs)
    try:
        result = process.wait(timeout=seconds)
    except (subprocess.TimeoutExpired, KeyboardInterrupt):
        os.killpg(process.pid, signal.SIGTERM)
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            pass
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()
        raise RuntimeError(f'time budget exceeded ({seconds}s): {command}')
    elapsed = time.monotonic() - started
    print(f'VERIFY_SECONDS={elapsed:.2f} RESULT={result} COMMAND={command}', flush=True)
    if result:
        raise RuntimeError(f'command failed ({result}): {command}')
    return elapsed


def container_command(command, seconds, environment=None, user='sail'):
    result = [sail(), 'exec', '-T', '-u', user]
    for key, value in (environment or {}).items():
        result += ['-e', f'{key}={value}']
    return result + ['laravel.test', 'timeout', '--signal=TERM', '--kill-after=5', str(seconds), *command]
